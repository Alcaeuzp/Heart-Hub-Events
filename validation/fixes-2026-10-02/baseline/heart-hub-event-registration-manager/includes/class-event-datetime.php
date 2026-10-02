<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Converts event date values between JetEngine timestamp storage and local inputs.
 */
final class Event_Datetime {
	public const DATE_FORMAT = 'j F Y';
	public const TIME_FORMAT = 'g:i a';
	public const DATETIME_FORMAT = 'j F Y, g:i a';
	public const EDITOR_FORMAT = 'd/m/Y h:i a';
	public const INPUT_FORMAT = 'Y-m-d\TH:i';

	/**
	 * Return a Unix timestamp for either current JetEngine storage or a legacy date string.
	 *
	 * @param mixed $value Stored date value.
	 */
	public static function timestamp( $value ): int {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		foreach ( array( 'Y-m-d\TH:i:s', self::INPUT_FORMAT, 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			$valid = false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] );
			if ( $date && $valid && $date->format( $format ) === $value ) {
				return $date->getTimestamp();
			}
		}

		return 0;
	}

	/**
	 * Convert a validated local datetime into JetEngine's timestamp storage format.
	 *
	 * @param mixed $value Local datetime input.
	 * @return int|string Timestamp, or an empty string for an empty/invalid value.
	 */
	public static function storage( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}

		$timestamp = self::timestamp( $value );
		return $timestamp > 0 ? $timestamp : '';
	}

	/**
	 * Format a stored value for an HTML datetime-local field.
	 *
	 * @param mixed $value Stored date value.
	 */
	public static function input( $value ): string {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}

		$timestamp = self::timestamp( $value );
		return $timestamp > 0 ? wp_date( self::INPUT_FORMAT, $timestamp, wp_timezone() ) : (string) $value;
	}

	/** Australian editor text, independent of the browser's native date locale. */
	public static function editor_input( $value ): string {
		return self::display( $value, self::EDITOR_FORMAT );
	}

	/** Accept Australian editor values and legacy ISO inputs, returning local ISO. */
	public static function editor_storage( $value ): ?string {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) return '';
		foreach ( array( self::EDITOR_FORMAT, 'd/m/Y H:i', self::INPUT_FORMAT ) as $format ) {
			$candidate = self::INPUT_FORMAT === $format ? str_replace( 't', 'T', $value ) : $value;
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $candidate, wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === $candidate ) return $date->format( self::INPUT_FORMAT );
		}
		return null;
	}

	public static function date_storage( string $value ): string {
		foreach ( array( 'd/m/Y', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, trim( $value ), wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === trim( $value ) ) return $date->format( 'Y-m-d' );
		}
		return '';
	}

	/**
	 * Format a stored value for display while preserving an unknown legacy value.
	 *
	 * @param mixed  $value  Stored date value.
	 * @param string $format WordPress/PHP date format.
	 */
	public static function display( $value, string $format ): string {
		if ( '' === trim( (string) $value ) ) {
			return '';
		}

		$timestamp = self::timestamp( $value );
		return $timestamp > 0 ? wp_date( $format, $timestamp, wp_timezone() ) : (string) $value;
	}
}
