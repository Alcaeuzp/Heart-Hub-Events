<?php
/** Regression probes for capacity races, failed writes, withdrawals and check-in recovery. */
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error { public $code; public $message; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
	require __DIR__ . '/support/option-lock.php';
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( (string) $value ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
	function sanitize_email( $value ) { return trim( (string) $value ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_unslash( $value ) { return $value; }
	function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce; }
	function current_time( $format ) { return '2026-09-27 12:00:00'; }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function add_option( $key, $value, ...$unused ) {
		// Another request completes after the caller read its ledger but before it acquires the lock.
		if ( isset( $GLOBALS['before_lock'] ) ) { $hook = $GLOBALS['before_lock']; unset( $GLOBALS['before_lock'] ); $hook(); }
		if ( isset( $GLOBALS['options'][ $key ] ) ) return false;
		$GLOBALS['options'][ $key ] = $value;
		return true;
	}
	function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'] ); }
	function update_post_meta( $id, $key, $value ) { if ( empty( $GLOBALS['fail_meta'] ) ) $GLOBALS['meta'][ $key ] = $value; if ( isset( $GLOBALS['after_meta'] ) ) { $callback = $GLOBALS['after_meta']; unset( $GLOBALS['after_meta'] ); $callback(); } }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
	function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
	function check( $condition, $description ) { if ( ! $condition ) throw new \RuntimeException( $description ); echo 'PASS ' . $description . PHP_EOL; }
}
namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return array( 'attendee_count_field' => 'number_of_attendees', 'event_remaining_capacity' => 'remaining' )[ $key ] ?? $default; } }
	class Public_Page_Theme {}
	class Audit_Log {
		public $entries = array();
		public $fail_type = '';
		public $on_write;
		public $fail_read_type = '';
		public function latest_entry( $id, $type ) { return $this->entries[ $id ][ $type ] ?? array(); }
		public function latest_entry_checked( $id, $type ) { return $this->fail_read_type === $type ? new \WP_Error( 'hherm_capacity_ledger_read_failed' ) : $this->latest_entry( $id, $type ); }
		public function latest_status( $id, $type ) { return $this->latest_entry( $id, $type )['status'] ?? ''; }
		public function write( $id, $type, $status, $details ) { if ( $this->fail_type === $type ) { if ( ! empty( $GLOBALS['fail_rollback'] ) ) $GLOBALS['fail_meta'] = true; return false; } $this->entries[ $id ][ $type ] = compact( 'status', 'details' ); if ( $this->on_write ) { $callback = $this->on_write; $this->on_write = null; $callback(); } return true; }
	}
	class CCT_Repository {
		public $fail = false;
		public $item = array();
		public $on_get;
		public function get( $id ) { if ( $this->on_get ) { $callback = $this->on_get; $this->on_get = null; $callback(); } return $this->item; }
		public function update_registration( $id, $fields ) { if ( $this->fail ) return new \WP_Error( 'save_failed' ); return $this->item = array_replace( $this->item, $fields ); }
		public function find_approved_by_email( $event_id, $email ) { return $this->item; }
		public function update_self_checkin( $id, $feedback, $party, $status, $score ) { return $this->update_registration( $id, array( 'checked_in_at' => 'now', 'checked_in_party_size' => $party, 'attendance_status' => $status ) ); }
	}
	require dirname( __DIR__ ) . '/includes/class-capacity-manager.php';
	require dirname( __DIR__ ) . '/includes/class-registration-self-service.php';
	require dirname( __DIR__ ) . '/includes/class-checkin-manager.php';
	$settings = new Settings(); $audit = new Audit_Log(); $repository = new CCT_Repository(); $capacity = new Capacity_Manager( $settings, $audit );
	$competing_capacity = new Capacity_Manager( $settings, $audit );
	$reset = static function ( $party = 2 ) use ( $audit, $repository ) {
		$GLOBALS['options'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['meta'] = array( 'event_capacity' => 10, 'remaining' => 4 ); $GLOBALS['fail_meta'] = false; $GLOBALS['fail_rollback'] = false;
		$repository->fail = false; $repository->item = array( '_ID' => 1, 'event_id' => 10, 'number_of_attendees' => $party, 'email' => 'guest@example.test', 'registration_status' => 'approved' );
		$repository->on_get = null; $audit->on_write = null;
		$audit->entries = array(); $audit->fail_type = ''; $audit->fail_read_type = ''; $audit->write( 1, 'capacity_reservation', 'reserved', array( 'event_id' => 10, 'attendees' => $party ) );
	};
	$reset();
	$GLOBALS['before_lock'] = static function () use ( $competing_capacity ) { $competing_capacity->release( 1, 10, 2 ); };
	$capacity->release( 1, 10, 2 );
	\check( 6 === $GLOBALS['meta']['remaining'], 'two interleaved releases return a reservation only once' );
	$reset();
	$GLOBALS['before_lock'] = static function () use ( $competing_capacity ) { $competing_capacity->adjust_reserved( 1, 10, 2, 4 ); };
	$capacity->adjust_reserved( 1, 10, 2, 3 );
	\check( 3 === $GLOBALS['meta']['remaining'] && 3 === $capacity->reservation_state( 1 )['attendees'], 'interleaved party edits calculate from the current locked ledger' );
	$reset( 4 );
	$GLOBALS['before_lock'] = static function () use ( $competing_capacity ) { $competing_capacity->release_unused( 1, 10, 1 ); };
	$capacity->release_unused( 1, 10, 1 );
	\check( 5 === $GLOBALS['meta']['remaining'], 'interleaved partial check-ins return unused places only once' );
	$reset(); $audit->entries = array(); $GLOBALS['meta'] = array( 'event_capacity' => 2 ); $GLOBALS['fail_meta'] = true;
	$capacity->lock_for_registration( 10, 2 ); $result = $capacity->complete_registration( 1, 10, 2 );
	\check( is_wp_error( $result ) && ! $capacity->is_reserved( 1 ) && 2 === $capacity->remaining( 10 ), 'failed zero-capacity metadata creation is rejected without reserving the ledger' );
	$service = new Registration_Self_Service( $settings, $repository, $audit, $capacity, new Public_Page_Theme() );
	$withdraw = new \ReflectionMethod( $service, 'withdraw' );
	if ( PHP_VERSION_ID < 80100 ) $withdraw->setAccessible( true );
	foreach ( array( 'capacity lock conflict', 'registration database failure' ) as $scenario ) {
		$reset();
		if ( 'capacity lock conflict' === $scenario ) $GLOBALS['options']['hherm_capacity_lock_10'] = time(); else $repository->fail = true;
		$token = Mutation_Lock::acquire( 'hherm_review_lock_1' );
		try { $result = $withdraw->invoke( $service, $repository->item, 1, 10, $token ); }
		finally { Mutation_Lock::release( 'hherm_review_lock_1', $token ); }
		\check( is_wp_error( $result ) && 4 === $GLOBALS['meta']['remaining'] && $capacity->is_reserved( 1 ), 'withdrawal returns a recoverable error and preserves capacity on ' . $scenario );
	}
	$reset( 4 );
	$GLOBALS['options']['hherm_capacity_lock_10'] = time();
	$manager = new Checkin_Manager( $settings, $repository, $audit, $capacity, new Public_Page_Theme() );
	$submit = new \ReflectionMethod( $manager, 'process_public_submission' );
	if ( PHP_VERSION_ID < 80100 ) $submit->setAccessible( true );
	$_POST = array( 'hherm_public_nonce' => 'valid', 'identity' => 'guest@example.test', 'checkin_step' => 'confirm', 'party_size' => '3' );
	$result = $submit->invoke( $manager, 10, 'token' );
	\check( false !== strpos( $result, 'try again' ) && empty( $repository->item['checked_in_at'] ), 'busy event lock blocks check-in before attendance is written' );
	unset( $GLOBALS['options']['hherm_capacity_lock_10'] );
	$result = $submit->invoke( $manager, 10, 'token' );
	\check( false !== strpos( $result, 'recorded' ) && 3 === $repository->item['checked_in_party_size'] && 5 === $GLOBALS['meta']['remaining'], 'check-in retries after a busy event lock and returns the unused place' );
	$reset( 4 ); $audit->fail_type = 'capacity_checkin_adjustment';
	$result = $submit->invoke( $manager, 10, 'token' );
	\check( false !== strpos( $result, 'could not be returned' ) && 3 === $repository->item['checked_in_party_size'] && 4 === $GLOBALS['meta']['remaining'], 'failed check-in capacity ledger is visible and its metadata is restored' );
	$audit->fail_type = ''; $_POST['party_size'] = '1';
	$result = $submit->invoke( $manager, 10, 'token' );
	$submit->invoke( $manager, 10, 'token' );
	\check( false !== strpos( $result, 'recorded' ) && 5 === $GLOBALS['meta']['remaining'] && 3 === $repository->item['checked_in_party_size'], 'replay reconciles the saved party exactly once and ignores a changed submitted party' );
	$reset();
	$older = Mutation_Lock::acquire( 'hherm_review_lock_1' );
	$GLOBALS['options']['hherm_review_lock_1']['expires_at'] = time() - 1;
	$newer = Mutation_Lock::acquire( 'hherm_review_lock_1' );
	Mutation_Lock::release( 'hherm_review_lock_1', $older );
	\check( is_string( $newer ) && Mutation_Lock::owns( 'hherm_review_lock_1', $newer ), 'expired owner cannot delete its replacement lock' );
	\check( false !== strpos( $submit->invoke( $manager, 10, 'token' ), 'being updated' ), 'check-in uses the same registration lock as staff review' );
	$GLOBALS['meta']['start_date'] = time() + 3600;
	$_POST['hherm_manage_nonce'] = 'valid'; $_POST['hherm_manage_action'] = 'withdraw';
	$manage_submit = new \ReflectionMethod( $service, 'process_submission' );
	if ( PHP_VERSION_ID < 80100 ) $manage_submit->setAccessible( true );
	\check( is_wp_error( $manage_submit->invoke( $service, $repository->item, array( 'id' => 10 ), 1, 'token' ) ), 'self-service uses the same registration lock as staff review' );
	Mutation_Lock::release( 'hherm_review_lock_1', $newer );
	$other_capacity = new Capacity_Manager( $settings, $audit );
	$capacity->with_event_lock( 10, function () use ( $capacity, $other_capacity ) {
		$capacity->adjust_reserved( 1, 10, 2, 3 );
		\check( is_wp_error( $other_capacity->ensure_reserved( 2, 10, 1 ) ), 'nested capacity operations keep the outer event lock through the registration save' );
		$capacity->adjust_reserved( 1, 10, 3, 2 );
	} );
	\check( 4 === $GLOBALS['meta']['remaining'] && ! isset( $GLOBALS['options']['hherm_capacity_lock_10'] ), 'outer lock is released after compensation restores original capacity' );
	foreach ( array( 'reserve', 'release', 'adjust', 'partial check-in' ) as $operation ) {
		$reset(); $GLOBALS['fail_rollback'] = true;
		$audit->fail_type = 'partial check-in' === $operation ? 'capacity_checkin_adjustment' : 'capacity_reservation';
		if ( 'reserve' === $operation ) { $audit->entries = array(); $result = $capacity->ensure_reserved( 1, 10, 2 ); }
		elseif ( 'release' === $operation ) $result = $capacity->release( 1, 10, 2 );
		elseif ( 'adjust' === $operation ) $result = $capacity->adjust_reserved( 1, 10, 2, 3 );
		else $result = $capacity->release_unused( 1, 10, 1 );
		\check( is_wp_error( $result ) && 'hherm_capacity_rollback_failed' === $result->get_error_code(), $operation . ' reports a failed ledger and failed compensation without claiming restoration' );
	}
	$reset( 4 );
	$capacity->release_unused( 1, 10, 1 ); $capacity->release( 1, 10, 4 );
	\check( 8 === $GLOBALS['meta']['remaining'], 'withdrawal after partial check-in returns only the places still reserved' );
	$capacity->ensure_reserved( 1, 10, 4 );
	$capacity->release_unused( 1, 10, 1 ); $capacity->release( 1, 10, 4 );
	\check( 8 === $GLOBALS['meta']['remaining'], 'a new reservation cycle ignores the prior cycle partial-release marker' );
	$reset();
	$capacity->with_event_lock( 10, function () use ( $capacity ) {
		$GLOBALS['options']['hherm_capacity_lock_10'] = array( 'token' => 'replacement', 'expires_at' => time() + 300 );
		$total = new \ReflectionMethod( $capacity, 'write_total' ); $total->setAccessible( true );
		$restore = new \ReflectionMethod( $capacity, 'restore_meta_state' ); $restore->setAccessible( true );
		\check( false === $total->invoke( $capacity, 10, 99 ) && false === $restore->invoke( $capacity, 10, 'remaining', array( 'exists' => true, 'value' => 99 ) ), 'obsolete event owner cannot write totals or restore metadata over a replacement owner' );
	} );
	\check( 'replacement' === $GLOBALS['options']['hherm_capacity_lock_10']['token'] && 10 === $GLOBALS['meta']['event_capacity'] && 4 === $GLOBALS['meta']['remaining'], 'obsolete event owner leaves replacement lock and capacity untouched' );
	$operations = array(
		'reserve' => static function () use ( $capacity ) { return $capacity->ensure_reserved( 1, 10, 2 ); },
		'release' => static function () use ( $capacity ) { return $capacity->release( 1, 10, 2 ); },
		'adjust' => static function () use ( $capacity ) { return $capacity->adjust_reserved( 1, 10, 2, 3 ); },
		'partial release' => static function () use ( $capacity ) { return $capacity->release_unused( 1, 10, 1 ); },
	);
	foreach ( $operations as $label => $operation ) {
		$reset(); $audit->entries[1]['capacity_reservation']['details']['event_id'] = 99;
		$result = $operation();
		\check( is_wp_error( $result ) && 4 === $GLOBALS['meta']['remaining'], $label . ' rejects a reservation belonging to another event without changing capacity' );
		$all_rejected = true;
		foreach ( array( -2, 0, 'oops', '2.5', array(), 1001 ) as $invalid_count ) {
			$reset(); $audit->entries[1]['capacity_reservation']['details']['attendees'] = $invalid_count;
			$result = $operation();
			$all_rejected = $all_rejected && is_wp_error( $result ) && 4 === $GLOBALS['meta']['remaining'];
		}
		\check( $all_rejected, $label . ' rejects malformed ledger party sizes instead of inventing a reservation size' );
	}
	$reset(); $audit->entries = array();
	\check( is_wp_error( $capacity->release_unused( 1, 10, 1 ) ) && 4 === $GLOBALS['meta']['remaining'], 'partial release cannot create free places for an unreserved registration' );
	$reset(); $capacity->release( 1, 10, 2 );
	\check( is_wp_error( $capacity->release_unused( 1, 10, 1 ) ) && 6 === $GLOBALS['meta']['remaining'], 'partial release cannot return places again after withdrawal' );
	$reset();
	\check( is_wp_error( $capacity->release_unused( 1, 10, 3 ) ) && 4 === $GLOBALS['meta']['remaining'], 'partial release cannot return more places than the reservation contains' );
	$reset();
	\check( is_wp_error( $capacity->ensure_reserved( 1, 10, 4 ) ) && 4 === $GLOBALS['meta']['remaining'], 'a different requested party is not reported as already fully reserved' );
	foreach ( array( '4.5', 11, array( 4 ), '-1', '1e3' ) as $invalid_remaining ) {
		$reset(); $GLOBALS['meta']['remaining'] = $invalid_remaining;
		\check( is_wp_error( $capacity->remaining( 10 ) ), 'invalid remaining capacity is rejected: ' . json_encode( $invalid_remaining ) );
	}
	$reset();
	$audit->write( 1, 'capacity_checkin_adjustment', 'released', array( 'event_id' => 99, 'attendees' => 1 ) );
	\check( is_wp_error( $capacity->release( 1, 10, 2 ) ) && 4 === $GLOBALS['meta']['remaining'], 'a partial-release marker for another event cannot alter this reservation' );
	$reset( 4 ); $capacity->release_unused( 1, 10, 1 );
	\check( is_wp_error( $capacity->release_unused( 1, 10, 2 ) ) && 5 === $GLOBALS['meta']['remaining'], 'a changed persisted partial check-in cannot falsely reuse a different released count' );
	$replace_lock = static function () { $GLOBALS['options']['hherm_review_lock_1'] = array( 'token' => 'replacement', 'expires_at' => time() + 300 ); };
	foreach ( array( 'withdraw', 'update' ) as $action ) {
		$reset(); $GLOBALS['meta']['start_date'] = time() + 3600; $repository->on_get = $replace_lock;
		$_POST = array( 'hherm_manage_nonce' => 'valid', 'hherm_manage_action' => $action, 'number_of_attendees' => '3' );
		$result = $manage_submit->invoke( $service, $repository->item, array( 'id' => 10 ), 1, 'token' );
		\check( is_wp_error( $result ) && 'hherm_registration_lock_lost' === $result->get_error_code() && 'approved' === $repository->item['registration_status'] && 4 === $GLOBALS['meta']['remaining'] && 'replacement' === $GLOBALS['options']['hherm_review_lock_1']['token'], $action . ' stops before capacity and customer writes when its registration lease was replaced during the read' );
		$reset(); $GLOBALS['meta']['start_date'] = time() + 3600; $audit->on_write = $replace_lock;
		$result = $manage_submit->invoke( $service, $repository->item, array( 'id' => 10 ), 1, 'token' );
		\check( is_wp_error( $result ) && 'hherm_registration_lock_lost' === $result->get_error_code() && 'approved' === $repository->item['registration_status'] && 2 === $repository->item['number_of_attendees'] && 'replacement' === $GLOBALS['options']['hherm_review_lock_1']['token'], $action . ' stops before saving when its lease was replaced during the capacity write and reports reconciliation is needed' );
	}
	$reset( 4 ); $repository->on_get = $replace_lock;
	$_POST = array( 'hherm_public_nonce' => 'valid', 'identity' => 'guest@example.test', 'checkin_step' => 'confirm', 'party_size' => '3' );
	$result = $submit->invoke( $manager, 10, 'token' );
	\check( false !== strpos( $result, 'changed' ) && empty( $repository->item['checked_in_at'] ) && 4 === $GLOBALS['meta']['remaining'] && 'replacement' === $GLOBALS['options']['hherm_review_lock_1']['token'], 'check-in stops before attendance and capacity writes when its registration lease was replaced' );
	foreach ( $operations as $label => $operation ) {
		$reset(); if ( 'reserve' === $label ) $audit->entries = array();
		$before_entries = $audit->entries;
		$GLOBALS['after_meta'] = static function () { $GLOBALS['options']['hherm_capacity_lock_10'] = array( 'token' => 'replacement', 'expires_at' => time() + 300 ); };
		$result = $operation();
		\check( is_wp_error( $result ) && 'hherm_capacity_rollback_failed' === $result->get_error_code() && $before_entries === $audit->entries && 'replacement' === $GLOBALS['options']['hherm_capacity_lock_10']['token'], $label . ' stops before writing the ledger if its event lease was replaced during metadata storage and explicitly requires reconciliation' );
	}
	foreach ( $operations as $label => $operation ) {
		$reset(); $before_entries = $audit->entries; $audit->fail_read_type = 'capacity_reservation';
		$result = $operation();
		\check( is_wp_error( $result ) && 4 === $GLOBALS['meta']['remaining'] && $before_entries === $audit->entries, $label . ' propagates reservation SELECT failure without changing metadata or ledger' );
	}
	foreach ( array( 'release', 'partial release' ) as $label ) {
		$reset(); $before_entries = $audit->entries; $audit->fail_read_type = 'capacity_checkin_adjustment';
		$result = $operations[ $label ]();
		\check( is_wp_error( $result ) && 4 === $GLOBALS['meta']['remaining'] && $before_entries === $audit->entries, $label . ' propagates adjustment SELECT failure instead of issuing another refund' );
	}
	$reset(); $audit->fail_read_type = 'capacity_reservation';
	\check( is_wp_error( $capacity->reservation_state( 1 ) ), 'a failed reservation snapshot cannot be mistaken for an unreserved registration' );
	foreach ( array( 'withdraw', 'update' ) as $action ) {
		$reset(); $GLOBALS['meta']['start_date'] = time() + 3600; $audit->fail_read_type = 'capacity_reservation';
		$_POST = array( 'hherm_manage_nonce' => 'valid', 'hherm_manage_action' => $action, 'number_of_attendees' => '3' );
		$result = $manage_submit->invoke( $service, $repository->item, array( 'id' => 10 ), 1, 'token' );
		\check( is_wp_error( $result ) && 'approved' === $repository->item['registration_status'] && 2 === $repository->item['number_of_attendees'] && 4 === $GLOBALS['meta']['remaining'], $action . ' refuses to mutate registration or capacity when its rollback snapshot cannot be read' );
	}
	foreach ( $operations as $label => $operation ) {
		$all_rejected = true;
		foreach ( array( 'unknown', '' ) as $status ) {
			$reset(); $audit->entries[1]['capacity_reservation']['status'] = $status;
			$before_entries = $audit->entries; $result = $operation();
			$all_rejected = $all_rejected && is_wp_error( $result ) && $before_entries === $audit->entries && 4 === $GLOBALS['meta']['remaining'];
		}
		\check( $all_rejected, $label . ' rejects unknown or missing reservation status without treating it as unreserved' );
	}
	$reset(); $audit->entries[1]['capacity_reservation']['status'] = 'unknown';
	\check( is_wp_error( $capacity->reservation_state( 1 ) ), 'a reservation snapshot rejects an unknown ledger status' );
	foreach ( array( 'release', 'partial release' ) as $label ) {
		$reset(); $audit->write( 1, 'capacity_checkin_adjustment', 'unknown', array( 'event_id' => 10, 'attendees' => 1 ) );
		\check( is_wp_error( $operations[ $label ]() ) && 4 === $GLOBALS['meta']['remaining'], $label . ' rejects an unknown partial-refund status instead of refunding again' );
	}
}
