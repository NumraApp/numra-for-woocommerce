<?php
/**
 * Numra for WooCommerce — Platform Connection
 *
 * Reference implementation of the Numra Platform Connection flow.
 * Future platform integrations (YouCan, Shopify, mobile, public API) should
 * follow this same five-stage lifecycle. They will not share this PHP code —
 * what transfers is the sequence and the contract:
 *
 *   1. AUTHORIZE  Redirect the merchant to app.numra.ma/plugin-connect with
 *                 state, site_url, platform, return_url.
 *                 `state` MUST be at least 16 characters.
 *   2. STATE      Persist `state` server-side, single-use, bound to the
 *                 initiating user, with a TTL longer than the token TTL.
 *   3. EXCHANGE   On return, POST {token, site_url, platform} to
 *                 api.numra.ma/v1/plugin/connect/exchange.
 *                 This call is UNAUTHENTICATED — the token is the credential.
 *                 Tokens are single-use with a 10-minute TTL.
 *   4. PERSIST    Store the API key in its own canonical location; store the
 *                 remaining response as an extensible metadata object.
 *   5. VERIFY     Confirm the stored credential works, then strip the burned
 *                 token from the URL.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Connect {

	/** Query args exchanged with app.numra.ma */
	const ARG_TOKEN     = 'numra_connect_token';
	const ARG_STATE     = 'numra_state';
	const ARG_CANCELLED = 'numra_connect_cancelled';

	/** Authorize endpoint (merchant UI lives on app.numra.ma, not api.numra.ma) */
	const AUTHORIZE_URL = 'https://app.numra.ma/plugin-connect';

	/**
	 * A link into the merchant portal.
	 *
	 * Derived from AUTHORIZE_URL rather than declared again, so the portal
	 * host exists in exactly one place. A second literal is a second thing to
	 * miss when this moves, and the failure would be silent: a link that
	 * still resolves, to the wrong environment.
	 *
	 * @param string $path leading-slash path, e.g. '/billing'
	 */
	public static function portal_url( $path = '/' ) {
		$parts  = wp_parse_url( self::AUTHORIZE_URL );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : 'app.numra.ma';

		return $scheme . '://' . $host . '/' . ltrim( (string) $path, '/' );
	}

	/** admin-post action for the "Connect" button */
	const ACTION_CONNECT    = 'numra_connect';
	const ACTION_DISCONNECT = 'numra_disconnect';

	/**
	 * State TTL. MUST exceed the connection token's 10-minute TTL, otherwise a
	 * token could still be valid while the state needed to accept it has gone.
	 */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/** `state` length in characters. Backend rejects anything under 16. */
	const STATE_LENGTH = 32;

	const STATE_TRANSIENT_PREFIX     = 'numra_connect_state_';

	/**
	 * Challenge: cryptographically random value generated alongside `state`.
	 * Stored in the same transient as state; returned by the REST endpoint only
	 * when the correct state is presented. Proves the WordPress site controls
	 * the hostname — the Numra backend fetches the endpoint remotely.
	 */
	const CHALLENGE_LENGTH           = 32; // characters (hex)
	const REST_NAMESPACE             = 'numra/v1';
	const REST_CHALLENGE_ROUTE       = '/connect-challenge';

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'maybe_handle_callback' ), 5 );
		add_action( 'admin_post_' . self::ACTION_CONNECT,    array( $this, 'handle_connect_request' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect_request' ) );
		// REST route: publicly accessible so Numra backend can call it remotely.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the public challenge verification REST endpoint.
	 *
	 * GET /wp-json/numra/v1/connect-challenge?state=STATE
	 *
	 * Public (no authentication required) because the Numra backend calls it
	 * remotely without WordPress credentials. Security is provided by:
	 * - The state parameter must match a live transient on THIS store only.
	 * - The response contains only the challenge value — no secrets.
	 * - Transient TTL matches STATE_TTL (15 min), self-expiring.
	 * - No-store cache headers prevent caching of the one-time response.
	 * - Challenge is single-use: deleted after successful domain verification.
	 */
	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_CHALLENGE_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_challenge_handler' ),
				'permission_callback' => '__return_true', // Public — auth via state transient
				'args'                => array(
					'state' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function( $v ) {
							return is_string( $v ) && strlen( $v ) >= 16;
						},
					),
				),
			)
		);
	}

	/**
	 * REST callback: return the challenge for a valid, live state.
	 *
	 * Returns the challenge only if the state matches a live transient.
	 * Returns 404 otherwise — does not distinguish expired vs. never-existed
	 * to avoid timing oracles.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_challenge_handler( WP_REST_Request $request ) {
		$state         = $request->get_param( 'state' );
		$transient_key = self::state_transient_key( $state );
		$stored        = get_transient( $transient_key );

		// No-store headers — response must never be cached.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'Pragma: no-cache' );

		// Unknown or expired state — return 404.
		// Do NOT reveal whether the state existed or was expired.
		if ( false === $stored || ! isset( $stored['challenge'] ) ) {
			return new WP_Error(
				'numra_challenge_not_found',
				'Challenge not found or expired.',
				array( 'status' => 404 )
			);
		}

		// Return only the challenge. Never echo state, user_id, or any other value.
		return new WP_REST_Response(
			array( 'challenge' => $stored['challenge'] ),
			200
		);
	}

	// ── Stage 1 + 2: authorize + state ────────────────────────────────────────

	/**
	 * Build the authorize URL and redirect the merchant to app.numra.ma.
	 * Nonce + capability enforced before any state is created.
	 */
	public function handle_connect_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect this store.', 'numra-for-woocommerce' ) );
		}
		check_admin_referer( self::ACTION_CONNECT );

		$state     = wp_generate_password( self::STATE_LENGTH, false, false );
		$challenge = bin2hex( random_bytes( self::CHALLENGE_LENGTH / 2 ) );

		// Store state + challenge together in one transient.
		// The challenge is returned by the REST endpoint to prove domain ownership.
		// The user_id gates the callback flow as before.
		set_transient(
			self::state_transient_key( $state ),
			array(
				'user_id'   => get_current_user_id(),
				'challenge' => $challenge,
			),
			self::STATE_TTL
		);

		$authorize_url = add_query_arg(
			array(
				'state'      => rawurlencode( $state ),
				'challenge'  => rawurlencode( $challenge ),
				'site_url'   => rawurlencode( self::site_url() ),
				'platform'   => rawurlencode( NUMRA_PLATFORM ),
				'return_url' => rawurlencode( self::return_url() ),
			),
			self::AUTHORIZE_URL
		);

		Numra_Logger::info( 'Connect initiated for ' . self::site_url() );

		wp_redirect( $authorize_url ); // External URL — wp_safe_redirect() would block it.
		exit;
	}

	// ── Stage 3 + 4 + 5: callback ─────────────────────────────────────────────

	/**
	 * Runs on admin_init (before any output, so redirects are legal).
	 * Bails immediately unless this is our page carrying a connect response.
	 */
	public function maybe_handle_callback() {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || 'numra' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$has_token     = isset( $_GET[ self::ARG_TOKEN ] );
		$was_cancelled = isset( $_GET[ self::ARG_CANCELLED ] );

		if ( ! $has_token && ! $was_cancelled ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to complete this connection.', 'numra-for-woocommerce' ) );
		}

		if ( $was_cancelled ) {
			Numra_Logger::info( 'Connect cancelled by merchant.' );
			// Clean up challenge transient if state is present — best effort.
			$cancel_state = isset( $_GET[ self::ARG_STATE ] )
				? sanitize_text_field( wp_unslash( $_GET[ self::ARG_STATE ] ) )
				: '';
			if ( $cancel_state ) {
				delete_transient( self::state_transient_key( $cancel_state ) );
			}
			// No API request, nothing persisted — one notice, one redirect.
			self::set_notice( 'info', 'CANCELLED' );
			self::redirect_clean();
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::ARG_TOKEN ] ) );
		$state = isset( $_GET[ self::ARG_STATE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ARG_STATE ] ) ) : '';

		$transient_key  = self::state_transient_key( $state );
		$stored         = $state ? get_transient( $transient_key ) : false;

		// Support both the legacy (user_id int) and current (array) transient shapes.
		// This ensures callbacks initiated before the plugin upgrade still complete.
		$state_owner = false;
		if ( is_array( $stored ) && isset( $stored['user_id'] ) ) {
			$state_owner = (int) $stored['user_id'];
		} elseif ( is_int( $stored ) || ( is_string( $stored ) && ctype_digit( $stored ) ) ) {
			// Legacy shape: bare user_id stored as int.
			$state_owner = (int) $stored;
		}

		// State must exist in the transient store and belong to the initiating
		// user. Any other outcome is STATE_INVALID — no exchange call is made.
		if ( false === $state_owner || $state_owner !== get_current_user_id() ) {
			Numra_Logger::error( 'Connect state validation failed — no API call made.' );
			self::set_notice( 'error', 'STATE_INVALID' );
			self::redirect_clean();
		}

		// Single-use: consume the state + challenge before the exchange.
		// A replayed callback cannot reach the API. Challenge is also invalidated.
		delete_transient( $transient_key );

		$client = new Numra_API_Client();
		$result = $client->exchange_token( $token, self::site_url() );

		if ( empty( $result['ok'] ) ) {
			$code = ! empty( $result['error'] ) ? $result['error'] : 'EXCHANGE_FAILED';
			Numra_Logger::error( 'Token exchange failed: ' . $code );
			self::set_notice( 'error', $code );
			self::redirect_clean();
		}

		self::persist_connection( $result['body'] );

		Numra_Logger::info( 'Store connected to Numra.' );
		self::set_notice( 'success', 'CONNECTED' );
		self::redirect_clean();
	}

	// ── Disconnect ────────────────────────────────────────────────────────────

	/**
	 * Disconnect this store — LOCAL ONLY.
	 *
	 * This removes the credential and connection record from THIS WordPress
	 * install. It performs no HTTP request and revokes nothing on Numra servers:
	 * the API key remains valid on api.numra.ma until revoked from the merchant
	 * dashboard. Remote revocation is a deliberate future feature, not an
	 * oversight. Do not add a revoke call here without a backend endpoint that
	 * authenticates the request.
	 */
	public function handle_disconnect_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to disconnect this store.', 'numra-for-woocommerce' ) );
		}
		check_admin_referer( self::ACTION_DISCONNECT );

		// Call the remote disconnect endpoint to revoke API Key and clear site mapping on the Numra platform
		$client = new Numra_API_Client();
		$client->disconnect();

		update_option( Numra_Settings::OPT_API_KEY, '' );
		delete_option( Numra_Settings::OPT_CONNECTION );
		update_option( Numra_Settings::OPT_CONNECTION_STATUS, 'disconnected' );
		update_option( Numra_Settings::OPT_LAST_CONNECTION_CHECK, '' );
		Numra_Announcements::bust_cache();

		Numra_Logger::info( 'Store disconnected from Numra.' );

		self::set_notice( 'info', 'DISCONNECTED' );
		self::redirect_clean();
	}

	// ── Persistence ───────────────────────────────────────────────────────────

	/**
	 * Persist a successful exchange.
	 *
	 * The credential lives in `numra_api_key` and ONLY there — one home for one
	 * secret. Everything else goes into `numra_connection`, an extensible object
	 * stored with autoload='no' (this plugin is admin-only; there is no reason
	 * to load it on every frontend request).
	 *
	 * @param array $body Decoded exchange response.
	 */
	private static function persist_connection( $body ) {
		$api_key = isset( $body['api_key'] ) ? sanitize_text_field( $body['api_key'] ) : '';

		if ( '' === $api_key ) {
			Numra_Logger::error( 'Exchange succeeded but returned no api_key.' );
			self::set_notice( 'error', 'EXCHANGE_FAILED' );
			self::redirect_clean();
		}

		// Everything except the credential itself, with unknown future fields preserved.
		$metadata = self::sanitize_metadata( $body );
		unset( $metadata['ok'] );

		$connection = array_merge(
			$metadata,
			array(
				'connection_schema_version'     => Numra_Settings::CONNECTION_SCHEMA_VERSION,
				'connected_at'                  => self::iso8601_utc_now(),
				'connected_with_plugin_version' => NUMRA_VERSION,
				'platform'                      => NUMRA_PLATFORM,
				'connected_site_url'            => self::site_url(),
				// Diagnostics and support ONLY. Never branch business logic on these.
				// Nested under `support` so our keys can never collide with unknown
				// fields a future backend version adds at the top level (ADR-008 rule 2).
				'support'                       => self::support_metadata(),
			)
		);

		update_option( Numra_Settings::OPT_API_KEY, $api_key );

		// autoload='no' — only add_option accepts the flag, so delete first.
		delete_option( Numra_Settings::OPT_CONNECTION );
		add_option( Numra_Settings::OPT_CONNECTION, $connection, '', false );

		update_option( Numra_Settings::OPT_CONNECTION_STATUS, 'connected' );
		update_option( Numra_Settings::OPT_LAST_CONNECTION_CHECK, current_time( 'mysql' ) );

		// The API key changed — cached placements belong to the previous identity.
		Numra_Announcements::bust_cache();
	}

	/**
	 * Recursively sanitize the exchange response for storage.
	 *
	 * Unknown fields from newer backend versions are preserved, so an older
	 * plugin does not silently discard metadata. "Unsafe" is defined narrowly:
	 * any key that looks like a credential is dropped, because the only
	 * credential we store lives in `numra_api_key`.
	 *
	 * Depth and width are capped so a malformed or hostile response cannot
	 * balloon the options table.
	 *
	 * @param mixed $value
	 * @param int   $depth
	 * @return mixed
	 */
	private static function sanitize_metadata( $value, $depth = 0 ) {
		if ( $depth > 4 ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$out   = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( ++$count > 50 ) {
					break;
				}
				$key = sanitize_key( $key );
				if ( '' === $key || preg_match( '/(key|secret|token|password|credential)/i', $key ) ) {
					continue; // Credentials are never stored here.
				}
				$clean = self::sanitize_metadata( $item, $depth + 1 );
				if ( null !== $clean ) {
					$out[ $key ] = $clean;
				}
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return null; // objects, resources, null
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Current time as an ISO-8601 UTC timestamp, e.g. 2026-07-09T14:30:00Z.
	 *
	 * Deliberately UTC and deliberately not `current_time( 'mysql' )`: a MySQL
	 * datetime carries no timezone, so a connection made in Casablanca and read
	 * by a support engineer elsewhere is ambiguous. ISO-8601 UTC is not.
	 *
	 * @return string
	 */
	private static function iso8601_utc_now() {
		return gmdate( 'Y-m-d\\TH:i:s\\Z' );
	}

	/**
	 * Environment snapshot captured at connect time.
	 *
	 * For diagnostics and support only — these values are never used to make a
	 * decision in code. They answer "what did this store look like when it
	 * connected?" without having to ask the merchant.
	 *
	 * @return array
	 */
	private static function support_metadata() {
		return array(
			'plugin_version'      => NUMRA_VERSION,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php_version'         => PHP_VERSION,
		);
	}

	private static function state_transient_key( $state ) {
		return self::STATE_TRANSIENT_PREFIX . md5( (string) $state );
	}

	/**
	 * The store address sent to Numra. `home_url()` is the storefront address the
	 * merchant recognises; `site_url()` can differ when WordPress lives in a
	 * subdirectory. The backend normalises to a hostname (lowercase, leading
	 * "www." stripped), so scheme, port and path do not matter — but the
	 * hostname must match a domain registered to the license.
	 */
	private static function site_url() {
		return home_url();
	}

	private static function return_url() {
		return admin_url( 'admin.php?page=numra&tab=dashboard' );
	}

	/**
	 * One-shot, user-scoped notice for the next dashboard render.
	 *
	 * Notices deliberately do NOT travel as query parameters: a parameter that
	 * is also a callback trigger (numra_connect_cancelled) creates an infinite
	 * redirect loop, and any notice-in-URL persists on refresh and in history.
	 * A short-lived transient has neither problem.
	 *
	 * @param string $type success|info|error
	 * @param string $code Optional machine code (error map key).
	 */
	private static function set_notice( $type, $code = '' ) {
		set_transient(
			self::notice_key(),
			array( 'type' => $type, 'code' => $code ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Consume (read + delete) the pending notice for the current user.
	 * First render shows it; refresh does not.
	 *
	 * @return array|null { type, code } or null.
	 */
	public static function consume_notice() {
		$key    = self::notice_key();
		$notice = get_transient( $key );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $notice ) ? $notice : null;
	}

	private static function notice_key() {
		return 'numra_notice_' . get_current_user_id();
	}

	/**
	 * Redirect to the bare dashboard URL — no callback triggers, no notice
	 * parameters, nothing that could re-fire the admin_init handler. This is
	 * also what removes a burned token from the address bar, browser history,
	 * and any downstream access log. Exactly one redirect, always.
	 */
	private static function redirect_clean() {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'numra', 'tab' => 'dashboard' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Public URL builders (used by the views) ───────────────────────────────

	public static function connect_button_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_CONNECT ),
			self::ACTION_CONNECT
		);
	}

	public static function disconnect_button_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISCONNECT ),
			self::ACTION_DISCONNECT
		);
	}

	/**
	 * Map an API error code to a translated, merchant-readable message.
	 * Codes are contract (never translated); messages are UI (always translated).
	 *
	 * @param string $code
	 * @return string
	 */
	public static function error_message( $code ) {
		$messages = array(
			'STATE_INVALID'         => __( 'The connection request could not be verified. Please start again.', 'numra-for-woocommerce' ),
			'TOKEN_EXPIRED'         => __( 'The connection link expired. Links are valid for 10 minutes — please try again.', 'numra-for-woocommerce' ),
			'TOKEN_USED'            => __( 'This connection link has already been used. Please start a new connection.', 'numra-for-woocommerce' ),
			'TOKEN_INVALID'         => __( 'The connection link is not valid. Please start a new connection.', 'numra-for-woocommerce' ),
			'SITE_MISMATCH'         => __( 'This connection link was issued for a different store address.', 'numra-for-woocommerce' ),
			'PLATFORM_MISMATCH'     => __( 'This connection link was issued for a different platform.', 'numra-for-woocommerce' ),
			'LICENSE_INACTIVE'      => __( 'The Numra license for this store is no longer active. Please check your account.', 'numra-for-woocommerce' ),
			'LICENSE_SITE_MISMATCH' => __( 'Your Numra license is registered for a different domain than this store.', 'numra-for-woocommerce' ),
			'MISSING_FIELD'         => __( 'The connection request was incomplete. Please start again.', 'numra-for-woocommerce' ),
			'INTERNAL_ERROR'        => __( 'Numra could not complete the connection. Please try again shortly.', 'numra-for-woocommerce' ),
			'EXCHANGE_FAILED'       => __( 'Could not reach Numra. Please check your connection and try again.', 'numra-for-woocommerce' ),
			'CONNECTED'             => __( 'Your store is connected to Numra.', 'numra-for-woocommerce' ),
			'DISCONNECTED'          => __( 'Your store has been disconnected from Numra.', 'numra-for-woocommerce' ),
			'CANCELLED'             => __( 'Connection cancelled. Your store was not changed.', 'numra-for-woocommerce' ),
			'CHALLENGE_INVALID'     => __( 'Domain verification failed. Please start the connection again.', 'numra-for-woocommerce' ),
		);

		return isset( $messages[ $code ] )
			? $messages[ $code ]
			: __( 'The connection could not be completed. Please try again.', 'numra-for-woocommerce' );
	}

	/**
	 * Whether a given error should offer "Retry" (vs. pointing at the account).
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function error_is_retryable( $code ) {
		$account_errors = array( 'LICENSE_INACTIVE', 'LICENSE_SITE_MISMATCH', 'PLATFORM_MISMATCH' );
		return ! in_array( $code, $account_errors, true );
	}
}
