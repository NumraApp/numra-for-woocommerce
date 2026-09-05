<?php
/**
 * Plugin Name:       Numra for WooCommerce
 * Plugin URI:        https://numra.ma
 * Description:       Protect your WooCommerce store from COD fraud. Connect to your Numra account to score orders, verify phone numbers, and reduce failed deliveries.
 * Version:           1.18.0
 * Author:            Numra
 * Author URI:        https://numra.ma
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       numra-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'NUMRA_VERSION',     '1.18.0' );
define( 'NUMRA_PLUGIN_FILE', __FILE__ );
define( 'NUMRA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'NUMRA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'NUMRA_API_BASE',    'https://api.numra.ma' );

/**
 * Version of the merchant's STORED data, not of the plugin.
 *
 * Bumped only when an upgrade routine has to touch the database. The value
 * lives in the numra_schema_version option so the routine runs once per site
 * rather than on every request — see numra_migrate_legacy_options().
 *
 * 1 = pre-rename (dpd_* namespace). 2 = numra_* namespace.
 */
define( 'NUMRA_SCHEMA_VERSION', 2 );

/**
 * The platform this plugin integrates with.
 *
 * Declared once so the literal 'woocommerce' appears exactly once in the
 * codebase. Future platform plugins (YouCan, Shopify, ...) change this line
 * and nothing else in the connection flow. Prefixed NUMRA_* to match the
 * plugin's constant convention and avoid collisions in the global namespace.
 */
define( 'NUMRA_PLATFORM',    'woocommerce' );

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
	$prefix = 'Numra_';
	$map    = array(
		'Numra_API_Client'    => 'includes/class-numra-api-client.php',
		'Numra_Admin_Menu'    => 'includes/class-numra-admin-menu.php',
		'Numra_Settings'      => 'includes/class-numra-settings.php',
		// The Growth Center class was renamed to Numra_Announcements, but the
		// map and three call sites kept the old name — and the old file never
		// existed, so the autoloader's require_once fataled on disconnect and
		// on API-key change. Announcements was meanwhile used statically in
		// four places while absent from this map, fataling the settings page.
		// One correct entry fixes both.
		'Numra_Announcements' => 'includes/class-numra-announcements.php',
		'Numra_Logger'        => 'includes/class-numra-logger.php',
		'Numra_Connect'       => 'includes/class-numra-connect.php',
		'Numra_Updater'       => 'includes/class-numra-updater.php',
		'Numra_Order_Guard'      => 'includes/class-numra-order-guard.php',
		'Numra_Outcome_Reporter' => 'includes/class-numra-outcome-reporter.php',
		'Numra_Order_UI'         => 'includes/class-numra-order-ui.php',
		'Numra_Heartbeat'        => 'includes/class-numra-heartbeat.php',
		'Numra_Alerts'           => 'includes/class-numra-alerts.php',
		'Numra_Release_Notice'   => 'includes/class-numra-release-notice.php',
		'Numra_Dashboard_Widget' => 'includes/class-numra-dashboard-widget.php',
		'Numra_Backfill'         => 'includes/class-numra-backfill.php',
	);
	if ( isset( $map[ $class ] ) ) {
		require_once NUMRA_PLUGIN_DIR . $map[ $class ];
	}
} );

// ── Activation / deactivation hooks ──────────────────────────────────────────
register_activation_hook( __FILE__, 'numra_activate' );
register_deactivation_hook( __FILE__, 'numra_deactivate' );

