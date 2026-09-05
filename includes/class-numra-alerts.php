<?php
/**
 * Numra for WooCommerce — Dashboard alerts
 *
 * Renders the state the heartbeat learned, on EVERY admin screen.
 *
 * Why not just on the Numra page: a merchant whose protection has been switched
 * off does not visit the Numra page — they have no reason to, because as far as
 * they know everything is fine. That is precisely the person who needs telling.
 * A notice that only appears where you go to look for problems is a notice for
 * people who already know they have one.
 *
 * The wording comes from the server (see the ALERTS catalogue in
 * routes/plugin-heartbeat.js), not from this file. A merchant on WooCommerce
 * and a merchant on any other platform should read the same sentence about the
 * same problem, and fixing a confusing message must not require every
 * storefront to ship a plugin update.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Alerts {

	const DISMISS_ACTION = 'numra_dismiss_alert';

	public function register_hooks() {
		add_action( 'admin_notices',                     array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$alert = Numra_Heartbeat::get_alert();
		if ( empty( $alert ) || empty( $alert['message'] ) ) {
			return;
		}

		$level = isset( $alert['level'] ) ? $alert['level'] : 'warning';
		$code  = isset( $alert['code'] )  ? $alert['code']  : '';

		/* Errors mean protection is OFF and cannot be dismissed — a merchant
		   should not be able to hide the fact that they are unprotected.
		   Warnings are advisory (expiring soon, quota reached) and can be. */
		$is_error = ( 'error' === $level );
		if ( ! $is_error && get_option( Numra_Heartbeat::OPT_NOTICE_SEEN ) === $code ) {
			return;
		}

		$class = $is_error ? 'notice notice-error' : 'notice notice-warning';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" style="border-left-width:4px;padding:12px 14px;">
			<p style="margin:0 0 6px;display:flex;align-items:center;gap:8px;">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="17" viewBox="0 0 245.13 293.44" aria-hidden="true" style="flex-shrink:0;">
					<polygon fill="#FF9523" points="176.42 .07 113.33 66.7 164.05 66.88 164.08 215.56 111.46 293.24 191.99 293.36 245.13 215.54 245.13 0 176.42 .07"/>
					<polygon fill="#30363E" points="133.3 91.6 64.45 91.61 1.62 158.59 52.76 158.69 52.71 215.31 0 293.25 80.22 293.44 133.25 215.51 133.3 91.6"/>
				</svg>
				<strong><?php echo esc_html( isset( $alert['title'] ) ? $alert['title'] : __( 'Numra', 'numra-for-woocommerce' ) ); ?></strong>
			</p>
			<p style="margin:0 0 8px;"><?php echo esc_html( $alert['message'] ); ?></p>
			<p style="margin:0;">
				<?php if ( ! empty( $alert['action_url'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $alert['action_url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( isset( $alert['action_label'] ) ? $alert['action_label'] : __( 'Open Numra', 'numra-for-woocommerce' ) ); ?>
					</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'numra' ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'Plugin settings', 'numra-for-woocommerce' ); ?>
				</a>
				<?php if ( ! $is_error ) : ?>
					<a class="button-link" style="margin-left:8px;"
					   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&code=' . rawurlencode( $code ) ), self::DISMISS_ACTION ) ); ?>">
						<?php esc_html_e( 'Dismiss', 'numra-for-woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'numra-for-woocommerce' ) );
		}
		check_admin_referer( self::DISMISS_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified directly above.
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( $_GET['code'] ) ) : '';
		if ( $code ) {
			update_option( Numra_Heartbeat::OPT_NOTICE_SEEN, $code, false );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
