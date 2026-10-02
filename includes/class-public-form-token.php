<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Cache-tolerant request token for public (logged-out) forms.
 *
 * WordPress nonces for logged-out visitors are shared by every guest and expire
 * after 12–24 hours, so a page cache can serve a form whose nonce has already
 * expired and valid submissions are lost. Guests may instead present a signed
 * token bound to the form action that remains valid for 30 days. Signed-in
 * visitors bypass page caches and must still present their user-bound nonce.
 */
final class Public_Form_Token {
	public const FIELD = 'hherm_form_token';
	private const LIFETIME = 30 * DAY_IN_SECONDS;
	private const CLOCK_SKEW = 5 * MINUTE_IN_SECONDS;

	public static function field( string $action ): string {
		return '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( self::issue( $action ) ) . '">';
	}

	public static function issue( string $action ): string {
		$issued = time();
		return $issued . '.' . self::signature( $action, $issued );
	}

	/**
	 * @param mixed $nonce Submitted WordPress nonce.
	 * @param mixed $token Submitted signed token.
	 */
	public static function verify( string $action, $nonce, $token ): bool {
		$nonce = is_scalar( $nonce ) ? (string) $nonce : '';
		if ( '' !== $nonce && wp_verify_nonce( $nonce, $action ) ) {
			return true;
		}
		if ( get_current_user_id() > 0 ) {
			return false;
		}
		$token = is_scalar( $token ) ? (string) $token : '';
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $parts ) ) {
			return false;
		}
		$issued = (int) $parts[1];
		$now    = time();
		if ( $issued > $now + self::CLOCK_SKEW || $now - $issued > self::LIFETIME ) {
			return false;
		}
		return hash_equals( self::signature( $action, $issued ), $parts[2] );
	}

	private static function signature( string $action, int $issued ): string {
		return hash_hmac( 'sha256', 'hherm_public_form|' . $action . '|' . $issued, wp_salt( 'nonce' ) );
	}
}