function numra_activate() {
	// Set default option values on activation.
	// numra_api_base_url is legacy and deliberately not seeded: the API base
	// is pinned to NUMRA_API_BASE and any stored option value is ignored.
	//
	// autoload=false: all Numra options are admin-only; loading them on every
	// frontend request wastes a cache slot and a DB column scan. WordPress
	// normalises boolean false on all supported versions (5.8+).
	// Existing installations already have these rows with autoload='yes' — no
	// migration is attempted (altering autoload on live rows requires a direct
	// DB write outside the Options API and is not worth the risk). The residual
	// effect is one extra option in the autoload set for already-installed sites;
	// new installations from this version onward will have autoload=false.
	numra_migrate_legacy_options();

	add_option( 'numra_api_key',               '',        '', false );
	add_option( 'numra_connection_status',     'disconnected', '', false );
	add_option( 'numra_last_connection_check', '',        '', false );
	add_option( 'numra_debug_enabled',         '0',       '', false );

	// Order protection. Seeded ON so a merchant who connects and touches
	// nothing else still gets the product. add_option() never overwrites, so
	// a store that has already chosen its settings keeps them across updates.
	/* Automatic scoring is OPT-IN. A credit is the merchant's money, and
	   spending it on every order without being asked is not ours to decide.
	   The default flow is: the order arrives, the risk data is shown as
	   present-but-hidden, and the merchant chooses to reveal it. */
	add_option( 'numra_guard_enabled',   '0', '', false );
	add_option( 'numra_autohold_enabled','1', '', false );
	add_option( 'numra_cod_only',        '1', '', false );
	add_option( 'numra_outcome_enabled', '1', '', false );
	add_option( 'numra_risk_threshold',  70,  '', false );
	add_option( 'numra_status_map',      array(), '', false );

	/* Backfill of existing orders, on by default. A store arrives with
	   history the network has never seen; logging only new orders means the
	   product knows nothing about this merchant's customers for weeks. It is
	   throttled to half the daily plan and stops on its own when finished. */
	add_option( 'numra_backfill_enabled', '1', '', false );

	/* Start the 15-minute state check. Without it a store only discovers a
	   revoked key or a lapsed subscription by failing a real order lookup. */
	Numra_Heartbeat::schedule();
}

/**
 * Carry settings across the DPD → Numra rename.
 *
 * The option namespace moved from dpd_* to numra_*. Without this, a store that
 * upgrades loses its saved API key and silently drops to "disconnected" — the
 * merchant sees a working plugin stop working, with no error and nothing in the
 * log to explain it. That is the single worst outcome available from a rename,
 * so it is handled here rather than left to a support ticket.
 *
 * Runs on activation AND on plugins_loaded, because a plugin updated in place
 * through the WordPress updater does not re-fire the activation hook.
 *
 * GUARDED BY numra_schema_version. Before the guard this function issued seven
 * get_option() calls on every single request, forever, on every install —
 * including the overwhelming majority that never had a dpd_* row to begin
 * with. It now reads one option and returns. The guard is a stored schema
 * version rather than a "did I run" flag so a future migration can be added by
 * bumping NUMRA_SCHEMA_VERSION and appending a step.
 *
 * A fresh install has no version row, so the routine runs once, finds nothing,
 * and stamps the current version — which is the correct outcome: there is
 * nothing to carry and it must never look again.
 *
 * Two concurrent requests can both pass the guard. Every step below is
 * idempotent (add_option never overwrites, the meta rename matches on the old
 * key that the first run already removed, delete is a no-op on a missing row),
 * so a double run costs a few queries and changes nothing.
 */
function numra_migrate_legacy_options() {
	if ( (int) get_option( 'numra_schema_version', 0 ) >= NUMRA_SCHEMA_VERSION ) {
		return;
	}

	numra_migrate_legacy_option_rows();
	numra_migrate_legacy_order_meta();
	numra_migrate_legacy_transients();
	numra_migrate_legacy_cron();

	update_option( 'numra_schema_version', NUMRA_SCHEMA_VERSION, false );
}
add_action( 'plugins_loaded', 'numra_migrate_legacy_options', 1 );

/**
 * Options: copy each dpd_* value to its numra_* name, then drop the old row.
 *
 * add_option() is used, not update_option(): if a numra_* value already exists
 * it wins. A merchant who reconnected after upgrading must not have their new
 * key overwritten by the stale one they replaced.
 *
 * This map is deliberately an explicit allow-list and NOT a wildcard sweep of
 * every dpd_* row in the options table. "DPD" is also a real parcel carrier
 * with its own WooCommerce shipping plugins; renaming their options into our
 * namespace would corrupt an unrelated plugin's settings on the merchant's
 * store. Only keys this plugin is known to have written are touched.
 *
 * The map stops at these seven because they are the complete set of options
 * that existed when the namespace moved (plugin 1.1.0). Everything added since
 * — order protection, heartbeat, backfill — was born numra_* and never had a
 * dpd_* name to carry.
 */
