<?php
/**
 * Numra for WooCommerce — historical order backfill
 *
 * A store that installs Numra arrives with history: hundreds of orders whose
 * phone numbers the network has never seen. Scoring only new orders means the
 * merchant waits weeks before the product knows anything about their customer
 * base, and the network never learns from the trading that already happened.
 *
 * This walks backwards through existing orders and logs them, quietly.
 *
 * Three rules it obeys, because a background job that spends money needs them:
 *
 *  1. NEVER exhaust the plan. It stops at a reserve of the daily limit so a
 *     backfill can never eat the credits a live order needs today. A merchant
 *     discovering their checkout stopped being screened because a history job
 *     drained the quota would be right to uninstall.
 *  2. NEVER shout. No admin notices, no order notes on clean results, no
 *     emails. Progress is readable on the Numra settings page if anyone cares
 *     to look, and invisible otherwise.
 *  3. ALWAYS resumable. It records its position, so a timeout, a deploy or a
 *     quota pause costs one batch, not the whole run.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Backfill {

	const CRON_HOOK = 'numra_backfill_batch';
	const SCHEDULE  = 'numra_quarter_hour'; // Registered by Numra_Heartbeat.

	const OPT_ENABLED  = 'numra_backfill_enabled';
	const OPT_CURSOR   = 'numra_backfill_cursor';   // Highest order id already considered.
	const OPT_DONE     = 'numra_backfill_done';
	const OPT_COUNT    = 'numra_backfill_count';    // Orders logged so far.
	const OPT_LAST_RUN = 'numra_backfill_last_run';

	/** Orders looked up per batch. Small on purpose: 15 lookups is a few
	 *  seconds of HTTP, comfortably inside any host's PHP time limit. */
	const BATCH = 15;

	/** Share of the daily plan the backfill will never cross into. Live orders
	 *  own the rest. */
	const RESERVE_RATIO = 0.5;

	public function register_hooks() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_init',    array( $this, 'ensure_scheduled' ) );
	}

	public function ensure_scheduled() {
		if ( ! self::is_enabled() || self::is_done() || ! Numra_Settings::is_connected() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ── State ─────────────────────────────────────────────────────────────────

	/**
	 * Runs only with the merchant's explicit agreement.
	 *
	 * This used to `return true` unconditionally, with no screen anywhere
	 * saying so and no way to stop it. The reasoning at the time was that it
	 * costs the merchant nothing and is invisible — both true, and neither is
	 * the point. This reads a store's entire order history and sends it to a
	 * third party. "Free and quiet" is not a substitute for being asked.
	 *
	 * Silence is not consent: an unanswered store syncs nothing. The consent
	 * screen appears once the store is connected, and until it is answered
	 * this returns false.
	 *
	 * Stopping is also honoured live, not just at the start of a run — a
	 * merchant who presses Stop mid-sync gets the next batch cancelled, and
	 * ensure_scheduled() stops re-arming the cron.
	 */
	public static function is_enabled() {
		return Numra_Settings::has_sync_consent();
	}
	public static function is_done()    { return '1' === (string) get_option( self::OPT_DONE, '0' ); }
	public static function logged()     { return (int) get_option( self::OPT_COUNT, 0 ); }
	public static function last_run()   { return (int) get_option( self::OPT_LAST_RUN, 0 ); }

	/** Start again from the newest order — used when a merchant re-enables it. */
	public static function reset() {
		delete_option( self::OPT_CURSOR );
		delete_option( self::OPT_DONE );
		delete_option( self::OPT_COUNT );
	}

	/**
	 * Turn the sync on.
	 *
	 * The cursor is deliberately NOT reset. A merchant who stops at order
	 * 4,000 of 10,000 and starts again wants the remaining 6,000, not the same
	 * 4,000 re-sent — and re-sending them would be harmless on the server
	 * (outcome writes are idempotent) but would look, from the counter, like
	 * the sync had stalled and restarted. reset() exists for the rare case
	 * where starting over is what is actually wanted.
	 */
	public static function start() {
		Numra_Settings::set_sync_consent( true );
		( new self() )->ensure_scheduled();
	}

	/**
	 * Stop it, and record that agreement has been withdrawn.
	 *
	 * Stopping writes consent to 'no' rather than to a separate "paused" flag,
	 * because there is no honest difference between the two: a merchant who
	 * presses Stop has withdrawn permission to read their order history, and a
	 * flag that remembers "they agreed once" while not syncing is a flag
	 * waiting to be misread by a future change. One question, one answer.
	 *
	 * The cron is torn down here rather than left to expire, so nothing runs in
	 * the gap before the next admin_init.
	 */
	public static function stop() {
		Numra_Settings::set_sync_consent( false );
		self::unschedule();
	}

	/**
	 * Where this store is, in one word, for the panel.
	 *
	 * @return string not_asked|declined|running|complete
	 */
	public static function state() {
		if ( ! Numra_Settings::sync_consent_answered() ) {
			return 'not_asked';
		}
		if ( ! Numra_Settings::has_sync_consent() ) {
			return 'declined';
		}
		return self::is_done() ? 'complete' : 'running';
	}

	// ── The batch ─────────────────────────────────────────────────────────────

	public static function run_batch() {
		if ( ! self::is_enabled() || self::is_done() ) {
			return;
		}
		if ( ! Numra_Settings::is_connected() ) {
			return;
		}
		if ( class_exists( 'Numra_Heartbeat' ) && ! Numra_Heartbeat::protection_enabled() ) {
			return; // Revoked or lapsed — the platform would refuse anyway.
		}

		update_option( self::OPT_LAST_RUN, time(), false );

		$cursor = (int) get_option( self::OPT_CURSOR, 0 );

		// Over-fetch: most rows in a window are already reported or not terminal.
		$orders = self::fetch_candidates( $cursor, self::BATCH * 4 );

		if ( empty( $orders ) ) {
			update_option( self::OPT_DONE, '1', false );
			self::unschedule();
			Numra_Logger::info( 'Numra backfill complete: ' . self::logged() . ' historical orders logged.' );
			return;
		}

		$spent  = 0;
		$lowest = $cursor;

		$reporter = new Numra_Outcome_Reporter();

		foreach ( $orders as $order ) {
			$id     = $order->get_id();
			$lowest = ( 0 === $lowest ) ? $id : min( $lowest, $id );

			if ( $spent >= self::BATCH ) {
				break;
			}
			if ( '' === trim( (string) $order->get_billing_phone() ) ) {
				continue;
			}

			/* History, not scores.
			
			   The first version of this looked every historical order up,
			   which was the wrong operation twice over: a lookup is a READ
			   that costs a credit and answers "how risky is this number
			   today", and for an order that was delivered three months ago
			   nobody needs that answer. What the network is missing is the
			   FACT — this number ordered, and here is what happened. That is
			   an outcome write, it costs no lookup credit, and it is what
			   actually builds a customer's history.
			
			   report_current_state() returns '' for anything still in flight.
			   A pending or processing order has no outcome yet, and inventing
			   one would poison the data this exists to build. */
			if ( '' !== $reporter->report_current_state( $order ) ) {
				$spent++;
			}
		}

		update_option( self::OPT_CURSOR, $lowest > 0 ? $lowest : $cursor, false );
		update_option( self::OPT_COUNT, self::logged() + $spent, false );
	}

	/**
	 * Orders older than the cursor, newest first.
	 *
	 * wc_get_orders() is used rather than a direct query so this works
	 * identically on HPOS and the legacy post table.
	 */
	private static function fetch_candidates( $cursor, $limit ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$args = array(
			'limit'   => $limit,
			'orderby' => 'ID',
			'order'   => 'DESC',
			'type'    => 'shop_order',
			'status'  => array_keys( wc_get_order_statuses() ),
		);

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		if ( $cursor > 0 ) {
			$orders = array_filter( $orders, function ( $o ) use ( $cursor ) {
				return $o instanceof WC_Order && $o->get_id() < $cursor;
			} );
		}

		return array_values( $orders );
	}
}
