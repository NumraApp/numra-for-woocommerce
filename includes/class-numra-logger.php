<?php
/**
 * Numra — Logger
 * Writes to WordPress debug log when numra_debug_enabled = 1.
 *
 * @package Numra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Numra_Logger {

	/**
	 * Log a message if debug mode is enabled.
	 *
	 * @param string $message
	 * @param string $level  'info' | 'warning' | 'error'
	 */
	public static function log( $message, $level = 'info' ) {
		if ( '1' !== get_option( 'numra_debug_enabled', '0' ) ) {
			return;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[Numra][%s] %s', strtoupper( $level ), self::redact( $message ) ) );
		}
	}

	/**
	 * Redact credential-bearing values before anything reaches a log file.
	 *
	 * debug.log is frequently world-readable on shared hosting, so no value of
	 * a field whose NAME suggests a credential may ever be written. Applied
	 * unconditionally inside log() — call sites cannot forget it.
	 *
	 * Covers JSON bodies ("api_key":"..."), assignment forms (key=...), and
	 * Authorization/Bearer headers.
	 *
	 * @param string $message
	 * @return string
	 */
	private static function redact( $message ) {
		// Compound names must match: access_token, refresh_token, api_key,
		// client_secret, ... — the optional [a-z0-9_]* prefix applies to
		// every credential word, not only "key".
		$fields  = '(?:[a-z0-9_]*(?:key|token|secret|password|credential)|authorization)';
		$message = (string) $message;

		// Bearer tokens FIRST — otherwise the key:value rule below consumes
		// "Authorization: Bearer" as key:value and leaves the real token exposed.
		$message = self::apply_redaction(
			'/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]+/i',
			'$1 [REDACTED]',
			$message
		);

		// JSON: "api_key":"value" (value may contain escaped quotes).
		// In a single-quoted PHP string, \\\\ yields \\ in the pattern (one
		// regex backslash) — the previous single-\\ form collapsed to an
		// invalid class [^"\] and preg_replace returned null.
		$message = self::apply_redaction(
			'/("' . $fields . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
			'$1"[REDACTED]"',
			$message
		);

		// key=value / key: value
		$message = self::apply_redaction(
			'/\b(' . $fields . ')(\s*[=:]\s*)[^\s&"\']+/i',
			'$1$2[REDACTED]',
			$message
		);

		return $message;
	}

	/**
	 * Run one redaction pattern with a null guard.
	 *
	 * preg_replace() returns null on PCRE failure. Losing the whole log line
	 * is worse than a coarse fallback, so on failure we strip anything that
	 * looks like a long token (20+ chars of key-material alphabet) instead.
	 *
	 * @param string $pattern
	 * @param string $replacement
	 * @param string $message
	 * @return string
	 */
	private static function apply_redaction( $pattern, $replacement, $message ) {
		$result = preg_replace( $pattern, $replacement, $message );
		if ( null === $result ) {
			$fallback = preg_replace( '/[A-Za-z0-9._~+\/=-]{20,}/', '[REDACTED]', $message );
			return null === $fallback ? '[Numra: message withheld — redaction failure]' : $fallback;
		}
		return $result;
	}

	public static function info( $message )    { self::log( $message, 'info' ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message )   { self::log( $message, 'error' ); }
}
