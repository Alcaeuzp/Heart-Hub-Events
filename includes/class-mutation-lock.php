<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Established plugin namespace.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Token-owned locks shared by registration and capacity mutation entry points.
 * Ownership checks stop an expired owner before its next write. They cannot fence
 * a database write or third-party hook that itself runs beyond the lease duration.
 */
final class Mutation_Lock {
	public static function acquire( string $name, int $ttl = 300 ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$value = array( 'token' => $token, 'expires_at' => time() + max( 1, $ttl ) );
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			// add_option() performs an upsert after a separate existence check. Two
			// contenders can both pass that check and overwrite each other's token.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique option_name index atomically elects exactly one owner.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, maybe_serialize( $value ), 'no' ) );
			self::clear_cache( $name );
			if ( 1 === $inserted ) return $token;
			if ( false === $inserted ) return new \WP_Error( 'hherm_lock_storage_failed', 'The update lock could not be saved. Please try again.', array( 'status' => 500 ) );
			$current = get_option( $name, false );
			// Older plugin versions stored the acquisition timestamp alone.
			$expiry = is_array( $current ) ? absint( $current['expires_at'] ?? ( absint( $current['created_at'] ?? 0 ) + max( 1, $ttl ) ) ) : absint( $current ) + max( 1, $ttl );
			if ( $current && $expiry > time() ) break;
			self::delete_if_unchanged( $name, $current );
		}
		return new \WP_Error( 'hherm_locked', 'This record is being updated. Please try again.', array( 'status' => 409 ) );
	}

	public static function owns( string $name, string $token ): bool {
		self::clear_cache( $name );
		$current = get_option( $name, array() );
		return is_array( $current ) && ! empty( $current['token'] ) && hash_equals( (string) $current['token'], $token ) && absint( $current['expires_at'] ?? 0 ) > time();
	}

	public static function release( string $name, string $token ): void {
		self::clear_cache( $name );
		$current = get_option( $name, array() );
		if ( is_array( $current ) && ! empty( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) self::delete_if_unchanged( $name, $current );
	}

	private static function delete_if_unchanged( string $name, $expected ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic comparison prevents deleting a replacement owner's lock.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, maybe_serialize( $expected ) ) );
		self::clear_cache( $name );
	}

	private static function clear_cache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		// A direct insert must also invalidate negative lookups from this or another
		// request, otherwise get_option() can hide a successfully acquired lock.
		wp_cache_delete( 'notoptions', 'options' );
	}
}
