<?php
/**
 * Numra for WooCommerce — Order admin UI
 *
 * The merchant's side of the product: a Risk column on the orders list so a
 * flagged order is visible without opening it, and a panel on the order screen
 * explaining the verdict.
 *
 * ── HPOS ──
 * WooCommerce has two order screens: the legacy post-table (shop_order) and
 * the HPOS orders table. They use DIFFERENT hooks for the same job. Wiring
 * only one means the column silently vanishes for half of all stores, so both
 * are registered.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Order_UI {

	const COLUMN_ID = 'numra_risk';

	public function register_hooks() {
		// Orders list — HPOS table.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );

		// Orders list — legacy post table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );

		// Order detail panel — registered for both screen ids.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'admin_head', array( $this, 'print_styles' ) );

		// On-demand check, from the order panel or the orders list.
		add_action( 'admin_post_numra_check_order', array( $this, 'handle_check_order' ) );
		// Merchant confirming what actually happened to a finished order.
		add_action( 'admin_post_numra_report_outcome', array( $this, 'handle_report_outcome' ) );
	}

	/**
	 * The outcomes a merchant is asked to confirm on a finished order.
	 *
	 * Deliberately short. The API accepts seven, but a merchant standing at a
	 * screen will not read seven — these four cover what actually happens to a
	 * cash-on-delivery parcel, and the status map handles the rest silently.
	 */
	private function outcome_choices() {
		return array(
			'DELIVERED'       => __( 'Delivered and paid', 'numra-for-woocommerce' ),
			'REFUSED_COD'     => __( 'Refused on delivery', 'numra-for-woocommerce' ),
			'NO_ANSWER'       => __( 'Never answered', 'numra-for-woocommerce' ),
			'FRAUD_CONFIRMED' => __( 'Fraud', 'numra-for-woocommerce' ),
		);
	}

	public function handle_report_outcome() {
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'numra-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'numra_report_outcome_' . $order_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$outcome = isset( $_GET['outcome'] ) ? sanitize_text_field( wp_unslash( $_GET['outcome'] ) ) : '';
		$order   = wc_get_order( $order_id );

		if ( $order instanceof WC_Order && array_key_exists( $outcome, $this->outcome_choices() ) ) {
			$reporter = new Numra_Outcome_Reporter();
			$reporter->report_manual( $order, $outcome );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private function outcome_url( $order_id, $outcome ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=numra_report_outcome&order=' . (int) $order_id . '&outcome=' . rawurlencode( $outcome ) ),
			'numra_report_outcome_' . (int) $order_id
		);
	}

	/**
	 * Ask the merchant what actually happened.
	 *
	 * The status map already infers an outcome from the WooCommerce status,
	 * but "completed" only means the shop marked it completed — plenty of
	 * merchants mark a refused parcel completed to close their books. The
	 * network is only as honest as its worst signal, so on a finished order we
	 * ask the one person who knows.
	 *
	 * Shown once and dismissible by answering: after an outcome is recorded the
	 * block is replaced by what was sent.
	 */
	private function render_outcome_prompt( WC_Order $order ) {
		if ( ! Numra_Settings::is_connected() ) {
			return;
		}
		if ( in_array( $order->get_status(), Numra_Order_Guard::LIVE_STATUSES, true ) ) {
			return; // Still in flight — nothing has happened yet.
		}

		$sent = (string) $order->get_meta( Numra_Outcome_Reporter::META_REPORTED );

		echo '<div class="numra-mb-outcome">';
		echo '<p class="numra-mb-outcome-q">' . esc_html__( 'How did this order end?', 'numra-for-woocommerce' ) . '</p>';

		if ( '' !== $sent ) {
			$choices = $this->outcome_choices();
			$label   = isset( $choices[ $sent ] ) ? $choices[ $sent ] : $sent;
			echo '<p class="numra-mb-fine">' . esc_html( sprintf(
				/* translators: %s: the outcome already reported, e.g. "Delivered and paid". */
				__( 'Recorded as: %s. Choose again to correct it.', 'numra-for-woocommerce' ),
				$label
			) ) . '</p>';
		} else {
			echo '<p class="numra-mb-fine">' . esc_html__( 'Telling Numra is what builds this customer\'s history — and it is free.', 'numra-for-woocommerce' ) . '</p>';
		}

		echo '<div class="numra-mb-outcomes">';
		foreach ( $this->outcome_choices() as $value => $label ) {
			printf(
				'<a class="numra-outcome-btn%1$s" href="%2$s">%3$s</a>',
				$sent === $value ? ' is-current' : '',
				esc_url( $this->outcome_url( $order->get_id(), $value ) ),
				esc_html( $label )
			);
		}
		echo '</div>';
		echo '</div>';
	}

	// ── On-demand check ───────────────────────────────────────────────────────

	/**
	 * "Check now" from the order screen or the orders list.
	 *
	 * A POST-less admin-post GET is acceptable here because the action is
	 * nonce-protected, capability-gated, and idempotent in effect (it costs one
	 * credit and overwrites the previous verdict) — it creates nothing and
	 * deletes nothing the merchant cannot regenerate by pressing it again.
	 */
	public function handle_check_order() {
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'numra-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'numra_check_order_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			Numra_Order_Guard::check_now( $order );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private function check_url( $order_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=numra_check_order&order=' . (int) $order_id ),
			'numra_check_order_' . (int) $order_id
		);
	}

	/**
	 * Whether a check can be run at all right now.
	 *
	 * Offering a button that is guaranteed to fail is worse than offering
	 * nothing: the merchant spends a click and gets an error they cannot act
	 * on from here. The heartbeat already knows whether the platform will
	 * answer, so the button only appears when it will.
	 */
	private function can_check() {
		if ( ! Numra_Settings::is_connected() ) {
			return false;
		}
		return ! class_exists( 'Numra_Heartbeat' ) || Numra_Heartbeat::protection_enabled();
	}

	// ── Orders list column ────────────────────────────────────────────────────

	/**
	 * Insert the Risk column just before the order total, where the merchant's
	 * eye already is when deciding whether to dispatch.
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'order_total' === $key ) {
				$out[ self::COLUMN_ID ] = __( 'Risk', 'numra-for-woocommerce' );
			}
			$out[ $key ] = $label;
		}

		// Total column absent (a store has customised the list) — append.
		if ( ! isset( $out[ self::COLUMN_ID ] ) ) {
			$out[ self::COLUMN_ID ] = __( 'Risk', 'numra-for-woocommerce' );
		}

		return $out;
	}

	/** HPOS passes the order object. */
	public function render_column_hpos( $column, $order ) {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}
		echo wp_kses_post( $this->column_html( $order ) );
	}

	/** The legacy table passes a post id. */
	public function render_column_legacy( $column, $post_id ) {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}
		echo wp_kses_post( $this->column_html( wc_get_order( $post_id ) ) );
	}

	/**
	 * One compact cell: the score, coloured only when it is actually a
	 * problem. A column that colours every row teaches the merchant to stop
	 * reading it.
	 */
	private function column_html( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '<span class="numra-risk numra-risk--none">&mdash;</span>';
		}

		$status = (string) $order->get_meta( Numra_Order_Guard::META_STATUS );

		/* An unchecked order is the one row where the merchant can still act,
		   so the cell offers the action instead of an em dash they cannot do
		   anything with. This is the whole point of the column: decide whether
		   to dispatch, from the list, without opening anything. */
		if ( '' === $status || 0 === strpos( $status, 'skipped:' ) ) {
			$title = '' === $status
				? __( 'Not checked', 'numra-for-woocommerce' )
				: $this->skip_label( substr( $status, 8 ) );

			/* Present, not absent.
			
			   An em dash says "Numra knows nothing about this customer", which
			   is both discouraging and untrue — the network has a record, it
			   simply has not been paid for on this order. The blurred figure
			   says the opposite: the data exists, one click away. */
			if ( $this->can_check() && '' !== trim( (string) $order->get_billing_phone() ) ) {
				return '<a class="numra-risk numra-risk--locked" href="' . esc_url( $this->check_url( $order->get_id() ) ) . '"'
					. ' title="' . esc_attr__( 'Reveal this customer\'s score — uses one check', 'numra-for-woocommerce' ) . '">'
					. '<span class="numra-blur" aria-hidden="true">87</span>'
					. '<span class="screen-reader-text">' . esc_html__( 'Reveal score', 'numra-for-woocommerce' ) . '</span>'
					. '</a>';
			}
			return '<span class="numra-risk numra-risk--none" title="' . esc_attr( $title ) . '">&mdash;</span>';
		}

		$blacklisted = 'yes' === (string) $order->get_meta( Numra_Order_Guard::META_BLACKLISTED );
		$flagged     = 'yes' === (string) $order->get_meta( Numra_Order_Guard::META_FLAGGED );
		$score       = (string) $order->get_meta( Numra_Order_Guard::META_SCORE );

		if ( $blacklisted ) {
			return '<span class="numra-risk numra-risk--blocked" title="' . esc_attr__( 'Blacklisted on the Numra network', 'numra-for-woocommerce' ) . '">'
				. esc_html__( 'Blacklisted', 'numra-for-woocommerce' ) . '</span>';
		}

		/* A number with no history must not render as a score. The API returns
		   a neutral 50 for it, and a bare "50" in a risk column reads as a
		   middling judgement rather than "we have never seen this person" —
		   which is a different fact, and the one the merchant needs when
		   deciding whether to phone before dispatch. */
		if ( 'no' === (string) $order->get_meta( Numra_Order_Guard::META_RATED ) ) {
			return '<span class="numra-risk numra-risk--new" title="'
				. esc_attr__( 'No history on the Numra network yet — first order from this number.', 'numra-for-woocommerce' ) . '">'
				. esc_html__( 'New', 'numra-for-woocommerce' ) . '</span>';
		}

		$class = $flagged ? 'numra-risk--high' : 'numra-risk--ok';
		return '<span class="numra-risk ' . esc_attr( $class ) . '">' . esc_html( '' === $score ? '—' : $score ) . '</span>';
	}

	// ── Order detail panel ────────────────────────────────────────────────────

	public function add_meta_box() {
		// The order screen id differs under HPOS; register against whichever
		// one this store is using rather than guessing.
		$screen = class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& function_exists( 'wc_get_container' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

		add_meta_box(
			'numra_order_risk',
			__( 'Numra risk check', 'numra-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$status = (string) $order->get_meta( Numra_Order_Guard::META_STATUS );

		/* Unchecked and skipped both used to dead-end on a sentence. The panel
		   now always ends in something the merchant can do — check it, or a
		   plain reason why they cannot. */
		if ( '' === $status || 0 === strpos( $status, 'skipped:' ) ) {
			echo '<p class="numra-mb-muted">' . esc_html(
				'' === $status
					? __( 'This order has not been checked yet.', 'numra-for-woocommerce' )
					: $this->skip_label( substr( $status, 8 ) )
			) . '</p>';

			$phone = trim( (string) $order->get_billing_phone() );
			if ( '' === $phone ) {
				return; // Nothing to check — the skip label already said so.
			}

			if ( $this->can_check() ) {
				/* The score sits behind the blur, not behind an absence. The
				   merchant is buying a reveal, not commissioning a search. */
				echo '<div class="numra-mb-locked" aria-hidden="true">'
					. '<span class="numra-blur numra-blur-lg">87</span>'
					. '<span class="numra-blur numra-blur-sm">' . esc_html__( 'High risk', 'numra-for-woocommerce' ) . '</span>'
					. '</div>';
				echo '<p class="numra-mb-actions"><a class="button button-primary" href="'
					. esc_url( $this->check_url( $order->get_id() ) ) . '">'
					. esc_html__( 'Reveal score', 'numra-for-woocommerce' ) . '</a></p>';
				echo '<p class="numra-mb-fine">' . esc_html__( 'Uses one check from your plan. Reporting the outcome afterwards is always free.', 'numra-for-woocommerce' ) . '</p>';
			} else {
				echo '<p class="numra-mb-fine">' . esc_html__( 'Connect this store to Numra to check orders.', 'numra-for-woocommerce' ) . '</p>';
				echo '<p class="numra-mb-actions"><a class="button" href="'
					. esc_url( add_query_arg( array( 'page' => 'numra' ), admin_url( 'admin.php' ) ) ) . '">'
					. esc_html__( 'Open Numra', 'numra-for-woocommerce' ) . '</a></p>';
			}

			/* Even an unchecked order has history worth recording once it is
			   finished — arguably more, since we know nothing else about this
			   customer. */
			$this->render_outcome_prompt( $order );
			return;
		}

		$score       = (string) $order->get_meta( Numra_Order_Guard::META_SCORE );
		$level       = (string) $order->get_meta( Numra_Order_Guard::META_LEVEL );
		$blacklisted = 'yes' === (string) $order->get_meta( Numra_Order_Guard::META_BLACKLISTED );
		$flagged     = 'yes' === (string) $order->get_meta( Numra_Order_Guard::META_FLAGGED );
		$reason      = (string) $order->get_meta( Numra_Order_Guard::META_REASON );
		$carrier     = (string) $order->get_meta( Numra_Order_Guard::META_CARRIER );
		$held        = 'yes' === (string) $order->get_meta( Numra_Order_Guard::META_HOLD_APPLIED );
		$reported    = (string) $order->get_meta( Numra_Outcome_Reporter::META_REPORTED );

		$rated = 'no' !== (string) $order->get_meta( Numra_Order_Guard::META_RATED );
		$style = (string) $order->get_meta( Numra_Order_Guard::META_STYLE );

		/* "Cleared" is a claim. For a number the network has never seen we have
		   not cleared anything — we have simply never heard of them, which for
		   a cash-on-delivery merchant is a reason to phone before dispatch,
		   not a reason to relax. Saying "Cleared" over a neutral 50 is the
		   single most misleading thing this panel could do. */
		$verdict_class = $blacklisted
			? 'numra-risk--blocked'
			: ( $flagged ? 'numra-risk--high' : ( $rated ? 'numra-risk--ok' : 'numra-risk--new' ) );

		echo '<p class="numra-mb-score"><span class="numra-risk ' . esc_attr( $verdict_class ) . '">'
			. esc_html( $rated ? ( '' === $score ? '—' : $score ) : __( 'New', 'numra-for-woocommerce' ) ) . '</span> ';

		if ( $blacklisted ) {
			echo '<strong>' . esc_html__( 'Blacklisted', 'numra-for-woocommerce' ) . '</strong>';
		} elseif ( $flagged ) {
			echo '<strong>' . esc_html__( 'High risk', 'numra-for-woocommerce' ) . '</strong>';
		} elseif ( ! $rated ) {
			echo '<strong>' . esc_html__( 'No history yet', 'numra-for-woocommerce' ) . '</strong>';
		} else {
			echo '<strong>' . esc_html__( 'Cleared', 'numra-for-woocommerce' ) . '</strong>';
		}
		echo '</p>';

		if ( ! $rated && ! $blacklisted ) {
			echo '<p class="numra-mb-reason">' . esc_html__(
				'First order we have seen from this number. It is now on the network, so the next merchant to check it will see what you report back.',
				'numra-for-woocommerce'
			) . '</p>';
		}

		if ( '' !== $reason ) {
			echo '<p class="numra-mb-reason">' . esc_html( $reason ) . '</p>';
		}

		echo '<ul class="numra-mb-list">';
		// UNRATED is the absence of a level, not a level. Printing it as a row
		// makes the panel look like it reached a verdict it did not reach.
		if ( '' !== $level && 'UNRATED' !== $level ) {
			echo '<li><span>' . esc_html__( 'Risk level', 'numra-for-woocommerce' ) . '</span><span>' . esc_html( $level ) . '</span></li>';
		}
		if ( '' !== $style ) {
			echo '<li><span>' . esc_html__( 'Customer style', 'numra-for-woocommerce' ) . '</span><span>' . esc_html( $style ) . '</span></li>';
		}
		if ( '' !== $carrier ) {
			echo '<li><span>' . esc_html__( 'Carrier', 'numra-for-woocommerce' ) . '</span><span>' . esc_html( $carrier ) . '</span></li>';
		}
		if ( $held ) {
			echo '<li><span>' . esc_html__( 'Action', 'numra-for-woocommerce' ) . '</span><span>' . esc_html__( 'Held for review', 'numra-for-woocommerce' ) . '</span></li>';
		}
		if ( '' !== $reported ) {
			echo '<li><span>' . esc_html__( 'Outcome sent', 'numra-for-woocommerce' ) . '</span><span>' . esc_html( $reported ) . '</span></li>';
		}
		echo '</ul>';

		$this->render_outcome_prompt( $order );

		/* A verdict can go stale: the network learns from every other merchant
		   that reports an outcome, so a number cleared last week may be
		   blacklisted today. Re-checking is the merchant's call because it
		   costs a credit, which is why it is a quiet secondary action and the
		   cost is stated next to it. */
		if ( $this->can_check() ) {
			echo '<p class="numra-mb-actions"><a class="button" href="'
				. esc_url( $this->check_url( $order->get_id() ) ) . '">'
				. esc_html__( 'Check again', 'numra-for-woocommerce' ) . '</a></p>';
			echo '<p class="numra-mb-fine">' . esc_html__( 'Scores change as the network learns. Uses one check.', 'numra-for-woocommerce' ) . '</p>';
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Turn a machine skip reason into something a merchant can act on.
	 * "Not checked" with no explanation is the kind of thing that generates a
	 * support ticket; naming the cause usually prevents one.
	 */
	private function skip_label( $reason ) {
		switch ( $reason ) {
			case 'not_connected':
				return __( 'Not checked — the store is not connected to Numra.', 'numra-for-woocommerce' );
			case 'not_cod':
				return __( 'Not checked — not a cash-on-delivery order.', 'numra-for-woocommerce' );
			case 'no_phone':
				return __( 'Not checked — the order has no billing phone number.', 'numra-for-woocommerce' );
			default:
				if ( 0 === strpos( $reason, 'api_' ) ) {
					return __( 'Not checked — Numra could not be reached. The order was allowed through.', 'numra-for-woocommerce' );
				}
				return __( 'Not checked.', 'numra-for-woocommerce' );
		}
	}

	/**
	 * Styles for the column and panel.
	 *
	 * Printed inline on the order screens only, rather than added to
	 * assets/admin.css: that file is enqueued exclusively on toplevel_page_numra
	 * (ADR-006 row 9), and loading it on the orders list would break that
	 * standard for about twenty lines of CSS.
	 */
	public function print_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$id = (string) $screen->id;
		if ( false === strpos( $id, 'shop_order' ) && false === strpos( $id, 'shop-order' ) && 'edit-shop_order' !== $id ) {
			return;
		}
		?>
		<style>
			.numra-risk { display:inline-block; min-width:28px; padding:2px 8px; border-radius:4px;
				font-size:12px; font-weight:600; line-height:1.6; text-align:center; }
			.numra-risk--ok      { background:#eaf7ef; color:#1c6b3f; }
			.numra-risk--high    { background:#fdf0e6; color:#8a4b12; }
			.numra-risk--blocked { background:#fdeaea; color:#8a1c1c; }
			.numra-risk--none    { background:transparent; color:#8c8f94; font-weight:400; }
			/* Deliberately neutral, not green. A number with no history is not
			   a cleared number, and colouring it like one would tell the
			   merchant the opposite of what it means. */
			.numra-risk--new     { background:#eef2f7; color:#3c4a5a; }
			/* The one actionable cell in the column, so it reads as a control
			   rather than as another status chip. */
			.numra-risk--check   { background:#fff; color:#30363e; border:1px solid #c6cbd1;
				text-decoration:none; cursor:pointer; }
			.numra-risk--check:hover { border-color:#FF9523; color:#30363e; background:#FFF7ED; }
			/* Locked, not empty. The digits are a placeholder blurred past
			   legibility — no real score is ever rendered before it is paid
			   for, on this screen or the list. */
			.numra-risk--locked { background:#f6f7f7; border:1px dashed #c6cbd1; text-decoration:none;
				position:relative; }
			.numra-risk--locked:hover { border-color:#FF9523; background:#FFF7ED; }
			.numra-blur { filter:blur(4px); user-select:none; pointer-events:none; color:#50575e;
				font-weight:600; letter-spacing:1px; }
			.numra-mb-locked { display:flex; align-items:center; gap:10px; margin:4px 0 2px; }
			.numra-blur-lg { filter:blur(6px); font-size:24px; line-height:1.2; }
			.numra-blur-sm { filter:blur(4px); font-size:13px; }
			.numra-mb-actions { margin:10px 0 4px; }
			/* Outcome prompt */
			.numra-mb-outcome { margin-top:12px; padding-top:10px; border-top:1px solid #f0f0f1; }
			.numra-mb-outcome-q { margin:0 0 2px; font-weight:600; color:#30363e; }
			.numra-mb-outcomes { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
			.numra-outcome-btn { display:inline-block; padding:4px 10px; border:1px solid #c6cbd1;
				border-radius:4px; background:#fff; color:#30363e; text-decoration:none; font-size:12px; }
			.numra-outcome-btn:hover { border-color:#FF9523; background:#FFF7ED; color:#30363e; }
			.numra-outcome-btn.is-current { border-color:#FF9523; background:#FF9523; color:#1B1B1B; font-weight:600; }
			.numra-mb-fine    { margin:0; color:#8c8f94; font-size:11px; line-height:1.5; }
			.numra-mb-score  { margin:0 0 8px; display:flex; align-items:center; gap:8px; }
			.numra-mb-reason { margin:0 0 10px; color:#50575e; }
			.numra-mb-muted  { color:#8c8f94; margin:0; }
			.numra-mb-list   { margin:0; }
			.numra-mb-list li { display:flex; justify-content:space-between; gap:12px;
				padding:5px 0; border-top:1px solid #f0f0f1; margin:0; font-size:12px; }
			.numra-mb-list li span:first-child { color:#646970; }
			.numra-mb-list li span:last-child  { font-weight:600; text-align:right; }
		</style>
		<?php
	}
}
