<?php
/**
 * Numra for WooCommerce — dependency-free unit tests.
 *
 * Covers the pure logic that decides what the plugin does to a merchant's
 * orders. These are the paths where a silent mistake is expensive: a status
 * that never maps reports nothing, and a threshold that never matches holds
 * nothing — both look exactly like "working" from the outside.
 *
 * Run:  php tests/unit.php
 * No PHPUnit, no composer — this must be runnable on any host with PHP.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'NUMRA_PLATFORM', 'woocommerce' );
define( 'MINUTE_IN_SECONDS', 60 );

// ── Minimal WordPress surface these classes touch ─────────────────────────────
$GLOBALS['numra_test_options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['numra_test_options'] ) ? $GLOBALS['numra_test_options'][ $k ] : $d; }
function update_option( $k, $v, $a = '', $b = null ) { $GLOBALS['numra_test_options'][ $k ] = $v; return true; }
function add_option( $k, $v, $a = '', $b = null ) { if ( ! array_key_exists( $k, $GLOBALS['numra_test_options'] ) ) { $GLOBALS['numra_test_options'][ $k ] = $v; } return true; }
function delete_option( $k ) { unset( $GLOBALS['numra_test_options'][ $k ] ); return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function __( $s, $d = null ) { return $s; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }

require_once dirname( __DIR__ ) . '/includes/class-numra-outcome-reporter.php';

// Numra_API_Client is pulled in only for its OUTCOME_TYPES constant; the file
// defines the class without executing anything at load time.
require_once dirname( __DIR__ ) . '/includes/class-numra-logger.php';
if ( ! class_exists( 'Numra_API_Client' ) ) {
	// Avoid loading the real client (it needs Numra_Settings at construct time
	// and makes HTTP calls). Only the constant matters here, and it is asserted
	// against the real file below so the stub can never drift unnoticed.
	class Numra_API_Client {
		const OUTCOME_TYPES = array( 'DELIVERED', 'PAID_ONLINE', 'REFUSED_COD', 'CANCELLED', 'NO_ANSWER', 'FRAUD_CONFIRMED', 'RETURNED' );
	}
}
require_once dirname( __DIR__ ) . '/includes/class-numra-settings.php';

// ── Tiny assertion harness ────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else         { $fail++; echo "  FAIL  $label\n"; }
}
function eq( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : sprintf( '  (got %s, expected %s)', var_export( $actual, true ), var_export( $expected, true ) ) ) );
}

echo "\n── normalize_status ──────────────────────────────────────────────\n";
/* The bug this guards: ltrim($s,'wc-') is a character-set trim, so it turned
   'completed' into 'ompleted' and the outcome map matched nothing. */
eq( Numra_Outcome_Reporter::normalize_status( 'wc-completed' ), 'completed', 'strips the wc- prefix' );
eq( Numra_Outcome_Reporter::normalize_status( 'completed' ),    'completed', 'leaves an unprefixed status alone' );
eq( Numra_Outcome_Reporter::normalize_status( 'cancelled' ),    'cancelled', 'does not eat a leading c' );
eq( Numra_Outcome_Reporter::normalize_status( 'wc-cancelled' ), 'cancelled', 'prefix + leading c' );
eq( Numra_Outcome_Reporter::normalize_status( 'checkout-draft' ), 'checkout-draft', 'does not eat c/h from a custom status' );
eq( Numra_Outcome_Reporter::normalize_status( '' ), '', 'empty string is safe' );

echo "\n── the stub matches the real OUTCOME_TYPES ───────────────────────\n";
$client_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-numra-api-client.php' );
preg_match( "/const OUTCOME_TYPES = array\((.*?)\);/s", $client_src, $m );
preg_match_all( "/'([A-Z_]+)'/", $m[1], $types );
eq( $types[1], Numra_API_Client::OUTCOME_TYPES, 'test stub is in sync with the client' );

