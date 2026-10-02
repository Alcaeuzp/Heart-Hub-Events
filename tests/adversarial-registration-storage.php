<?php
/** Real repository/controller/capacity integration with adversarial CCT storage. */
namespace {
	define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error { public $code; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->code; } }
	class WP_REST_Request extends ArrayObject { public function has_param( $key ) { return $this->offsetExists( $key ); } public function get_param( $key ) { return $this[ $key ] ?? null; } }
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function sanitize_key( $value ) { return strtolower( (string) $value ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
	function sanitize_email( $value ) { return trim( (string) $value ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function rest_ensure_response( $value ) { return $value; }
	function get_current_user_id() { return 1; }
	function current_time( $format ) { return '2026-09-27 12:00:00'; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { if ( ! empty( $GLOBALS['fail_meta'] ) ) return false; $GLOBALS['meta'][ $key ] = $value; return true; }
	function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'] ); }
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish', 'post_content' => '', 'post_excerpt' => '' ); }
	function get_the_title( $id ) { return 'Adversarial event'; }
	function get_userdata( $id ) { return (object) array( 'display_name' => 'Staff' ); }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return gmdate( $format, $timestamp ); }
	function wp_unslash( $value ) { return $value; }
	function wp_verify_nonce( $value, $action ) { return 'valid' === $value; }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
	function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
	function check( $condition, $label ) { if ( ! $condition ) throw new \RuntimeException( $label ); echo 'PASS ' . $label . PHP_EOL; }
	require __DIR__ . '/support/option-lock.php';
}
namespace Jet_Engine\Modules\Custom_Content_Types {
	class Module { public static function instance() { return (object) array( 'manager' => new class { public function get_content_types( $slug ) { return $GLOBALS['cct_type']; } } ); } }
}
namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return array( 'attendee_count_field' => 'number_of_attendees', 'event_remaining_capacity' => 'remaining', 'events_cpt' => 'events' )[ $key ] ?? $default; } }
	class Event_Public_Display { public static function normalise_date_status( $a, $b ) { return 'confirmed'; } }
	class Audit_Log {
		public $entries = array();
		public function latest_entry( $id, $type ) { return $this->entries[ $id ][ $type ] ?? array(); }
		public function latest_entry_checked( $id, $type ) { return $this->latest_entry( $id, $type ); }
		public function latest_status( $id, $type ) { return $this->latest_entry( $id, $type )['status'] ?? ''; }
		public function write( $id, $type, $status, $details = array() ) { $this->entries[ $id ][ $type ] = compact( 'status', 'details' ); return true; }
	}
	class Email_Automation {
		public $sent = 0;
		public function send_decision( $item, $event, $status ) {
			++$this->sent;
			\check( true === $GLOBALS['competing_capacity']->with_event_lock( 10, static function () { return true; } ), 'mail delivery permits another registration to use the event capacity lock' );
			\check( is_wp_error( Mutation_Lock::acquire( 'hherm_review_lock_1' ) ), 'mail delivery keeps this registration protected against concurrent review' );
			return array( 'status' => 'logged' );
		}
	}
	class Public_Page_Theme {}
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-cct-repository.php';
	require dirname( __DIR__ ) . '/includes/class-capacity-manager.php';
	require dirname( __DIR__ ) . '/includes/class-rest-controller.php';
	require dirname( __DIR__ ) . '/includes/class-registration-self-service.php';
	require dirname( __DIR__ ) . '/includes/class-checkin-manager.php';
	$db = new class {
		public $item = array(); public $read_failure = false;
		public function set_format_flag( $format ) {}
		public function get_item( $id ) { if ( $this->read_failure ) throw new \RuntimeException( 'read fault' ); return $this->item; }
		public function query( ...$args ) { return array( $this->item ); }
	};
	$handler = new class( $db ) {
		private $db; public $plans = array(); public $calls = 0; public $on_write;
		public function __construct( $db ) { $this->db = $db; }
		public function update_item( $fields ) {
			++$this->calls;
			\check( isset( $GLOBALS['options']['hherm_review_lock_1'], $GLOBALS['options']['hherm_capacity_lock_10'] ), 'registration and event locks span CCT write ' . $this->calls );
			if ( $this->on_write ) { $hook = $this->on_write; $this->on_write = null; $hook(); }
			$plan = array_shift( $this->plans ) ?: array();
			if ( ! empty( $plan['before_throw'] ) ) throw new \RuntimeException( 'before write fault' );
			if ( ! empty( $plan['skip'] ) ) return false;
			foreach ( $plan['drop'] ?? array() as $key ) unset( $fields[ $key ] );
			$fields = array_replace( $fields, $plan['force'] ?? array() );
			$this->db->item = array_replace( $this->db->item, $fields );
			if ( ! empty( $plan['after_throw'] ) ) throw new \RuntimeException( 'after write fault' );
			if ( ! empty( $plan['read_failure'] ) ) $this->db->read_failure = true;
			return array_key_exists( 'result', $plan ) ? $plan['result'] : 1;
		}
	};
	$GLOBALS['cct_type'] = new class( $db, $handler ) {
		public $db; private $handler;
		public function __construct( $db, $handler ) { $this->db = $db; $this->handler = $handler; }
		public function get_item_handler() { return $this->handler; }
	};
	$settings = new Settings(); $audit = new Audit_Log(); $repository = new CCT_Repository( $settings ); $capacity = new Capacity_Manager( $settings, $audit ); $email = new Email_Automation();
	$GLOBALS['competing_capacity'] = new Capacity_Manager( $settings, $audit );
	$rest = new REST_Controller( $repository, $email, $audit, $settings, $capacity );
	$reset = static function ( $status = 'approved', $reserved = true ) use ( $db, $handler, $audit, $email ) {
		$GLOBALS['options'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['meta'] = array( 'event_capacity' => 10, 'remaining' => $reserved ? 8 : 10, 'start_date' => time() + 3600 ); $GLOBALS['fail_meta'] = false;
		$db->read_failure = false; $db->item = array( '_ID' => 1, 'event_id' => 10, 'registration_status' => $status, 'number_of_attendees' => 2, 'first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'guest@example.test', 'dietaryrequirements' => 'no', 'please_let_us_know' => '', 'approval_notes' => '', 'decline_reason' => '', 'reviewed_by' => 0, 'reviewed_date' => '' );
		$handler->calls = 0; $handler->plans = array(); $handler->on_write = null; $audit->entries = array(); $email->sent = 0;
		if ( $reserved ) $audit->write( 1, 'capacity_reservation', 'reserved', array( 'event_id' => 10, 'attendees' => 2 ) );
	};
	$edit = static function () use ( $rest ) { return $rest->edit( new \WP_REST_Request( array( 'id' => 1, 'number_of_attendees' => 4, 'dietaryrequirements' => 'yes', 'please_let_us_know' => 'Gluten free' ) ) ); };
	$check_state = static function ( $party, $status, $remaining, $reserved, $label ) use ( $db, $capacity ) {
		\check( $party === (int) $db->item['number_of_attendees'] && $status === $db->item['registration_status'] && $remaining === $GLOBALS['meta']['remaining'] && $reserved === $capacity->is_reserved( 1 ), $label );
		\check( ! isset( $GLOBALS['options']['hherm_review_lock_1'] ) && ! isset( $GLOBALS['options']['hherm_capacity_lock_10'] ), 'mutation locks released after ' . $label );
	};
	$reset(); $handler->plans = array( array( 'drop' => array( 'dietaryrequirements', 'please_let_us_know' ) ) );
	$result = $edit();
	\check( is_wp_error( $result ) && 'no' === $db->item['dietaryrequirements'], 'partially saved party/dietary edit is rejected and contact fields restored' );
	$check_state( 2, 'approved', 8, true, 'partial edit restores party and matching capacity' );
	$reset(); $handler->plans = array( array( 'drop' => array( 'dietaryrequirements' ) ), array( 'skip' => true ) );
	$result = $edit();
	\check( is_wp_error( $result ) && 'hherm_update_rollback_failed' === $result->get_error_code(), 'failed storage compensation is explicit' );
	$check_state( 4, 'approved', 6, true, 'failed edit compensation keeps capacity aligned with persisted party' );
	$reset(); $handler->plans = array( array( 'drop' => array( 'dietaryrequirements' ) ), array( 'force' => array( 'number_of_attendees' => 3 ) ) );
	$result = $edit();
	\check( is_wp_error( $result ), 'partial compensation is rejected' );
	$check_state( 3, 'approved', 7, true, 'partial compensation reconciles the actual intermediate party' );
	foreach ( array( 'before_throw', 'after_throw' ) as $fault ) {
		$reset(); $handler->plans = array( array( $fault => true ) );
		$result = $edit();
		\check( is_wp_error( $result ) && 'hherm_storage_exception' === $result->get_error_code(), $fault . ' returns an actionable storage error' );
		$check_state( 2, 'approved', 8, true, $fault . ' leaves party and capacity restored' );
	}
	foreach ( array( 'declined', 'approved' ) as $decision ) {
		$initial = 'declined' === $decision ? 'pending' : 'waitlist'; $held = 'declined' === $decision;
		$reset( $initial, $held ); $handler->plans = array( array( 'result' => false ) );
		$result = $rest->review( new \WP_REST_Request( array( 'id' => 1, 'status' => $decision, 'approval_notes' => 'Welcome', 'decline_reason' => 'Unavailable' ) ) );
		\check( is_wp_error( $result ) && 0 === $email->sent, $decision . ' storage failure sends no decision email' );
		$check_state( 2, $initial, $held ? 8 : 10, $held, $decision . ' failure restores initial decision and places' );
		$reset( $initial, $held ); $handler->plans = array( array( 'drop' => array( 'reviewed_date' ) ), array( 'skip' => true ) );
		$result = $rest->review( new \WP_REST_Request( array( 'id' => 1, 'status' => $decision, 'approval_notes' => 'Welcome', 'decline_reason' => 'Unavailable' ) ) );
		\check( is_wp_error( $result ) && 0 === $email->sent, $decision . ' partial decision with failed compensation is explicit' );
		$check_state( 2, $decision, 'approved' === $decision ? 8 : 10, 'approved' === $decision, $decision . ' failed compensation preserves capacity for the actual stored decision' );
	}
	$reset(); $handler->plans = array( array( 'drop' => array( 'dietaryrequirements' ) ), array( 'force' => array( 'number_of_attendees' => 3 ) ) );
	$handler->on_write = static function () { $GLOBALS['fail_meta'] = true; };
	$result = $edit();
	\check( is_wp_error( $result ) && 'storage_reconciliation_failed' === $audit->latest_status( 1, 'capacity_error' ), 'storage and capacity reconciliation double fault is reported explicitly in the ledger' );
	$service = new Registration_Self_Service( $settings, $repository, $audit, $capacity, new Public_Page_Theme() );
	$self_submit = new \ReflectionMethod( $service, 'process_submission' ); $self_submit->setAccessible( true );
	$reset(); $handler->plans = array( array( 'drop' => array( 'phone' ) ), array( 'skip' => true ) );
	$_POST = array( 'hherm_manage_nonce' => 'valid', 'hherm_manage_action' => 'update', 'number_of_attendees' => 4, 'phone' => '123' );
	$result = $self_submit->invoke( $service, $db->item, array( 'id' => 10 ), 1, 'token' );
	\check( is_wp_error( $result ), 'self-service partial write and failed compensation is rejected' );
	$check_state( 4, 'pending', 6, true, 'self-service reconciles the actually persisted party after failed compensation' );
	$reset(); $handler->plans = array( array( 'drop' => array( 'reviewed_date' ) ), array( 'skip' => true ) );
	$_POST = array( 'hherm_manage_nonce' => 'valid', 'hherm_manage_action' => 'withdraw' );
	$result = $self_submit->invoke( $service, $db->item, array( 'id' => 10 ), 1, 'token' );
	\check( is_wp_error( $result ), 'withdrawal partial write and failed compensation is rejected' );
	$check_state( 2, 'declined', 10, false, 'failed withdrawal compensation keeps released places aligned with saved decline' );
	$checkin = new Checkin_Manager( $settings, $repository, $audit, $capacity, new Public_Page_Theme() );
	$checkin_submit = new \ReflectionMethod( $checkin, 'process_public_submission' ); $checkin_submit->setAccessible( true );
	$reset(); $handler->plans = array( array( 'drop' => array( 'checked_in_party_size' ) ), array( 'skip' => true ) );
	$_POST = array( 'hherm_public_nonce' => 'valid', 'identity' => 'guest@example.test', 'checkin_step' => 'confirm', 'party_size' => '1' );
	$result = $checkin_submit->invoke( $checkin, 10, 'token' );
	\check( false !== strpos( $result, 'could not save' ), 'partial check-in storage failure is reported' );
	$again = $checkin_submit->invoke( $checkin, 10, 'token' );
	\check( false === strpos( $again, 'Your attendance has been recorded' ), 'a retry cannot report success for an incomplete persisted check-in' );
	$reset( 'waitlist', false );
	$result = $rest->review( new \WP_REST_Request( array( 'id' => 1, 'status' => 'approved', 'approval_notes' => 'Welcome' ) ) );
	\check( ! is_wp_error( $result ) && 1 === $email->sent, 'successful review emails only after the event capacity lock is released' );
	$check_state( 2, 'approved', 8, true, 'successful approval saves the decision and matching capacity' );
	// The primary CCT hook itself can outlive the lease. Later compensation must
	// not overwrite another owner, even though that in-flight hook cannot be fenced.
	foreach ( array( 'edit', 'review', 'self-service', 'withdraw', 'check-in' ) as $operation ) {
		$reset( 'review' === $operation ? 'pending' : 'approved' );
		$handler->plans = array( array( 'result' => false ) );
		$handler->on_write = static function () { $GLOBALS['options']['hherm_review_lock_1'] = array( 'token' => 'replacement', 'expires_at' => time() + 300 ); };
		if ( 'edit' === $operation ) $result = $edit();
		elseif ( 'review' === $operation ) $result = $rest->review( new \WP_REST_Request( array( 'id' => 1, 'status' => 'declined', 'decline_reason' => 'Unavailable' ) ) );
		elseif ( 'check-in' === $operation ) {
			$_POST = array( 'hherm_public_nonce' => 'valid', 'identity' => 'guest@example.test', 'checkin_step' => 'confirm', 'party_size' => '1' );
			$result = $checkin_submit->invoke( $checkin, 10, 'token' );
		} else {
			$_POST = array( 'hherm_manage_nonce' => 'valid', 'hherm_manage_action' => 'withdraw' === $operation ? 'withdraw' : 'update', 'number_of_attendees' => '4' );
			$result = $self_submit->invoke( $service, $db->item, array( 'id' => 10 ), 1, 'token' );
		}
		$error = 'check-in' === $operation ? false === strpos( $result, 'Your attendance has been recorded' ) : is_wp_error( $result );
		\check( $error && 1 === $handler->calls && 0 === $email->sent && 'replacement' === $GLOBALS['options']['hherm_review_lock_1']['token'], $operation . ' fails visibly without stale storage compensation or mail after its lease is replaced inside the CCT hook' );
	}
}