function numra_migrate_legacy_option_rows() {
	$map = array(
		'dpd_api_key'               => 'numra_api_key',
		'dpd_connection'            => 'numra_connection',
		'dpd_connection_status'     => 'numra_connection_status',
		'dpd_last_connection_check' => 'numra_last_connection_check',
		'dpd_debug_enabled'         => 'numra_debug_enabled',
		'dpd_api_base_url'          => 'numra_api_base_url',
		'dpd_setup'                 => 'numra_setup',
	);

	foreach ( $map as $old => $new ) {
		$value = get_option( $old, null );
		if ( null === $value ) {
			continue; // Nothing stored under the old name.
		}
		add_option( $new, $value, '', false );
		delete_option( $old );
	}
}

/**
 * Order meta: rename every _dpd_* key on the merchant's orders to _numra_*.
 *
 * This is belt-and-braces and expected to match zero rows on a store that
 * upgraded from a released build: order scoring shipped in 1.2.0, after the
 * namespace moved, so no public version ever wrote a _dpd_* key. It runs
 * anyway because the rename was made while the plugin was on internal test
 * stores (ADR-002), and those stores carry dev-build meta that nothing else
 * would ever pick up. A one-time no-op costs two indexed statements per key.
 *
 * The old name is derived by swapping the prefix, so a key added to the list
 * can never fall out of step with its pre-rename twin.
 */
function numra_migrate_legacy_order_meta() {
	global $wpdb;

	$meta_keys = array(
		'_numra_risk_score',
		'_numra_risk_level',
		'_numra_blacklisted',
		'_numra_reason',
		'_numra_carrier',
		'_numra_phone',
		'_numra_checked_at',
		'_numra_check_status',
		'_numra_flagged',
		'_numra_hold_applied',
		'_numra_verdict',
		'_numra_rated',
		'_numra_style',
		'_numra_outcome_reported',
	);

	/* Both order stores. A store on HPOS keeps order meta in wc_orders_meta
	   and nothing in postmeta; a store on legacy storage is the reverse; a
	   store mid-migration has both. Renaming in only one table would silently
	   lose the risk history of every order held in the other — and this plugin
	   declares HPOS compatibility, so both are live cases. */
	$tables = array(
		array( $wpdb->postmeta, 'post_id' ),
	);

	$hpos_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $hpos_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) ) {
		$tables[] = array( $hpos_table, 'order_id' );
	}

	foreach ( $tables as $table ) {
		list( $table_name, $id_column ) = $table;
		foreach ( $meta_keys as $new_key ) {
			$old_key = '_dpd_' . substr( $new_key, strlen( '_numra_' ) );
			numra_rename_meta_key( $table_name, $id_column, $old_key, $new_key );
		}
	}
}

/**
 * Rename one meta key in one meta table, without creating duplicates.
 *
 * Two statements, both bounded by an indexed meta_key match rather than by
 * loading orders into PHP:
 *
 *   1. Drop old-key rows belonging to an order that ALREADY carries the new
 *      key. Without this the order would end up holding the value twice, and
 *      get_meta() would start handing back an array to callers that all
 *      expect a scalar.
 *   2. Rename whatever is left.
 *
 * The subquery in step 1 is wrapped in a derived table deliberately: MySQL
 * refuses to read the same table it is deleting from in a plain subquery
 * (error 1093), and materialising it sidesteps that.
 *
 * Table and column names are interpolated because placeholders cannot carry
 * identifiers. They are built from $wpdb->prefix and literals in this file and
 * never from a request; the meta keys, the only values involved, go through
 * prepare().
 */