echo "\n── get_status_map ────────────────────────────────────────────────\n";
$GLOBALS['numra_test_options'] = array();
$map = Numra_Settings::get_status_map();
eq( $map['completed'], 'DELIVERED', 'default: completed maps to DELIVERED' );
ok( ! isset( $map['processing'] ), 'default: processing is not reported' );
ok( ! isset( $map['on-hold'] ), 'default: on-hold is not reported — a Numra hold is not an outcome' );

$GLOBALS['numra_test_options']['numra_status_map'] = array( 'delivered-cod' => 'DELIVERED' );
$map = Numra_Settings::get_status_map();
eq( $map['delivered-cod'], 'DELIVERED', 'a custom status can be mapped' );
eq( $map['completed'], 'DELIVERED', 'mapping one status does not drop the defaults' );

$GLOBALS['numra_test_options']['numra_status_map'] = array( 'completed' => 'none' );
$map = Numra_Settings::get_status_map();
ok( ! isset( $map['completed'] ), '"none" removes a default mapping' );

$GLOBALS['numra_test_options']['numra_status_map'] = array( 'completed' => 'NOT_A_REAL_OUTCOME' );
$map = Numra_Settings::get_status_map();
eq( $map['completed'], 'DELIVERED', 'an outcome the API would reject is ignored, default survives' );

$GLOBALS['numra_test_options']['numra_status_map'] = 'not-an-array';
$map = Numra_Settings::get_status_map();
eq( $map['completed'], 'DELIVERED', 'a corrupt option falls back to defaults instead of fataling' );

echo "\n── get_risk_threshold ────────────────────────────────────────────\n";
$GLOBALS['numra_test_options'] = array();
eq( Numra_Settings::get_risk_threshold(), 70, 'defaults to 70' );

$GLOBALS['numra_test_options']['numra_risk_threshold'] = 55;
eq( Numra_Settings::get_risk_threshold(), 55, 'honours a valid value' );

/* 0 would flag every order and 500 would flag none — both are ways to switch
   the product off while believing it is on. */
$GLOBALS['numra_test_options']['numra_risk_threshold'] = 0;
eq( Numra_Settings::get_risk_threshold(), 70, '0 is out of range, falls back' );
$GLOBALS['numra_test_options']['numra_risk_threshold'] = 500;
eq( Numra_Settings::get_risk_threshold(), 70, '500 is out of range, falls back' );
$GLOBALS['numra_test_options']['numra_risk_threshold'] = 'abc';
eq( Numra_Settings::get_risk_threshold(), 70, 'a non-numeric value falls back' );
$GLOBALS['numra_test_options']['numra_risk_threshold'] = 100;
eq( Numra_Settings::get_risk_threshold(), 100, '100 is in range' );
$GLOBALS['numra_test_options']['numra_risk_threshold'] = 1;
eq( Numra_Settings::get_risk_threshold(), 1, '1 is in range' );

echo "\n── protection toggles default ON for upgrading stores ────────────\n";
$GLOBALS['numra_test_options'] = array();
/* Auto-scoring is the one default that spends money, so it is the one default
   that must be off. This assertion is inverted from what it used to say, on
   purpose — the old version locked in a per-order charge nobody agreed to. */
ok( ! Numra_Settings::is_guard_enabled(), 'AUTO-SCORING DEFAULTS OFF (it bills per order)' );
ok( Numra_Settings::is_autohold_enabled(),'auto-hold defaults on' );
ok( Numra_Settings::is_cod_only(),        'COD-only defaults on' );
ok( Numra_Settings::is_outcome_reporting_enabled(), 'outcome reporting defaults on' );
$GLOBALS['numra_test_options']['numra_guard_enabled'] = '1';
ok( Numra_Settings::is_guard_enabled(),   'auto-scoring can be switched on explicitly' );
$GLOBALS['numra_test_options']['numra_guard_enabled'] = '0';
ok( ! Numra_Settings::is_guard_enabled(), 'auto-scoring stays off when set off' );

