<?php
/**
 * Numra for WooCommerce — WordPress dashboard card
 *
 * The WordPress dashboard is the screen a merchant lands on every morning.
 * Until now Numra appeared nowhere on it: to learn whether their store was
 * protected, and how much of their plan was left, they had to remember Numra
 * existed and go looking. This card answers both without being asked.
 *
 * It reads the heartbeat's stored state rather than calling the API — the
 * dashboard must never wait on a network round trip, and the beat is at most
 * fifteen minutes old.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Dashboard_Widget {

	public function register_hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	public function add_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'numra_status',
			__( 'Numra — order protection', 'numra-for-woocommerce' ),
			array( $this, 'render' )
		);
	}

	public function render() {
		$connected = Numra_Settings::is_connected();
		$state     = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::state() : 'unknown';
		$protected = ! class_exists( 'Numra_Heartbeat' ) || Numra_Heartbeat::protection_enabled();
		$stored    = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::get_state() : array();
		$alert     = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::get_alert() : null;

		$usage     = isset( $stored['usage'] ) && is_array( $stored['usage'] ) ? $stored['usage'] : array();
		$used      = isset( $usage['today'] ) ? (int) $usage['today'] : 0;
		$limit     = isset( $usage['daily_limit'] ) ? (int) $usage['daily_limit'] : 0;
		$remaining = $limit > 0 ? max( 0, $limit - $used ) : null;
		$pct       = $limit > 0 ? min( 100, (int) round( ( $used / $limit ) * 100 ) ) : 0;

		$live = $connected && $protected;

		$this->print_styles();

		echo '<div class="numra-dw">';

		// ── Connection ────────────────────────────────────────────────────
		echo '<div class="numra-dw-row">';
		printf(
			'<span class="numra-dw-pill %1$s"><span class="numra-dw-dot"></span>%2$s</span>',
			esc_attr( $live ? 'is-live' : 'is-off' ),
			esc_html( $live
				? __( 'Protecting this store', 'numra-for-woocommerce' )
				: ( $connected
					? __( 'Protection paused', 'numra-for-woocommerce' )
					: __( 'Not connected', 'numra-for-woocommerce' ) ) )
		);
		if ( class_exists( 'Numra_Heartbeat' ) && Numra_Heartbeat::last_beat_at() ) {
			printf(
				'<span class="numra-dw-ago">%s</span>',
				esc_html( sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 mins". */
					__( 'checked %s ago', 'numra-for-woocommerce' ),
					human_time_diff( Numra_Heartbeat::last_beat_at(), time() )
				) )
			);
		}
		echo '</div>';

		/* A problem the merchant can act on outranks the usage numbers, so it
		   sits above them rather than below. */
		if ( ! $live && ! empty( $alert['message'] ) ) {
			echo '<p class="numra-dw-alert">' . esc_html( $alert['message'] ) . '</p>';
			if ( ! empty( $alert['action_url'] ) ) {
				printf(
					'<p class="numra-dw-cta"><a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
					esc_url( $alert['action_url'] ),
					esc_html( ! empty( $alert['action_label'] ) ? $alert['action_label'] : __( 'Open Numra', 'numra-for-woocommerce' ) )
				);
			}
		} elseif ( ! $connected ) {
			echo '<p class="numra-dw-alert">' . esc_html__( 'Connect this store to start screening cash-on-delivery orders.', 'numra-for-woocommerce' ) . '</p>';
			printf(
				'<p class="numra-dw-cta"><a class="button button-primary" href="%1$s">%2$s</a></p>',
				esc_url( add_query_arg( array( 'page' => 'numra' ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Connect to Numra', 'numra-for-woocommerce' )
			);
		}

		// ── Credits ───────────────────────────────────────────────────────
		if ( $connected && $limit > 0 ) {
			$bar_class = $pct >= 90 ? 'is-danger' : ( $pct >= 70 ? 'is-warn' : '' );

			echo '<div class="numra-dw-credits">';
			echo '<div class="numra-dw-credit-head">';
			echo '<span>' . esc_html__( 'Checks left today', 'numra-for-woocommerce' ) . '</span>';
			printf(
				'<strong>%1$s <span>/ %2$s</span></strong>',
				esc_html( number_format_i18n( $remaining ) ),
				esc_html( number_format_i18n( $limit ) )
			);
			echo '</div>';
			printf(
				'<div class="numra-dw-bar"><span class="%1$s" style="width:%2$d%%"></span></div>',
				esc_attr( $bar_class ),
				(int) $pct
			);
			printf(
				'<p class="numra-dw-fine">%s</p>',
				esc_html( sprintf(
					/* translators: 1: checks used, 2: percentage of the daily plan used. */
					__( '%1$s used today (%2$d%% of your plan).', 'numra-for-woocommerce' ),
					number_format_i18n( $used ),
					$pct
				) )
			);
			echo '</div>';
		} elseif ( $connected ) {
			echo '<p class="numra-dw-fine">' . esc_html__( 'Usage will appear here after the next check-in.', 'numra-for-woocommerce' ) . '</p>';
		}

		// ── Footer ────────────────────────────────────────────────────────
		echo '<p class="numra-dw-links">';
		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( add_query_arg( array( 'page' => 'numra' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Numra settings', 'numra-for-woocommerce' )
		);
		if ( $live ) {
			printf(
				' <span class="numra-dw-sep">·</span> <a href="%1$s">%2$s</a>',
				esc_url( add_query_arg( array( 'page' => 'numra', 'tab' => 'check' ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Check a number', 'numra-for-woocommerce' )
			);
		}
		echo '</p>';

		echo '</div>';
	}

	private function print_styles() {
		?>
		<style>
			.numra-dw-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
			.numra-dw-pill { display:inline-flex; align-items:center; gap:7px; padding:4px 11px;
				border-radius:999px; font-size:12px; font-weight:600; border:1px solid #E4E6EA;
				background:#F7F8F9; color:#444C56; }
			.numra-dw-pill .numra-dw-dot { width:7px; height:7px; border-radius:50%; background:#6E7781; }
			.numra-dw-pill.is-live { color:#17803D; border-color:#BBE7C8; background:#F1FAF3; }
			.numra-dw-pill.is-live .numra-dw-dot { background:#17803D; }
			.numra-dw-pill.is-off { color:#B42318; border-color:#F3C9C4; background:#FEF3F2; }
			.numra-dw-pill.is-off .numra-dw-dot { background:#B42318; }
			.numra-dw-ago { font-size:11px; color:#8c8f94; }
			.numra-dw-alert { margin:0 0 10px; color:#444C56; line-height:1.6; }
			.numra-dw-cta { margin:0 0 12px; }
			.numra-dw-credits { border-top:1px solid #f0f0f1; padding-top:12px; margin-top:4px; }
			.numra-dw-credit-head { display:flex; justify-content:space-between; align-items:baseline;
				gap:12px; margin-bottom:6px; font-size:13px; color:#6E7781; }
			.numra-dw-credit-head strong { font-size:18px; color:#30363E; font-weight:600; }
			.numra-dw-credit-head strong span { font-size:12px; color:#8c8f94; font-weight:400; }
			.numra-dw-bar { height:6px; border-radius:3px; background:#F0F0F1; overflow:hidden; }
			.numra-dw-bar span { display:block; height:100%; border-radius:3px; background:#FF9523; }
			.numra-dw-bar span.is-warn   { background:#D97706; }
			.numra-dw-bar span.is-danger { background:#B42318; }
			.numra-dw-fine { margin:7px 0 0; font-size:11px; color:#8c8f94; }
			.numra-dw-links { margin:12px 0 0; padding-top:10px; border-top:1px solid #f0f0f1; font-size:12px; }
			.numra-dw-sep { color:#c3c4c7; }
		</style>
		<?php
	}
}
