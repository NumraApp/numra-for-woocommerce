<?php
/**
 * Numra for WooCommerce — Heartbeat
 *
 * The store asking Numra "am I still allowed to work, and is there anything I
 * should be telling the shop owner?" — on a timer, not on a sale.
 *
 * Why this exists
 * ---------------
 * Before this class, connection state only changed as a side effect of a real
 * order lookup. A merchant who revoked a key, let a subscription lapse, or had
 * a store address removed saw "Connected" in this dashboard until the next
 * customer bought something. A shop with no orders for a week was switched off
 * for a week and said so nowhere.
 *
 * What it will never do
 * ---------------------
 * Block a sale. `protection_enabled = false` means "stop spending requests" and
 * nothing more. A billing problem between a merchant and Numra is not a reason
 * to stop that merchant selling, so the guard degrades to letting orders
 * through unscored and the alert explains that plainly.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Heartbeat {

	const CRON_HOOK     = 'numra_heartbeat';
	const SCHEDULE_SLUG = 'numra_quarter_hour';
	const INTERVAL      = 900; // 15 minutes — matches next_beat_seconds server-side.

	const OPT_STATE      = 'numra_platform_state';
	const OPT_LAST_BEAT  = 'numra_last_beat';
	const OPT_NOTICE_SEEN = 'numra_alert_dismissed';
	/** The customer-style catalogue, as the platform last described it. */
	const OPT_STYLES     = 'numra_customer_styles';

	/* Release news gets its OWN dismissal option, not OPT_NOTICE_SEEN.
	   ─────────────────────────────────────────────────────────────────────
	   OPT_NOTICE_SEEN holds a single alert code — `update_option($code)`, one
	   scalar. Putting release notices through the same option would mean
	   dismissing "your licence expires Friday" un-dismisses "1.17 is out",
	   and dismissing the upgrade notice brings the licence warning back. Two
	   independent things sharing one slot cancel each other, and the failure
	   only shows up when both happen to be live at once — which is exactly
	   the moment a merchant most needs both to behave. */
	const OPT_RELEASE      = 'numra_release';
	const OPT_RELEASE_SEEN = 'numra_release_dismissed';

	/* Guard policy pushed from the merchant's Numra account.
	   ─────────────────────────────────────────────────────────────────────
	   OPT_POLICY holds the last policy the server sent, and OPT_POLICY_REV
	   the revision that was APPLIED to the real settings. Two options, not
	   one, because they answer different questions: "what does the server
	   currently say" and "what have I already acted on".

	   Without the second, every beat would rewrite the merchant's settings
	   with the same values fifteen minutes apart. A merchant who changed a
	   band in wp-admin would see it revert, with nothing on screen to
	   explain why — the exact silent flap this design exists to avoid. */
	const OPT_POLICY     = 'numra_policy';
	const OPT_POLICY_REV = 'numra_policy_revision';

	public function register_hooks() {
		add_filter( 'cron_schedules',            array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK,             array( __CLASS__, 'beat' ) );
		add_action( 'admin_init',                array( $this, 'ensure_scheduled' ) );
		add_action( 'admin_init',                array( $this, 'catch_up' ), 20 );
	}

	/**
	 * WordPress ships hourly/twicedaily/daily and nothing shorter, so the
	 * 15-minute cadence has to be registered before it can be scheduled.
	 */
	public function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE_SLUG ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 15 minutes (Numra)', 'numra-for-woocommerce' ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE_SLUG, self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Re-arm on every admin load. Cron events are lost by cache plugins,
	 * migrations and DEFINE( 'DISABLE_WP_CRON' ) setups often enough that
	 * scheduling once at activation is not a guarantee.
	 */
	public function ensure_scheduled() {
		if ( Numra_Settings::has_api_key() ) {
			self::schedule();
		}
	}

	/**
	 * WP-Cron only runs when someone visits the site. A quiet store can go a
	 * day without firing a scheduled event, which is exactly the store most
	 * likely to be sitting on a revoked key without knowing. If an admin is
	 * looking at the dashboard and the last beat is stale, beat now — the one
	 * moment we know a human is present is the one moment worth spending a
	 * request on.
	 */
	public function catch_up() {
		if ( ! Numra_Settings::has_api_key() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$last = (int) get_option( self::OPT_LAST_BEAT, 0 );
		if ( ( time() - $last ) < ( self::INTERVAL * 2 ) ) {
			return;
		}
		self::beat();
	}

	// ── The beat ──────────────────────────────────────────────────────────────

	/**
	 * Ask the platform for authoritative state and store the answer.
	 *
	 * A transport failure is NOT a state change. If Numra cannot be reached the
	 * previously known state is left exactly as it was: treating a flaky
	 * network as "revoked" would switch protection off across every store the
	 * moment our own DNS hiccuped, and treating it as "active" would hide a
	 * real revocation. Neither is acceptable, so an unreachable server only
	 * records that it was unreachable.
	 */
	public static function beat() {
		if ( ! Numra_Settings::has_api_key() ) {
			return null;
		}

		$client = new Numra_API_Client();
		$result = $client->heartbeat();

		update_option( self::OPT_LAST_BEAT, time(), false );

		if ( empty( $result['ok'] ) ) {
			Numra_Settings::set_connection_status( 'unavailable' );
			Numra_Logger::info( 'Heartbeat could not reach Numra; previous state kept.' );
			return null;
		}

		$body = isset( $result['body'] ) && is_array( $result['body'] ) ? $result['body'] : array();
		$prev = self::get_state();

		$state = isset( $body['state'] ) ? sanitize_key( $body['state'] ) : 'active';

		$stored = array(
			'state'              => $state,
			'protection_enabled' => ! empty( $body['protection_enabled'] ),
			'alert'              => isset( $body['alert'] ) && is_array( $body['alert'] ) ? $body['alert'] : null,
			'revocation_reason'  => isset( $body['revocation_reason'] ) ? sanitize_text_field( $body['revocation_reason'] ) : null,
			'license'            => isset( $body['license'] ) && is_array( $body['license'] ) ? $body['license'] : null,
			'usage'              => isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : null,
			'checked_at'         => time(),
		);

		/* The customer-style catalogue, kept OUTSIDE the state blob.
		   ─────────────────────────────────────────────────────────────────
		   State is replaced wholesale on every beat, which is right for state
		   — a stale "protection_enabled" is dangerous. The catalogue is the
		   opposite: it is a list the settings page renders, and losing it
		   because one beat came back from an older server would blank the
		   merchant's picker and make their saved choices look invalid.

		   So it is only ever written when the server actually sends a
		   non-empty list, and otherwise the previous one survives. */
		if ( isset( $body['customer_styles'] ) && is_array( $body['customer_styles'] ) && $body['customer_styles'] ) {
			$styles = array();
			foreach ( $body['customer_styles'] as $s ) {
				if ( ! is_array( $s ) || empty( $s['code'] ) ) {
					continue;
				}
				$styles[] = array(
					'code'        => sanitize_key( $s['code'] ),
					'label'       => isset( $s['label'] ) ? sanitize_text_field( $s['label'] ) : $s['code'],
					'description' => isset( $s['description'] ) ? sanitize_text_field( $s['description'] ) : '',
				);
			}
			if ( $styles ) {
				update_option( self::OPT_STYLES, $styles, false );
			}
		}

		/* Release news, kept OUTSIDE the state blob for the same reason the
		   style catalogue is.
		   ─────────────────────────────────────────────────────────────────
		   `release` is null whenever the server has no published release for
		   this platform, and null must mean "no news" — never "you are up to
		   date". Those are different claims and only one of them is safe to
		   make on behalf of a server that has nothing on file. So a null is
		   ignored and whatever was known before survives; only a real
		   release object replaces it.

		   Version comparison is the SERVER's job, not this plugin's. The
		   store already reports `plugin_version` on the request this is the
		   answer to, so `update_available` arrives decided. Re-deciding it
		   here would be a second copy of a comparator, and a second copy is
		   a second thing that can disagree about whether 1.10 beats 1.9. */
		if ( isset( $body['release'] ) && is_array( $body['release'] ) && ! empty( $body['release']['latest_version'] ) ) {
			$rel = $body['release'];

			$prev_release = get_option( self::OPT_RELEASE, array() );
			$prev_version = is_array( $prev_release ) && isset( $prev_release['latest_version'] )
				? $prev_release['latest_version']
				: null;

			update_option(
				self::OPT_RELEASE,
				array(
					'latest_version'   => sanitize_text_field( $rel['latest_version'] ),
					'update_available' => ! empty( $rel['update_available'] ),
					'is_critical'      => ! empty( $rel['is_critical'] ),
					'download_url'     => isset( $rel['download_url'] ) ? esc_url_raw( $rel['download_url'] ) : '',
					'changelog_url'    => isset( $rel['changelog_url'] ) ? esc_url_raw( $rel['changelog_url'] ) : '',
					'title'            => self::pick_locale( isset( $rel['title_i18n'] ) ? $rel['title_i18n'] : array() ),
					'notes'            => self::pick_locale( isset( $rel['notes_i18n'] ) ? $rel['notes_i18n'] : array() ),
					'checked_at'       => time(),
				),
				false
			);

			/* A NEW version un-dismisses the release notice. Someone who
			   dismissed 1.16 should still be told about 1.17; carrying the
			   dismissal forward would mean the one release they most need
			   to hear about is the one they are guaranteed not to see. */
			if ( $prev_version !== sanitize_text_field( $rel['latest_version'] ) ) {
				delete_option( self::OPT_RELEASE_SEEN );
			}
		}

		/* Guard policy from the merchant's Numra account.
		   ─────────────────────────────────────────────────────────────────
		   Applied ONLY when the revision is newer than the one last applied.
		   That single check is what separates "the merchant changed their
		   policy in the portal" from "the server is echoing what I already
		   have", and without it every beat would stamp over a merchant's own
		   wp-admin edit fifteen minutes after they made it.

		   `null` is ignored, like `release` and `customer_styles`: it means
		   no policy has been set centrally, NOT that everything is off. A
		   store beating against an older server, or one whose merchant has
		   never opened the page, keeps its local settings untouched.

		   Written through Numra_Settings::apply_remote_policy() rather than
		   update_option() here, because the settings class owns validation
		   and its save path has three separate post-mortems about values
		   destroying themselves when written from the wrong place. */
		if ( isset( $body['policy'] ) && is_array( $body['policy'] ) && isset( $body['policy']['revision'] ) ) {
			$policy   = $body['policy'];
			$incoming = (int) $policy['revision'];
			$applied  = (int) get_option( self::OPT_POLICY_REV, 0 );

			update_option( self::OPT_POLICY, $policy, false );

			if ( $incoming > $applied && class_exists( 'Numra_Settings' ) ) {
				if ( Numra_Settings::apply_remote_policy( $policy ) ) {
					update_option( self::OPT_POLICY_REV, $incoming, false );
					Numra_Logger::info( 'Applied guard policy revision ' . $incoming . ' from Numra.' );
				}
			}
		}

		update_option( self::OPT_STATE, $stored, false );

		/* Keep the existing connection-status vocabulary in step, so the
		   dashboard badge and this class can never disagree. */
		Numra_Settings::set_connection_status( 'active' === $state ? 'connected' : 'connection_lost' );

		/* A new problem un-dismisses the banner. Someone who dismissed
		   "subscription expiring" should still be interrupted when the
		   subscription actually expires. */
		$prev_state = isset( $prev['state'] ) ? $prev['state'] : null;
		if ( $prev_state !== $state ) {
			delete_option( self::OPT_NOTICE_SEEN );
			Numra_Logger::info( 'Heartbeat state changed: ' . (string) $prev_state . ' -> ' . $state );
		}

		return $stored;
	}

	// ── Readers ───────────────────────────────────────────────────────────────

	public static function get_state() {
		$s = get_option( self::OPT_STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function get_alert() {
		$s = self::get_state();
		return isset( $s['alert'] ) && is_array( $s['alert'] ) ? $s['alert'] : null;
	}

	public static function last_beat_at() {
		return (int) get_option( self::OPT_LAST_BEAT, 0 );
	}

	/**
	 * Whether the guard should still spend requests on scoring.
	 *
	 * Defaults to TRUE when nothing is known. A store that has never completed
	 * a beat — freshly connected, or cron not yet fired — must not sit
	 * unprotected because of a missing option; the API is the authority and it
	 * will refuse if the credential is genuinely dead.
	 */
	public static function protection_enabled() {
		$s = self::get_state();
		if ( ! isset( $s['protection_enabled'] ) ) {
			return true;
		}
		return (bool) $s['protection_enabled'];
	}

	public static function state() {
		$s = self::get_state();
		return isset( $s['state'] ) ? $s['state'] : 'unknown';
	}

	/**
	 * Today's consumption, as the platform last reported it.
	 *
	 * Returns null rather than zeroes when nothing is known. A store that has
	 * never beaten has not used 0 of 0 checks — it has no figure at all, and
	 * rendering "0 / 0" would read as an exhausted plan.
	 *
	 * @return array|null { used:int, limit:int, left:int, percent:int }
	 */
	public static function usage() {
		$s = self::get_state();
		if ( empty( $s['usage'] ) || ! is_array( $s['usage'] ) ) {
			return null;
		}

		$used  = isset( $s['usage']['today'] ) ? (int) $s['usage']['today'] : 0;
		$limit = isset( $s['usage']['daily_limit'] ) ? (int) $s['usage']['daily_limit'] : 0;

		/* An unlimited plan reports 0 as its limit. Dividing by it would be a
		   crash; treating it as "nothing left" would be a lie. */
		if ( $limit <= 0 ) {
			return array( 'used' => $used, 'limit' => 0, 'left' => -1, 'percent' => 0 );
		}

		$left = max( 0, $limit - $used );
		return array(
			'used'    => $used,
			'limit'   => $limit,
			'left'    => $left,
			'percent' => (int) min( 100, round( ( $used / $limit ) * 100 ) ),
		);
	}

	/** The licence facts the platform last reported. */
	public static function license() {
		$s = self::get_state();
		return isset( $s['license'] ) && is_array( $s['license'] ) ? $s['license'] : null;
	}

	/**
	 * The customer-style catalogue for the settings picker.
	 *
	 * Empty until the first successful beat. The settings page renders an
	 * explanation in that case rather than an empty box, because "no styles"
	 * and "we have not spoken to Numra yet" are different problems.
	 */
	public static function styles() {
		$s = get_option( self::OPT_STYLES, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Pick the best string out of an { en, fr, ar } bag from the server.
	 *
	 * Order is the store's own locale, then French, then English, then
	 * whatever is there. French before English because this plugin's
	 * merchants are Moroccan: a French sentence is far likelier to be
	 * understood than an English one, and falling back alphabetically would
	 * put English first for no reason anyone chose.
	 *
	 * Returns '' when the bag is empty, and the caller must treat '' as
	 * "nothing to say" rather than printing an empty heading.
	 *
	 * @param  mixed $bag
	 * @return string
	 */
	private static function pick_locale( $bag ) {
		if ( ! is_array( $bag ) || ! $bag ) {
			return '';
		}

		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$short  = strtolower( substr( $locale, 0, 2 ) );

		foreach ( array( $short, 'fr', 'en' ) as $try ) {
			if ( isset( $bag[ $try ] ) && is_string( $bag[ $try ] ) && '' !== trim( $bag[ $try ] ) ) {
				return sanitize_text_field( $bag[ $try ] );
			}
		}

		foreach ( $bag as $v ) {
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				return sanitize_text_field( $v );
			}
		}
		return '';
	}

	/**
	 * The release the platform last told us about, or null.
	 *
	 * Null means the server has published nothing for this platform — NOT
	 * that this store is up to date. Callers must not turn one into the
	 * other.
	 *
	 * @return array|null { latest_version, update_available, is_critical,
	 *                      download_url, changelog_url, title, notes, checked_at }
	 */
	public static function release() {
		$r = get_option( self::OPT_RELEASE, array() );
		return ( is_array( $r ) && ! empty( $r['latest_version'] ) ) ? $r : null;
	}

	/** True when there is a newer version AND the merchant has not waved it off. */
	public static function release_pending() {
		$r = self::release();
		if ( ! $r || empty( $r['update_available'] ) ) {
			return false;
		}
		/* A critical release is not dismissible. Everything else is. */
		if ( ! empty( $r['is_critical'] ) ) {
			return true;
		}
		return get_option( self::OPT_RELEASE_SEEN, '' ) !== $r['latest_version'];
	}

	public static function dismiss_release( $version ) {
		update_option( self::OPT_RELEASE_SEEN, sanitize_text_field( $version ), false );
	}

	public static function reset() {
		delete_option( self::OPT_STATE );
		delete_option( self::OPT_LAST_BEAT );
		delete_option( self::OPT_NOTICE_SEEN );
		delete_option( self::OPT_STYLES );
		delete_option( self::OPT_RELEASE );
		delete_option( self::OPT_RELEASE_SEEN );
	}
}
