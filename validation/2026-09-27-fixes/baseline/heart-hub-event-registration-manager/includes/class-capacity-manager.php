<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Capacity_Manager {
	private const TOTAL_KEY = 'event_capacity';
	private const AUDIT_TYPE = 'capacity_reservation';
	private const LOCK_PREFIX = 'hherm_capacity_lock_';
	private const LOCK_TTL = 60;

	private $settings;
	private $audit;

	public function __construct( Settings $settings, Audit_Log $audit ) {
		$this->settings = $settings;
		$this->audit = $audit;
	}

	public function enabled(): bool {
		return '' !== $this->attendee_key() && '' !== $this->remaining_key();
	}

	public function partially_configured(): bool {
		return ( ( '' !== $this->attendee_key() ) xor ( '' !== $this->remaining_key() ) );
	}

	public function is_reserved( int $registration_id ): bool {
		return $this->enabled() && 'reserved' === $this->audit->latest_status( $registration_id, self::AUDIT_TYPE );
	}

	public function reservation_state( int $registration_id ): array {
		$entry = $this->enabled() ? $this->audit->latest_entry( $registration_id, self::AUDIT_TYPE ) : array();
		$reserved = 'reserved' === ( $entry['status'] ?? '' );
		return array(
			'reserved'  => $reserved,
			'attendees' => $reserved ? max( 1, absint( $entry['details']['attendees'] ?? 1 ) ) : 0,
		);
	}

	public function attendee_key(): string {
		return sanitize_key( $this->settings->get( 'attendee_count_field', '' ) );
	}

	public function remaining_key(): string {
		return sanitize_key( $this->settings->get( 'event_remaining_capacity', '' ) );
	}

	public function attendees_from( array $item ) {
		$key = $this->attendee_key() ?: 'number_of_attendees';
		if ( isset( $item[ $key ] ) && ! is_scalar( $item[ $key ] ) ) {
			return new \WP_Error( 'hherm_invalid_attendees', 'The number of attendees must be a whole number.', array( 'status' => 422 ) );
		}
		$raw = isset( $item[ $key ] ) ? trim( (string) $item[ $key ] ) : '';
		if ( '' === $raw || preg_match( '/^-?0+$/', $raw ) || preg_match( '/^-\d+$/', $raw ) ) {
			return 1;
		}
		if ( ! preg_match( '/^[1-9]\d*$/', $raw ) ) {
			return new \WP_Error( 'hherm_invalid_attendees', 'The number of attendees must be a whole number of at least one.', array( 'status' => 422 ) );
		}
		$count = absint( $raw );
		return $count > 1000 ? new \WP_Error( 'hherm_invalid_attendees', 'The number of attendees is too large.', array( 'status' => 422 ) ) : $count;
	}

	public function lock_for_registration( int $event_id, int $attendees ) {
		if ( ! $this->enabled() ) {
			return true;
		}
		if ( $event_id < 1 || $attendees < 1 ) {
			return new \WP_Error( 'hherm_invalid_capacity_request', 'The event or attendee count is invalid.', array( 'status' => 422 ) );
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		$current = $this->remaining( $event_id );
		if ( is_wp_error( $current ) || $current < $attendees ) {
			$this->release_lock( $event_id );
			return is_wp_error( $current ) ? $current : $this->full_error();
		}
		return true;
	}

	public function complete_registration( int $registration_id, int $event_id, int $attendees ) {
		if ( ! $this->enabled() ) {
			return true;
		}
		try {
			return $this->reserve_while_locked( $registration_id, $event_id, $attendees );
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function abandon_registration( int $event_id ): void {
		if ( $this->enabled() ) {
			$this->release_lock( $event_id );
		}
	}

	public function ensure_reserved( int $registration_id, int $event_id, int $attendees ) {
		if ( ! $this->enabled() ) {
			return true;
		}
		if ( 'reserved' === $this->audit->latest_status( $registration_id, self::AUDIT_TYPE ) ) {
			return true;
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			return $this->reserve_while_locked( $registration_id, $event_id, $attendees );
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function release( int $registration_id, int $event_id, int $attendees ) {
		$entry = $this->enabled() ? $this->audit->latest_entry( $registration_id, self::AUDIT_TYPE ) : array();
		if ( ! $this->enabled() || 'reserved' !== ( $entry['status'] ?? '' ) ) {
			return true;
		}
		$reserved_attendees = absint( $entry['details']['attendees'] ?? $attendees );
		if ( $reserved_attendees > 0 ) {
			$attendees = $reserved_attendees;
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$current = $this->remaining( $event_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$total = $this->total( $event_id );
			$new_remaining = min( $total, $current + $attendees );
			if ( ! $this->write_remaining( $event_id, $new_remaining ) ) {
				return new \WP_Error( 'hherm_capacity_update_failed', 'The event capacity could not be returned. Please try again.', array( 'status' => 500 ) );
			}
			if ( ! $this->audit->write( $registration_id, self::AUDIT_TYPE, 'released', array( 'event_id' => $event_id, 'attendees' => $attendees, 'remaining' => $new_remaining ) ) ) {
				$this->write_remaining( $event_id, $current );
				return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity release could not be recorded. Please try again.', array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function adjust_reserved( int $registration_id, int $event_id, int $old_attendees, int $new_attendees ) {
		if ( ! $this->enabled() || $old_attendees === $new_attendees ) {
			return true;
		}
		if ( $event_id < 1 || $new_attendees < 1 ) {
			return new \WP_Error( 'hherm_invalid_capacity_request', 'The event or attendee count is invalid.', array( 'status' => 422 ) );
		}

		$entry = $this->audit->latest_entry( $registration_id, self::AUDIT_TYPE );
		if ( 'reserved' !== ( $entry['status'] ?? '' ) ) {
			return new \WP_Error( 'hherm_capacity_not_reserved', 'This registration does not have an active capacity reservation.', array( 'status' => 409 ) );
		}
		$reserved = max( 1, absint( $entry['details']['attendees'] ?? $old_attendees ) );
		$delta    = $new_attendees - $reserved;
		if ( 0 === $delta ) {
			return true;
		}

		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$current = $this->remaining( $event_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$total = $this->total( $event_id );
			$new_remaining = $delta > 0 ? $current - $delta : min( $total, $current + abs( $delta ) );
			if ( $new_remaining < 0 ) {
				return $this->full_error();
			}
			if ( ! $this->write_remaining( $event_id, $new_remaining ) ) {
				return new \WP_Error( 'hherm_capacity_update_failed', 'The event capacity could not be updated.', array( 'status' => 500 ) );
			}
			if ( ! $this->audit->write( $registration_id, self::AUDIT_TYPE, 'reserved', array( 'event_id' => $event_id, 'attendees' => $new_attendees, 'remaining' => $new_remaining, 'adjustment' => $delta ) ) ) {
				$this->write_remaining( $event_id, $current );
				return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity update could not be recorded. Please try again.', array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function release_unused( int $registration_id, int $event_id, int $attendees ) {
		if ( ! $this->enabled() || $attendees < 1 || 'released' === $this->audit->latest_status( $registration_id, 'capacity_checkin_adjustment' ) ) {
			return true;
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) { return $locked; }
		try {
			$current = $this->remaining( $event_id );
			if ( is_wp_error( $current ) ) { return $current; }
			$total = $this->total( $event_id );
			$new_remaining = min( $total, $current + $attendees );
			if ( ! $this->write_remaining( $event_id, $new_remaining ) ) { return new \WP_Error( 'hherm_capacity_update_failed', 'The event capacity could not be returned. Please try again.', array( 'status' => 500 ) ); }
			if ( ! $this->audit->write( $registration_id, 'capacity_checkin_adjustment', 'released', array( 'event_id' => $event_id, 'attendees' => $attendees, 'remaining' => $new_remaining ) ) ) {
				$this->write_remaining( $event_id, $current );
				return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity release could not be recorded. Please try again.', array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function save_total( int $event_id, int $new_total, bool $new_event = false ) {
		if ( ! $this->enabled() ) {
			$total_state = $this->meta_state( $event_id, self::TOTAL_KEY );
			if ( $this->write_total( $event_id, $new_total ) ) return true;
			if ( ! $this->restore_meta_state( $event_id, self::TOTAL_KEY, $total_state ) ) return new \WP_Error( 'hherm_capacity_rollback_failed', 'WordPress could not restore the event capacity after a failed update.' );
			return new \WP_Error( 'hherm_capacity_update_failed', 'WordPress could not update the event capacity.' );
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$total_state = $this->meta_state( $event_id, self::TOTAL_KEY );
			$remaining_state = $this->meta_state( $event_id, $this->remaining_key() );
			$old_total = $this->total( $event_id );
			$current = $new_event || ! metadata_exists( 'post', $event_id, $this->remaining_key() ) ? $old_total : $this->remaining( $event_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$reserved = $new_event ? 0 : max( 0, $old_total - $current );
			if ( $new_total < $reserved ) {
				return new \WP_Error( 'hherm_capacity_below_reserved', sprintf( 'Capacity cannot be lower than the %d attendee places already reserved.', $reserved ) );
			}
			$new_remaining = $new_total - $reserved;
			if ( ! $this->write_total( $event_id, $new_total ) ) {
				$total_restored = $this->restore_meta_state( $event_id, self::TOTAL_KEY, $total_state );
				$remaining_restored = $this->restore_meta_state( $event_id, $this->remaining_key(), $remaining_state );
				if ( ! $total_restored || ! $remaining_restored ) return new \WP_Error( 'hherm_capacity_rollback_failed', 'WordPress could not restore event capacity after a failed update.' );
				return new \WP_Error( 'hherm_capacity_update_failed', 'WordPress could not update the event capacity.' );
			}
			if ( ! $this->write_remaining( $event_id, $new_remaining ) ) {
				$total_restored = $this->restore_meta_state( $event_id, self::TOTAL_KEY, $total_state );
				$remaining_restored = $this->restore_meta_state( $event_id, $this->remaining_key(), $remaining_state );
				if ( ! $total_restored || ! $remaining_restored ) return new \WP_Error( 'hherm_capacity_rollback_failed', 'WordPress could not restore event capacity after a failed update.' );
				return new \WP_Error( 'hherm_capacity_update_failed', 'WordPress could not update the remaining event capacity.' );
			}
			return true;
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function validate_total( int $event_id, int $new_total, bool $new_event = false ) {
		if ( ! $this->enabled() || $new_event ) return true;
		$old_total = $this->total( $event_id );
		$current = metadata_exists( 'post', $event_id, $this->remaining_key() ) ? $this->remaining( $event_id ) : $old_total;
		if ( is_wp_error( $current ) ) return $current;
		$reserved = max( 0, $old_total - $current );
		if ( $new_total < $reserved ) {
			return new \WP_Error( 'hherm_capacity_below_reserved', sprintf( 'Capacity cannot be lower than the %d attendee places already reserved.', $reserved ) );
		}
		return true;
	}

	public function remaining( int $event_id ) {
		if ( ! $this->enabled() ) {
			return new \WP_Error( 'hherm_capacity_not_configured', 'Attendee capacity fields have not been configured.' );
		}
		$value = get_post_meta( $event_id, $this->remaining_key(), true );
		if ( '' === (string) $value ) {
			return $this->total( $event_id );
		}
		return is_numeric( $value ) && (int) $value >= 0 ? (int) $value : new \WP_Error( 'hherm_invalid_remaining_capacity', 'The event remaining-capacity value is invalid.', array( 'status' => 500 ) );
	}

	private function reserve_while_locked( int $registration_id, int $event_id, int $attendees ) {
		if ( 'reserved' === $this->audit->latest_status( $registration_id, self::AUDIT_TYPE ) ) {
			return true;
		}
		$current = $this->remaining( $event_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( $current < $attendees ) {
			return $this->full_error();
		}
		$new_remaining = $current - $attendees;
		if ( ! $this->write_remaining( $event_id, $new_remaining ) ) {
			return new \WP_Error( 'hherm_capacity_update_failed', 'WordPress could not reserve the attendee places.', array( 'status' => 500 ) );
		}
		if ( ! $this->audit->write( $registration_id, self::AUDIT_TYPE, 'reserved', array( 'event_id' => $event_id, 'attendees' => $attendees, 'remaining' => $new_remaining ) ) ) {
			$this->write_remaining( $event_id, $current );
			return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity reservation could not be recorded. Please try again.', array( 'status' => 500 ) );
		}
		return true;
	}

	private function total( int $event_id ): int {
		$value = get_post_meta( $event_id, self::TOTAL_KEY, true );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function write_remaining( int $event_id, int $value ): bool {
		update_post_meta( $event_id, $this->remaining_key(), max( 0, $value ) );
		return (int) get_post_meta( $event_id, $this->remaining_key(), true ) === max( 0, $value );
	}

	private function write_total( int $event_id, int $value ): bool {
		$value = max( 0, $value );
		update_post_meta( $event_id, self::TOTAL_KEY, $value );
		return metadata_exists( 'post', $event_id, self::TOTAL_KEY ) && (int) get_post_meta( $event_id, self::TOTAL_KEY, true ) === $value;
	}

	private function meta_state( int $event_id, string $key ): array {
		return array( 'exists' => metadata_exists( 'post', $event_id, $key ), 'value' => get_post_meta( $event_id, $key, true ) );
	}

	private function restore_meta_state( int $event_id, string $key, array $state ): bool {
		if ( ! empty( $state['exists'] ) ) {
			update_post_meta( $event_id, $key, $state['value'] ?? '' );
			return metadata_exists( 'post', $event_id, $key ) && (string) get_post_meta( $event_id, $key, true ) === (string) ( $state['value'] ?? '' );
		}
		delete_post_meta( $event_id, $key );
		return ! metadata_exists( 'post', $event_id, $key );
	}

	private function acquire_lock( int $event_id ) {
		$key = self::LOCK_PREFIX . $event_id;
		$existing = absint( get_option( $key ) );
		if ( $existing && $existing < time() - self::LOCK_TTL ) {
			delete_option( $key );
		}
		return add_option( $key, time(), '', false ) ? true : new \WP_Error( 'hherm_capacity_locked', 'This event capacity is being updated. Please try again.', array( 'status' => 409 ) );
	}

	private function release_lock( int $event_id ): void {
		delete_option( self::LOCK_PREFIX . $event_id );
	}

	private function full_error(): \WP_Error {
		return new \WP_Error( 'hherm_event_at_capacity', 'Sorry, this event is at capacity.', array( 'status' => 409 ) );
	}
}
