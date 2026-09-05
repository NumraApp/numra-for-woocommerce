<?php
/**
 * Numra for WooCommerce — Order Guard
 *
 * Scores an order's phone number the moment the order is created, records the
 * verdict on the order, and holds the risky ones so the merchant reviews them
 * before anything is dispatched.
 *
 * ── Why scoring happens AFTER the order is created, not during checkout ──
 * A cash-on-delivery loss happens at dispatch, not at checkout. Scoring after
 * placement therefore prevents the same loss while costing one credit per real
 * order instead of one per checkout attempt — a bot hammering checkout cannot
 * drain a merchant's quota — and it keeps the plugin free of frontend hooks and
 * assets, which ADR-004 and ADR-006 record as enforced standards.
 *
 * ── Why it fails OPEN ──
 * Numra being unreachable must never stop a store taking orders. Every failure
 * path here records why the check did not happen and lets the order through.
 * An unscored order is a merchant inconvenience; a checkout that refuses money
 * because a third party is down is a catastrophe.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Order_Guard {

	/* Order meta keys. Underscore-prefixed so WooCommerce treats them as
	   internal and does not surface them in the generic custom-fields box. */
	const META_SCORE        = '_numra_risk_score';
	const META_LEVEL        = '_numra_risk_level';
	const META_BLACKLISTED  = '_numra_blacklisted';
	const META_REASON       = '_numra_reason';
	const META_CARRIER      = '_numra_carrier';
	const META_PHONE        = '_numra_phone';
	const META_CHECKED_AT   = '_numra_checked_at';
	const META_STATUS       = '_numra_check_status';
	const META_FLAGGED      = '_numra_flagged';
	const META_HOLD_APPLIED = '_numra_hold_applied';
	/* The resolved verdict and whether the number has any history at all.
	   Without these the order screen cannot distinguish a neutral 50 that
	   means "never seen" from a real, earned, middling score. */
	const META_VERDICT      = '_numra_verdict';
	const META_RATED        = '_numra_rated';
	const META_STYLE        = '_numra_style';

	/** Guards against re-entry when our own hold triggers the status hook. */
	private static $applying_hold = false;

	public function register_hooks() {
		// Classic checkout. Fires after the order row exists and before the
		// gateway runs, so the verdict is on the order from its first moment.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'score_order' ), 10, 1 );

		// Store API / Blocks checkout. A growing share of stores use it and it
		// does NOT fire the classic hook above; without this the plugin would
		// silently score nothing on a modern theme.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'score_order' ), 10, 1 );

		/* ── Applying the hold ──────────────────────────────────────────────
		   Setting the status during score_order() would be undone: WooCommerce
		   runs woocommerce_checkout_order_processed BEFORE the gateway's
		   process_payment(), and WC_Gateway_COD then calls update_status() and
		   overwrites whatever we set. Two mechanisms cover it instead.

		   1. The COD status filter — the precise one. It changes the status
		      the gateway is about to set, so the order never passes through
		      "processing" and the customer never receives a processing email
		      for an order we are holding.
		   2. A one-shot on the first status change — the gateway-agnostic
		      fallback for stores taking risky orders through something other
		      than COD. It flaps the status once, which is the cost of not
		      being able to filter an arbitrary gateway. */
		add_filter( 'woocommerce_cod_process_payment_order_status', array( $this, 'filter_cod_status' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_hold_on_status_change' ), 5, 4 );
	}

	/**
	 * Statuses where the merchant still has a shipping decision to make.
	 *
	 * `pending` is awaiting payment, `processing` is paid and awaiting
	 * dispatch, `on-hold` is under review — in all three the parcel has not
	 * gone. Everything else (completed, cancelled, refunded, failed) is
	 * settled, and its value to Numra is its outcome, not a fresh score.
	 */
	const LIVE_STATUSES = array( 'pending', 'processing', 'on-hold' );

	// ── Scoring ───────────────────────────────────────────────────────────────

	/**
	 * Score one order. Safe to call twice — the second call is a no-op.
	 *
	 * @param int|WC_Order $order_id
	 */
	/**
	 * Score an order on demand, ignoring the "already checked" guard.
	 *
	 * score_order() refuses to run twice — that is deliberate, so one order
	 * costs one credit no matter how many hooks fire. A merchant pressing
	 * "Check now" is a different intent: they are asking for a fresh answer and
	 * accepting the credit. Clearing the status meta first is what turns the
	 * one-shot guard back on for a single deliberate run.
	 *
	 * @param WC_Order $order
	 * @return bool Whether a lookup was actually attempted.
	 */
	public static function check_now( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		foreach ( array( self::META_STATUS, self::META_SCORE, self::META_LEVEL,
			self::META_BLACKLISTED, self::META_FLAGGED, self::META_REASON, self::META_CARRIER ) as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->save();

		$guard = new self();
		$guard->score_order( $order, true );

		return true;
	}

	/**
	 * @param int|WC_Order $order_id
	 * @param bool         $manual   True when a human pressed Reveal / Check now.
	 */
	public function score_order( $order_id, $manual = false ) {
		try {
			$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			/* is_guard_enabled() governs AUTOMATIC scoring only — whether a
			   credit is spent on every incoming order without anyone asking.
			   It defaults off, because a check is the merchant's money.

			   It must not gate a manual reveal. A merchant who clicks "Reveal"
			   has just asked for this one check and accepted its cost; refusing
			   them because the automatic setting is off would leave the button
			   visible, clickable, and silently dead — which is exactly what
			   shipped in 1.12.0 when the default flipped. */
			if ( ! $manual && ! Numra_Settings::is_guard_enabled() ) {
				return;
			}

			// One credit per order, not per hook. The Blocks and classic hooks
			// can both be present in a stack, and a resumed checkout re-fires.
			if ( '' !== (string) $order->get_meta( self::META_STATUS ) ) {
				return;
			}

			if ( ! Numra_Settings::is_connected() ) {
				$this->record_skip( $order, 'not_connected' );
				return;
			}

			/* The platform's own verdict, learned by the 15-minute heartbeat
			   rather than by failing this request. When the key has been
			   revoked or the subscription has lapsed, every lookup would be
			   refused anyway — this just stops us spending the round trip and
			   writing a failure into the order for something the merchant has
			   already been alerted about.

			   Note what does NOT happen here: the order is not blocked, held,
			   or altered in any way. A billing dispute between a merchant and
			   Numra is not a reason to stop that merchant selling. The order
			   goes through unscored and the skip reason records why. */
			if ( ! Numra_Heartbeat::protection_enabled() ) {
				$this->record_skip( $order, 'protection_off:' . Numra_Heartbeat::state() );
				return;
			}

			/* Payment method no longer decides whether an order is LOGGED.
			
			   It used to: a non-COD order returned here unscored, so the
			   network never learned that number existed. That is backwards —
			   the value of the network is the breadth of what it has seen, and
			   a prepaid customer today is a cash-on-delivery customer next
			   week. Every order with a phone number is now looked up.

			   The COD setting still governs ENFORCEMENT — see maybe_hold().
			   A prepaid order carries no delivery risk, so holding one would
			   punish a customer who has already paid. Logging is silent and
			   universal; holding stays narrow and deliberate. */

			/* A risk check is a decision aid, so it is only worth buying while
			   a decision is still open. An order that is already completed,
			   cancelled or refunded has had its outcome — scoring it answers
			   "should I ship this?" about a parcel that shipped last month,
			   and spends a credit to do it. Those orders are valuable to the
			   network as HISTORY, which Numra_Outcome_Reporter sends without
			   a lookup. */
			if ( ! in_array( $order->get_status(), self::LIVE_STATUSES, true ) ) {
				$this->record_skip( $order, 'not_live:' . $order->get_status() );
				return;
			}

			$phone = trim( (string) $order->get_billing_phone() );
			if ( '' === $phone ) {
				$this->record_skip( $order, 'no_phone' );
				return;
			}

			$client = new Numra_API_Client();
			$result = $client->lookup_phone( $phone, array(
				'payment_method' => $order->get_payment_method(),
				'order_total'    => (float) $order->get_total(),
				'currency'       => $order->get_currency(),
				'region'         => $order->get_billing_state() ? $order->get_billing_state() : $order->get_billing_city(),
			) );

			if ( empty( $result['ok'] ) ) {
				// Fail open, but leave a trail the merchant and support can read.
				$this->record_skip( $order, 'api_' . strtolower( (string) $result['error'] ) );
				Numra_Logger::warning( sprintf(
					'Order #%s not scored: %s (HTTP %d)',
					$order->get_order_number(),
					(string) $result['error'],
					(int) $result['status']
				) );
				return;
			}

			$this->record_result( $order, $result, $manual );

		} catch ( Throwable $e ) {
			// Nothing in this class may ever break checkout. Throwable catches
			// Error as well as Exception, so a fatal in a dependency degrades
			// to an unscored order instead of a white screen at payment.
			Numra_Logger::error( 'Order Guard exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Persist a successful lookup and decide whether the order is flagged.
	 */
	private function record_result( WC_Order $order, array $result, $manual = false ) {
		$score     = null === $result['score'] ? null : (int) $result['score'];
		$threshold = Numra_Settings::get_risk_threshold();
		$rated     = ! empty( $result['rated'] );

		/* ── An unrated number is not a risky number ──────────────────────
		   The API returns a neutral 50 for a phone it has never seen, so that
		   a first-time consumer is not flattered with a clean 0. That is right
		   for the score and wrong for a threshold: comparing 50 against the
		   merchant's setting means a store that lowers its threshold to 50 or
		   below holds EVERY first-time customer — on a cash-on-delivery store
		   in a market where most buyers are first-time, that is the whole
		   order book. The merchant would conclude the product is broken, and
		   they would be right.

		   So thresholding requires a rating. Everything else still applies:
		   a blacklist flags regardless, because the network has a confirmed
		   reason for that number and no threshold should talk a store out of
		   it. */
		/* Flag on the BAND the server resolved, not on a number this plugin
		   re-thresholds. risk_level is decided once in phone_verdict and is
		   the same word the control panel and the order screen show; comparing
		   scores here was a second place for that decision to live, and the
		   two drifted the moment either scale moved. */
		/* Three independent reasons to hold, in descending authority.
		   ─────────────────────────────────────────────────────────────────
		   A blacklist is the network's confirmed answer and needs no setting.
		   A band is the merchant's tolerance for score. A customer type is the
		   merchant's policy about behaviour — "I do not ship to people who
		   never answer", which no band can express, because a never-answers
		   buyer and a refuses-at-the-door buyer can score identically and want
		   opposite handling. */
		/* `style_code`, not `style`.
		   ─────────────────────────────────────────────────────────────────
		   This passed `$result['style']`, which the API client sets to the
		   HUMAN LABEL ("Never answers"). style_is_flagged() sanitize_key()s
		   its argument and compares it against the stored style CODES
		   ("never_answers"). sanitize_key('Never answers') is 'neveranswers',
		   which can never equal a code — so the customer-type hold has never
		   once fired, on any store, since the feature shipped.

		   Nothing surfaced it because the failure is silent and looks like
		   "that customer type just didn't come up". `style_code` was already
		   being returned right beside the label and was simply unused. */
		$style_flag = Numra_Settings::style_is_flagged(
			isset( $result['style_code'] ) ? $result['style_code'] : ''
		);

		$flagged = ! empty( $result['blacklisted'] )
			|| ( $rated && Numra_Settings::level_meets_threshold( $result['level'] ) )
			|| $style_flag;

		$order->update_meta_data( self::META_SCORE,       null === $score ? '' : $score );
		$order->update_meta_data( self::META_LEVEL,       $result['level'] );
		/* Recorded so the order screen can say "no history yet" rather than
		   render a 50 the merchant will read as a judgement. */
		$order->update_meta_data( self::META_VERDICT,     isset( $result['verdict'] ) ? $result['verdict'] : '' );
		$order->update_meta_data( self::META_RATED,       $rated ? 'yes' : 'no' );
		$order->update_meta_data( self::META_STYLE,       isset( $result['style'] ) ? $result['style'] : '' );
		$order->update_meta_data( self::META_BLACKLISTED, ! empty( $result['blacklisted'] ) ? 'yes' : 'no' );
		$order->update_meta_data( self::META_REASON,      $result['reason'] );
		$order->update_meta_data( self::META_CARRIER,     $result['carrier_label'] );
		// Store the E.164 the server resolved, not the raw billing field, so
		// the outcome we report later is keyed to the identity it scored.
		$order->update_meta_data( self::META_PHONE,       $result['phone'] );
		$order->update_meta_data( self::META_CHECKED_AT,  gmdate( 'c' ) );
		$order->update_meta_data( self::META_STATUS,      'ok' );
		$order->update_meta_data( self::META_FLAGGED,     $flagged ? 'yes' : 'no' );
		$order->save();

		/* Quiet by default. Logging now runs on every order, and a timeline
		   note on each one would bury the notes a merchant actually writes
		   under a wall of "checked — score 12". A clean result is recorded in
		   meta, which is what the Risk column and the order panel read; only a
		   result the merchant needs to act on earns a note. */
		if ( $flagged ) {
			$order->add_order_note( $this->build_note( $result, $score, $flagged, $threshold, $style_flag ) );
		}

		if ( $flagged ) {
			Numra_Logger::info( sprintf( 'Order #%s flagged by Numra (score %s).', $order->get_order_number(), null === $score ? 'n/a' : $score ) );
		}

		/* ── Flagging happens at reveal, not at checkout ───────────────────
		   With automatic scoring off, an order is never looked up on its way
		   in — so there is no verdict at checkout, and the two hold paths
		   (filter_cod_status, maybe_hold_on_status_change) both fire before
		   anything is known and then never fire again. The band the merchant
		   set would decide nothing.

		   A manual reveal is the moment the verdict exists. Applying the hold
		   here is what makes "Flag from HIGH" mean something on a store that
		   pays per check: nothing is spent until the merchant asks, and what
		   they asked for is acted on immediately.

		   should_hold() still owns the conditions — auto-hold on, COD rule,
		   flagged, not already held — so a merchant who wants the verdict
		   without the status change simply turns auto-hold off. */
		if ( $manual && ! self::$applying_hold && $this->should_hold( $order ) ) {
			self::$applying_hold = true;
			$order->update_meta_data( self::META_HOLD_APPLIED, 'yes' );
			$order->save();
			$order->update_status( 'on-hold', __( 'Numra: order placed on hold for review.', 'numra-for-woocommerce' ) );
			self::$applying_hold = false;
		}
	}

	/**
	 * The order note. This is what the merchant actually reads, so it states
	 * the verdict, the number it applies to, and the reason — not just a score.
	 */
	private function build_note( array $result, $score, $flagged, $threshold, $style_flag = false ) {
		$parts = array();

		if ( ! empty( $result['blacklisted'] ) ) {
			$parts[] = __( 'Numra: this number is blacklisted on the network.', 'numra-for-woocommerce' );
			if ( '' !== $result['reason'] ) {
				/* translators: %s: reason the number was blacklisted. */
				$parts[] = sprintf( __( 'Reason: %s', 'numra-for-woocommerce' ), $result['reason'] );
			}
		} elseif ( $style_flag ) {
			/* Named before the band, because it is the merchant's own rule and
			   the band may not have been crossed at all. A note saying "at or
			   past the band you set" on an order the band did not catch would
			   send them to the wrong setting to change it. */
			/* translators: %s: customer style label, e.g. Never answers. */
			$parts[] = sprintf(
				__( 'Numra: flagged — this is a customer type you chose to hold (%s).', 'numra-for-woocommerce' ),
				$result['style']
			);
		} elseif ( $flagged ) {
			/* translators: %s: the resolved risk band, e.g. HIGH. */
			$parts[] = sprintf( __( 'Numra: flagged — this number is %s risk, at or past the band you set.', 'numra-for-woocommerce' ), $result['level'] );
		} elseif ( empty( $result['rated'] ) ) {
			/* Say the true thing. "Score 50" invites the merchant to read a
			   judgement into a number that means we have never seen this
			   person — and 50 sits close enough to a plausible threshold that
			   they may act on it. */
			$parts[] = __( 'Numra: first time we have seen this number — no history yet, so no rating.', 'numra-for-woocommerce' );
		} else {
			/* translators: %s: risk score. */
			$parts[] = sprintf( __( 'Numra: checked — score %s.', 'numra-for-woocommerce' ), null === $score ? '—' : $score );
		}

		// An UNRATED level is the absence of a level; printing it as one reads
		// like a verdict the network has not made.
		if ( '' !== $result['level'] && 'UNRATED' !== $result['level'] ) {
			/* translators: %s: risk level, e.g. LOW / HIGH. */
			$parts[] = sprintf( __( 'Risk level: %s.', 'numra-for-woocommerce' ), $result['level'] );
		}
		if ( ! empty( $result['style'] ) ) {
			/* translators: %s: customer style label, e.g. Reliable. */
			$parts[] = sprintf( __( 'Customer style: %s.', 'numra-for-woocommerce' ), $result['style'] );
		}
		if ( '' !== $result['carrier_label'] ) {
			/* translators: %s: mobile carrier name. */
			$parts[] = sprintf( __( 'Carrier: %s.', 'numra-for-woocommerce' ), $result['carrier_label'] );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Record that no check ran, and why. Written with the same META_STATUS key
	 * the success path uses so the "already handled" guard covers both.
	 */
	private function record_skip( WC_Order $order, $reason ) {
		$order->update_meta_data( self::META_STATUS,     'skipped:' . sanitize_key( $reason ) );
		$order->update_meta_data( self::META_CHECKED_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::META_FLAGGED,    'no' );
		$order->save();
	}

	// ── Holding ───────────────────────────────────────────────────────────────

	/**
	 * Change the status COD is about to set, for a flagged order.
	 *
	 * @param string   $status Status the gateway chose.
	 * @param WC_Order $order
	 * @return string
	 */
	public function filter_cod_status( $status, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			return $status;
		}
		if ( ! $this->should_hold( $order ) ) {
			return $status;
		}

		$order->update_meta_data( self::META_HOLD_APPLIED, 'yes' );
		$order->save();
		$order->add_order_note( __( 'Numra: order placed on hold for review.', 'numra-for-woocommerce' ) );

		return 'on-hold';
	}

	/**
	 * Gateway-agnostic fallback: hold a flagged order the first time it lands
	 * in a status that means "ready to fulfil".
	 *
	 * @param int      $order_id
	 * @param string   $from
	 * @param string   $to
	 * @param WC_Order $order
	 */
	public function maybe_hold_on_status_change( $order_id, $from, $to, $order = null ) {
		if ( self::$applying_hold ) {
			return; // Our own update_status() re-entering this hook.
		}
		if ( ! in_array( $to, array( 'processing', 'pending' ), true ) ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $this->should_hold( $order ) ) {
			return;
		}

		self::$applying_hold = true;
		$order->update_meta_data( self::META_HOLD_APPLIED, 'yes' );
		$order->save();
		$order->update_status( 'on-hold', __( 'Numra: order placed on hold for review.', 'numra-for-woocommerce' ) );
		self::$applying_hold = false;
	}

	/**
	 * Whether this order should be held right now.
	 *
	 * META_HOLD_APPLIED is checked so the hold happens exactly once. A merchant
	 * who reviews a flagged order and moves it back to processing has made a
	 * decision; the plugin must not overrule it on every subsequent transition.
	 */
	private function should_hold( WC_Order $order ) {
		if ( ! Numra_Settings::is_autohold_enabled() ) {
			return false;
		}

		/* The COD rule moved here from score_order(). It used to decide
		   whether an order was looked up at all, which meant prepaid orders
		   were invisible to the network. Now everything is looked up and only
		   ENFORCEMENT is narrowed: holding a prepaid order punishes a customer
		   who has already paid, and recovers nothing the merchant has lost. */
		if ( Numra_Settings::is_cod_only() && ! $this->is_cod( $order ) ) {
			return false;
		}
		if ( 'yes' !== (string) $order->get_meta( self::META_FLAGGED ) ) {
			return false;
		}
		if ( 'yes' === (string) $order->get_meta( self::META_HOLD_APPLIED ) ) {
			return false;
		}
		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Whether this order is cash on delivery.
	 *
	 * Matches the core `cod` gateway id and the id patterns the common
	 * Moroccan COD gateways use, then lets a store correct the guess with a
	 * filter rather than forcing it to turn COD-only mode off entirely.
	 */
	private function is_cod( WC_Order $order ) {
		$method = (string) $order->get_payment_method();
		$is_cod = ( 'cod' === $method ) || ( false !== stripos( $method, 'cod' ) ) || ( false !== stripos( $method, 'cash' ) );

		return (bool) apply_filters( 'numra_is_cod_order', $is_cod, $order, $method );
	}
}
