<?php
/**
 * Numra for WooCommerce — Outcome Reporter
 *
 * Tells Numra what actually happened to an order, so the network learns from
 * this store's deliveries. Without this the plugin only consumes scores and
 * never contributes any, which is what makes the scores worth having.
 *
 * ── Why this runs through Action Scheduler ──
 * Reporting is not time-critical and must not fail because the API blinked.
 * Action Scheduler ships with WooCommerce, retries on failure, and survives a
 * request dying mid-flight. A merchant changing an order status must never
 * wait on an HTTP call, and a five-minute API outage must not silently lose a
 * day of delivery outcomes.
 *
 * ── Why retrying is safe ──
 * The server is idempotent on (merchant, order_id, outcome_type) — a duplicate
 * is a 200 no-op. That is what allows a blind retry with no local bookkeeping
 * about whether the previous attempt actually landed.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Outcome_Reporter {

	/** Action Scheduler hook name. */
	const ACTION = 'numra_report_outcome';

	/** Action Scheduler group, so these are filterable in the WC admin UI. */
	const GROUP = 'numra';

	/** Order meta: the last outcome we successfully reported. */
	const META_REPORTED = '_numra_outcome_reported';

	/** How many times to retry before giving up. */
	const MAX_ATTEMPTS = 4;

	/**
	 * Default WooCommerce status → Numra outcome.
	 *
	 * Deliberately conservative. `cancelled` and `failed` are NOT mapped to
	 * REFUSED_COD: a customer cancelling, or a gateway erroring, is not the
	 * same event as a courier being turned away at the door, and reporting it
	 * as one would poison the very signal the network exists to provide.
	 * Stores that run a dedicated "returned"/"refused" status map it in
	 * settings; that is what the mapping UI is for.
	 */
	public static function default_map() {
		return array(
			'completed' => 'DELIVERED',
			'cancelled' => 'CANCELLED',
			'refunded'  => 'RETURNED',
			'failed'    => 'NO_ANSWER',
		);
	}

	public function register_hooks() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( self::ACTION, array( $this, 'run_report' ), 10, 2 );
	}

	/**
	 * Strip WooCommerce's `wc-` status prefix, if present.
	 *
	 * NOT ltrim( $status, 'wc-' ). ltrim takes a character SET, so it eats any
	 * leading w, c or - and turns 'completed' into 'ompleted' and 'cancelled'
	 * into 'ancelled' — the map would then match nothing and the plugin would
	 * silently report no outcomes at all while appearing to work.
	 *
	 * The prefix is present in wc_get_order_statuses() keys and absent from
	 * both $order->get_status() and the $to argument of the status hook, so
	 * every caller has to normalise and only this function is trusted to.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function normalize_status( $status ) {
		$status = (string) $status;
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Queue a report when an order reaches a status we have a mapping for.
	 *
	 * Priority 20 so it runs after Numra_Order_Guard's hold logic at 5 — a
	 * flagged order being moved to on-hold is not an outcome, and on-hold is
	 * not in the map anyway.
	 *
	 * @param int      $order_id
	 * @param string   $from
	 * @param string   $to
	 * @param WC_Order $order
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ) {
		try {
			if ( ! Numra_Settings::is_outcome_reporting_enabled() ) {
				return;
			}

			$map     = Numra_Settings::get_status_map();
			$to_key  = self::normalize_status( $to );
			$outcome = isset( $map[ $to_key ] ) ? $map[ $to_key ] : '';

			if ( '' === $outcome || 'none' === $outcome ) {
				return;
			}

			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			// Report the number the API resolved at scoring time when we have
			// it, so the outcome attaches to the same identity as the lookup.
			// Fall back to the billing field for orders that predate the guard.
			$phone = (string) $order->get_meta( Numra_Order_Guard::META_PHONE );
			if ( '' === $phone ) {
				$phone = (string) $order->get_billing_phone();
			}
			if ( '' === trim( $phone ) ) {
				return;
			}

			// Skip a repeat of an outcome we already landed. The server would
			// no-op it anyway; this just avoids the pointless request.
			if ( (string) $order->get_meta( self::META_REPORTED ) === $outcome ) {
				return;
			}

			$this->enqueue( (int) $order->get_id(), $outcome );

		} catch ( Throwable $e ) {
			Numra_Logger::error( 'Outcome queue exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Report an order's history from the status it is ALREADY in.
	 *
	 * on_status_changed() only fires on a transition, so an order that reached
	 * its final state before Numra was installed never reports anything — the
	 * network learns nothing from trading that already happened. This is the
	 * same decision, taken from the order's current status instead of from a
	 * hook, so history can be replayed.
	 *
	 * Returns the outcome queued, or '' when this order has no history worth
	 * sending. A pending or processing order returns '' on purpose: nothing has
	 * happened to it yet, and inventing an outcome for an order still in flight
	 * would poison the very data this is meant to build.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	/**
	 * A merchant stating what happened, overriding whatever the status implied.
	 *
	 * This outranks the status map on purpose. "Completed" only means the shop
	 * marked it completed, and plenty of merchants close a refused parcel as
	 * completed to tidy their books — so the human answer is the better signal,
	 * and it is allowed to replace an outcome already sent.
	 *
	 * @param WC_Order $order
	 * @param string   $outcome One of Numra_API_Client::OUTCOME_TYPES.
	 * @return bool
	 */
	public function report_manual( $order, $outcome ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( ! in_array( $outcome, Numra_API_Client::OUTCOME_TYPES, true ) ) {
			return false;
		}

		$phone = (string) $order->get_meta( Numra_Order_Guard::META_PHONE );
		if ( '' === $phone ) {
			$phone = (string) $order->get_billing_phone();
		}
		if ( '' === trim( $phone ) ) {
			return false;
		}

		/* Clear the landed marker so enqueue() is not treated as a repeat of
		   an outcome the status map already sent. A correction must be able to
		   overwrite the thing it is correcting. */
		$order->delete_meta_data( self::META_REPORTED );
		$order->save();

		$this->enqueue( (int) $order->get_id(), $outcome );
		return true;
	}

	public function report_current_state( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		if ( ! Numra_Settings::is_outcome_reporting_enabled() ) {
			return '';
		}

		$map     = Numra_Settings::get_status_map();
		$key     = self::normalize_status( $order->get_status() );
		$outcome = isset( $map[ $key ] ) ? $map[ $key ] : '';

		if ( '' === $outcome || 'none' === $outcome ) {
			return ''; // Not a terminal state we have history for.
		}

		if ( (string) $order->get_meta( self::META_REPORTED ) === $outcome ) {
			return ''; // Already landed.
		}

		$phone = (string) $order->get_meta( Numra_Order_Guard::META_PHONE );
		if ( '' === $phone ) {
			$phone = (string) $order->get_billing_phone();
		}
		if ( '' === trim( $phone ) ) {
			return '';
		}

		$this->enqueue( (int) $order->get_id(), $outcome );
		return $outcome;
	}

	/**
	 * Put one report on the queue, or send it inline if Action Scheduler is
	 * somehow unavailable.
	 */
	private function enqueue( $order_id, $outcome, $attempt = 1 ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array( $order_id, $attempt ), self::GROUP );
			return;
		}
		// Action Scheduler is bundled with WooCommerce, so this is a belt-and-
		// braces path rather than an expected one — but sending nothing at all
		// would be worse than sending it on this request.
		$this->run_report( $order_id, $attempt );
	}

	/**
	 * Send one outcome. Invoked by Action Scheduler.
	 *
	 * @param int $order_id
	 * @param int $attempt
	 */
	public function run_report( $order_id, $attempt = 1 ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			// Re-read the mapping at send time rather than trusting a value
			// queued minutes ago: the merchant may have corrected the mapping,
			// or moved the order on again, since this job was scheduled.
			$map     = Numra_Settings::get_status_map();
			$status  = self::normalize_status( $order->get_status() );
			$outcome = isset( $map[ $status ] ) ? $map[ $status ] : '';

			if ( '' === $outcome || 'none' === $outcome ) {
				return;
			}
			if ( (string) $order->get_meta( self::META_REPORTED ) === $outcome ) {
				return;
			}

			$phone = (string) $order->get_meta( Numra_Order_Guard::META_PHONE );
			if ( '' === $phone ) {
				$phone = (string) $order->get_billing_phone();
			}
			if ( '' === trim( $phone ) ) {
				return;
			}

			$client = new Numra_API_Client();
			$result = $client->report_outcome(
				$phone,
				(string) $order->get_id(),
				$outcome,
				array(
					'order_total' => (float) $order->get_total(),
					'currency'    => $order->get_currency(),
					'region'      => $order->get_billing_state() ? $order->get_billing_state() : $order->get_billing_city(),
				)
			);

			if ( ! empty( $result['ok'] ) ) {
				$order->update_meta_data( self::META_REPORTED, $outcome );
				$order->save();
				Numra_Logger::info( sprintf( 'Reported %s for order #%s.', $outcome, $order->get_order_number() ) );
				return;
			}

			/* A 4xx is the server telling us this request is wrong — the
			   licence is invalid, the payload is rejected, the country is not
			   allowed. Retrying an argument we have already lost just burns
			   scheduler slots, so only transient failures (network, 5xx, and
			   429 rate limiting) are retried. */
			$status_code = (int) $result['status'];
			$transient   = ( 0 === $status_code ) || ( 429 === $status_code ) || ( $status_code >= 500 );

			if ( $transient && $attempt < self::MAX_ATTEMPTS ) {
				$delay = MINUTE_IN_SECONDS * pow( 4, $attempt ); // 4m, 16m, 64m
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + $delay, self::ACTION, array( $order_id, $attempt + 1 ), self::GROUP );
				}
				Numra_Logger::warning( sprintf(
					'Outcome %s for order #%s failed (HTTP %d) — retry %d of %d in %d min.',
					$outcome, $order->get_order_number(), $status_code, $attempt + 1, self::MAX_ATTEMPTS, $delay / MINUTE_IN_SECONDS
				) );
				return;
			}

			Numra_Logger::error( sprintf(
				'Outcome %s for order #%s abandoned after %d attempt(s): %s (HTTP %d)',
				$outcome, $order->get_order_number(), $attempt, (string) $result['error'], $status_code
			) );

		} catch ( Throwable $e ) {
			Numra_Logger::error( 'Outcome report exception: ' . $e->getMessage() );
		}
	}
}