function numra_rename_meta_key( $table, $id_column, $old_key, $new_key ) {
	global $wpdb;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are file literals; values are prepared.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE stale FROM `{$table}` AS stale
			 INNER JOIN (
			     SELECT `{$id_column}` AS owner_id FROM `{$table}` WHERE meta_key = %s
			 ) AS already_named ON already_named.owner_id = stale.`{$id_column}`
			 WHERE stale.meta_key = %s",
			$new_key,
			$old_key
		)
	);

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE `{$table}` SET meta_key = %s WHERE meta_key = %s",
			$new_key,
			$old_key
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Transients: delete the dpd_* ones rather than carrying them over.
 *
 * Every transient this plugin has ever set is a cache — announcement
 * placements, a connect handshake state, a one-shot admin notice. Copying a
 * stale one under a new name buys nothing, since the next request refetches,
 * and it would resurrect an expired handshake state that should have died.
 *
 * delete_transient() cannot wildcard, so the underlying option rows go
 * directly, scoped strictly to the dpd_ transient namespace. Same query shape
 * as uninstall.php. Unlike options, deleting a transient that turned out to
 * belong to some other dpd_* plugin is harmless: it is a cache, and it will be
 * rebuilt on demand.
 */
function numra_migrate_legacy_transients() {
	global $wpdb;

	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_dpd\_%'
		    OR option_name LIKE '\_transient\_timeout\_dpd\_%'"
	);
}

/**
 * Cron: unschedule the pre-rename hooks.
 *
 * A scheduled event lives in the cron array under the name it was booked with.
 * The callbacks now register against numra_heartbeat and numra_backfill_batch,
 * so a surviving dpd_* event fires forever against nothing: WordPress wakes
 * up, finds no listener, reschedules, repeats. It never stops on its own and
 * nothing in the admin shows it.
 */
function numra_migrate_legacy_cron() {
	foreach ( array( 'dpd_heartbeat', 'dpd_backfill_batch' ) as $legacy_hook ) {
		wp_clear_scheduled_hook( $legacy_hook );
	}
}

/**
 * Switch automatic scoring off on stores that already had it on.
 *
 * A check is the merchant's money. Spending it on every order without asking
 * was never a decision the plugin should have been making on their behalf, so
 * the default is now opt-in — and a default only means something if existing
 * installs move to it too. Left alone, every store upgrading from 1.5 or
 * earlier would keep silently auto-spending while the settings screen claimed
 * the feature was off by default.
 *
 * Runs EXACTLY ONCE, gated on its own flag rather than on a version compare.
 * That distinction matters: a merchant who reads the new setting and
 * deliberately turns automatic checking back on must keep it, and a
 * version-gated migration would helpfully undo their choice on the next
 * update.
 */
function numra_migrate_guard_optin() {
	if ( '1' === (string) get_option( 'numra_guard_optin_migrated', '0' ) ) {
		return;
	}

	// Only touch a store that actually has the old default set.
	if ( '1' === (string) get_option( 'numra_guard_enabled', '0' ) ) {
		update_option( 'numra_guard_enabled', '0', false );
		if ( class_exists( 'Numra_Logger' ) ) {
			Numra_Logger::info( 'Automatic order checking switched off: it is now opt-in. Re-enable it under Order Protection.' );
		}
	}

	add_option( 'numra_guard_optin_migrated', '1', '', false );
}
add_action( 'plugins_loaded', 'numra_migrate_guard_optin', 2 );

function numra_deactivate() {
	// Settings are preserved, but the scheduled beat must go: a deactivated
	// plugin that keeps calling home every 15 minutes is a bug the merchant
	// cannot see and cannot stop.
	Numra_Heartbeat::unschedule();
	Numra_Backfill::unschedule();
}

// ── Internationalization ──────────────────────────────────────────────────────
/**
 * Load the plugin text domain.
 *
 * Hooked to `init` (not `plugins_loaded`) per WordPress 6.7+, which warns when
 * translations are loaded too early. Priority 0 so translations are available
 * to everything that runs on `init` and later. Multilingual plugins
 * (WPML, Polylang, TranslatePress, Loco Translate) hook the standard
 * `load_plugin_textdomain()` / `load_textdomain_mofile` filters, so no
 * plugin-specific integration code is required.
 */
add_action( 'init', 'numra_load_textdomain', 0 );

function numra_load_textdomain() {
	load_plugin_textdomain(
		'numra-for-woocommerce',
		false,
		dirname( plugin_basename( NUMRA_PLUGIN_FILE ) ) . '/languages'
	);
}

