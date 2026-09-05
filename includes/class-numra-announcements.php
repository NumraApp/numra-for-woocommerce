<?php
/**
 * Numra for WooCommerce — Plugin Announcements
 * Fetches and renders Plugin Announcements banners from api.numra.ma.
 * All output is properly escaped.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Announcements {

	/** Placement slot. Platform segment comes from the plugin platform constant. */
	const PLACEMENT_KEY = 'plugin.' . NUMRA_PLATFORM . '.settings';

	/** Transient key for caching placements — 15 minutes */
	const CACHE_KEY = 'numra_growth_placements_settings';
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Fetch placements — with a short transient cache.
	 *
	 * @return array
	 */
	public static function get_placements() {
		if ( ! Numra_Settings::is_connected() ) {
			return array();
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$client     = new Numra_API_Client();
		$placements = $client->get_growth_placements( self::PLACEMENT_KEY );

		set_transient( self::CACHE_KEY, $placements, self::CACHE_TTL );

		return $placements;
	}

	/**
	 * Bust the placement cache — called after DISMISS so the banner
	 * won't re-appear until the server's dismiss window expires.
	 */
	public static function bust_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Render all eligible placements.
	 * Outputs HTML directly — call inside admin page body.
	 */
	public static function render() {
		$placements = self::get_placements();

		if ( empty( $placements ) ) {
			return;
		}

		foreach ( $placements as $placement ) {
			self::render_placement( $placement );
		}
	}

	/**
	 * Render a single placement card.
	 * Applies theme_variant colour, supports dismissible, tracks IMPRESSION via JS.
	 *
	 * @param array $p  Sanitized placement data from Numra_API_Client.
	 */
	private static function render_placement( $p ) {
		$theme_colors = array(
			'BLUE'    => array( 'bg' => '#EFF6FF', 'border' => '#BFDBFE', 'accent' => '#3B82F6', 'btn' => '#2563EB' ),
			'GREEN'   => array( 'bg' => '#F0FDF4', 'border' => '#BBF7D0', 'accent' => '#22C55E', 'btn' => '#16A34A' ),
			'NEUTRAL' => array( 'bg' => '#F8FAFC', 'border' => '#E2E8F0', 'accent' => '#64748B', 'btn' => '#475569' ),
			'WARNING' => array( 'bg' => '#FFFBEB', 'border' => '#FDE68A', 'accent' => '#F59E0B', 'btn' => '#D97706' ),
		);

		$variant = isset( $theme_colors[ $p['theme_variant'] ] )
			? $theme_colors[ $p['theme_variant'] ]
			: $theme_colors['BLUE'];

		$placement_id  = esc_attr( $p['id'] );
		$placement_key = esc_attr( $p['placement_key'] );
		$dismissible   = ! empty( $p['dismissible'] );

		?>
		<div class="numra-announcement"
		     id="numra-banner-<?php echo $placement_id; ?>"
		     data-placement-id="<?php echo $placement_id; ?>"
		     data-placement-key="<?php echo $placement_key; ?>"
		     data-dismissible="<?php echo $dismissible ? '1' : '0'; ?>"
		     style="
		     	background: <?php echo esc_attr( $variant['bg'] ); ?>;
		     	border: 1px solid <?php echo esc_attr( $variant['border'] ); ?>;
		     	border-radius: 10px;
		     	padding: 18px 20px;
		     	margin-bottom: 16px;
		     	position: relative;
		     	max-width: 640px;
		     	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		     ">

			<?php if ( $dismissible ) : ?>
			<button class="numra-banner-dismiss"
			        data-placement-id="<?php echo $placement_id; ?>"
			        data-placement-key="<?php echo $placement_key; ?>"
			        title="<?php esc_attr_e( 'Dismiss', 'numra-for-woocommerce' ); ?>"
			        style="
			        	position: absolute; top: 10px; right: 10px;
			        	background: rgba(0,0,0,0.06); border: none; border-radius: 50%;
			        	width: 24px; height: 24px; cursor: pointer;
			        	display: flex; align-items: center; justify-content: center;
			        	font-size: 12px; color: #6B7280; line-height: 1;
			        ">
				&times;
			</button>
			<?php endif; ?>

			<div style="font-size: 11px; font-weight: 700; color: <?php echo esc_attr( $variant['accent'] ); ?>;
			            text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px;">
				<?php echo esc_html( $p['content_type'] ); ?>
			</div>

			<h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700;
			            color: #111827; line-height: 1.3;
			            padding-right: <?php echo $dismissible ? '28px' : '0'; ?>;">
				<?php echo esc_html( $p['title'] ); ?>
			</h3>

			<?php if ( ! empty( $p['message'] ) ) : ?>
			<p style="margin: 0 0 16px; font-size: 13px; color: #6B7280; line-height: 1.6;">
				<?php echo esc_html( $p['message'] ); ?>
			</p>
			<?php endif; ?>

			<?php if ( ! empty( $p['action_url'] ) && ! empty( $p['action_label'] ) ) : ?>
			<a class="numra-banner-cta"
			   href="<?php echo esc_url( $p['action_url'] ); ?>"
			   target="_blank"
			   rel="noopener noreferrer"
			   data-placement-id="<?php echo $placement_id; ?>"
			   data-placement-key="<?php echo $placement_key; ?>"
			   style="
			   	display: inline-flex; align-items: center; gap: 6px;
			   	padding: 9px 18px;
			   	border-radius: 7px;
			   	background: <?php echo esc_attr( $variant['btn'] ); ?>;
			   	color: #fff; text-decoration: none;
			   	font-size: 13px; font-weight: 700;
			   ">
				<?php echo esc_html( $p['action_label'] ); ?>
				<span aria-hidden="true">&#8594;</span>
			</a>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Handle AJAX event tracking.
	 * Nonce + capability checked before forwarding to API.
	 */
	public static function handle_ajax_event() {
		check_ajax_referer( 'numra_announcement_dismiss', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$placement_id  = isset( $_POST['placement_id'] )  ? sanitize_text_field( wp_unslash( $_POST['placement_id'] ) )  : '';
		$event_type    = isset( $_POST['event_type'] )    ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) )    : '';
		$placement_key = isset( $_POST['placement_key'] ) ? sanitize_text_field( wp_unslash( $_POST['placement_key'] ) ) : '';

		if ( empty( $placement_id ) || empty( $event_type ) || empty( $placement_key ) ) {
			wp_send_json_error( array( 'message' => 'Missing required fields.' ), 400 );
		}

		$meta = array(
			'platform' => NUMRA_PLATFORM,
			'page'     => 'settings',
		);

		$client = new Numra_API_Client();
		$ok     = $client->send_growth_event( $placement_id, $event_type, $placement_key, $meta );

		// Bust cache after DISMISS so the banner is hidden on next load.
		if ( 'DISMISS' === $event_type ) {
			self::bust_cache();
		}

		if ( $ok ) {
			wp_send_json_success();
		} else {
			// Don't surface API errors to admin — just silently fail.
			wp_send_json_success();
		}
	}
}