echo "\n── flagging by customer type ─────────────────────────────────────\n";
/* A band says how badly a customer scores. A style says what they repeatedly
   DO — and a never-answers buyer and a refuses-at-the-door buyer can score
   identically while needing opposite handling. */
$GLOBALS['numra_test_options'] = array();
eq( Numra_Settings::get_flag_styles(), array(), 'no customer types held by default' );
ok( ! Numra_Settings::style_is_flagged( 'hshoumi' ), 'nothing is flagged before the merchant chooses' );

$GLOBALS['numra_test_options']['numra_flag_styles'] = array( 'hshoumi', 'tahramiyat' );
ok( Numra_Settings::style_is_flagged( 'hshoumi' ),    'a chosen type is flagged' );
ok( Numra_Settings::style_is_flagged( 'tahramiyat' ), 'a second chosen type is flagged' );
ok( ! Numra_Settings::style_is_flagged( 'weld_el_asl' ), 'an unchosen type is not flagged' );

/* An empty style is what the classifier returns for a number it has never
   seen. It must never match, or every first-time buyer on a store that picked
   any type at all would be held. */
ok( ! Numra_Settings::style_is_flagged( '' ),    'EMPTY STYLE NEVER FLAGS (first-time buyers)' );
ok( ! Numra_Settings::style_is_flagged( null ),  'a null style never flags' );

/* Codes arrive from the API and are compared against stored option values;
   both go through sanitize_key, so casing cannot cause a silent miss. */
$GLOBALS['numra_test_options']['numra_flag_styles'] = array( 'HSHOUMI' );
ok( Numra_Settings::style_is_flagged( 'hshoumi' ), 'codes are matched case-insensitively' );

$GLOBALS['numra_test_options']['numra_flag_styles'] = 'not-an-array';
eq( Numra_Settings::get_flag_styles(), array(), 'a corrupt option falls back to holding nothing' );

echo "\n── store sync consent ────────────────────────────────────────────\n";
/* The sync reads a store's entire order history and sends it to us. Every
   assertion here exists because the previous version did that unconditionally,
   with no screen anywhere saying so. */
$GLOBALS['numra_test_options'] = array();
ok( ! Numra_Settings::has_sync_consent(),      'SILENCE IS NOT CONSENT — sync is off until asked' );
ok( ! Numra_Settings::sync_consent_answered(), 'an untouched store counts as unanswered' );
eq( Numra_Settings::sync_consent_state(), '',  'unanswered is its own state, not "no"' );

Numra_Settings::set_sync_consent( true );
ok( Numra_Settings::has_sync_consent(),        'agreeing turns it on' );
ok( Numra_Settings::sync_consent_answered(),   'agreeing counts as answered' );

Numra_Settings::set_sync_consent( false );
ok( ! Numra_Settings::has_sync_consent(),      'withdrawing turns it off' );
ok( Numra_Settings::sync_consent_answered(),   'declining is answered — the screen must not ask again' );
eq( Numra_Settings::sync_consent_state(), 'no','declining is distinguishable from never asked' );

/* A junk value must read as "never asked" rather than as agreement. The option
   is written by exactly one handler, but a half-finished migration or a
   hand-edited row must fail closed. */
$GLOBALS['numra_test_options']['numra_sync_consent'] = '1';
ok( ! Numra_Settings::has_sync_consent(),      'a stray "1" is NOT consent — only the literal "yes" is' );
ok( ! Numra_Settings::sync_consent_answered(), 'a junk value falls back to unanswered, not to agreed' );

echo "\n── the risk band scale ───────────────────────────────────────────\n";
/* LOW was missing from risk_level_order() until the portal started pushing
   policy. Its absence meant a merchant choosing LOW in the portal sent a
   value this plugin rejects, get_risk_level_threshold() failed its in_array
   check, and the store silently fell through to the legacy numeric mapping —
   the setting quietly becoming a different setting. */
