<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';

final class Capacity_Manager {
	private const TOTAL_KEY = 'event_capacity';
	private const AUDIT_TYPE = 'capacity_reservation';
	private const LOCK_PREFIX = 'hherm_capacity_lock_';
	private const LOCK_TTL = 300;

	private $settings;
	private $audit;
	private $locks = array();

	public function __construct( Settings $settings, Audit_Log $audit ) {
		$this->settings = $settings;
		$this->audit = $audit;
	}

	public function enabled(): bool {
		return '' !== $this->attendee_key() && '' !== $this->remaining_key();
	}

	/** Keep the event lock through the registration write and any compensation. */
	public function with_event_lock( int $event_id, callable $callback ) {
		if ( ! $this->enabled() ) return $callback();
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) return $locked;
		try {
			return $callback();
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function partially_configured(): bool {
		return ( ( '' !== $this->attendee_key() ) xor ( '' !== $this->remaining_key() ) );
	}

	public function is_reserved( int $registration_id ): bool {
		return $this->enabled() && 'reserved' === $this->audit->latest_status( $registration_id, self::AUDIT_TYPE );
	}

	/** Mutation callers must stop if the ledger snapshot could not be read. */
	public function reservation_state( int $registration_id ) {
		$entry = $this->enabled() ? $this->reservation_entry( $registration_id ) : array();
		if ( is_wp_error( $entry ) ) return $entry;
		$reserved = 'reserved' === ( $entry['status'] ?? '' );
		if ( $reserved ) {
			$event_id = $entry['details']['event_id'] ?? null;
			$valid = $this->validate_reservation( $entry, is_scalar( $event_id ) ? (int) $event_id : 0 );
			if ( is_wp_error( $valid ) ) return $valid;
		}
		return array(
			'reserved'  => $reserved,
			'attendees' => $reserved ? (int) $entry['details']['attendees'] : 0,
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
		if ( $event_id < 1 || $attendees < 1 || $attendees > 1000 ) {
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
		if ( ! $this->enabled() ) {
			return true;
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$entry = $this->reservation_entry( $registration_id );
			if ( is_wp_error( $entry ) ) return $entry;
			if ( 'reserved' !== ( $entry['status'] ?? '' ) ) return true;
			$valid = $this->validate_reservation( $entry, $event_id );
			if ( is_wp_error( $valid ) ) return $valid;
			$attendees = (int) $entry['details']['attendees'];
			// A partial check-in may already have returned some of this reservation.
			$unused = $this->unused_released( $registration_id, $entry );
			if ( is_wp_error( $unused ) ) return $unused;
			$attendees -= $unused;
			$current = $this->remaining( $event_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$total = $this->total( $event_id );
			$new_remaining = min( $total, $current + $attendees );
			if ( ! $this->write_remaining( $event_id, $new_remaining ) ) {
				return new \WP_Error( 'hherm_capacity_update_failed', 'The event capacity could not be returned. Please try again.', array( 'status' => 500 ) );
			}
			if ( ! $this->owns_lock( $event_id ) ) return $this->rollback_error();
			if ( ! $this->audit->write( $registration_id, self::AUDIT_TYPE, 'released', array( 'event_id' => $event_id, 'attendees' => $attendees, 'remaining' => $new_remaining ) ) ) {
				if ( ! $this->write_remaining( $event_id, $current ) ) return $this->rollback_error();
				return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity release could not be recorded. Please try again.', array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function adjust_reserved( int $registration_id, int $event_id, int $old_attendees, int $new_attendees ) {
		if ( ! $this->enabled() ) {
			return true;
		}
		if ( $event_id < 1 || $new_attendees < 1 || $new_attendees > 1000 ) {
			return new \WP_Error( 'hherm_invalid_capacity_request', 'The event or attendee count is invalid.', array( 'status' => 422 ) );
		}

		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$entry = $this->reservation_entry( $registration_id );
			if ( is_wp_error( $entry ) ) return $entry;
			if ( 'reserved' !== ( $entry['status'] ?? '' ) ) return new \WP_Error( 'hherm_capacity_not_reserved', 'This registration does not have an active capacity reservation.', array( 'status' => 409 ) );
			$valid = $this->validate_reservation( $entry, $event_id );
			if ( is_wp_error( $valid ) ) return $valid;
			$reserved = (int) $entry['details']['attendees'];
			$delta = $new_attendees - $reserved;
			if ( 0 === $delta ) return true;
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
			if ( ! $this->owns_lock( $event_id ) ) return $this->rollback_error();
			if ( ! $this->audit->write( $registration_id, self::AUDIT_TYPE, 'reserved', array( 'event_id' => $event_id, 'attendees' => $new_attendees, 'remaining' => $new_remaining, 'adjustment' => $delta, 'reservation_token' => (string) ( $entry['details']['reservation_token'] ?? '' ) ) ) ) {
				if ( ! $this->write_remaining( $event_id, $current ) ) return $this->rollback_error();
				return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity update could not be recorded. Please try again.', array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock( $event_id );
		}
	}

	public function release_unused( int $registration_id, int $event_id, int $attendees ) {
		if ( ! $this->enabled() || $attendees < 1 ) {
			return true;
		}
		$locked = $this->acquire_lock( $event_id );
		if ( is_wp_error( $locked ) ) { return $locked; }
		try {
			$entry = $this->reservation_entry( $registration_id );
			if ( is_wp_error( $entry ) ) return $entry;
			if ( 'reserved' !== ( $entry['status'] ?? '' ) ) return new \WP_Error( 'hherm_capacity_not_reserved', 'Unused places cannot be returned without an active capacity reservation. Please ask the event organiser to check this registration.', array( 'status' => 409 ) );
			$valid = $this->validate_reservation( $entry, $event_id );
			if ( is_wp_error( $valid ) ) return $valid;
			if ( $attendees >= (int) $entry['details']['attendees'] ) return new \WP_Error( 'hherm_invalid_capacity_request', 'A partial check-in must retain at least one reserved place.', array( 'status' => 422 ) );
			$unused = $this->unused_released( $registration_id, $entry );
			if ( is_wp_error( $unused ) ) return $unused;
			if ( $unused > 0 ) return $unused === $attendees ? true : new \WP_Error( 'hherm_capacity_reservation_mismatch', 'The saved check-in does not match its returned places. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
			$current = $this->remaining( $event_id );
			if ( is_wp_error( $current ) ) { return $current; }
			$total = $this->total( $event_id );
			$new_remaining = min( $total, $current + $attendees );
			if ( ! $this->write_remaining( $event_id, $new_remaining ) ) { return new \WP_Error( 'hherm_capacity_update_failed', 'The event capacity could not be returned. Please try again.', array( 'status' => 500 ) ); }
			if ( ! $this->owns_lock( $event_id ) ) return $this->rollback_error();
			if ( ! $this->audit->write( $registration_id, 'capacity_checkin_adjustment', 'released', array( 'event_id' => $event_id, 'attendees' => $attendees, 'remaining' => $new_remaining, 'reservation_token' => (string) ( $entry['details']['reservation_token'] ?? '' ) ) ) ) {
				if ( ! $this->write_remaining( $event_id, $current ) ) return $this->rollback_error();
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
		$total = get_post_meta( $event_id, self::TOTAL_KEY, true );
		if ( '' === $total ) $total = 0;
		$value = get_post_meta( $event_id, $this->remaining_key(), true );
		if ( '' === $value ) $value = $total;
		return $this->whole_count( $total, 0 ) && $this->whole_count( $value, 0, (int) $total ) ? (int) $value : new \WP_Error( 'hherm_invalid_remaining_capacity', 'The event capacity values are invalid. Please ask the event organiser to check the total and remaining places.', array( 'status' => 500 ) );
	}

	private function reserve_while_locked( int $registration_id, int $event_id, int $attendees ) {
		if ( ! $this->owns_lock( $event_id ) ) return new \WP_Error( 'hherm_capacity_locked', 'The event capacity lock expired. Please try again.', array( 'status' => 409 ) );
		if ( $registration_id < 1 || $attendees < 1 || $attendees > 1000 ) return new \WP_Error( 'hherm_invalid_capacity_request', 'The registration or attendee count is invalid.', array( 'status' => 422 ) );
		$entry = $this->reservation_entry( $registration_id );
		if ( is_wp_error( $entry ) ) return $entry;
		if ( 'reserved' === ( $entry['status'] ?? '' ) ) {
			$valid = $this->validate_reservation( $entry, $event_id );
			if ( is_wp_error( $valid ) ) return $valid;
			if ( $attendees !== (int) $entry['details']['attendees'] ) return new \WP_Error( 'hherm_capacity_reservation_mismatch', 'The saved party size does not match its reserved places. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
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
		if ( ! $this->owns_lock( $event_id ) ) return $this->rollback_error();
		if ( ! $this->audit->write( $registration_id, self::AUDIT_TYPE, 'reserved', array( 'event_id' => $event_id, 'attendees' => $attendees, 'remaining' => $new_remaining, 'reservation_token' => bin2hex( random_bytes( 16 ) ) ) ) ) {
			if ( ! $this->write_remaining( $event_id, $current ) ) return $this->rollback_error();
			return new \WP_Error( 'hherm_capacity_ledger_failed', 'The capacity reservation could not be recorded. Please try again.', array( 'status' => 500 ) );
		}
		return true;
	}

	private function total( int $event_id ): int {
		$value = get_post_meta( $event_id, self::TOTAL_KEY, true );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function unused_released( int $registration_id, array $reservation ) {
		$adjustment = $this->audit->latest_entry_checked( $registration_id, 'capacity_checkin_adjustment' );
		if ( is_wp_error( $adjustment ) ) return $adjustment;
		if ( ! $adjustment ) return 0;
		if ( 'released' !== ( $adjustment['status'] ?? '' ) ) return new \WP_Error( 'hherm_invalid_capacity_ledger', 'The partial check-in ledger is invalid. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		if ( ! is_array( $adjustment['details'] ?? null ) ) return new \WP_Error( 'hherm_invalid_capacity_ledger', 'The partial check-in ledger is invalid. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		$token = (string) ( $reservation['details']['reservation_token'] ?? '' );
		$adjustment_token = $adjustment['details']['reservation_token'] ?? '';
		if ( ! is_scalar( $adjustment_token ) ) return new \WP_Error( 'hherm_invalid_capacity_ledger', 'The partial check-in ledger is invalid. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		if ( $token !== (string) $adjustment_token ) return 0;
		$valid = $this->validate_reservation( $adjustment, (int) $reservation['details']['event_id'] );
		if ( is_wp_error( $valid ) || (int) $adjustment['details']['attendees'] >= (int) $reservation['details']['attendees'] ) return new \WP_Error( 'hherm_invalid_capacity_ledger', 'The partial check-in ledger is invalid. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		return (int) $adjustment['details']['attendees'];
	}

	private function reservation_entry( int $registration_id ) {
		$entry = $this->audit->latest_entry_checked( $registration_id, self::AUDIT_TYPE );
		if ( is_wp_error( $entry ) ) return $entry;
		if ( $entry && ! in_array( $entry['status'] ?? '', array( 'reserved', 'released' ), true ) ) return new \WP_Error( 'hherm_invalid_capacity_ledger', 'The capacity reservation ledger is invalid. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		return $entry;
	}

	private function validate_reservation( array $entry, int $event_id ) {
		$details = $entry['details'] ?? array();
		if ( ! is_array( $details ) || ! $this->whole_count( $details['event_id'] ?? null, 1 ) || ! $this->whole_count( $details['attendees'] ?? null, 1, 1000 ) || ( isset( $details['reservation_token'] ) && ! is_scalar( $details['reservation_token'] ) ) ) return new \WP_Error( 'hherm_invalid_capacity_ledger', 'The capacity reservation ledger is invalid. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		if ( (int) $details['event_id'] !== $event_id ) return new \WP_Error( 'hherm_capacity_event_mismatch', 'The capacity reservation belongs to another event. Please ask the event organiser to reconcile this registration.', array( 'status' => 409 ) );
		return true;
	}

	/** Validate persisted counters without truncating decimals, negatives or oversized integers. */
	private function whole_count( $value, int $minimum, int $maximum = PHP_INT_MAX ): bool {
		if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]+$/', (string) $value ) ) return false;
		$digits = ltrim( (string) $value, '0' );
		$limit = (string) $maximum;
		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) return false;
		return (int) $value >= $minimum && (int) $value <= $maximum;
	}

	private function rollback_error(): \WP_Error {
		return new \WP_Error( 'hherm_capacity_rollback_failed', 'The capacity change could not be recorded or restored. Please ask the event organiser to check the remaining places before retrying.', array( 'status' => 500 ) );
	}

	private function write_remaining( int $event_id, int $value ): bool {
		if ( ! $this->owns_lock( $event_id ) ) return false;
		update_post_meta( $event_id, $this->remaining_key(), max( 0, $value ) );
		$stored = get_post_meta( $event_id, $this->remaining_key(), true );
		return metadata_exists( 'post', $event_id, $this->remaining_key() ) && $this->whole_count( $stored, 0 ) && (int) $stored === max( 0, $value );
	}

	private function write_total( int $event_id, int $value ): bool {
		if ( $this->enabled() && ! $this->owns_lock( $event_id ) ) return false;
		$value = max( 0, $value );
		update_post_meta( $event_id, self::TOTAL_KEY, $value );
		$stored = get_post_meta( $event_id, self::TOTAL_KEY, true );
		return metadata_exists( 'post', $event_id, self::TOTAL_KEY ) && $this->whole_count( $stored, 0 ) && (int) $stored === $value;
	}

	private function meta_state( int $event_id, string $key ): array {
		return array( 'exists' => metadata_exists( 'post', $event_id, $key ), 'value' => get_post_meta( $event_id, $key, true ) );
	}

	private function restore_meta_state( int $event_id, string $key, array $state ): bool {
		if ( $this->enabled() && ! $this->owns_lock( $event_id ) ) return false;
		if ( ! empty( $state['exists'] ) ) {
			update_post_meta( $event_id, $key, $state['value'] ?? '' );
			return metadata_exists( 'post', $event_id, $key ) && (string) get_post_meta( $event_id, $key, true ) === (string) ( $state['value'] ?? '' );
		}
		delete_post_meta( $event_id, $key );
		return ! metadata_exists( 'post', $event_id, $key );
	}

	private function acquire_lock( int $event_id ) {
		if ( $event_id < 1 ) return new \WP_Error( 'hherm_invalid_capacity_request', 'The event is invalid.', array( 'status' => 422 ) );
		$key = self::LOCK_PREFIX . $event_id;
		if ( isset( $this->locks[ $event_id ] ) ) {
			if ( ! $this->owns_lock( $event_id ) ) return new \WP_Error( 'hherm_capacity_locked', 'The event capacity lock expired. Please try again.', array( 'status' => 409 ) );
			++$this->locks[ $event_id ]['depth'];
			return true;
		}
		$token = Mutation_Lock::acquire( $key, self::LOCK_TTL );
		if ( is_wp_error( $token ) ) return new \WP_Error( 'hherm_capacity_locked', 'This event capacity is being updated. Please try again.', array( 'status' => 409 ) );
		$this->locks[ $event_id ] = array( 'token' => $token, 'depth' => 1 );
		// Another request may have changed metadata since this request first read it.
		wp_cache_delete( $event_id, 'post_meta' );
		return true;
	}

	private function release_lock( int $event_id ): void {
		if ( ! isset( $this->locks[ $event_id ] ) ) return;
		if ( --$this->locks[ $event_id ]['depth'] > 0 ) return;
		Mutation_Lock::release( self::LOCK_PREFIX . $event_id, $this->locks[ $event_id ]['token'] );
		unset( $this->locks[ $event_id ] );
	}

	private function owns_lock( int $event_id ): bool {
		return isset( $this->locks[ $event_id ] ) && Mutation_Lock::owns( self::LOCK_PREFIX . $event_id, $this->locks[ $event_id ]['token'] );
	}

	private function full_error(): \WP_Error {
		return new \WP_Error( 'hherm_event_at_capacity', 'Sorry, this event is at capacity.', array( 'status' => 409 ) );
	}
}