// ── HPOS (High-Performance Order Storage) ────────────────────────────────────
/**
 * Declare compatibility with WooCommerce's custom order tables.
 *
 * Without this declaration WooCommerce marks the plugin "incompatible" and a
 * store running HPOS — the default for new installs since WooCommerce 8.2 —
 * either refuses to enable HPOS or warns the merchant about this plugin by
 * name. The declaration is honest: every order read and write in this plugin
 * goes through wc_get_order() and the CRUD methods, never get_post_meta() or
 * a direct postmeta query.
 *
 * Must run on before_woocommerce_init, which is the only point where the
 * FeaturesUtil registry is listening.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'numra_init' );

function numra_init() {
	// WooCommerce dependency check.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Numra for WooCommerce requires WooCommerce to be installed and active.', 'numra-for-woocommerce' )
				. '</p></div>';
		} );
		return;
	}

	// Numra_Connect registers REST routes (rest_api_init) and admin-post
	// handlers. It MUST be instantiated for all request types — not only admin
	// requests — because the challenge endpoint:
	//
	//   GET /wp-json/numra/v1/connect-challenge
	//
	// is called by the Numra backend server during domain verification. REST API
	// requests are NOT admin requests (is_admin() returns false for them), so
	// placing this inside is_admin() would prevent the route from ever
	// registering and make domain ownership verification permanently impossible.
	$connect = new Numra_Connect();
	$connect->register_hooks();

	/* Order protection. Registered for ALL request types, not just admin:
	   an order is created on a frontend request, and woocommerce_order_status_
	   changed fires from the frontend, from REST, from WP-CLI and from an
	   Action Scheduler job. Gating these behind is_admin() would mean the
	   plugin scored nothing and reported nothing — which is precisely the
	   mistake the Numra_Connect comment above documents for REST routes.

	   ADR-004/006 record "no frontend hooks" as a standard. These are server-
	   side order lifecycle hooks: they render nothing, enqueue nothing, and
	   emit no frontend output. The standard's intent — never touch the
	   customer-facing page — is intact. See ADR-009. */
	$guard = new Numra_Order_Guard();
	$guard->register_hooks();

	$reporter = new Numra_Outcome_Reporter();
	$reporter->register_hooks();

	/* State check. Registered for all request types because the cron callback
	   fires on a WP-Cron request, which is not an admin request — gating this
	   behind is_admin() would register the schedule but never run it. */
	$heartbeat = new Numra_Heartbeat();
	$heartbeat->register_hooks();

	/* Historical order logging. Registered outside is_admin() for the same
	   reason as the heartbeat: its callback fires on a WP-Cron request. */
	$backfill = new Numra_Backfill();
	$backfill->register_hooks();

	// Boot admin UI (admin-only). Numra_Admin_Menu is kept admin-gated
	// because it registers admin menus, settings pages, and enqueues admin
	// assets — none of which are needed outside the WordPress dashboard.
	if ( is_admin() ) {
		new Numra_Admin_Menu();
		new Numra_Updater( NUMRA_PLUGIN_FILE, NUMRA_VERSION );

		/* Alerts render on every admin screen, not just the Numra page. A
		   merchant whose protection is off has no reason to visit our page —
		   they think everything is fine. That is exactly who needs telling. */
		$alerts = new Numra_Alerts();
		$alerts->register_hooks();

		/* New-version news, on every admin screen for the same reason.
		   The updater above already paints WordPress's native badge on the
		   Plugins page; this carries the sentence explaining why the release
		   matters, which a version number cannot. It stands down whenever
		   Numra_Alerts is showing an error — a store with protection off has
		   a problem upgrading will not solve. */
		$release_notice = new Numra_Release_Notice();
		$release_notice->register_hooks();

		/* The WordPress dashboard is where a merchant starts the day. Numra
		   appeared nowhere on it, so "am I protected and how much plan is
		   left" required remembering we exist and going to look. */
		$widget = new Numra_Dashboard_Widget();
		$widget->register_hooks();

		$order_ui = new Numra_Order_UI();
		$order_ui->register_hooks();
	}
}