$order = Numra_Settings::risk_level_order();
eq( $order, array( 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL', 'BLOCKED_ONLY' ), 'all five bands, loosest first' );
ok( array_key_exists( 'LOW', Numra_Settings::risk_level_choices() ), 'LOW is offered to the merchant, not just accepted' );

$GLOBALS['numra_test_options'] = array( 'numra_risk_level' => 'HIGH' );
ok(   Numra_Settings::level_meets_threshold( 'CRITICAL' ), 'HIGH threshold catches CRITICAL' );
ok(   Numra_Settings::level_meets_threshold( 'HIGH' ),     'HIGH threshold catches HIGH' );
ok( ! Numra_Settings::level_meets_threshold( 'MEDIUM' ),   'HIGH threshold does not catch MEDIUM' );

$GLOBALS['numra_test_options']['numra_risk_level'] = 'LOW';
ok(   Numra_Settings::level_meets_threshold( 'LOW' ),      'LOW threshold catches LOW' );
ok( ! Numra_Settings::level_meets_threshold( 'UNRATED' ),  'UNRATED IS NEVER HELD — most COD buyers are first-time' );

$GLOBALS['numra_test_options']['numra_risk_level'] = 'BLOCKED_ONLY';
ok( ! Numra_Settings::level_meets_threshold( 'CRITICAL' ), 'BLOCKED_ONLY never flags on score alone' );

echo "\n── policy pushed from the merchant's Numra account ───────────────\n";
/* apply_remote_policy() must never travel handle_save()'s path, which reads
   `isset($_POST[...]) ? '1' : '0'` and therefore destroys any setting whose
   field was not rendered. Each key here is written ONLY when the server
   actually sent it. */
$GLOBALS['numra_test_options'] = array(
	'numra_guard_enabled' => '1',
	'numra_risk_level'    => 'HIGH',
	'numra_cod_only'      => '1',
	'numra_flag_styles'   => array( 'ghadban' ),
);

ok( ! Numra_Settings::apply_remote_policy( 'not-an-array' ), 'a non-array policy writes nothing' );
ok( ! Numra_Settings::apply_remote_policy( array() ),        'an empty policy writes nothing' );
eq( Numra_Settings::get_risk_level_threshold(), 'HIGH',      'and leaves the existing band alone' );

/* A partial policy must leave untouched keys untouched — an older server that
   omits a field must not reset it to a default nobody chose. */
Numra_Settings::apply_remote_policy( array( 'flag_threshold' => 'MEDIUM' ) );
eq( Numra_Settings::get_risk_level_threshold(), 'MEDIUM',    'a sent band is applied' );
ok( Numra_Settings::is_guard_enabled(),                      'a key the server did not send is left alone' );
eq( Numra_Settings::get_flag_styles(), array( 'ghadban' ),   'styles survive a policy that omits them' );

/* An unrecognised band is DISCARDED, not stored. Storing it would make
   get_risk_level_threshold() fall through to the legacy numeric mapping and
   silently pick a band neither side intended. */
Numra_Settings::apply_remote_policy( array( 'flag_threshold' => 'NONSENSE' ) );
eq( Numra_Settings::get_risk_level_threshold(), 'MEDIUM',    'an unknown band is ignored, not stored' );

/* An empty styles array IS a real answer — "flag on no customer type" —
   unlike the styles catalogue, where empty means the server had nothing
   to say. isset() is what tells the two apart. */
Numra_Settings::apply_remote_policy( array( 'flag_styles' => array() ) );
eq( Numra_Settings::get_flag_styles(), array(),              'an explicit empty styles list clears the selection' );

Numra_Settings::apply_remote_policy( array( 'guard_enabled' => false, 'cod_only' => false ) );
ok( ! Numra_Settings::is_guard_enabled(),                    'the server can switch scoring off' );
ok( ! Numra_Settings::is_cod_only(),                         'and can widen beyond COD' );

echo "\n";
echo ( 0 === $fail )
	? "All $pass assertions passed.\n"
	: "$fail of " . ( $pass + $fail ) . " assertions FAILED.\n";
exit( $fail > 0 ? 1 : 0 );
