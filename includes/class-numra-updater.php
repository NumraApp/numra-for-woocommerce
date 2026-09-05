<?php
/**
 * Numra for WooCommerce — Auto Update Checker
 * Checks api.numra.ma for plugin updates and integrates with WordPress update APIs.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Updater {

	/**
	 * Plugin file path.
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin slug (folder name).
	 * @var string
	 */
	private $slug;

	/**
	 * Current version.
	 * @var string
	 */
	private $version;

	/**
	 * Numra API endpoint base.
	 * @var string
	 */
	private $api_base_url;

	/**
	 * Numra_Updater constructor.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @param string $version     Current plugin version.
	 */
	public function __construct( $plugin_file, $version ) {
		$this->plugin_file  = $plugin_file;
		$this->slug         = dirname( plugin_basename( $plugin_file ) );
		$this->version      = $version;
		$this->api_base_url = Numra_Settings::get_api_base_url();

		$this->register_hooks();
	}

	/**
	 * Hook into core plugin update processes.
	 */
	private function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'get_plugin_details' ), 10, 3 );
	}

	/**
	 * Check Numra API for new updates and push response to WordPress update transient.
	 *
	 * @param object $transient Core transient metadata object.
	 * @return object
	 */
	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$url = add_query_arg(
			array(
				'platform' => NUMRA_PLATFORM,
				'version'  => $this->version,
			),
			$this->api_base_url . '/v1/plugin/connect/update-check'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return $transient;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return $transient;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return $transient;
		}

		if ( ! empty( $body['update_available'] ) && ! empty( $body['latest_version'] ) ) {
			/* The package URL is handed to WordPress, which downloads it and
			 * unpacks it over this plugin's directory. Whatever answers that
			 * request therefore executes PHP on the merchant's store.
			 *
			 * It used to be taken from the response verbatim. Pinning it to a
			 * host we control means that even a compromised or misconfigured
			 * API cannot redirect the install to somebody else's zip — the
			 * response can say "there is an update", but not "and fetch it
			 * from here". */
			$package = self::trusted_package_url(
				isset( $body['download_url'] ) ? $body['download_url'] : ''
			);
			if ( '' === $package ) {
				Numra_Logger::error( 'Update offered but its download URL is not on a trusted Numra host — ignoring.' );
				return $transient;
			}

			$plugin_basename = plugin_basename( $this->plugin_file );

			$transient->response[ $plugin_basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $plugin_basename,
				'new_version' => sanitize_text_field( $body['latest_version'] ),
				'package'     => $package,
				'url'         => isset( $body['homepage'] ) ? esc_url_raw( $body['homepage'] ) : 'https://numra.ma',
				// Must match readme.txt's `Tested up to:` and the details
				// modal below. It said 6.6 while readme.txt said 6.7 — a
				// merchant on 6.7 saw an incompatibility warning on the
				// Updates screen and no such warning in the modal.
				'tested'      => '6.7',
				'icons'       => $this->icons(),
			);
		}

		return $transient;
	}

	/**
	 * The plugin icon WordPress renders on Plugins, Updates and in the
	 * details modal.
	 *
	 * Served from the installed plugin's own assets rather than from a URL
	 * the API hands back. An icon is markup the browser loads on an admin
	 * screen; taking its address from a remote response would put a second
	 * remotely-controlled URL on the same page as the package URL, for a
	 * decorative gain. The file ships in the zip, so it is present whenever
	 * the plugin is, needs no host to stay up, and cannot be repointed.
	 *
	 * WordPress accepts an `svg` key and prefers it over the raster sizes,
	 * which is why no PNG artwork is required here.
	 *
	 * @return array<string,string>
	 */
	private function icons() {
		$svg = plugins_url( 'assets/numra-icon.svg', $this->plugin_file );

		return array(
			'svg'     => $svg,
			'1x'      => $svg,
			'2x'      => $svg,
			'default' => $svg,
		);
	}

	/**
	 * Allow-list for anything WordPress will download and execute.
	 *
	 * Returns the URL when it is HTTPS on a Numra host, or '' when it is not.
	 *
	 * Exact hostname matches only — no suffix test. `str_ends_with( $host,
	 * 'numra.ma' )` would accept both `numra.ma.evil.com` and `notnumra.ma`,
	 * which is the standard way a suffix check is defeated. A new build host
	 * is one array entry; that is cheaper than a clever matcher.
	 *
	 * @param string $url Candidate download URL.
	 * @return string Safe URL, or '' if it must not be used.
	 */
	private static function trusted_package_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' !== $scheme || '' === $host ) {
			return '';
		}

		$allowed = array( 'numra.ma', 'api.numra.ma', 'app.numra.ma', 'cdn.numra.ma', 'downloads.numra.ma' );

		/**
		 * Filter the hosts a Numra plugin update may be downloaded from.
		 *
		 * Exposed so a self-hosted deployment can point at its own build
		 * server without editing the plugin — but it defaults closed.
		 *
		 * @param string[] $allowed Hostnames.
		 */
		$allowed = (array) apply_filters( 'numra_trusted_update_hosts', $allowed );

		foreach ( $allowed as $ok ) {
			$ok = strtolower( (string) $ok );
			if ( $host === $ok ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Hydrate details modal for plugin information query.
	 * Called when user clicks "View version X.Y.Z details".
	 *
	 * @param object|false $res    Default response object or false.
	 * @param string       $action Context request action (e.g. 'plugin_information').
	 * @param object       $args   Request arguments containing the target plugin slug.
	 * @return object
	 */
	public function get_plugin_details( $res, $action, $args ) {
		// `slug` is not a required property of the plugins_api args object, so
		// reading it unguarded raised an "Undefined property" notice for every
		// other plugin's details modal on the same screen.
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->slug !== $args->slug ) {
			return $res;
		}

		$url = add_query_arg(
			array(
				'platform' => NUMRA_PLATFORM,
				'version'  => $this->version,
			),
			$this->api_base_url . '/v1/plugin/connect/update-check'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return $res;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return $res;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return $res;
		}

		/* Everything below lands in the plugin-details modal, which is an
		 * HTML-rendering surface — this very method relies on that by setting
		 * `author` to raw markup. Core assumes that structure comes from
		 * wordpress.org; pointing it at api.numra.ma moved the trust boundary
		 * without moving the sink, so each value is sanitised for its own
		 * context before it goes in. */
		$res = new stdClass();
		$res->name           = 'Numra for WooCommerce';
		$res->slug           = $this->slug;
		$res->version        = isset( $body['latest_version'] )
			? sanitize_text_field( $body['latest_version'] )
			: $this->version;
		$res->author         = '<a href="https://numra.ma">Numra</a>';
		$res->homepage       = isset( $body['homepage'] )
			? esc_url_raw( $body['homepage'] )
			: 'https://numra.ma';
		// Same allow-list as the update transient: this is the URL an
		// "Install" click downloads from.
		$res->download_link  = self::trusted_package_url(
			isset( $body['download_url'] ) ? $body['download_url'] : ''
		);
		$res->icons          = $this->icons();
		$res->last_updated   = isset( $body['released_at'] )
			? sanitize_text_field( $body['released_at'] )
			: '';
		// Stated so the modal does not render "Unknown" against every
		// compatibility row. These three are copies of what readme.txt and
		// the plugin header already declare; the update transient above
		// repeats `tested` as well. Change one, change all four.
		$res->requires       = '5.8';
		$res->tested         = '6.7';
		$res->requires_php   = '7.4';

		$res->sections       = array(
			'description' => esc_html__( 'Protect your WooCommerce store from COD fraud. Connect to your Numra account to score orders, verify phone numbers, and reduce failed deliveries.', 'numra-for-woocommerce' ),
			'changelog'   => $this->changelog_section( $res->version, $body ),
		);

		return $res;
	}

	/**
	 * The "Changelog" tab of the details modal.
	 *
	 * This used to be a single sentence linking to a changelog on GitHub.
	 * That was wrong in two ways: the repository is not the distribution
	 * channel — Numra ships its own packages — so the link pointed at
	 * somewhere a merchant has no reason to be able to reach; and a merchant
	 * deciding whether to click "Update now" wants to read what changed
	 * without leaving the modal to find out.
	 *
	 * The notes are the ones the platform published with the release. The
	 * heartbeat has usually already stored them, localised, so they are used
	 * when they describe the same version being offered here — a stale note
	 * from a previous release next to a new version number would be worse
	 * than no note at all. When there is nothing stored (a fresh install
	 * whose first heartbeat has not run), it falls back to the link, and
	 * when there is no link either it says so plainly rather than rendering
	 * an empty tab.
	 *
	 * @param string $version Version being offered.
	 * @param array  $body    Decoded update-check response.
	 * @return string HTML for the changelog section.
	 */
	private function changelog_section( $version, $body ) {
		$rel = class_exists( 'Numra_Heartbeat' ) ? Numra_Heartbeat::release() : null;

		if ( is_array( $rel )
			&& ! empty( $rel['notes'] )
			&& isset( $rel['latest_version'] )
			&& $rel['latest_version'] === $version
		) {
			$title = ! empty( $rel['title'] ) ? $rel['title'] : $version;

			return '<h4>' . esc_html( $title ) . '</h4>'
				// Notes are authored by Numra staff in the control panel, not
				// by the merchant or the store — but they arrive over HTTP,
				// so they are escaped and only line breaks are restored.
				. wpautop( esc_html( $rel['notes'] ) );
		}

		$link = isset( $body['changelog_url'] ) ? esc_url_raw( $body['changelog_url'] ) : '';

		if ( '' !== $link ) {
			return sprintf(
				wp_kses(
					/* translators: %s: URL of the release notes. */
					__( 'Read the <a href="%s" target="_blank" rel="noopener">release notes for this version</a>.', 'numra-for-woocommerce' ),
					array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
				),
				esc_url( $link )
			);
		}

		return '<p>' . esc_html__( 'No release notes were published for this version.', 'numra-for-woocommerce' ) . '</p>';
	}
}
