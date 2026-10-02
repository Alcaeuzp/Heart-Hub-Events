<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Established plugin namespace.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/** Character limits shared by attendee comment forms, including without mbstring. */
final class Text_Validation {
	public static function within_limit( string $text, int $maximum ): bool {
		if ( $maximum < 0 || 1 !== preg_match( '//u', $text ) ) return false;
		$bytes = strlen( $text );
		if ( $bytes <= $maximum ) return true;
		// A UTF-8 code point occupies at most four bytes; bound the fallback allocation.
		if ( $bytes > $maximum * 4 ) return false;
		if ( function_exists( 'mb_strlen' ) ) return mb_strlen( $text, 'UTF-8' ) <= $maximum;
		$count = preg_match_all( '/./us', $text, $characters );
		return false !== $count && $count <= $maximum;
	}
}
