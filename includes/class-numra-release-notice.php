<?php
/**
 * Numra for WooCommerce — Release notice
 *
 * Tells the shop owner that a new version of this plugin is out, in words
 * written by whoever shipped it.
 *
 * WHY THIS EXISTS ALONGSIDE THE WORDPRESS UPDATER
 * ------------------------------------------------
 * Numra_Updater already hooks `pre_set_site_transient_update_plugins`, so the
 * native "update available" badge appears on Plugins and Updates and the
 * merchant can install with one click. That badge is not replaced here and
 * should not be — it owns the install.
 *
 * What it cannot do is say anything. A version number is not a reason. "1.17.0
 * is available" does not tell a merchant that this release stops the guard
 * timing out on slow hosts, which is the sentence that makes them click. And
 * WP-Cron polls for updates roughly twice a day, so an urgent fix can sit
 * unseen for twelve hours; the heartbeat runs every fifteen minutes and
 * already carries the answer.
 *
 * So: the updater installs, this notice explains and hurries.
 *
 * THE WORDING COMES FROM THE SERVER, like every other merchant-facing string
 * in this plugin. `notes_i18n` is written in the control panel at publish time
 * and rides down on the beat. Fixing a confusing release note must not require
 * shipping a plugin update to explain a plugin update.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Release_Notice {

	const DISMISS_ACTION = 'numra_dismiss_release';

	public function register_hooks() {
		add_action( 'admin_notices',                      array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	public function render() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		/* One notice at a time, and a broken licence outranks a new version.
		   ─────────────────────────────────────────────────────────────────
		   A store whose protection is OFF has a problem that upgrading will
		   not fix, and stacking two Numra banners means the merchant reads
		   neither. Numra_Alerts renders errors non-dismissibly; when one is
		   on screen this one stands down and waits. */
		$alert = Numra_Heartbeat::get_alert();
		if ( ! empty( $alert ) && isset( $alert['level'] ) && 'error' === $alert['level'] ) {
			return;
		}

		if ( ! Numra_Heartbeat::release_pending() ) {
			return;
		}

		$rel      = Numra_Heartbeat::release();
		$version  = $rel['latest_version'];
		$critical = ! empty( $rel['is_critical'] );

		/* The server may have sent no editorial title. Fall back to a plain
		   factual one rather than printing an empty heading — but never
		   invent a reason, because there is no honest default for "why you
		   want this". An empty notes body simply renders nothing. */
		$title = ! empty( $rel['title'] )
			? $rel['title']
			/* translators: %s: version number, e.g. 1.17.0 */
			: sprintf( __( 'Numra %s is available', 'numra-for-woocommerce' ), $version );

		$installed = defined( 'NUMRA_VERSION' ) ? NUMRA_VERSION : '';
		$class     = $critical ? 'notice notice-error' : 'notice notice-info';

		$updates_url = admin_url( 'plugins.php' );
		?>
		<div class="<?php echo esc_attr( $class ); ?>" style="padding:12px 14px;">
			<p style="margin:0 0 6px;display:flex;align-items:center;gap:8px;">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="17" viewBox="0 0 245.13 293.44" aria-hidden="true" style="flex-shrink:0;">
					<polygon fill="#FF9523" points="176.42 .07 113.33 66.7 164.05 66.88 164.08 215.56 111.46 293.24 191.99 293.36 245.13 215.54 245.13 0 176.42 .07"/>
					<polygon fill="#30363E" points="133.3 91.6 64.45 91.61 1.62 158.59 52.76 158.69 52.71 215.31 0 293.25 80.22 293.44 133.25 215.51 133.3 91.6"/>
				</svg>
				<strong><?php echo esc_html( $title ); ?></strong>
				<?php if ( $critical ) : ?>
					<span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;opacity:.8;">
						<?php esc_html_e( 'Important', 'numra-for-woocommerce' ); ?>
					</span>
				<?php endif; ?>
			</p>

			<?php if ( ! empty( $rel['notes'] ) ) : ?>
				<p style="margin:0 0 8px;"><?php echo esc_html( $rel['notes'] ); ?></p>
			<?php endif; ?>

			<p style="margin:0 0 8px;font-size:12px;opacity:.75;">
				<?php
				if ( $installed ) {
					printf(
						/* translators: 1: installed version, 2: available version */
						esc_html__( 'You are running %1$s. Version %2$s is available.', 'numra-for-woocommerce' ),
						esc_html( $installed ),
						esc_html( $version )
					);
				} else {
					/* translators: %s: version number */
					printf( esc_html__( 'Version %s is available.', 'numra-for-woocommerce' ), esc_html( $version ) );
				}
				?>
			</p>

			<p style="margin:0;">
				<a class="button button-primary" href="<?php echo esc_url( $updates_url ); ?>">
					<?php esc_html_e( 'Go to Plugins to update', 'numra-for-woocommerce' ); ?>
				</a>
				<?php if ( ! empty( $rel['changelog_url'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( $rel['changelog_url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( "What's new", 'numra-for-woocommerce' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! $critical ) : ?>
					<a class="button-link" style="margin-left:8px;"
					   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION . '&version=' . rawurlencode( $version ) ), self::DISMISS_ACTION ) ); ?>">
						<?php esc_html_e( 'Not now', 'numra-for-woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public function handle_dismiss() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'numra-for-woocommerce' ) );
		}
		check_admin_referer( self::DISMISS_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified directly above.
		$version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';
		if ( $version ) {
			/* Dismissal is recorded against THIS version only. The next
			   release clears it (see Numra_Heartbeat::beat), so waving off
			   1.17 does not silence 1.18 — the one a merchant most needs to
			   hear about is always the one they have not seen yet. */
			Numra_Heartbeat::dismiss_release( $version );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
