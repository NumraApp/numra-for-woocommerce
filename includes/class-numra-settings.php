<?php
/**
 * Numra for WooCommerce — Settings
 * Handles saving and retrieving plugin options securely.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Settings {

	/** WordPress option keys */
	const OPT_API_BASE_URL          = 'numra_api_base_url';
	const OPT_API_KEY               = 'numra_api_key';
	const OPT_CONNECTION_STATUS     = 'numra_connection_status';
	const OPT_LAST_CONNECTION_CHECK = 'numra_last_connection_check';
	const OPT_DEBUG_ENABLED         = 'numra_debug_enabled';

	/**
	 * Connection metadata object (autoload = no).
	 *
	 * Holds the full exchange response EXCEPT the credential: the API key lives
	 * only in OPT_API_KEY, so there is exactly one home for one secret. Unknown
	 * fields from newer backend versions are preserved here rather than dropped.
	 */
	const OPT_CONNECTION = 'numra_connection';

	/** Bump when the shape of OPT_CONNECTION changes in a way that needs migration. */
	const CONNECTION_SCHEMA_VERSION = 1;

	/* ── Order protection ─────────────────────────────────────────────────
	   Defaults are chosen so a merchant who installs, connects, and touches
	   nothing else still gets the product's value: orders are scored, risky
	   ones are held, and outcomes flow back to the network. */
	const OPT_GUARD_ENABLED   = 'numra_guard_enabled';
	const OPT_RISK_THRESHOLD  = 'numra_risk_threshold';
	const OPT_AUTOHOLD        = 'numra_autohold_enabled';
	const OPT_COD_ONLY        = 'numra_cod_only';
	const OPT_OUTCOME_ENABLED = 'numra_outcome_enabled';
	const OPT_STATUS_MAP      = 'numra_status_map';

	/**
	 * Default flag threshold, on the API's 0-100 public score.
	 *
	 * 70 sits at the bottom of the risk engine's HIGH band. Lower and a store
	 * holds orders it should be shipping, which teaches the merchant to ignore
	 * the hold; that failure mode is worse than missing a marginal fraud.
	 */
	const DEFAULT_THRESHOLD = 70;

	/**
	 * Process the settings form POST.
	 * Hooked to `load-{$hook}` by Numra_Admin_Menu::register_menu(), so it
	 * runs before any admin-header output and the redirect below always works.
	 */
	public function handle_save() {
		// POST-only: load-{$hook} fires on every visit to the page, including
		// plain GETs, which must never reach the write path.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( ! isset( $_POST['numra_settings_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['numra_settings_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'numra_save_settings' ) ) {
			wp_die( esc_html__( 'Nonce verification failed.', 'numra-for-woocommerce' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'numra-for-woocommerce' ) );
		}

		// API base URL is pinned to NUMRA_API_BASE and is not merchant-
		// editable: any posted numra_api_base_url value is deliberately ignored.

		// API key — sanitize, never echo back to page.
		// A blank submitted key means "keep the existing key": the entire
		// write block is skipped, so the key, connection metadata, status and
		// growth cache are all left untouched. Disconnect is the explicit
		// credential-removal action.
		if ( isset( $_POST['numra_api_key'] ) ) {
			$api_key = sanitize_text_field( trim( wp_unslash( $_POST['numra_api_key'] ) ) );
			if ( '' !== $api_key ) {
				update_option( self::OPT_API_KEY, $api_key );
				// Reset connection status when key changes
				update_option( self::OPT_CONNECTION_STATUS, 'disconnected' );
				update_option( self::OPT_LAST_CONNECTION_CHECK, '' );
				// The connection object describes the account the OLD key belonged to.
				// A manually entered key may belong to a different account, so the
				// stale metadata must go rather than be shown against the new key.
				delete_option( self::OPT_CONNECTION );
				Numra_Announcements::bust_cache();
			}
		}

		// Debug mode
		$debug = isset( $_POST['numra_debug_enabled'] ) ? '1' : '0';
		update_option( self::OPT_DEBUG_ENABLED, $debug );

		/* ── Order protection ──────────────────────────────────────────────
		   Guarded by numra_protection_submitted, a hidden field present only
		   on the protection tab. Without it, saving the API-key tab would post
		   no checkboxes and switch every protection feature off — the classic
		   way a tabbed settings form silently destroys the settings on the
		   tabs the merchant is not looking at. */
		if ( isset( $_POST['numra_protection_submitted'] ) ) {
			update_option( self::OPT_GUARD_ENABLED,   isset( $_POST['numra_guard_enabled'] ) ? '1' : '0' );
			update_option( self::OPT_AUTOHOLD,        isset( $_POST['numra_autohold_enabled'] ) ? '1' : '0' );
			update_option( self::OPT_COD_ONLY,        isset( $_POST['numra_cod_only'] ) ? '1' : '0' );
			/* Outcome reporting and the historical backfill are NOT read from
			   this form, and must never be. They are automatic, always on, and
			   have no controls on any screen — see is_outcome_reporting_enabled().

			   The absence is load-bearing. This block runs on every protection
			   save, and `isset( $_POST[...] ) ? '1' : '0'` on a field that is
			   no longer rendered evaluates to '0' — so leaving the old lines
			   here would have switched network logging off the first time any
			   merchant saved this tab, silently and permanently. That is the
			   exact failure the numra_protection_submitted guard above exists
			   to prevent, one level down. */

			/* The band the merchant chose. Its own block, NOT nested inside the
			   legacy threshold one: that field is no longer rendered, so it is
			   never posted, and anything nested under it would never run. The
			   value is checked against the known set rather than trusted —
			   an unrecognised one would fall through to the legacy numeric
			   mapping on the next read, which is a setting changing itself
			   behind the merchant's back. */
			if ( isset( $_POST['numra_risk_level'] ) ) {
				$band = sanitize_text_field( wp_unslash( $_POST['numra_risk_level'] ) );
				if ( in_array( $band, self::risk_level_order(), true ) ) {
					update_option( self::OPT_RISK_LEVEL, $band, false );
				}
			}

			/* Customer types to hold on sight.
			   ──────────────────────────────────────────────────────────────
			   Checkboxes, so an empty selection posts NOTHING — the field
			   simply is not there. That means "isset" cannot be the test: a
			   merchant unticking their last type would post no key, the
			   branch would be skipped, and the old selection would survive a
			   save that was meant to clear it.

			   The protection form therefore carries a hidden marker, and the
			   presence of THAT is what says "this form owns this option". It
			   is the same shape as the numra_protection_submitted guard one
			   level up, for the same reason, and it is the third time this
			   codebase has had to solve it. */
			if ( isset( $_POST['numra_flag_styles_submitted'] ) ) {
				$picked = isset( $_POST['numra_flag_styles'] ) && is_array( $_POST['numra_flag_styles'] )
					? (array) wp_unslash( $_POST['numra_flag_styles'] )
					: array();

				$clean = array();
				foreach ( $picked as $code ) {
					$code = sanitize_key( $code );
					if ( '' !== $code ) {
						$clean[] = $code;
					}
				}
				update_option( self::OPT_FLAG_STYLES, array_values( array_unique( $clean ) ), false );
			}

			/* Still accepted if something posts it (the field is gone from the
			   UI but the option is what older installs are migrated from). */
			if ( isset( $_POST['numra_risk_threshold'] ) ) {
				$threshold = (int) wp_unslash( $_POST['numra_risk_threshold'] );
				$threshold = max( 1, min( 100, $threshold ) );
				update_option( self::OPT_RISK_THRESHOLD, $threshold );
			}

			if ( isset( $_POST['numra_status_map'] ) && is_array( $_POST['numra_status_map'] ) ) {
				$clean = array();
				// wp_unslash before sanitising: the raw superglobal is slashed.
				foreach ( (array) wp_unslash( $_POST['numra_status_map'] ) as $status => $outcome ) {
					$status  = sanitize_key( $status );
					$outcome = sanitize_text_field( (string) $outcome );
					if ( '' === $status ) {
						continue;
					}
					if ( 'none' === $outcome || in_array( $outcome, Numra_API_Client::OUTCOME_TYPES, true ) ) {
						$clean[ $status ] = $outcome;
					}
				}
				update_option( self::OPT_STATUS_MAP, $clean );
			}
		}

		Numra_Logger::info( 'Settings saved.' );

		// Redirect to avoid form re-submission, returning to the tab that was
		// actually submitted — a merchant who saves the protection tab and
		// lands on the API-key tab cannot tell whether the save worked.
		$tab = isset( $_POST['numra_protection_submitted'] ) ? 'protection' : 'api-key';

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'numra',
			'tab'     => $tab,
			'updated' => '1',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the AJAX connection test.
	 * Called via wp_ajax_numra_test_connection.
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'numra_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$client = new Numra_API_Client();
		$result = $client->test_connection();

		// Map connection status based on HTTP code
		$new_status = 'connected';
		if ( ! $result['ok'] ) {
			if ( in_array( $result['status'], array( 401, 403 ), true ) ) {
				$new_status = 'connection_lost';
			} else {
				$new_status = 'unavailable';
			}
		}

		update_option( self::OPT_CONNECTION_STATUS,     $new_status );
		update_option( self::OPT_LAST_CONNECTION_CHECK, current_time( 'mysql' ) );

		Numra_Logger::info( 'Connection test: ' . ( $result['ok'] ? 'OK' : 'FAILED: ' . $result['message'] . ' Status: ' . $result['status'] ) );

		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array(
				'message'    => $result['message'],
				'status'     => $result['status'],
				'error_code' => isset( $result['error_code'] ) ? $result['error_code'] : 'SERVICE_UNAVAILABLE'
			) );
		}
	}

	/**
	 * Synchronize the connection status option based on a response HTTP status code.
	 * Handles definitive 401/403 responses by marking state as connection_lost.
	 * Ignores temporary failure status codes (500/503/0/timeouts) so credentials aren't broken.
	 *
	 * @param int $status_code HTTP response code.
	 */
	public static function sync_status_from_response_code( $status_code ) {
		if ( ! self::has_api_key() ) {
			return; // Do not sync status if there is no API key stored yet
		}

		if ( empty( $status_code ) ) {
			return; // 0/timeout/dns error: ignore, treat as temporarily unavailable without changing state
		}

		$status_code = (int) $status_code;

		if ( 401 === $status_code || 403 === $status_code ) {
			update_option( self::OPT_CONNECTION_STATUS, 'connection_lost' );
			update_option( self::OPT_LAST_CONNECTION_CHECK, current_time( 'mysql' ) );
			Numra_Logger::warning( 'Numra connection lost due to definitive HTTP ' . $status_code . ' auth failure.' );
		} elseif ( $status_code >= 200 && $status_code < 300 ) {
			// If we got a successful API response, the connection is healthy
			$current = self::get_connection_status();
			if ( 'connected' !== $current ) {
				update_option( self::OPT_CONNECTION_STATUS, 'connected' );
				update_option( self::OPT_LAST_CONNECTION_CHECK, current_time( 'mysql' ) );
			}
		}
	}

	// ── Static getters ────────────────────────────────────────────────────────

	public static function get_api_key()               { return (string) get_option( self::OPT_API_KEY, '' ); }

	/**
	 * The API base URL, pinned to production.
	 *
	 * All traffic goes to NUMRA_API_BASE. The legacy numra_api_base_url
	 * option (OPT_API_BASE_URL) is deliberately never read: a hostile or
	 * stale stored value must not redirect API traffic. The only override
	 * is the code-level NUMRA_API_BASE_OVERRIDE constant.
	 *
	 * @return string
	 */
	public static function get_api_base_url() {
		$base = defined( 'NUMRA_API_BASE_OVERRIDE' ) && is_string( NUMRA_API_BASE_OVERRIDE ) && '' !== NUMRA_API_BASE_OVERRIDE
			? NUMRA_API_BASE_OVERRIDE
			: NUMRA_API_BASE;
		return rtrim( (string) $base, '/' );
	}
	public static function get_connection_status()      { return (string) get_option( self::OPT_CONNECTION_STATUS, 'disconnected' ); }

	/**
	 * Record connection status and stamp the check time.
	 *
	 * Every other writer of this option did both halves inline, in five places,
	 * and one of them forgot the timestamp. One setter so they cannot drift.
	 *
	 * @param string $status connected | connection_lost | unavailable | disconnected
	 */
	public static function set_connection_status( $status ) {
		update_option( self::OPT_CONNECTION_STATUS, (string) $status, false );
		update_option( self::OPT_LAST_CONNECTION_CHECK, current_time( 'mysql' ), false );
	}

	public static function get_last_connection_check()  { return (string) get_option( self::OPT_LAST_CONNECTION_CHECK, '' ); }
	public static function is_debug_enabled()           { return '1' === get_option( self::OPT_DEBUG_ENABLED, '0' ); }
	public static function has_api_key()                { return '' !== self::get_api_key(); }

	/**
	 * The connection metadata object, or an empty array when not connected.
	 *
	 * @return array
	 */
	public static function get_connection() {
		$conn = get_option( self::OPT_CONNECTION, array() );
		return is_array( $conn ) ? $conn : array();
	}

	/**
	 * A store is connected when it holds a credential AND a connection record.
	 * A manually pasted API key alone is "has_api_key" but not "is_connected".
	 */
	public static function is_connected() {
		$conn = self::get_connection();
		$status = self::get_connection_status();
		return self::has_api_key() && ! empty( $conn ) && 'connection_lost' !== $status && 'disconnected' !== $status;
	}

	/**
	 * Read a value from the connection object using dot notation.
	 * Returns $default when absent, so newer/missing API fields never fatal.
	 *
	 * @param string $path    e.g. 'license.plan'
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get_connection_value( $path, $default = '' ) {
		$value = self::get_connection();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return ( '' === $value || null === $value ) ? $default : $value;
	}

	// ── Order protection getters ──────────────────────────────────────────────

	/* These options do not exist on a store upgrading into this version, so
	   get_option() hands back the default below. For the two that only change
	   how an already-paid-for check is presented, that default is '1' — off
	   would mean an existing install silently gains a feature nobody notices.

	   Auto-scoring is the exception, and it is not a presentation choice. */

	/**
	 * Automatic scoring at order time. OFF by default, deliberately.
	 *
	 * This defaulted to '1', which meant a merchant who installed, connected
	 * and touched nothing had every order sent to POST /v1/phone/lookup — the
	 * billing endpoint. One credit per order, spent without anyone choosing to
	 * spend it. The old comment here described that as "the product's value";
	 * it is the merchant's balance.
	 *
	 * The model is: logging is automatic and free, because it builds the
	 * network. SCORING is paid, and paid things are chosen. A default that
	 * spends money on the merchant's behalf is not a default, it is a charge
	 * they did not agree to.
	 *
	 * Off for new installs AND for upgrading ones. A store already carrying
	 * numra_guard_enabled = '1' set it explicitly and keeps it; a store that
	 * never had the option gets off, which is the honest reading of "this
	 * merchant has never agreed to per-order billing".
	 */
	public static function is_guard_enabled()   { return '1' === (string) get_option( self::OPT_GUARD_ENABLED, '0' ); }
	public static function is_autohold_enabled(){ return '1' === (string) get_option( self::OPT_AUTOHOLD, '1' ); }
	public static function is_cod_only()        { return '1' === (string) get_option( self::OPT_COD_ONLY, '1' ); }
	/**
	 * Outcome reporting is always on.
	 *
	 * It reads no option on purpose. A store upgrading from a version that had
	 * the toggle may be carrying `numra_outcome_enabled = '0'`, either because
	 * the merchant switched it off or because an earlier build of the save
	 * handler wrote a '0' when the field stopped being rendered. Reading the
	 * option would let either of those silently keep this store out of the
	 * network forever.
	 *
	 * Reporting an outcome costs the merchant nothing, sends only the phone,
	 * the outcome and the order total, and is the single thing that keeps
	 * scores accurate for every store including this one. It is not a
	 * preference.
	 */
	public static function is_outcome_reporting_enabled() { return true; }

	// ── Store sync consent ────────────────────────────────────────────────────

	/** Option holding the merchant's answer. Tri-state, see below. */
	const OPT_SYNC_CONSENT = 'numra_sync_consent';

	/**
	 * Has this store agreed to sync its order history?
	 *
	 * THREE states, not two, and the third is the important one:
	 *
	 *   'yes' — the merchant ticked the box and pressed the button.
	 *   'no'  — the merchant declined, explicitly.
	 *   ''    — never asked. NOT the same as 'no'.
	 *
	 * A boolean option cannot express "not yet asked", and that distinction is
	 * the whole feature: unanswered means show the consent screen, declined
	 * means do not. Collapsing them would either nag a merchant who already
	 * said no, or silently treat silence as agreement — which is exactly the
	 * behaviour this replaces.
	 *
	 * It is also deliberately NOT written by handle_save(). The save-handler
	 * trap in this codebase — `isset( $_POST[...] ) ? '1' : '0'` on a field
	 * that is not rendered on the submitted form — has silently switched a
	 * feature off twice. Consent is written only by its own nonce-checked
	 * handler, from a form that exists for nothing else, so no other save can
	 * touch it.
	 */
	public static function sync_consent_state() {
		$v = (string) get_option( self::OPT_SYNC_CONSENT, '' );
		return in_array( $v, array( 'yes', 'no' ), true ) ? $v : '';
	}

	public static function has_sync_consent()      { return 'yes' === self::sync_consent_state(); }
	public static function sync_consent_answered() { return ''    !== self::sync_consent_state(); }

	public static function set_sync_consent( $agreed ) {
		update_option( self::OPT_SYNC_CONSENT, $agreed ? 'yes' : 'no', false );
	}

	/**
	 * Flag threshold, clamped to the 0-100 the API actually returns.
	 *
	 * A stored 0 would flag every order and a stored 500 would flag none;
	 * both are ways a merchant can accidentally switch the product off while
	 * believing it is on, so the range is enforced on read as well as on save.
	 */
	public static function get_risk_threshold() {
		$value = (int) get_option( self::OPT_RISK_THRESHOLD, self::DEFAULT_THRESHOLD );
		if ( $value < 1 || $value > 100 ) {
			return self::DEFAULT_THRESHOLD;
		}
		return $value;
	}

	/* ── Risk bands ───────────────────────────────────────────────────────────
	   The merchant picks a band, not a number. `risk_level` is resolved once,
	   server-side, in the phone_verdict view, and is the same word the control
	   panel and the order screen show — so the setting, the API and the UI all
	   say CRITICAL/HIGH/MEDIUM or none of them do.

	   UNRATED is deliberately absent. It is not a low band, it means "we have
	   never seen this number", and there is nothing to threshold. On a
	   cash-on-delivery store in this market most buyers are first-time; a band
	   that caught them would hold the whole order book. */
	const OPT_RISK_LEVEL     = 'numra_risk_level';
	const DEFAULT_RISK_LEVEL = 'HIGH';

	/* Severity order, low to high. Index is the comparison rank.
	   ─────────────────────────────────────────────────────────────────────
	   LOW was missing, and its absence was a real defect once the portal
	   began pushing policy. The portal offers five bands (see
	   packages/shared/guardPolicy.js) and the engine emits LOW as a verdict;
	   a merchant selecting it there would send a value this list rejects,
	   `get_risk_level_threshold()` would fail its in_array check, and the
	   store would silently fall through to the legacy numeric mapping below
	   — a setting changing itself behind the merchant's back, which is
	   exactly what this file's save-side validation exists to prevent.

	   Widened here rather than narrowing the portal, because "flag anything
	   we have any doubt about" is a legitimate choice for a merchant
	   shipping expensive goods, and the engine already produces the band. */
	public static function risk_level_order() {
		return array( 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL', 'BLOCKED_ONLY' );
	}

	/**
	 * The choices, in the order a merchant should read them: loosest first,
	 * so the list runs from "hold more" to "hold almost nothing".
	 */
	public static function risk_level_choices() {
		return array(
			'LOW' => array(
				'label' => __( 'Any rated risk', 'numra-for-woocommerce' ),
				'desc'  => __( 'Holds every order from a number with any record at all. For high-value goods where a held order costs far less than a lost one.', 'numra-for-woocommerce' ),
			),
			'MEDIUM' => array(
				'label' => __( 'Medium risk and worse', 'numra-for-woocommerce' ),
				'desc'  => __( 'Cautious. Catches the most, and will hold some orders that would have been fine.', 'numra-for-woocommerce' ),
			),
			'HIGH' => array(
				'label'       => __( 'High risk and worse', 'numra-for-woocommerce' ),
				'desc'        => __( 'Numbers with a real record of refused or undelivered orders.', 'numra-for-woocommerce' ),
				'recommended' => true,
			),
			'CRITICAL' => array(
				'label' => __( 'Critical only', 'numra-for-woocommerce' ),
				'desc'  => __( 'Confirmed fraud, or repeat offenders. Holds the fewest orders.', 'numra-for-woocommerce' ),
			),
			'BLOCKED_ONLY' => array(
				'label' => __( 'Blacklisted numbers only', 'numra-for-woocommerce' ),
				'desc'  => __( 'Nothing is held on score alone — only numbers the network has blacklisted.', 'numra-for-woocommerce' ),
			),
		);
	}

	/**
	 * The stored band, migrating a legacy numeric threshold on the way.
	 *
	 * Stores upgrading from a version with the 1-100 spinner have a number and
	 * no band. Mapping it here rather than in a migration means the setting
	 * they chose keeps meaning what it meant, without a database write on read
	 * and without asking them to set it again.
	 */
	public static function get_risk_level_threshold() {
		$stored = (string) get_option( self::OPT_RISK_LEVEL, '' );
		if ( in_array( $stored, self::risk_level_order(), true ) ) {
			return $stored;
		}

		// Legacy: map the old score onto the band it used to sit in.
		$n = (int) get_option( self::OPT_RISK_THRESHOLD, self::DEFAULT_THRESHOLD );
		if ( $n >= 80 ) { return 'CRITICAL'; }   // old 80+  ≈ trust under 21
		if ( $n >= 60 ) { return 'HIGH'; }       // old 70   ≈ trust under 41
		return 'MEDIUM';
	}

	/**
	 * Does this resolved level meet the merchant's band?
	 *
	 * @param string $level risk_level from the API: LOW|MEDIUM|HIGH|CRITICAL|UNRATED
	 */
	public static function level_meets_threshold( $level ) {
		$want  = self::get_risk_level_threshold();
		$order = self::risk_level_order();

		// "Blacklisted only" never flags on level — the blacklist is handled
		// separately and unconditionally by the order guard.
		if ( 'BLOCKED_ONLY' === $want ) {
			return false;
		}

		$have = array_search( strtoupper( (string) $level ), $order, true );
		$need = array_search( $want, $order, true );

		/* UNRATED is not in the order and returns false here, which is the
		   intent: a number nobody has ever reported is not evidence, and on a
		   COD store in this market most buyers are first-time — a band that
		   caught them would hold the whole order book.

		   LOW used to be excluded by the same accident. It is now a real
		   choice (see risk_level_order), so a LOW verdict against a LOW
		   threshold flags, and against any stricter threshold does not. */
		return ( false !== $have && false !== $need && $have >= $need );
	}

	/**
	 * Apply guard policy pushed from the merchant's Numra account.
	 *
	 * WHY THIS IS NOT handle_save()
	 * ------------------------------
	 * handle_save() reads `isset($_POST[...]) ? '1' : '0'`, which is correct
	 * for a submitted form and catastrophic for anything else: a field that
	 * was not rendered reads as "unchecked" and the setting destroys itself.
	 * This file carries three separate post-mortems about exactly that. A
	 * heartbeat is not a form submission and must never travel that path.
	 *
	 * Each key is written only when the server actually sent it, so a policy
	 * from an older server that omits a field leaves that field alone rather
	 * than resetting it to a default nobody chose.
	 *
	 * The caller (Numra_Heartbeat::beat) is responsible for only invoking
	 * this when the revision is newer than the one already applied. Without
	 * that gate this would overwrite a merchant's own wp-admin edit on the
	 * next beat, every time.
	 *
	 * @param  array $p policy block from the heartbeat response.
	 * @return bool  true when something was written.
	 */
	public static function apply_remote_policy( $p ) {
		if ( ! is_array( $p ) ) {
			return false;
		}

		$wrote = false;

		if ( isset( $p['guard_enabled'] ) ) {
			update_option( self::OPT_GUARD_ENABLED, ! empty( $p['guard_enabled'] ) ? '1' : '0' );
			$wrote = true;
		}
		if ( isset( $p['auto_hold'] ) ) {
			update_option( self::OPT_AUTOHOLD, ! empty( $p['auto_hold'] ) ? '1' : '0' );
			$wrote = true;
		}
		if ( isset( $p['cod_only'] ) ) {
			update_option( self::OPT_COD_ONLY, ! empty( $p['cod_only'] ) ? '1' : '0' );
			$wrote = true;
		}

		/* An unrecognised band is DISCARDED, not stored and not mapped.
		   Storing it would make get_risk_level_threshold() fall through to
		   the legacy numeric mapping and silently pick a different band than
		   either side intended. */
		if ( isset( $p['flag_threshold'] ) ) {
			$band = strtoupper( (string) $p['flag_threshold'] );
			if ( in_array( $band, self::risk_level_order(), true ) ) {
				update_option( self::OPT_RISK_LEVEL, $band );
				$wrote = true;
			} else {
				Numra_Logger::error( 'Ignoring unknown risk band from Numra: ' . $band );
			}
		}

		/* An empty array is a real answer here — "flag on no customer type" —
		   unlike the styles CATALOGUE, where empty means the server had
		   nothing to say. Distinguished by isset(), so a server that omits
		   the key leaves the merchant's selection alone. */
		if ( isset( $p['flag_styles'] ) && is_array( $p['flag_styles'] ) ) {
			$codes = array();
			foreach ( $p['flag_styles'] as $c ) {
				$c = sanitize_key( (string) $c );
				if ( '' !== $c ) {
					$codes[] = $c;
				}
			}
			update_option( self::OPT_FLAG_STYLES, array_values( array_unique( $codes ) ) );
			$wrote = true;
		}

		return $wrote;
	}

	/** The policy the server last sent, or null. For the admin notice. */
	public static function remote_policy() {
		if ( ! class_exists( 'Numra_Heartbeat' ) ) {
			return null;
		}
		$p = get_option( Numra_Heartbeat::OPT_POLICY, null );
		return ( is_array( $p ) && isset( $p['revision'] ) ) ? $p : null;
	}

	// ── Flagging by customer type ─────────────────────────────────────────────

	/** Style codes the merchant wants held regardless of band. */
	const OPT_FLAG_STYLES = 'numra_flag_styles';

	/**
	 * Which customer types this store holds on sight.
	 *
	 * A band is a summary of how a customer scores. A style is what they
	 * repeatedly DO — a buyer who never answers the phone and a buyer who
	 * refuses at the door can land in the same band and need opposite
	 * handling. Some merchants would rather hold every one of a given type
	 * than reason about where the band falls, and that is a legitimate policy
	 * this plugin could not express.
	 *
	 * Empty by default: this adds nothing to what a store already flags until
	 * a merchant chooses a type.
	 *
	 * @return string[]
	 */
	public static function get_flag_styles() {
		$v = get_option( self::OPT_FLAG_STYLES, array() );
		if ( ! is_array( $v ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $v ) ) );
	}

	/**
	 * Is this customer type one the merchant holds?
	 *
	 * Note what is NOT checked here: whether the phone is rated. A style is
	 * only ever assigned from observed history — the classifier returns
	 * nothing for a number it has not seen — so a non-empty style is itself
	 * evidence. Requiring `is_rated` on top would be a second guard against a
	 * case that cannot occur, and it would silently drop the styles the
	 * merchant asked for.
	 */
	public static function style_is_flagged( $style ) {
		$style = sanitize_key( (string) $style );
		if ( '' === $style ) {
			return false;
		}
		return in_array( $style, self::get_flag_styles(), true );
	}

	/**
	 * WooCommerce status → Numra outcome type.
	 *
	 * Merged over the defaults rather than replacing them, so a store that
	 * saves a mapping for one custom status does not lose the sensible
	 * handling of `completed`. Only outcomes the API accepts survive.
	 *
	 * @return array<string,string>
	 */
	public static function get_status_map() {
		$stored = get_option( self::OPT_STATUS_MAP, array() );
		$map    = Numra_Outcome_Reporter::default_map();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $status => $outcome ) {
				$status  = sanitize_key( $status );
				$outcome = (string) $outcome;
				if ( '' === $status ) {
					continue;
				}
				if ( 'none' === $outcome || '' === $outcome ) {
					unset( $map[ $status ] );   // Explicitly "do not report this status".
					continue;
				}
				if ( in_array( $outcome, Numra_API_Client::OUTCOME_TYPES, true ) ) {
					$map[ $status ] = $outcome;
				}
			}
		}

		return $map;
	}
}
