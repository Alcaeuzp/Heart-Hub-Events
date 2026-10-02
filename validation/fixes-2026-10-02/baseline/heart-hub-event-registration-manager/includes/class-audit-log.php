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

	/**
	 * Erase support applicant email payloads without touching an event registration
	 * that happens to have the same numeric ID. Keep delivery status/history only.
	 */
	public function erase_support_applicant_personal_data( int $applicant_id, string $programme_id ): bool {
		global $wpdb;
		$table = self::table();
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded scan of this exact applicant's potentially shared audit ID.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_type, details FROM %i WHERE registration_id = %d AND id > %d AND event_type IN (%s, %s) ORDER BY id ASC LIMIT 100', $table, $applicant_id, $after, 'support_customer_email', 'internal_notification_email' ), ARRAY_A );
			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) return false;
			foreach ( $rows as $row ) {
				$after = absint( $row['id'] );
				$details = json_decode( (string) ( $row['details'] ?? '' ), true );
				if ( ! is_array( $details ) ) return false;
				$source = (string) ( $details['source'] ?? '' );
				// Ambiguous legacy internal messages require manual identification;
				// never erase an unrelated event's payload just because IDs collide.
				if ( '' === $source && 'internal_notification_email' === $row['event_type'] && $programme_id === (string) ( $details['programme_id'] ?? '' ) ) return false;
				$is_support = 'support_group' === $source || ( '' === $source && 'support_customer_email' === $row['event_type'] );
				if ( ! $is_support || $programme_id !== (string) ( $details['programme_id'] ?? '' ) ) continue;
				$redacted = array( 'source' => 'support_group', 'programme_id' => $programme_id, 'personal_data_erased' => true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Retains the ledger row while removing recipient, subject, body and record URL.
				if ( false === $wpdb->update( $table, array( 'details' => wp_json_encode( $redacted ) ), array( 'id' => $after ), array( '%s' ), array( '%d' ) ) ) return false;
			}
		} while ( count( $rows ) === 100 );
		return true;
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
		$entry = $this->latest_entry_checked( $registration_id, $event_type );
		return is_wp_error( $entry ) ? array() : $entry;
	}

	/**
	 * Read an operational ledger entry without treating a failed read as absence.
	 *
	 * @return array|\WP_Error Empty array means no entry; errors must stop mutations.
	 */
	public function latest_entry_checked( int $registration_id, string $event_type ) {
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
		if ( ! empty( $wpdb->last_error ) || ( null !== $row && ! is_array( $row ) ) ) {
			return new \WP_Error( 'hherm_audit_read_failed', 'The registration history could not be read. Please try again.', array( 'status' => 500 ) );
		}
		if ( null === $row ) {
			return array();
		}
		$details = json_decode( (string) ( $row['details'] ?? '' ), true );
		if ( ! isset( $row['status'] ) || ! is_array( $details ) ) {
			return new \WP_Error( 'hherm_audit_read_failed', 'The registration history could not be read. Please try again.', array( 'status' => 500 ) );
		}
		return array( 'status' => sanitize_key( $row['status'] ), 'details' => $details );
	}
}
