<?php
/**
 * Numra for WooCommerce — Admin Menu
 * Registers the WordPress admin menu, enqueues assets,
 * and renders all Phase 1 tabs: Dashboard, API Key, Growth Notices.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Admin_Menu {

	public function __construct() {
		add_action( 'admin_menu',         array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_numra_test_connection', array( new Numra_Settings(), 'handle_test_connection' ) );
		add_action( 'wp_ajax_numra_announcement_dismiss',    array( 'Numra_Announcements', 'handle_ajax_event' ) );
		add_action( 'wp_ajax_numra_check_phone',             array( $this, 'handle_check_phone' ) );
		add_action( 'admin_post_numra_sync_consent',         array( $this, 'handle_sync_consent' ) );
	}

	/**
	 * The merchant's answer about syncing their order history.
	 *
	 * Its own handler, its own nonce, its own form. Deliberately NOT part of
	 * Numra_Settings::handle_save(): that handler writes a batch of checkboxes
	 * from whichever form was posted, and this codebase has twice shipped a bug
	 * where a field absent from the submitted form was written as off. Consent
	 * is the one option where that failure mode is not a bug but a breach, so
	 * it is unreachable from any other save.
	 */
	public function handle_sync_consent() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'numra-for-woocommerce' ) );
		}
		check_admin_referer( 'numra_sync_consent' );

		$choice = isset( $_POST['numra_sync_choice'] ) ? sanitize_key( wp_unslash( $_POST['numra_sync_choice'] ) ) : '';
		$agreed = isset( $_POST['numra_sync_agree'] );

		/* Agreement requires the box AND the button. The checkbox carries
		   `required`, but a required attribute is a browser courtesy, not a
		   guarantee — anything can post this form. Server-side, an unticked box
		   is not agreement, so a "start" without it falls through to no change
		   and the screen is shown again. */
		if ( 'start' === $choice && $agreed ) {
			Numra_Backfill::start();
		} elseif ( 'decline' === $choice || 'stop' === $choice ) {
			Numra_Backfill::stop();
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'numra', 'tab' => 'dashboard' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Manual phone lookup from the "Check a number" tab.
	 *
	 * Returns the verdict AND the refreshed credit figures, because the check
	 * the merchant just ran changed them — sending back a stale count would
	 * have the tool lie about its own cost.
	 */
	public function handle_check_phone() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'numra-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( 'numra_check_phone', 'nonce' );

		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		if ( '' === trim( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a phone number.', 'numra-for-woocommerce' ) ) );
		}

		$client = new Numra_API_Client();
		$result = $client->lookup_phone( $phone, array( 'source' => 'manual_admin_check' ) );

		if ( empty( $result['ok'] ) ) {
			$msg = ! empty( $result['error'] )
				? sprintf( /* translators: %s: error code from the Numra API. */
					__( 'Could not check that number (%s).', 'numra-for-woocommerce' ), $result['error'] )
				: __( 'Could not reach Numra. Try again in a moment.', 'numra-for-woocommerce' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		/* Numra_API_Client::lookup_phone() returns a FLAT, already-normalised
		 * array — there is no 'body' key on it, and never was.
		 *
		 * This used to dig for $result['body']['data'], find nothing, and fall
		 * back to an empty array. The credit was still spent, and the merchant
		 * was shown an em dash and "Cleared" — which is worse than showing
		 * nothing, because on a blacklisted number it reads as an exoneration.
		 *
		 * The client already resolves verdict, banding and the unrated case,
		 * so those are passed through rather than re-derived here. */

		/* Beat immediately so the credit counter the merchant is looking at
		   reflects the check they just spent, rather than drifting until the
		   next scheduled beat. */
		if ( class_exists( 'Numra_Heartbeat' ) ) {
			Numra_Heartbeat::beat();
		}
		$stored = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::get_state() : array();
		$usage  = isset( $stored['usage'] ) && is_array( $stored['usage'] ) ? $stored['usage'] : array();
		$used   = isset( $usage['today'] ) ? (int) $usage['today'] : 0;
		$limit  = isset( $usage['daily_limit'] ) ? (int) $usage['daily_limit'] : 0;

		wp_send_json_success( array(
			'phone'        => ! empty( $result['phone'] ) ? (string) $result['phone'] : $phone,
			'score'        => isset( $result['score'] ) && null !== $result['score'] ? (int) $result['score'] : null,
			'level'        => isset( $result['level'] ) ? (string) $result['level'] : '',
			'verdict'      => isset( $result['verdict'] ) ? (string) $result['verdict'] : '',
			/* Whether the number has any history at all. Without this the
			 * result panel thresholds a neutral server-side score for an
			 * unseen number and calls it "Cleared" — exactly what the order
			 * screen deliberately refuses to do. */
			'rated'        => ! empty( $result['rated'] ),
			'blacklisted'  => ! empty( $result['blacklisted'] ),
			'reason'       => isset( $result['reason'] ) ? (string) $result['reason'] : '',
			'carrier'      => isset( $result['carrier_label'] ) ? (string) $result['carrier_label'] : '',
			'style'        => isset( $result['style'] ) ? (string) $result['style'] : '',
			'threshold'    => (int) Numra_Settings::get_risk_threshold(),
			'remaining'    => $limit > 0 ? max( 0, $limit - $used ) : null,
			'limit'        => $limit,
		) );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	/**
	 * The Numra mark, flattened for the admin sidebar.
	 *
	 * WordPress renders a data-URI menu icon as a CSS background on a div and
	 * does NOT recolour it, so the icon has to ship in the sidebar's own ink
	 * (#f3f1f1 against the #1d2327 chrome) rather than in brand orange. Core
	 * handles the rest: 0.6 opacity at rest, full opacity on hover and for the
	 * current menu item.
	 *
	 * Inlined rather than referenced by URL so it renders on the very first
	 * paint, survives an asset host being blocked, and costs no extra request.
	 * The source of truth is assets/numra-symbol-wp.svg; the <style> block and
	 * class attributes are flattened to a plain fill because a data URI has no
	 * stylesheet context to resolve them against.
	 */
	private function menu_icon_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 245.13 293.44">'
			. '<g fill="#f3f1f1">'
			. '<polygon points="176.42 .07 113.33 66.7 164.05 66.88 164.08 215.56 111.46 293.24 191.99 293.36 245.13 215.54 245.13 0 176.42 .07"/>'
			. '<polygon points="133.3 91.6 64.45 91.61 1.62 158.59 52.76 158.69 52.71 215.31 0 293.25 80.22 293.44 133.25 215.51 133.3 91.6"/>'
			. '</g></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu() {
		/* Top of the sidebar, directly under WordPress's own menus.
		
		   Core positions: Dashboard 2, separator 4, Posts 5, Media 10,
		   Pages 20, Comments 25, separator 59, Appearance 60 … Settings 80.
		   26 lands immediately below Comments — the end of the core block and
		   the start of the plugin block — so Numra is the first thing a
		   merchant sees that is not WordPress itself, and sits above
		   WooCommerce (55) rather than below it.

		   It was 58: past WooCommerce, at the bottom of the plugin pile.

		   The fractional value is deliberate. add_menu_page() uses the
		   position as an array key, so an integer silently OVERWRITES any
		   other plugin that claimed the same slot — one of the two menus just
		   disappears, with nothing logged. A float cannot collide that way. */
		$hook = add_menu_page(
			__( 'Numra', 'numra-for-woocommerce' ),
			__( 'Numra', 'numra-for-woocommerce' ),
			'manage_options',
			'numra',
			array( $this, 'render_page' ),
			$this->menu_icon_uri(),
			'26.31'
		);

		// Process the settings form on `load-{$hook}`: this fires before
		// admin-header output, so the post-save redirect can never hit a
		// "headers already sent" failure. POST-only and capability-gated
		// inside handle_save() itself.
		add_action( 'load-' . $hook, array( new Numra_Settings(), 'handle_save' ) );
	}

	// ── Asset enqueue ─────────────────────────────────────────────────────────

	/**
	 * Enqueue admin assets — Numra pages only.
	 *
	 * The hook check below is the whole isolation guarantee: `admin_enqueue_scripts`
	 * passes the current screen's hook suffix, and only our own page produces
	 * `toplevel_page_numra`. The Elementor editor, the block editor, and every
	 * other admin screen fail this check and load none of our CSS or JS.
	 *
	 * The second check is belt-and-braces for editors that render inside an admin
	 * request without a normal screen hook. It is deliberately written without
	 * referencing any Elementor class or constant, so the plugin never depends on
	 * Elementor being installed.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_numra' !== $hook ) {
			return;
		}

		// Never load inside a page-builder editing surface.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only guard.
		$editor_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( in_array( $editor_action, array( 'elementor', 'elementor_library' ), true ) ) {
			return;
		}

		/* Font Awesome is gone. It was pulled from cdnjs on every Numra admin
		   page — a third-party request in the merchant's dashboard, ~100KB of
		   CSS plus webfonts, for 29 decorative glyphs that all sat next to a
		   label saying the same word. It also broke on any site with a strict
		   CSP or no outbound access, leaving squares. Nothing replaced it:
		   the panel now carries its own type and colour instead of leaning on
		   icons to look designed. */
		wp_enqueue_style(
			'numra-admin',
			NUMRA_PLUGIN_URL . 'assets/admin.css',
			array(),
			NUMRA_VERSION
		);

		wp_enqueue_script(
			'numra-admin',
			NUMRA_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			NUMRA_VERSION,
			true
		);

		/* Separate file, depending on the first so it shares its localised
		   data. Keeping the manual lookup out of admin.js means the existing
		   "Test connection" behaviour is untouched by this feature. */
		wp_enqueue_script(
			'numra-admin-check',
			NUMRA_PLUGIN_URL . 'assets/admin-check.js',
			array( 'numra-admin' ),
			NUMRA_VERSION,
			true
		);

		// Pass data to JS — API key is NEVER included here.
		wp_localize_script( 'numra-admin', 'numraAdmin', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'testNonce'        => wp_create_nonce( 'numra_test_connection' ),
			'dismissNonce' => wp_create_nonce( 'numra_announcement_dismiss' ),
			'checkNonce'   => wp_create_nonce( 'numra_check_phone' ),
			'strings'          => array(
				'testing'         => __( 'Testing...', 'numra-for-woocommerce' ),
				'connected'       => __( 'Connected', 'numra-for-woocommerce' ),
				'failed'          => __( 'Connection failed', 'numra-for-woocommerce' ),
				'dismissed'       => __( 'Banner dismissed.', 'numra-for-woocommerce' ),
				'testConnection'  => __( 'Test Connection', 'numra-for-woocommerce' ),
				'checking'        => __( 'Checking…', 'numra-for-woocommerce' ),
				'checkNumber'     => __( 'Check number', 'numra-for-woocommerce' ),
				'enterNumber'     => __( 'Enter a phone number.', 'numra-for-woocommerce' ),
				'blacklisted'     => __( 'Blacklisted', 'numra-for-woocommerce' ),
				'highRisk'        => __( 'High risk', 'numra-for-woocommerce' ),
				'cleared'         => __( 'Cleared', 'numra-for-woocommerce' ),
				/* An unseen number is "No history yet", never "Cleared".
				   Numra has nothing to judge it on, and calling that a pass is
				   the one thing the order screen refuses to do. */
				'noHistory'       => __( 'No history yet', 'numra-for-woocommerce' ),
				'customerStyle'   => __( 'Customer type', 'numra-for-woocommerce' ),
				'carrier'         => __( 'Carrier', 'numra-for-woocommerce' ),
				'riskLevel'       => __( 'Risk level', 'numra-for-woocommerce' ),
				'checksLeft'      => __( 'checks left today', 'numra-for-woocommerce' ),
				'networkError'    => __( 'Network error. Try again.', 'numra-for-woocommerce' ),
			),
		) );
	}

	// ── Page router ───────────────────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'numra-for-woocommerce' ) );
		}

		// Settings form saves are handled on `load-{$hook}` (registered in
		// register_menu()), before any admin-header output — never here.

		/* Three tabs, not five and not none.
		   ──────────────────────────────────────────────────────────────────
		   Five tabs each held one card, which is why every screen was a strip
		   in a field of grey. Collapsing to a single page fixed the emptiness
		   and created a different problem: connection, billing settings and
		   API credentials are three unrelated jobs, and stacking them made one
		   long scroll where nothing was findable.

		   Three is the grouping the work actually has: get connected, decide
		   what happens to orders, and the credentials underneath. Old two-name
		   links (check, growth-notices) fold into the tab that now owns them
		   rather than 404-ing to the default. */
		$tab_aliases = array(
			'check'          => 'dashboard',
			'growth-notices' => 'dashboard',
		);
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( isset( $tab_aliases[ $active_tab ] ) ) {
			$active_tab = $tab_aliases[ $active_tab ];
		}
		if ( ! in_array( $active_tab, array( 'dashboard', 'protection', 'api-key' ), true ) ) {
			$active_tab = 'dashboard';
		}

		?>
		<div class="wrap numra-wrap">

			<?php
			/* The masthead. Previously: a green shield that was neither
			   Numra's shape nor Numra's colour, the word "Numra", and a
			   version string — three pieces of chrome telling the merchant
			   nothing they did not already know from clicking the menu item.

			   It now carries the one fact worth putting at the top of the
			   page: whether this store is protected right now. */
			$hdr_status    = Numra_Settings::get_connection_status();
			$hdr_protected = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::protection_enabled() : true;
			$hdr_live      = ( 'connected' === $hdr_status ) && $hdr_protected;
			?>
			<div class="numra-header">
				<div class="numra-header-brand">
					<svg class="numra-mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 245.13 293.44" role="img" aria-label="Numra">
						<polygon fill="#FF9523" points="176.42 .07 113.33 66.7 164.05 66.88 164.08 215.56 111.46 293.24 191.99 293.36 245.13 215.54 245.13 0 176.42 .07"/>
						<polygon fill="#30363E" points="133.3 91.6 64.45 91.61 1.62 158.59 52.76 158.69 52.71 215.31 0 293.25 80.22 293.44 133.25 215.51 133.3 91.6"/>
					</svg>
					<div>
						<h1><?php esc_html_e( 'Numra', 'numra-for-woocommerce' ); ?></h1>
						<p class="numra-header-sub"><?php esc_html_e( 'Cash-on-delivery fraud protection', 'numra-for-woocommerce' ); ?></p>
					</div>
				</div>
				<?php
				/* The status readout, not a status pill.
				   ─────────────────────────────────────────────────────────
				   This was a rounded chip: tinted fill, matching border, a
				   coloured dot, centred label. That combination is the
				   default every admin screen reaches for, and it broke this
				   sheet's own rule — "type carries hierarchy, not colour;
				   only what the merchant can act on is orange" — by painting
				   a whole surface for a fact nobody can click.

				   What replaced it takes its shape from the Numra mark, which
				   is built entirely from slanted parallelograms: the state is
				   a single skewed bar, the same angle as the logo beside it,
				   with the label in ink and the state word carrying the only
				   colour on the line. No fill, no border, no dot. */
				?>
				<div class="numra-header-meta">
					<span class="numra-state <?php echo $hdr_live ? 'is-live' : 'is-off'; ?>">
						<span class="numra-state-bar" aria-hidden="true"></span>
						<span class="numra-state-text">
							<span class="numra-state-label"><?php esc_html_e( 'This store', 'numra-for-woocommerce' ); ?></span>
							<strong class="numra-state-value"><?php echo $hdr_live
								? esc_html__( 'Protected', 'numra-for-woocommerce' )
								: esc_html__( 'Not protected', 'numra-for-woocommerce' ); ?></strong>
						</span>
					</span>
					<span class="numra-version">v<?php echo esc_html( NUMRA_VERSION ); ?></span>
				</div>
			</div>

			<?php
			/* The tab row carries three errands, in the order they happen:
			   connect the store, hand it credentials, then decide what it does
			   to orders. Labels name the job, not the object — "Order
			   protection" rather than "Settings" — because a merchant arrives
			   at this page with an errand, not with a noun. */
			$tabs = array(
				'dashboard'  => __( 'Dashboard', 'numra-for-woocommerce' ),
				'api-key'    => __( 'API configuration', 'numra-for-woocommerce' ),
				'protection' => __( 'Order protection', 'numra-for-woocommerce' ),
			);
			?>
			<nav class="numra-tabs" aria-label="<?php esc_attr_e( 'Numra sections', 'numra-for-woocommerce' ); ?>">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<?php $is_current = ( $tab_slug === $active_tab ); ?>
					<a
						class="numra-tab<?php echo $is_current ? ' numra-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=numra&tab=' . $tab_slug ) ); ?>"
						<?php echo $is_current ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="numra-content numra-grid">
			<?php if ( 'dashboard' === $active_tab ) : ?>

				<?php
				/* Disconnected, this tab has ONE job, so it shows one panel at
				   full width. Laying the empty state out as a dashboard put a
				   disabled "check a number" and an empty "recent checks" in two
				   of the most prominent slots — panels that can say nothing
				   until the store connects, sitting beside the one control that
				   would let them say something.

				   Connected, the pairing is by height and by errand: status and
				   check-a-number are both short and both about right now, so
				   they sit together; recent checks is the wide one because it is
				   a list that grows. */
				?>
				<?php
				/* The consent question comes before anything it would affect.
				   A store that has just connected sees this first, above its
				   own status — because until it is answered the sync reads
				   nothing, and a merchant should meet that decision rather than
				   scroll past it. Once answered, in either direction, it never
				   appears again; the sync panel below carries the state and the
				   means to change it. */
				?>
				<?php if ( Numra_Settings::is_connected() && ! Numra_Settings::sync_consent_answered() ) : ?>
					<section class="numra-span" id="numra-sync-consent">
						<?php $this->render_sync_consent_panel(); ?>
					</section>
				<?php endif; ?>

				<section class="<?php echo Numra_Settings::is_connected() ? 'numra-col' : 'numra-span'; ?>" id="numra-connect">
					<?php $this->render_dashboard_tab(); ?>
				</section>

				<?php if ( Numra_Settings::is_connected() ) : ?>

					<section class="numra-col" id="numra-check">
						<?php $this->render_check_tab(); ?>
					</section>

					<?php if ( Numra_Settings::sync_consent_answered() ) : ?>
						<section class="numra-span" id="numra-sync">
							<?php $this->render_sync_panel(); ?>
						</section>
					<?php endif; ?>

					<section class="numra-span" id="numra-activity">
						<?php $this->render_activity_panel(); ?>
					</section>

					<?php
					/* Rendered only when there is something to read.
					   "No notices available at this time." is not an empty
					   state, it is a panel apologising for existing — and on a
					   dashboard the merchant opens daily it would be the
					   permanent condition, since notices are occasional by
					   design. A section that has nothing to say says nothing. */
					?>
					<?php if ( Numra_Settings::has_api_key() && ! empty( Numra_Announcements::get_placements() ) ) : ?>
						<section class="numra-span" id="numra-growth">
							<?php $this->render_growth_notices_tab(); ?>
						</section>
					<?php endif; ?>

				<?php endif; ?>

			<?php elseif ( 'api-key' === $active_tab ) : ?>

				<section class="numra-span" id="numra-advanced">
					<?php $this->render_api_key_tab(); ?>
				</section>

			<?php else : ?>

				<section class="numra-span" id="numra-protection">
					<?php $this->render_protection_tab(); ?>
				</section>

			<?php endif; ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Ask, once, before reading a single historical order.
	 *
	 * Shown when the store is connected and the question has never been
	 * answered. Not a modal: a modal would interrupt a merchant who has just
	 * connected and is looking at their status, and would make the decision
	 * feel like something to dismiss rather than something to read. Inline, at
	 * the top of the dashboard, it is the first thing on the page and it is
	 * still the merchant's page.
	 *
	 * The copy names what is read AND what is not. The second half is the part
	 * that earns the yes: "we never read names, addresses, or what was bought"
	 * is a smaller promise than merchants expect, and it is true — the sync
	 * sends a phone number, an order id, and an outcome.
	 */
	private function render_sync_consent_panel() {
		?>
		<div class="numra-card numra-consent">
			<h2><?php esc_html_e( 'Turn on store sync', 'numra-for-woocommerce' ); ?></h2>

			<p class="numra-consent-lead">
				<?php esc_html_e( 'Numra will read the phone numbers and delivery outcomes from your past orders to build your protection. It never reads names, addresses, or what was bought. This is what makes the network work.', 'numra-for-woocommerce' ); ?>
			</p>

			<ul class="numra-consent-list">
				<li class="is-yes">
					<?php $this->line_icon( 'tick' ); ?>
					<span><?php esc_html_e( 'The customer\'s phone number', 'numra-for-woocommerce' ); ?></span>
				</li>
				<li class="is-yes">
					<?php $this->line_icon( 'tick' ); ?>
					<span><?php esc_html_e( 'Whether the order was delivered, refused, cancelled or returned', 'numra-for-woocommerce' ); ?></span>
				</li>
				<li class="is-no">
					<?php $this->line_icon( 'cross' ); ?>
					<span><?php esc_html_e( 'Never names, addresses, emails, or what was in the order', 'numra-for-woocommerce' ); ?></span>
				</li>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'numra_sync_consent' ); ?>
				<input type="hidden" name="action" value="numra_sync_consent" />

				<label class="numra-consent-agree">
					<input type="checkbox" name="numra_sync_agree" value="1" required />
					<span><?php esc_html_e( 'I agree Numra may sync this store\'s order history, and I have the right to share it.', 'numra-for-woocommerce' ); ?></span>
				</label>

				<p class="numra-consent-actions">
					<button type="submit" class="button button-primary" name="numra_sync_choice" value="start">
						<?php esc_html_e( 'Turn on sync', 'numra-for-woocommerce' ); ?>
					</button>
					<?php /* formnovalidate, or the required checkbox above would
					         block a merchant from declining — the browser would
					         refuse to submit the form they are trying to say no
					         on. Declining must never be harder than agreeing. */ ?>
					<button type="submit" class="button button-secondary" name="numra_sync_choice" value="decline" formnovalidate>
						<?php esc_html_e( 'Not now', 'numra-for-woocommerce' ); ?>
					</button>
				</p>
			</form>

			<p class="numra-consent-fine">
				<?php esc_html_e( 'You can stop this at any time. Syncing uses no checks from your plan — it costs you nothing.', 'numra-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * What the sync is doing, and the control to stop it.
	 *
	 * A background job that reads customer data must be visible while it runs,
	 * not only in the screen that started it. A merchant who agreed a month ago
	 * and wants to check, or change their mind, should not have to remember how
	 * they turned it on.
	 *
	 * Laid out as a wide readout with the action on the right rather than as a
	 * card with a button underneath: the content is one fact and one control,
	 * and a tall card for that is the empty-panel problem this page has already
	 * been redesigned twice to avoid.
	 */
	private function render_sync_panel() {
		$state = Numra_Backfill::state();
		$count = Numra_Backfill::logged();
		$last  = Numra_Backfill::last_run();

		switch ( $state ) {
			case 'running':
				$title = __( 'Syncing your order history', 'numra-for-woocommerce' );
				$body  = $count > 0
					/* translators: %s: number of orders synced so far. */
					? sprintf( _n( '%s order synced so far. This runs quietly in the background.', '%s orders synced so far. This runs quietly in the background.', $count, 'numra-for-woocommerce' ), number_format_i18n( $count ) )
					: __( 'Starting shortly. This runs quietly in the background.', 'numra-for-woocommerce' );
				break;

			case 'complete':
				$title = __( 'Store sync complete', 'numra-for-woocommerce' );
				/* translators: %s: number of orders synced. */
				$body  = sprintf( _n( '%s order from your history is now part of your protection.', '%s orders from your history are now part of your protection.', $count, 'numra-for-woocommerce' ), number_format_i18n( $count ) );
				break;

			default: // 'declined'
				$title = __( 'Store sync is off', 'numra-for-woocommerce' );
				$body  = $count > 0
					/* translators: %s: number of orders synced before it was stopped. */
					? sprintf( __( 'Stopped after %s orders. Nothing further is read from this store.', 'numra-for-woocommerce' ), number_format_i18n( $count ) )
					: __( 'Your past orders stay on your store. Only new orders build your protection.', 'numra-for-woocommerce' );
				break;
		}
		?>
		<div class="numra-card numra-sync is-<?php echo esc_attr( $state ); ?>">
			<div class="numra-sync-text">
				<h2><?php echo esc_html( $title ); ?></h2>
				<p><?php echo esc_html( $body ); ?></p>
				<?php if ( 'running' === $state && $last > 0 ) : ?>
					<p class="numra-sync-last">
						<?php
						printf(
							/* translators: %s: human-readable time, e.g. "5 minutes". */
							esc_html__( 'Last batch %s ago.', 'numra-for-woocommerce' ),
							esc_html( human_time_diff( $last, time() ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( 'complete' !== $state ) : ?>
				<form class="numra-sync-action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'numra_sync_consent' ); ?>
					<input type="hidden" name="action" value="numra_sync_consent" />
					<?php if ( 'running' === $state ) : ?>
						<button type="submit" class="button button-secondary" name="numra_sync_choice" value="stop">
							<?php esc_html_e( 'Stop syncing', 'numra-for-woocommerce' ); ?>
						</button>
					<?php else : ?>
						<input type="hidden" name="numra_sync_agree" value="1" />
						<button type="submit" class="button button-primary" name="numra_sync_choice" value="start">
							<?php esc_html_e( 'Turn on sync', 'numra-for-woocommerce' ); ?>
						</button>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Recent activity — the panel that gives the page a reason to be revisited.
	 *
	 * The dashboard felt dead for a reason deeper than layout: nothing on it
	 * ever changed. Status, endpoint and a key are the same on every visit, so
	 * there was never anything to come back for, and a surface nobody returns
	 * to reads as empty whatever is done to its spacing.
	 *
	 * Reads order meta the guard already writes. No new API call, no new
	 * storage, no new failure mode — and it degrades to a teaching empty state
	 * before the first order rather than to a blank box.
	 */
	private function render_activity_panel() {
		$orders = array();

		if ( function_exists( 'wc_get_orders' ) ) {
			$found = wc_get_orders( array(
				'limit'      => 8,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_key'   => Numra_Order_Guard::META_CHECKED_AT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'return'     => 'objects',
			) );
			if ( is_array( $found ) ) {
				$orders = $found;
			}
		}
		?>
		<div class="numra-card">
			<h2><?php esc_html_e( 'Recent checks', 'numra-for-woocommerce' ); ?></h2>

			<?php if ( empty( $orders ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'Every order Numra checks appears here, newest first — what it scored, and whether it was held. Nothing has been checked on this store yet.', 'numra-for-woocommerce' ); ?>
				</p>
			<?php else : ?>
				<ul class="numra-activity">
					<?php foreach ( $orders as $o ) :
						if ( ! $o instanceof WC_Order ) { continue; }
						$lvl     = (string) $o->get_meta( Numra_Order_Guard::META_LEVEL );
						$rated   = 'no' !== (string) $o->get_meta( Numra_Order_Guard::META_RATED );
						$black   = 'yes' === (string) $o->get_meta( Numra_Order_Guard::META_BLACKLISTED );
						$flagged = 'yes' === (string) $o->get_meta( Numra_Order_Guard::META_FLAGGED );
						$score   = (string) $o->get_meta( Numra_Order_Guard::META_SCORE );

						if ( $black )        { $cls = 'is-blocked'; $txt = __( 'Blacklisted', 'numra-for-woocommerce' ); }
						elseif ( $flagged )  { $cls = 'is-high';    $txt = '' !== $lvl ? $lvl : __( 'Flagged', 'numra-for-woocommerce' ); }
						elseif ( ! $rated )  { $cls = 'is-new';     $txt = __( 'New', 'numra-for-woocommerce' ); }
						else                 { $cls = 'is-ok';      $txt = '' !== $score ? $score : __( 'Cleared', 'numra-for-woocommerce' ); }
						?>
						<li class="numra-activity-row">
							<a class="numra-activity-order" href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">
								#<?php echo esc_html( $o->get_order_number() ); ?>
							</a>
							<span class="numra-activity-phone"><?php echo esc_html( (string) $o->get_meta( Numra_Order_Guard::META_PHONE ) ); ?></span>
							<span class="numra-risk numra-risk--<?php echo esc_attr( str_replace( 'is-', '', $cls ) ); ?>"><?php echo esc_html( $txt ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Shared helpers ────────────────────────────────────────────────────────

	private function tab_link( $tab, $label, $active_tab ) {
		$url    = add_query_arg( array( 'page' => 'numra', 'tab' => $tab ), admin_url( 'admin.php' ) );
		$active = ( $tab === $active_tab ) ? 'numra-tab-active' : '';
		printf(
			'<a href="%s" class="numra-tab %s">%s</a>',
			esc_url( $url ),
			esc_attr( $active ),
			esc_html( $label )
		);
	}

	/**
	 * Render notices produced by the connection callback.
	 * One-shot transient mechanism: notice is set by callback, consumed (read + deleted)
	 * on first dashboard render, so refresh does not re-show it. Messages are translated at render time.
	 */
	private function render_connect_notices() {
		$notice = Numra_Connect::consume_notice();
		if ( null === $notice ) {
			return;
		}

		$type = isset( $notice['type'] ) ? $notice['type'] : 'info';
		$code = isset( $notice['code'] ) ? $notice['code'] : '';

		$class = 'numra-notice';
		$role  = 'status';
		if ( 'success' === $type ) {
			$class .= ' numra-notice-success';
		} elseif ( 'error' === $type ) {
			$class .= ' numra-notice-error';
			$role   = 'alert';
		}

		printf( '<div class="%s" role="%s">', esc_attr( $class ), esc_attr( $role ) );
		echo esc_html( Numra_Connect::error_message( $code ) );

		if ( 'error' === $type ) {
			if ( Numra_Connect::error_is_retryable( $code ) ) {
				printf(
					' <a href="%s">%s</a>',
					esc_url( Numra_Connect::connect_button_url() ),
					esc_html__( 'Try again', 'numra-for-woocommerce' )
				);
			} else {
				printf(
					' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( 'https://app.numra.ma/licenses' ),
					esc_html__( 'Open your Numra account', 'numra-for-woocommerce' )
				);
			}
		}
		echo '</div>';
	}

	/**
	 * Render stable connection status banners.
	 * Displays permanent alerts for Connection Lost or Temporarily Unavailable states.
	 */
	private function render_connection_status_notices() {
		$status = Numra_Settings::get_connection_status();
		
		if ( 'connection_lost' === $status ) {
			echo '<div class="numra-notice numra-notice-error" role="alert">';
			echo '<strong>' . esc_html__( 'Connection Lost:', 'numra-for-woocommerce' ) . '</strong> ';
			echo esc_html__( 'The Numra platform rejected your API key. This happens if the key was revoked, the domain was removed, or your license subscription is suspended/expired. Automatic scoring lookups have been paused.', 'numra-for-woocommerce' );
			echo '</div>';
		} elseif ( 'unavailable' === $status ) {
			echo '<div class="numra-notice numra-notice-warning" role="status">';
			echo '<strong>' . esc_html__( 'Temporarily Unavailable:', 'numra-for-woocommerce' ) . '</strong> ';
			echo esc_html__( 'Could not connect to the Numra servers due to a temporary network issue or server timeout. Your local credentials are preserved, and lookups will resume automatically once servers are back online.', 'numra-for-woocommerce' );
			echo '</div>';
		}
	}

	private function status_badge( $status ) {
		$badges = array(
			'connected'       => array( 'label' => __( 'Connected', 'numra-for-woocommerce' ),             'class' => 'numra-badge-success' ),
			'connection_lost' => array( 'label' => __( 'Connection Lost', 'numra-for-woocommerce' ),         'class' => 'numra-badge-error' ),
			'unavailable'     => array( 'label' => __( 'Temporarily Unavailable', 'numra-for-woocommerce' ), 'class' => 'numra-badge-warning' ),
			'disconnected'    => array( 'label' => __( 'Disconnected', 'numra-for-woocommerce' ),          'class' => 'numra-badge-neutral' ),
			'unknown'         => array( 'label' => __( 'Disconnected', 'numra-for-woocommerce' ),          'class' => 'numra-badge-neutral' ),
		);
		$b = isset( $badges[ $status ] ) ? $badges[ $status ] : $badges['unknown'];

		// If key exists but status is unknown, it's saved but unverified
		if ( ( 'unknown' === $status || 'disconnected' === $status ) && Numra_Settings::has_api_key() ) {
			$b = array( 'label' => __( 'Saved (Unverified)', 'numra-for-woocommerce' ), 'class' => 'numra-badge-neutral' );
		}

		printf( '<span class="numra-badge %s">%s</span>', esc_attr( $b['class'] ), esc_html( $b['label'] ) );
	}

	// ── Dashboard tab ─────────────────────────────────────────────────────────

	private function render_dashboard_tab() {
		$status     = Numra_Settings::get_connection_status();
		$last_check = Numra_Settings::get_last_connection_check();
		$has_key    = Numra_Settings::has_api_key();
		$base_url   = Numra_Settings::get_api_base_url();
		$connected  = Numra_Settings::is_connected();

		$this->render_connect_notices();
		$this->render_connection_status_notices();

		/* ── Not connected yet ────────────────────────────────────────────────
		   This state used to open with a three-row diagnostic table — Status:
		   Disconnected, API Endpoint, API Key: Not set — which is the same fact
		   stated three times, in the most valuable space on the screen, above
		   the only thing the merchant can actually do. The endpoint is our
		   infrastructure detail and means nothing to a shop owner who has not
		   connected anything yet.

		   A store with nothing set up has no status worth tabulating. It has one
		   job. So that state is an activation surface: what the product will do,
		   the single action, and what happens after — real product behaviour,
		   not filler to fill a viewport.

		   Gated on has_api_key as well as is_connected, and the difference
		   matters: a merchant who pasted a key by hand is has_api_key but NOT
		   is_connected (Numra_Settings::is_connected() requires a connection
		   record too). Keying this on $connected alone would meet them with
		   "Start protecting this store — Connect to Numra" the moment after
		   they saved a key, and hide the status table that would have told them
		   whether the key they just pasted actually works. They have a setup to
		   inspect; they get the table. */
		if ( ! $connected && ! $has_key ) {
			$this->render_activation_panel( $base_url );
			return;
		}
		?>
		<?php
		/* ── The status panel ───────────────────────────────────────────────
		   This was seven label/value rows: status, endpoint, key, last check,
		   plan, account, store URL, daily limit. Every one of them is a
		   constant. Nothing on it changed between visits, which is why the
		   dashboard read as dead — and the merchant's actual questions were
		   not among them:

		     how many checks have I got left today?
		     is this thing working right now?

		   The answers were already on the machine. The heartbeat has stored
		   usage and licence every fifteen minutes for as long as this class
		   has existed; nothing rendered them. So the panel leads with the
		   number that moves, and the identifiers that never move drop to a
		   footer line where they can still be read but do not take the top of
		   the card. */
		$usage    = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::usage() : null;
		$hb_lic   = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::license() : null;
		$beat_at  = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::last_beat_at() : 0;
		$plan     = $hb_lic && ! empty( $hb_lic['plan'] )
			? $hb_lic['plan']
			: Numra_Settings::get_connection_value( 'license.plan', '' );

		/* Health is what the LAST BEAT says, plus how long ago it was. A store
		   whose cron stopped a week ago still holds a cheerful "connected"
		   status flag, because nothing has contradicted it — silence is
		   exactly what a dead cron and a healthy quiet store have in common.
		   Three beats' grace before saying so. */
		$beat_age  = $beat_at ? ( time() - $beat_at ) : null;
		$beat_late = ( null !== $beat_age && $beat_age > ( 45 * MINUTE_IN_SECONDS ) );
		?>
		<div class="numra-card">
			<h2><?php esc_html_e( 'This store', 'numra-for-woocommerce' ); ?></h2>

			<?php if ( $connected && $usage ) : ?>
				<div class="numra-meter">
					<div class="numra-meter-head">
						<span class="numra-meter-value">
							<?php
							if ( $usage['left'] < 0 ) {
								esc_html_e( 'Unlimited', 'numra-for-woocommerce' );
							} else {
								echo esc_html( number_format_i18n( $usage['left'] ) );
							}
							?>
						</span>
						<span class="numra-meter-label">
							<?php
							if ( $usage['left'] < 0 ) {
								esc_html_e( 'checks on your plan', 'numra-for-woocommerce' );
							} else {
								esc_html_e( 'checks left today', 'numra-for-woocommerce' );
							}
							?>
						</span>
					</div>

					<?php if ( $usage['limit'] > 0 ) : ?>
						<?php
						/* Amber at 75%, red at 90%. Not a gradient: a bar that
						   is always slightly orange teaches the merchant that
						   orange means nothing. */
						$tone = $usage['percent'] >= 90 ? 'is-critical'
							: ( $usage['percent'] >= 75 ? 'is-warn' : '' );
						?>
						<div class="numra-meter-track <?php echo esc_attr( $tone ); ?>">
							<div class="numra-meter-fill" style="width:<?php echo esc_attr( max( 2, $usage['percent'] ) ); ?>%"></div>
						</div>
						<p class="numra-meter-foot">
							<?php
							printf(
								/* translators: 1: checks used today, 2: daily limit. */
								esc_html__( '%1$s of %2$s used today', 'numra-for-woocommerce' ),
								esc_html( number_format_i18n( $usage['used'] ) ),
								esc_html( number_format_i18n( $usage['limit'] ) )
							);
							?>
							<?php if ( $usage['percent'] >= 75 ) : ?>
								· <a href="<?php echo esc_url( Numra_Connect::portal_url( '/billing' ) ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'Upgrade', 'numra-for-woocommerce' ); ?>
								</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<ul class="numra-health">
				<li>
					<span class="numra-health-k"><?php esc_html_e( 'Connection', 'numra-for-woocommerce' ); ?></span>
					<span class="numra-health-v"><?php $this->status_badge( $status ); ?></span>
				</li>
				<li>
					<span class="numra-health-k"><?php esc_html_e( 'Last contact', 'numra-for-woocommerce' ); ?></span>
					<span class="numra-health-v <?php echo $beat_late ? 'is-warn' : ''; ?>">
						<?php if ( ! $beat_at ) : ?>
							<?php esc_html_e( 'not yet', 'numra-for-woocommerce' ); ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: human-readable duration, e.g. "12 mins". */
								esc_html__( '%s ago', 'numra-for-woocommerce' ),
								esc_html( human_time_diff( $beat_at, time() ) )
							);
							?>
							<?php if ( $beat_late ) : ?>
								<?php esc_html_e( '— WP-Cron may be stalled', 'numra-for-woocommerce' ); ?>
							<?php endif; ?>
						<?php endif; ?>
					</span>
				</li>
				<?php if ( $plan ) : ?>
				<li>
					<span class="numra-health-k"><?php esc_html_e( 'Plan', 'numra-for-woocommerce' ); ?></span>
					<span class="numra-health-v"><?php echo esc_html( $plan ); ?></span>
				</li>
				<?php endif; ?>
				<li>
					<span class="numra-health-k"><?php esc_html_e( 'Automatic checks', 'numra-for-woocommerce' ); ?></span>
					<span class="numra-health-v">
						<?php echo Numra_Settings::is_guard_enabled()
							? esc_html__( 'On — every new order', 'numra-for-woocommerce' )
							: esc_html__( 'Off — you reveal each order', 'numra-for-woocommerce' ); ?>
					</span>
				</li>
			</ul>

			<?php /* The constants. Still here, because a support ticket needs
			         them, but no longer the first thing on the card. */ ?>
			<p class="numra-idline">
				<?php echo esc_html( Numra_Settings::get_connection_value( 'connected_site_url', get_site_url() ) ); ?>
				· <?php echo esc_html( $base_url ); ?>
				<?php if ( $has_key ) : ?>
					· <?php esc_html_e( 'key saved', 'numra-for-woocommerce' ); ?>
				<?php endif; ?>
			</p>

			<?php if ( $connected ) : ?>
				<p class="numra-disconnect-row">
					<a href="<?php echo esc_url( Numra_Connect::disconnect_button_url() ); ?>"
					   class="button button-secondary numra-disconnect-button">
						<?php esc_html_e( 'Disconnect this store', 'numra-for-woocommerce' ); ?>
					</a>
				</p>
			<?php else : ?>
				<?php /* A key is saved but there is no connection record — the
				         manual-paste path, or a connection that was lost. The
				         action here is to finish connecting, not to disconnect
				         from something this store is not attached to. */ ?>
				<p class="numra-disconnect-row">
					<a href="<?php echo esc_url( Numra_Connect::connect_button_url() ); ?>"
					   class="button button-primary numra-connect-button">
						<?php esc_html_e( 'Connect to Numra', 'numra-for-woocommerce' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<?php
		/* The second Growth Notices card that used to sit here is gone. The
		   dashboard already renders one as its own section, so a connected
		   store was drawing the same announcements twice on one screen — once
		   inside the status card and once below it. */
	}

	/**
	 * A 20px line icon, drawn rather than typed.
	 *
	 * The old empty-state reached for a 38px coloured glyph and ended up
	 * shipping an empty <div> when the emoji was removed — a blank 38px band
	 * above the button, in #10B981, a green this panel's own stylesheet says is
	 * not a Numra colour. Icons here are authored SVG on a single 1.6 stroke,
	 * inheriting currentColor, so they cannot drift from the type they sit next
	 * to and cannot leave a hole behind when one is dropped.
	 *
	 * @param string $name shield|scan|loop|tick|cross
	 */
	private function line_icon( $name ) {
		$paths = array(
			// Shield — protection.
			'shield' => '<path d="M10 2.5 3.75 5v4.4c0 3.6 2.5 6.6 6.25 8.1 3.75-1.5 6.25-4.5 6.25-8.1V5L10 2.5Z"/>',
			// Magnifier over a line — the check itself.
			'scan'   => '<circle cx="9" cy="9" r="4.75"/><path d="m12.6 12.6 4.15 4.15"/>',
			// Arrow returning — the outcome you send back.
			'loop'   => '<path d="M16.5 10a6.5 6.5 0 1 1-1.9-4.6"/><path d="M16.9 2.9v3.4h-3.4"/>',
			// What the sync reads.
			'tick'   => '<path d="m4.5 10.5 3.6 3.6 7.4-8.2"/>',
			// What it never reads. Drawn, not a × character, so it carries the
			// same 1.6 stroke as everything else on the line.
			'cross'  => '<path d="m5.5 5.5 9 9"/><path d="m14.5 5.5-9 9"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return;
		}
		printf(
			'<svg class="numra-step-icon" viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
			$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup above.
		);
	}

	/**
	 * The not-connected state.
	 *
	 * One heading that says what the product does, one action, and the three
	 * things that happen after — each of which is real behaviour this plugin
	 * already implements, not copy written to fill the page. The numbers are
	 * kept because the order genuinely matters: nothing is checked until the
	 * store is connected, and nothing improves until outcomes come back.
	 */
	private function render_activation_panel( $base_url ) {
		$steps = array(
			array(
				'icon'  => 'shield',
				'title' => __( 'Sign in and pick a licence', 'numra-for-woocommerce' ),
				'body'  => __( 'Numra opens in a new tab. Choose the licence this store should use — there is no key to copy or paste.', 'numra-for-woocommerce' ),
			),
			array(
				'icon'  => 'scan',
				'title' => __( 'Every order gets checked', 'numra-for-woocommerce' ),
				'body'  => __( 'New orders are scored the moment they are placed. Risky numbers are held for your review before anything is dispatched.', 'numra-for-woocommerce' ),
			),
			array(
				'icon'  => 'loop',
				'title' => __( 'Tell Numra how it ended', 'numra-for-woocommerce' ),
				'body'  => __( 'Delivered, refused, no answer. Each outcome sharpens the score every other merchant sees — and the one you see next time.', 'numra-for-woocommerce' ),
			),
		);
		?>
		<div class="numra-card numra-activate">
			<div class="numra-activate-body">
			<div class="numra-activate-head">
				<h2 class="numra-activate-title">
					<?php esc_html_e( 'Start protecting this store', 'numra-for-woocommerce' ); ?>
				</h2>
				<p class="numra-activate-lead">
					<?php esc_html_e( 'Numra checks the phone number on every cash-on-delivery order against a network built by Moroccan merchants, so you find out about a bad number before you pay to ship to it.', 'numra-for-woocommerce' ); ?>
				</p>

				<div class="numra-activate-actions">
					<a href="<?php echo esc_url( Numra_Connect::connect_button_url() ); ?>"
					   class="button button-primary numra-connect-button">
						<?php esc_html_e( 'Connect to Numra', 'numra-for-woocommerce' ); ?>
					</a>
					<span class="numra-activate-site">
						<?php esc_html_e( 'Connecting', 'numra-for-woocommerce' ); ?>
						<code><?php echo esc_html( home_url() ); ?></code>
					</span>
				</div>

				<p class="numra-activate-alt">
					<?php
					printf(
						wp_kses( __( 'Already have a key? <a href="%s">Paste it on the API Key tab</a>.', 'numra-for-woocommerce' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( add_query_arg( array( 'page' => 'numra', 'tab' => 'api-key' ), admin_url( 'admin.php' ) ) )
					);
					?>
				</p>
			</div>

			<ol class="numra-steps">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li class="numra-step">
						<span class="numra-step-mark"><?php $this->line_icon( $step['icon'] ); ?></span>
						<h3 class="numra-step-title"><?php echo esc_html( $step['title'] ); ?></h3>
						<span class="numra-step-n"><?php echo esc_html( number_format_i18n( $i + 1 ) ); ?></span>
						<p class="numra-step-body"><?php echo esc_html( $step['body'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ol>
			</div>

			<p class="numra-activate-foot">
				<?php
				printf(
					/* translators: %s: the Numra API hostname this store will talk to. */
					esc_html__( 'This store will talk to %s. Nothing is sent until you connect.', 'numra-for-woocommerce' ),
					'<code>' . esc_html( $base_url ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Check a number tab ────────────────────────────────────────────────────

	/**
	 * Look a number up by hand, without an order.
	 *
	 * The merchant takes calls and WhatsApp messages long before an order
	 * exists. Until now the only way to score a number was to let it become an
	 * order first, which is exactly backwards — by then the decision has been
	 * made. The remaining-credit figure sits next to the button because every
	 * press of it costs one, and a tool that spends your plan silently is a
	 * tool people stop trusting.
	 */
	private function render_check_tab() {
		$connected = Numra_Settings::is_connected();
		$stored    = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::get_state() : array();
		$usage     = isset( $stored['usage'] ) && is_array( $stored['usage'] ) ? $stored['usage'] : array();
		$used      = isset( $usage['today'] ) ? (int) $usage['today'] : 0;
		$limit     = isset( $usage['daily_limit'] ) ? (int) $usage['daily_limit'] : 0;
		$remaining = $limit > 0 ? max( 0, $limit - $used ) : null;
		$protected = ! class_exists( 'Numra_Heartbeat' ) || Numra_Heartbeat::protection_enabled();
		?>
		<div class="numra-card">
			<h2><?php esc_html_e( 'Check a phone number', 'numra-for-woocommerce' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Score a number before it becomes an order — when a customer calls, messages, or you are about to dispatch by hand.', 'numra-for-woocommerce' ); ?>
			</p>

			<?php if ( ! $connected || ! $protected ) : ?>
				<div class="numra-notice numra-notice-warning" style="margin-top:14px">
					<?php esc_html_e( 'This store is not connected to Numra, so numbers cannot be checked yet.', 'numra-for-woocommerce' ); ?>
				</div>
			<?php else : ?>
				<?php if ( null !== $remaining ) : ?>
					<div class="numra-credits">
						<span class="numra-credits-num"><?php echo esc_html( number_format_i18n( $remaining ) ); ?></span>
						<span class="numra-credits-label">
							<?php
							printf(
								/* translators: %s: the plan's daily check limit. */
								esc_html__( 'checks left today, of %s', 'numra-for-woocommerce' ),
								esc_html( number_format_i18n( $limit ) )
							);
							?>
						</span>
					</div>
				<?php endif; ?>

				<div class="numra-check-row">
					<label for="numra-check-phone" class="screen-reader-text"><?php esc_html_e( 'Phone number', 'numra-for-woocommerce' ); ?></label>
					<input type="tel" id="numra-check-phone" class="regular-text" placeholder="+212 6XX XXX XXX" autocomplete="off" />
					<button type="button" class="button button-primary" id="numra-check-run">
						<?php esc_html_e( 'Check number', 'numra-for-woocommerce' ); ?>
					</button>
				</div>
				<p class="description"><?php esc_html_e( 'Uses one check from your plan. A number nobody has reported comes back neutral — that is a clean record, not a missing one.', 'numra-for-woocommerce' ); ?></p>

				<div id="numra-check-result" class="numra-check-result" role="status" aria-live="polite"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Order Protection tab ──────────────────────────────────────────────────

	/**
	 * The controls for what the plugin actually does to orders.
	 *
	 * Every field is inside one form carrying numra_protection_submitted, which
	 * Numra_Settings::handle_save() requires before it touches any of these
	 * options — otherwise saving a different tab would post no checkboxes and
	 * silently switch the whole feature off.
	 */
	private function render_protection_tab() {
		$threshold   = Numra_Settings::get_risk_threshold();
		$status_map  = Numra_Settings::get_status_map();
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$outcomes    = Numra_API_Client::OUTCOME_TYPES;
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'numra_save_settings', 'numra_settings_nonce' ); ?>
			<input type="hidden" name="numra_protection_submitted" value="1" />

			<?php
			/* No heading here. The tab above says "Order protection"; repeating
			   it as the first line of the panel is the label twice and the
			   information once. */
			?>
			<p class="description">
				<?php esc_html_e( 'Every order is logged to the network for free. Checking a number costs one credit, so it only happens when you ask — either by turning on automatic checks below, or by unlocking an order yourself. These rules apply the moment a number is checked, however that happened.', 'numra-for-woocommerce' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Check new orders automatically', 'numra-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="numra_guard_enabled" value="1" <?php checked( Numra_Settings::is_guard_enabled() ); ?> />
							<?php esc_html_e( 'Spend a check on every new order, without asking', 'numra-for-woocommerce' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default, because a check is your money. Left off, every new order still appears in the Risk column — the score is simply hidden until you choose to reveal it, one order at a time. Turn this on for a high-volume store where reviewing each order by hand is not realistic.', 'numra-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Hold cash on delivery only', 'numra-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="numra_cod_only" value="1" <?php checked( Numra_Settings::is_cod_only() ); ?> />
							<?php esc_html_e( 'Only put cash-on-delivery orders on hold', 'numra-for-woocommerce' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Every order is checked either way — that is what keeps the network accurate. This decides only whether a prepaid order can be held, and it normally should not be: the customer has already paid, so holding recovers nothing.', 'numra-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Flag from', 'numra-for-woocommerce' ); ?></th>
					<td>
						<?php
						/* Bands, not a number.
						   ─────────────────────────────────────────────────
						   This was a 1-100 spinner defaulting to 70, which
						   asked the merchant to hold two things in their head
						   that are ours, not theirs: that the scale runs to
						   100, and that 70 happens to be where our HIGH band
						   starts. Nobody outside this codebase knows that.

						   The API already returns the band it decided —
						   risk_level, resolved once in phone_verdict — so the
						   setting now names the same bands the control panel
						   and the order screen show. One vocabulary end to
						   end, and the choice reads as a decision about
						   customers rather than a number to guess at. */
						$sel = Numra_Settings::get_risk_level_threshold();
						?>
						<div class="numra-bands">
						<?php
						foreach ( Numra_Settings::risk_level_choices() as $code => $c ) :
							?>
							<label class="numra-band">
								<input type="radio" name="numra_risk_level" value="<?php echo esc_attr( $code ); ?>" <?php checked( $sel, $code ); ?> />
								<span class="numra-band-text">
									<span class="numra-band-name">
										<?php echo esc_html( $c['label'] ); ?>
										<?php if ( ! empty( $c['recommended'] ) ) : ?>
											<em class="numra-band-rec"><?php esc_html_e( 'recommended', 'numra-for-woocommerce' ); ?></em>
										<?php endif; ?>
									</span>
									<span class="numra-band-desc"><?php echo esc_html( $c['desc'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'This band decides nothing until a number is actually checked. With automatic checks off, that is the moment you reveal an order — the flag, and any hold, are applied there and then. Nothing is flagged behind your back, and no order is checked that you did not pay for.', 'numra-for-woocommerce' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'A blacklisted number is always flagged, whichever band you pick. Numbers Numra has never seen are never flagged on their own — there is nothing to judge them on yet.', 'numra-for-woocommerce' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Also hold these customer types', 'numra-for-woocommerce' ); ?></th>
					<td>
						<?php
						/* Behaviour, not score.
						   ─────────────────────────────────────────────────
						   A band answers "how badly does this customer
						   score". It cannot answer "I do not ship to people
						   who never pick up the phone" — a never-answers
						   buyer and a refuses-at-the-door buyer can land in
						   the same band and want opposite handling.

						   The list comes from the platform on the heartbeat,
						   not from a constant in this file: an admin renames
						   or adds a type in the control panel and every store
						   sees it within fifteen minutes, with no plugin
						   update. */
						$all_styles  = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::styles() : array();
						$flag_styles = Numra_Settings::get_flag_styles();
						?>

						<?php /* The marker that makes "none selected" savable.
						         Unticking every box posts no numra_flag_styles
						         key at all, so without this the save handler
						         cannot tell "cleared" from "not this form". */ ?>
						<input type="hidden" name="numra_flag_styles_submitted" value="1" />

						<?php if ( ! $all_styles ) : ?>
							<p class="description">
								<?php if ( Numra_Settings::is_connected() ) : ?>
									<?php esc_html_e( 'Customer types will appear here after the next sync with Numra, within about fifteen minutes of connecting.', 'numra-for-woocommerce' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Connect this store to Numra to choose customer types.', 'numra-for-woocommerce' ); ?>
								<?php endif; ?>
							</p>
						<?php else : ?>
							<div class="numra-styles">
								<?php foreach ( $all_styles as $style ) : ?>
									<label class="numra-style">
										<input
											type="checkbox"
											name="numra_flag_styles[]"
											value="<?php echo esc_attr( $style['code'] ); ?>"
											<?php checked( in_array( $style['code'], $flag_styles, true ) ); ?> />
										<span class="numra-style-text">
											<span class="numra-style-name"><?php echo esc_html( $style['label'] ); ?></span>
											<?php if ( ! empty( $style['description'] ) ) : ?>
												<span class="numra-style-desc"><?php echo esc_html( $style['description'] ); ?></span>
											<?php endif; ?>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description">
								<?php esc_html_e( 'Optional. A customer type is only assigned once Numra has seen enough of that number to recognise a pattern, so this never catches a first-time buyer. Leave everything unticked to decide on the band alone.', 'numra-for-woocommerce' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Hold flagged orders', 'numra-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="numra_autohold_enabled" value="1" <?php checked( Numra_Settings::is_autohold_enabled() ); ?> />
							<?php esc_html_e( 'Put flagged orders On hold for review', 'numra-for-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Applied once per order. If you move a held order back yourself, Numra will not hold it again.', 'numra-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			/* The "Past orders" and "Delivery outcomes" blocks used to sit here:
			   a toggle for outcome reporting, a status-mapping table, and a
			   backfill switch with a progress counter.

			   All of it is gone, because none of it was the merchant's decision to
			   make. Reporting what happened to an order is how the network stays
			   accurate for every store on it, including this one; it costs the
			   merchant nothing and asking them to configure it invited them to
			   switch it off. It now runs automatically, always, with no controls
			   and no progress bar to watch — quiet by design.

			   The status map still exists in code (Numra_Settings::get_status_map)
			   and still honours a filter for the rare store with custom statuses.
			   It simply has no UI. The one place a merchant is asked about an
			   outcome is on the order itself, once it has finished, where they
			   actually know the answer. */
			?>

			<?php submit_button( __( 'Save changes', 'numra-for-woocommerce' ) ); ?>
		</form>
		<?php
	}

	// ── API Key tab ───────────────────────────────────────────────────────────

	private function render_api_key_tab() {
		$base_url = Numra_Settings::get_api_base_url();
		$has_key  = Numra_Settings::has_api_key();
		$status   = Numra_Settings::get_connection_status();

		if ( isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) {
			echo '<div class="numra-notice numra-notice-success">'
				. esc_html__( 'Settings saved.', 'numra-for-woocommerce' )
				. '</div>';
		}
		
		$this->render_connection_status_notices();
		?>
		<div class="numra-card">
			<h2><?php esc_html_e( 'Connection details', 'numra-for-woocommerce' ); ?></h2>

			<form method="post" action="">
				<?php wp_nonce_field( 'numra_save_settings', 'numra_settings_nonce' ); ?>

				<table class="form-table numra-form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'API Endpoint', 'numra-for-woocommerce' ); ?>
						</th>
						<td>
							<code><?php echo esc_html( $base_url ); ?></code>
							<p class="description"><?php esc_html_e( 'All requests go to the Numra production API. This is not editable.', 'numra-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="numra_api_key"><?php esc_html_e( 'API Key', 'numra-for-woocommerce' ); ?></label>
						</th>
						<td>
							<?php /* API key value is NEVER printed in the page source */ ?>
							<input type="password"
							       id="numra_api_key"
							       name="numra_api_key"
							       value=""
							       class="regular-text"
							       autocomplete="new-password"
							       placeholder="<?php echo $has_key ? esc_attr__( '(key saved — enter new key to change)', 'numra-for-woocommerce' ) : esc_attr__( 'numra_live_xxxxxxxxxxxxxxxxxxxxxxxx', 'numra-for-woocommerce' ); ?>" />
							<?php if ( $has_key ) : ?>
								<p class="description numra-key-saved" style="margin-top: 10px;">
									<?php if ( 'connected' === $status ) : ?>
										<span class="numra-badge numra-badge-success"><?php esc_html_e( 'API key is verified and active.', 'numra-for-woocommerce' ); ?></span>
									<?php elseif ( 'connection_lost' === $status ) : ?>
										<span class="numra-badge numra-badge-error"><?php esc_html_e( 'API key is invalid or has been revoked.', 'numra-for-woocommerce' ); ?></span>
									<?php elseif ( 'unavailable' === $status ) : ?>
										<span class="numra-badge numra-badge-warning"><?php esc_html_e( 'API key is saved but servers are unreachable.', 'numra-for-woocommerce' ); ?></span>
									<?php else : ?>
										<span class="numra-badge numra-badge-neutral"><?php esc_html_e( 'API key is saved (unverified).', 'numra-for-woocommerce' ); ?></span>
									<?php endif; ?>
									<span style="margin-left: 8px; color: #64748B;"><?php esc_html_e( 'Leave blank to keep the existing key.', 'numra-for-woocommerce' ); ?></span>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="numra_debug_enabled"><?php esc_html_e( 'Debug Logging', 'numra-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input type="checkbox"
							       id="numra_debug_enabled"
							       name="numra_debug_enabled"
							       value="1"
							       <?php checked( Numra_Settings::is_debug_enabled() ); ?> />
							<label for="numra_debug_enabled">
								<?php esc_html_e( 'Enable debug logging to WP_DEBUG_LOG', 'numra-for-woocommerce' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'numra-for-woocommerce' ); ?>
					</button>
					<?php if ( $has_key ) : ?>
					<button type="button" id="numra-test-connection" class="button button-secondary" style="margin-left: 8px;">
						<?php esc_html_e( 'Test Connection', 'numra-for-woocommerce' ); ?>
					</button>
					<span id="numra-test-result" style="margin-left: 12px; font-weight: 600;"></span>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	// ── Growth Notices tab ────────────────────────────────────────────────────

	private function render_growth_notices_tab() {
		?>
		<div class="numra-card">
			<h2><?php esc_html_e( 'Growth Notices', 'numra-for-woocommerce' ); ?></h2>
			<p class="description" style="margin-bottom: 16px;">
				<?php esc_html_e( 'Personalized notices from Numra — upgrades, announcements, and tips for your store.', 'numra-for-woocommerce' ); ?>
			</p>

			<?php if ( ! Numra_Settings::has_api_key() ) : ?>
				<div class="numra-notice numra-notice-warning">
					<?php printf(
						wp_kses( __( 'Set your API key on the <a href="%s">API Key tab</a> to see personalized notices.', 'numra-for-woocommerce' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( add_query_arg( array( 'page' => 'numra', 'tab' => 'api-key' ), admin_url( 'admin.php' ) ) )
					); ?>
				</div>
			<?php else : ?>
				<?php Numra_Announcements::render(); ?>
				<?php if ( empty( Numra_Announcements::get_placements() ) ) : ?>
					<p class="numra-empty"><?php esc_html_e( 'No notices available at this time.', 'numra-for-woocommerce' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
