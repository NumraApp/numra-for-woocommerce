<?php
/**
 * Numra for WooCommerce — API Client
 * All communication with api.numra.ma goes through this class.
 * Never exposes the API key to the frontend.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_API_Client {

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	/** Timeout in seconds for wp_remote_* calls */
	const TIMEOUT = 10;

	/**
	 * Shorter ceiling for the one call that happens while a customer waits.
	 * Ten seconds of dead air on checkout is worse than an unscored order,
	 * and the order is recoverable — the merchant can rescore it.
	 */
	const LOOKUP_TIMEOUT = 5;

	/**
	 * Outcome types the API accepts. Mirrors the server's zod enum in
	 * apps/api/routes/phone.js; sending anything else is a 400 for the whole
	 * request, so this list is validated client-side first.
	 */
	const OUTCOME_TYPES = array(
		'DELIVERED',
		'PAID_ONLINE',
		'REFUSED_COD',
		'CANCELLED',
		'NO_ANSWER',
		'FRAUD_CONFIRMED',
		'RETURNED',
	);

	/**
	 * v1 is Morocco-only and the server enforces it: a request without
	 * X-Country: MA is refused with 403 COUNTRY_NOT_ALLOWED.
	 */
	const COUNTRY = 'MA';

	public function __construct() {
		$this->api_key  = (string) get_option( 'numra_api_key', '' );
		// Pinned to production (or the code-level override constant). The
		// legacy numra_api_base_url option is intentionally never consulted.
		$this->base_url = Numra_Settings::get_api_base_url();
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Build common request headers.
	 * API key is sent as Bearer token — never printed in page source.
	 */
	private function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Headers for the phone endpoints, which additionally require the country.
	 */
	private function country_headers() {
		return array_merge( $this->headers(), array( 'X-Country' => self::COUNTRY ) );
	}

	/**
	 * Normalise a wp_remote_* response into a simple result array.
	 *
	 * @param array|WP_Error $response Raw wp_remote_* return value.
	 * @return array { ok: bool, status: int, body: array|null, error: string }
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			Numra_Logger::error( 'API request failed: ' . $response->get_error_message() );
			
			// Proactively notify status of network/timeout (uses 0 to indicate temporary issue)
			Numra_Settings::sync_status_from_response_code( 0 );

			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$body   = json_decode( $raw, true );

		$error_code = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : '';
		Numra_Logger::info( 'API response HTTP ' . (int) $status . ( '' !== $error_code ? ' error=' . $error_code : '' ) );

		// Proactively notify status of HTTP response code
		Numra_Settings::sync_status_from_response_code( $status );

		return array(
			'ok'     => ( $status >= 200 && $status < 300 ) && ! empty( $body['ok'] ),
			'status' => (int) $status,
			'body'   => $body,
			'error'  => isset( $body['error'] ) ? $body['error'] : '',
		);
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Ask the platform for authoritative state.
	 *
	 * Deliberately does NOT route through parse_response(). That helper calls
	 * Numra_Settings::sync_status_from_response_code(), which reads a 200 as
	 * "connected" — and this endpoint answers 200 even when the key is revoked,
	 * because a status report needs a body the store can render an alert from.
	 * Routing through it would briefly mark a revoked store connected before
	 * the heartbeat corrected it, and the flap would be visible in the badge.
	 *
	 * @return array { ok: bool, status: int, body: array|null, error: string }
	 */
	public function heartbeat() {
		if ( empty( $this->api_key ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => null, 'error' => 'NO_API_KEY' );
		}

		$payload = array(
			'platform'         => 'woocommerce',
			'platform_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'plugin_version'   => defined( 'NUMRA_VERSION' ) ? NUMRA_VERSION : null,
			'site_url'         => home_url(),
		);

		/* What this store answered about syncing its history.
		   ─────────────────────────────────────────────────────────────────
		   Reported so support and the control panel can see the answer
		   without asking the merchant to screenshot their settings. It rides
		   the existing beat rather than a new endpoint: this call already
		   carries platform and version telemetry, and a second request would
		   be a second thing to fail.

		   The answer is reported, never received. Nothing in the response is
		   allowed to change it — consent is given and withdrawn in the
		   merchant's own admin, and a server that could flip it would make
		   the asking meaningless. */
		if ( class_exists( 'Numra_Backfill' ) ) {
			$state = Numra_Backfill::state();   // not_asked|declined|running|complete

			$payload['sync_consent'] = ( 'not_asked' === $state )
				? 'not_asked'
				: ( 'declined' === $state ? 'no' : 'yes' );

			$payload['sync_orders_logged'] = (int) Numra_Backfill::logged();
			$payload['sync_complete']      = ( 'complete' === $state );
		}

		/* `/v1/`, not `/`.
		   ─────────────────────────────────────────────────────────────────
		   This line read `/plugin/heartbeat` for its whole life. The base URL
		   is `https://api.numra.ma` with no version segment (see
		   Numra_Settings::get_api_base_url), the router is mounted at
		   `/v1/plugin` (apps/api/index.js), and nginx has no rewrite — so
		   every beat this plugin has ever sent hit the catch-all 404 and came
		   back ENDPOINT_NOT_FOUND. `ok` was false, so the store recorded
		   `unavailable`, kept whatever state it already had forever, and
		   never received its customer styles.

		   Every other call in this class already spells it correctly —
		   `/v1/growth/render`, `/v1/plugin/connect/exchange`. This one was
		   the odd one out, and because a failed beat degrades quietly by
		   design, nothing ever complained. */
		$response = wp_remote_post(
			$this->base_url . '/v1/plugin/heartbeat',
			array(
				'headers' => array_merge( $this->headers(), array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => self::LOOKUP_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			Numra_Logger::error( 'Heartbeat failed: ' . $response->get_error_message() );
			return array( 'ok' => false, 'status' => 0, 'body' => null, 'error' => $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'ok'     => ( $status >= 200 && $status < 300 ) && ! empty( $body['ok'] ),
			'status' => $status,
			'body'   => is_array( $body ) ? $body : null,
			'error'  => isset( $body['error'] ) ? (string) $body['error'] : '',
		);
	}

	/**
	 * Test connection — uses the Growth Center render endpoint.
	 * A 200 ok:true means the key is valid; placements may be empty (flag off).
	 *
	 * @return array { ok: bool, status: int, error_code: string, message: string }
	 */
	public function test_connection() {
		if ( empty( $this->api_key ) ) {
			return array(
				'ok'         => false,
				'status'     => 0,
				'error_code' => 'API_KEY_INVALID',
				'message'    => __( 'API key is not set.', 'numra-for-woocommerce' )
			);
		}

		$url      = $this->base_url . '/v1/growth/render?placement=plugin.woocommerce.settings';
		$response = wp_remote_get( $url, array(
			'headers' => $this->headers(),
			'timeout' => self::TIMEOUT,
		) );

		$result = $this->parse_response( $response );

		if ( $result['status'] === 401 ) {
			return array(
				'ok'         => false,
				'status'     => 401,
				'error_code' => 'API_KEY_INVALID',
				'message'    => __( 'API key is missing or invalid.', 'numra-for-woocommerce' )
			);
		}
		if ( $result['status'] === 403 ) {
			$raw_error = isset( $result['body']['error'] ) ? $result['body']['error'] : 'LICENSE_INACTIVE';
			$error_code = $raw_error;
			$msg = __( 'API key is invalid or license is inactive.', 'numra-for-woocommerce' );

			if ( $raw_error === 'KEY_REVOKED' ) {
				$error_code = 'API_KEY_REVOKED';
				$msg = __( 'API key has been revoked.', 'numra-for-woocommerce' );
			} elseif ( $raw_error === 'LICENSE_EXPIRED' ) {
				$msg = __( 'Your Numra license has expired.', 'numra-for-woocommerce' );
			} elseif ( $raw_error === 'LICENSE_INACTIVE' ) {
				$msg = __( 'The Numra license is suspended or inactive.', 'numra-for-woocommerce' );
			}

			return array(
				'ok'         => false,
				'status'     => 403,
				'error_code' => $error_code,
				'message'    => $msg
			);
		}
		if ( $result['ok'] ) {
			return array(
				'ok'         => true,
				'status'     => $result['status'],
				'error_code' => '',
				'message'    => __( 'Connection successful.', 'numra-for-woocommerce' )
			);
		}

		$error_code = 'SERVICE_UNAVAILABLE';
		if ( $result['status'] === 429 ) {
			$error_code = 'RATE_LIMITED';
		}

		return array(
			'ok'         => false,
			'status'     => $result['status'],
			'error_code' => $error_code,
			/* translators: %s: backend error identifier or 'unknown error' */
			'message'    => sprintf( __( 'Connection failed: %s', 'numra-for-woocommerce' ), $result['error'] ?: __( 'temporary server issue', 'numra-for-woocommerce' ) ),
		);
	}

	/**
	 * Exchange a one-time connection token for the store's API key.
	 *
	 * This is the ONLY unauthenticated call the plugin makes: the token itself
	 * is the credential, so headers() (which always attaches a Bearer token) is
	 * deliberately NOT used here — at this point no API key exists yet.
	 *
	 * Tokens are single-use with a 10-minute TTL and are bound to the merchant,
	 * license, site_url, platform and state they were issued for.
	 *
	 * @param string $token    Raw connection token from the callback.
	 * @param string $site_url The store address (home_url()).
	 * @return array { ok: bool, status: int, body: mixed, error: string }
	 */
	public function exchange_token( $token, $site_url ) {
		$response = wp_remote_post(
			$this->base_url . '/v1/plugin/connect/exchange',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'token'    => $token,
						'site_url' => $site_url,
						'platform' => NUMRA_PLATFORM,
					)
				),
				'timeout' => self::TIMEOUT,
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Fetch Growth Center placements for a plugin slot.
	 *
	 * @param string $placement_key  e.g. 'plugin.woocommerce.settings'
	 * @return array  Placements array (may be empty). Never throws.
	 */
	public function get_growth_placements( $placement_key ) {
		if ( empty( $this->api_key ) ) {
			return array();
		}

		$url = add_query_arg(
			'placement',
			rawurlencode( $placement_key ),
			$this->base_url . '/v1/growth/render'
		);

		$response = wp_remote_get( $url, array(
			'headers' => $this->headers(),
			'timeout' => self::TIMEOUT,
		) );

		$result = $this->parse_response( $response );

		if ( ! $result['ok'] ) {
			Numra_Logger::warning( "Growth render failed for $placement_key: " . $result['error'] );
			return array();
		}

		$placements = isset( $result['body']['placements'] ) ? $result['body']['placements'] : array();

		// Sanitize each placement field before returning.
		return array_map( array( $this, 'sanitize_placement' ), $placements );
	}

	/**
	 * Send a Growth Center event.
	 *
	 * @param string $placement_id
	 * @param string $event_type   VIEW|IMPRESSION|CLICK|DISMISS|CONVERSION
	 * @param string $placement_key
	 * @param array  $meta
	 * @return bool
	 */
	public function send_growth_event( $placement_id, $event_type, $placement_key, $meta = array() ) {
		if ( empty( $this->api_key ) ) {
			return false;
		}

		$allowed_types = array( 'VIEW', 'IMPRESSION', 'CLICK', 'DISMISS', 'CONVERSION' );
		if ( ! in_array( $event_type, $allowed_types, true ) ) {
			return false;
		}

		$body = wp_json_encode( array(
			'placement_id'  => sanitize_text_field( $placement_id ),
			'event_type'    => $event_type,
			'placement_key' => sanitize_text_field( $placement_key ),
			'meta'          => array_merge(
				array( 'platform' => NUMRA_PLATFORM ),
				(array) $meta
			),
		) );

		$url      = $this->base_url . '/v1/growth/events';
		$response = wp_remote_post( $url, array(
			'headers' => $this->headers(),
			'body'    => $body,
			'timeout' => self::TIMEOUT,
		) );

		$result = $this->parse_response( $response );

		if ( ! $result['ok'] ) {
			Numra_Logger::warning( "Growth event $event_type failed: " . $result['error'] );
		}

		return $result['ok'];
	}

	/**
	 * Send a remote disconnect request to notify the platform.
	 *
	 * @return array { ok: bool, status: int, message: string }
	 */
	public function disconnect() {
		if ( empty( $this->api_key ) ) {
			return array( 'ok' => false, 'status' => 0, 'message' => 'API key is not set.' );
		}

		$url      = $this->base_url . '/v1/plugin/connect/disconnect';
		$response = wp_remote_post( $url, array(
			'headers' => $this->headers(),
			'timeout' => self::TIMEOUT,
		) );

		return $this->parse_response( $response );
	}

	// ── Phone intelligence ────────────────────────────────────────────────────

	/**
	 * Score a phone number.  POST /v1/phone/lookup
	 *
	 * Runs while an order is being created, so it uses LOOKUP_TIMEOUT rather
	 * than the class default: a slow API must never hold a customer on the
	 * checkout page. Every failure path returns ok=false and the caller lets
	 * the order through — see Numra_Order_Guard for why this fails open.
	 *
	 * The endpoint consumes one credit per call, so callers must not retry it.
	 *
	 * @param string $phone   Raw billing phone; the server normalises to E.164.
	 * @param array  $context Optional { payment_method, order_total, region, currency }.
	 * @return array {
	 *   ok: bool, status: int, error: string,
	 *   score: int|null, level: string, blacklisted: bool, reason: string,
	 *   carrier_code: string, carrier_label: string, phone: string
	 * }
	 */
	public function lookup_phone( $phone, $context = array() ) {
		/* The failure shape must carry every key the success shape does, or a
		   caller that reads a new field on a failed lookup hits an undefined
		   index — a PHP notice on the merchant's order screen, from the exact
		   path that is supposed to fail quietly and let the order through. */
		$empty = array(
			'ok'            => false,
			'status'        => 0,
			'error'         => '',
			'score'         => null,
			'level'         => '',
			'verdict'       => '',
			'verdict_source'=> '',
			'trust_score'   => null,
			'rated'         => false,
			'style'         => '',
			'style_code'    => '',
			'style_icon'    => '',
			'blacklisted'   => false,
			'reason'        => '',
			'carrier_code'  => '',
			'carrier_label' => '',
			'phone'         => '',
		);

		if ( empty( $this->api_key ) ) {
			$empty['error'] = 'API_KEY_MISSING';
			return $empty;
		}

		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			$empty['error'] = 'PHONE_EMPTY';
			return $empty;
		}

		$payload = array( 'phone' => $phone );

		// Only send context keys the server's schema accepts, with the types it
		// expects — order_total is a number there, and a string would fail
		// validation for the whole request.
		$ctx = array();
		if ( ! empty( $context['payment_method'] ) ) { $ctx['payment_method'] = substr( sanitize_text_field( $context['payment_method'] ), 0, 100 ); }
		if ( isset( $context['order_total'] ) && is_numeric( $context['order_total'] ) ) { $ctx['order_total'] = (float) $context['order_total']; }
		if ( ! empty( $context['region'] ) )   { $ctx['region']   = substr( sanitize_text_field( $context['region'] ), 0, 100 ); }
		if ( ! empty( $context['currency'] ) ) { $ctx['currency'] = substr( sanitize_text_field( $context['currency'] ), 0, 3 ); }
		if ( $ctx ) { $payload['context'] = $ctx; }

		$response = wp_remote_post( $this->base_url . '/v1/phone/lookup', array(
			'headers' => $this->country_headers(),
			'timeout' => self::LOOKUP_TIMEOUT,
			'body'    => wp_json_encode( $payload ),
		) );

		$result = $this->parse_response( $response );
		$body   = is_array( $result['body'] ) ? $result['body'] : array();

		if ( ! $result['ok'] ) {
			$empty['status'] = $result['status'];
			$empty['error']  = $result['error'] ? (string) $result['error'] : 'REQUEST_FAILED';
			return $empty;
		}

		/* `verdict` and `is_rated` are the two fields that make an unknown
		   number distinguishable from a middling one.

		   The API returns a neutral risk_score of 50 for a phone it has never
		   seen — deliberately, because pretending an unknown consumer is a 0
		   is exactly the number a fraudster benefits from. But a plugin that
		   only reads the number cannot tell "we have no history" from "we have
		   history and it is mediocre", and would compare 50 against the
		   merchant's flag threshold as though it meant something. `is_rated`
		   is false in the first case; nothing downstream should threshold it. */
		$verdict = isset( $body['verdict'] ) ? sanitize_text_field( (string) $body['verdict'] ) : '';

		return array(
			'ok'            => true,
			'status'        => $result['status'],
			'error'         => '',
			'score'         => isset( $body['risk_score'] ) ? (int) $body['risk_score'] : null,
			'level'         => isset( $body['risk_level'] ) ? sanitize_text_field( (string) $body['risk_level'] ) : '',
			'verdict'       => $verdict,
			'verdict_source'=> isset( $body['verdict_source'] ) ? sanitize_text_field( (string) $body['verdict_source'] ) : '',
			'trust_score'   => isset( $body['trust_score'] ) ? (int) $body['trust_score'] : null,
			/* Trust the server's own flag when present; fall back to the
			   verdict string so an older API still behaves sanely. */
			'rated'         => isset( $body['is_rated'] )
			                     ? (bool) $body['is_rated']
			                     : ( '' !== $verdict && 'UNRATED' !== $verdict ),
			'style'         => isset( $body['customer_style']['label'] )
			                     ? sanitize_text_field( (string) $body['customer_style']['label'] ) : '',
			'style_code'    => isset( $body['customer_style']['code'] )
			                     ? sanitize_text_field( (string) $body['customer_style']['code'] ) : '',
			'style_icon'    => isset( $body['customer_style']['icon'] )
			                     ? sanitize_text_field( (string) $body['customer_style']['icon'] ) : '',
			'blacklisted'   => ! empty( $body['is_blacklisted'] ),
			'reason'        => isset( $body['blacklisted_reason'] ) ? sanitize_text_field( (string) $body['blacklisted_reason'] ) : '',
			'carrier_code'  => isset( $body['carrier']['code'] )  ? sanitize_text_field( (string) $body['carrier']['code'] )  : '',
			'carrier_label' => isset( $body['carrier']['label'] ) ? sanitize_text_field( (string) $body['carrier']['label'] ) : '',
			'phone'         => isset( $body['phone'] ) ? sanitize_text_field( (string) $body['phone'] ) : '',
		);
	}

	/**
	 * Report what actually happened to an order.  POST /v1/phone/outcome
	 *
	 * The server is idempotent on (merchant, order_id, outcome_type), so a
	 * retry after a timeout is safe and a duplicate is a 200 no-op. That is
	 * what lets Numra_Outcome_Reporter retry without bookkeeping.
	 *
	 * @param string $phone
	 * @param string $order_id     Store-local order id; 1-100 chars.
	 * @param string $outcome_type One of the OUTCOME_TYPES below.
	 * @param array  $meta         Optional { order_total, currency, region, note }.
	 * @return array { ok, status, error }
	 */
	public function report_outcome( $phone, $order_id, $outcome_type, $meta = array() ) {
		if ( empty( $this->api_key ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'API_KEY_MISSING' );
		}
		if ( ! in_array( $outcome_type, self::OUTCOME_TYPES, true ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'INVALID_OUTCOME_TYPE' );
		}

		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'PHONE_EMPTY' );
		}

		$payload = array(
			'phone'        => $phone,
			'order_id'     => substr( (string) $order_id, 0, 100 ),
			'outcome_type' => $outcome_type,
		);

		// order_total must be POSITIVE per the server schema — a zero-value
		// order would fail validation for the whole request, so it is omitted.
		if ( isset( $meta['order_total'] ) && is_numeric( $meta['order_total'] ) && (float) $meta['order_total'] > 0 ) {
			$payload['order_total'] = (float) $meta['order_total'];
		}
		if ( ! empty( $meta['currency'] ) && 3 === strlen( (string) $meta['currency'] ) ) {
			$payload['currency'] = sanitize_text_field( (string) $meta['currency'] );
		}
		if ( ! empty( $meta['region'] ) ) { $payload['region'] = substr( sanitize_text_field( $meta['region'] ), 0, 100 ); }
		if ( ! empty( $meta['note'] ) )   { $payload['note']   = substr( sanitize_text_field( $meta['note'] ), 0, 500 ); }

		$response = wp_remote_post( $this->base_url . '/v1/phone/outcome', array(
			'headers' => $this->country_headers(),
			'timeout' => self::TIMEOUT,
			'body'    => wp_json_encode( $payload ),
		) );

		$result = $this->parse_response( $response );

		return array(
			'ok'     => (bool) $result['ok'],
			'status' => (int) $result['status'],
			'error'  => $result['error'] ? (string) $result['error'] : '',
		);
	}

	// ── Internal sanitizer ────────────────────────────────────────────────────

	/**
	 * Sanitize a raw placement object from the API response.
	 * Ensures only expected fields survive and all strings are clean.
	 */
	private function sanitize_placement( $p ) {
		return array(
			'id'            => isset( $p['id'] )            ? sanitize_text_field( $p['id'] )            : '',
			'content_type'  => isset( $p['content_type'] )  ? sanitize_text_field( $p['content_type'] )  : '',
			'placement_key' => isset( $p['placement_key'] ) ? sanitize_text_field( $p['placement_key'] ) : '',
			'template_key'  => isset( $p['template_key'] )  ? sanitize_text_field( $p['template_key'] )  : '',
			'theme_variant' => isset( $p['theme_variant'] ) ? sanitize_text_field( $p['theme_variant'] ) : 'BLUE',
			'title'         => isset( $p['title'] )         ? sanitize_text_field( $p['title'] )         : '',
			'message'       => isset( $p['message'] )       ? sanitize_textarea_field( $p['message'] )   : '',
			'action_label'  => isset( $p['action_label'] )  ? sanitize_text_field( $p['action_label'] )  : '',
			'action_url'    => isset( $p['action_url'] )    ? esc_url_raw( $p['action_url'] )            : '',
			'media_id'      => isset( $p['media_id'] )      ? sanitize_text_field( $p['media_id'] )      : '',
			'dismissible'   => ! empty( $p['dismissible'] ),
			'version'       => isset( $p['version'] )       ? (int) $p['version']                        : 1,
		);
	}
}
