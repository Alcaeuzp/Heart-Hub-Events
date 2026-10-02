<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Audit_Log {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hherm_audit_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT '',
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY registration_id (registration_id),
			KEY event_type (event_type)
		) {$charset};" );
	}

	public function write( int $registration_id, string $event_type, string $status, array $details = array() ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress has no API for inserting into this plugin-owned audit ledger.
		$result = $wpdb->insert( self::table(), array(
			'registration_id' => $registration_id,
			'user_id'         => get_current_user_id(),
			'event_type'      => sanitize_key( $event_type ),
			'status'          => sanitize_key( $status ),
			'details'         => wp_json_encode( $details ),
			'created_at'      => current_time( 'mysql' ),
		), array( '%d', '%d', '%s', '%s', '%s', '%s' ) );
		return false !== $result;
	}

	public function latest_status( int $registration_id, string $event_type ): string {
		$entry = $this->latest_entry( $registration_id, $event_type );
		return isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : '';
	}

	public function has_status( int $registration_id, string $event_type, string $status ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the plugin-owned delivery ledger for idempotency.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE registration_id = %d AND event_type = %s AND status = %s LIMIT 1',
				$table,
				$registration_id,
				sanitize_key( $event_type ),
				sanitize_key( $status )
			)
		);
	}

	/**
	 * Whether a matching entry was written after the newest $marker_type entry for this registration.
	 * With no marker entry, any matching entry counts.
	 */
	public function has_status_since( int $registration_id, string $event_type, array $statuses, string $marker_type ): bool {
		global $wpdb;
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( ! $statuses ) {
			return false;
		}
		$table = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the plugin-owned delivery ledger; the IN() list contains only generated %s placeholders.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i WHERE registration_id = %d AND event_type = %s AND status IN ({$placeholders}) AND id > ( SELECT COALESCE( MAX( id ), 0 ) FROM %i WHERE registration_id = %d AND event_type = %s ) LIMIT 1",
				array_merge( array( $table, $registration_id, sanitize_key( $event_type ) ), $statuses, array( $table, $registration_id, sanitize_key( $marker_type ) ) )
			)
		);
		// phpcs:enable
		return (bool) $found;
	}

	public function latest_entry( int $registration_id, string $event_type ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin-owned audit table is the capacity reservation ledger.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, details FROM %i WHERE registration_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1',
				$table,
				$registration_id,
				sanitize_key( $event_type )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array();
		}
		$details = json_decode( (string) ( $row['details'] ?? '' ), true );
		return array( 'status' => sanitize_key( $row['status'] ?? '' ), 'details' => is_array( $details ) ? $details : array() );
	}
}
