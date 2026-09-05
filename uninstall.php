<?php
/**
 * Numra for WooCommerce — Uninstall
 *
 * Runs ONLY when the merchant deletes the plugin from the Plugins screen.
 * This is deliberately different from DEACTIVATION, which preserves every
 * setting so the plugin can be re-activated without reconfiguration.
 * UNINSTALL is the merchant saying "remove this" — leaving a live API
 * credential behind in the database would not be defensible.
 *
 * Scope rule: delete ONLY data owned by this plugin (the numra_* namespace).
 * Never touch WordPress, WooCommerce, order, customer, or platform data.
 *
 * NOTE: this file runs WITHOUT the plugin loaded, so class constants
 * (Numra_Settings::OPT_*) are unavailable by design. Option names are
 * hardcoded and must be kept in sync with includes/class-numra-settings.php.
 *
 * @package Numra
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Options owned by this plugin ─────────────────────────────────────────────
$numra_options = array(
	'numra_api_key',               // the credential — the reason this file exists
	'numra_connection',            // connection metadata object (autoload=no)
	'numra_connection_status',
	'numra_last_connection_check',
	'numra_debug_enabled',
	'numra_api_base_url',
	'numra_setup',                 // setup state (Sprint 1.5+; harmless if absent)

	// Order protection settings.
	'numra_guard_enabled',
	'numra_autohold_enabled',
	'numra_cod_only',
	'numra_outcome_enabled',
	'numra_risk_threshold',
	'numra_status_map',
	'numra_risk_level',            // which band counts as "risky"
	'numra_flag_styles',           // customer styles the merchant flags on

	// Order history sync.
	'numra_sync_consent',
	'numra_backfill_enabled',
	'numra_backfill_cursor',
	'numra_backfill_done',
	'numra_backfill_count',
	'numra_backfill_last_run',

	// Heartbeat state — written by the 15-minute check, never by a settings
	// screen, which is exactly why this list had drifted away from them.
	'numra_platform_state',
	'numra_last_beat',
	'numra_alert_dismissed',
	'numra_customer_styles',

	// Migration bookkeeping. These must go too, or a merchant who deletes the
	// plugin and reinstalls it gets a database that claims to be already
	// migrated and an opt-in migration that refuses to run again.
	'numra_schema_version',
	'numra_guard_optin_migrated',

	// Pre-rename names. A site that is deleted before the dpd_* -> numra_*
	// migration ever ran would otherwise keep a live API credential in its
	// options table forever — the exact thing this file exists to prevent.
	'dpd_api_key',
	'dpd_connection',
	'dpd_connection_status',
	'dpd_last_connection_check',
	'dpd_debug_enabled',
	'dpd_api_base_url',
	'dpd_setup',
);

foreach ( $numra_options as $numra_option ) {
	delete_option( $numra_option );
}

// ── Scheduled events ─────────────────────────────────────────────────────────
/* Deactivation unschedules these, but uninstall is reachable without it: a
   merchant can delete a plugin whose files were already removed, and WP-CLI
   deletes without deactivating. A cron entry left behind fires forever against
   a callback that no longer exists — nothing breaks loudly, and nothing ever
   cleans it up either. The dpd_* names are here for a site deleted before the
   rename migration ever ran. Both names are hardcoded for the same reason the
   options are: the plugin's classes are not loaded in this file. */
foreach ( array( 'numra_heartbeat', 'numra_backfill_batch', 'dpd_heartbeat', 'dpd_backfill_batch' ) as $numra_hook ) {
	wp_clear_scheduled_hook( $numra_hook );
}

// ── Transients owned by this plugin ─────────────────────────────────────────
// Fixed-name transient:
delete_transient( 'numra_growth_placements_settings' );

// Prefixed transients with dynamic suffixes (per-user notices, per-flow connect
// state). delete_transient() cannot wildcard, so remove the underlying option
// rows directly — scoped strictly to the numra_ transient namespace.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_numra\_%'
	    OR option_name LIKE '\_transient\_timeout\_numra\_%'
	    OR option_name LIKE '\_transient\_dpd\_%'
	    OR option_name LIKE '\_transient\_timeout\_dpd\_%'"
);

/* ── Order meta is deliberately NOT deleted ──────────────────────────────────
   _numra_risk_score and its siblings live on the merchant's own orders. They
   are part of that order's history — why it was held, what was reported — and
   an order record is the merchant's data, not the plugin's. Stripping it would
   quietly rewrite their books at the moment they uninstall, and a merchant who
   reinstalls would find the history gone. The scope rule at the top of this
   file says never touch order data; this is that rule applied. */ 
