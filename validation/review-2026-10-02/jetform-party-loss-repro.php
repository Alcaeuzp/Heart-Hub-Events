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
	require dirname( __DIR__, 2 ) . '/tests/support/option-lock.php';
}
namespace Jet_Engine\Modules\Custom_Content_Types {
	class Module { public static function instance() { return (object) array( 'manager' => new class { public function get_content_types( $slug ) { return $GLOBALS['cct_type']; } } ); } }
}
namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return array( 'attendee_count_field' => 'number_of_attendees', 'event_remaining_capacity' => 'remaining', 'events_cpt' => 'events' )[ $key ] ?? $default; } }
	class Event_Public_Display { public static function normalise_date_status( $a, $b ) { return 'confirmed'; } public static function normalise_registration_type($value){ return $value ?: 'website_registration';} }
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
	require dirname( __DIR__, 2 ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__, 2 ) . '/includes/class-cct-repository.php';
	require dirname( __DIR__, 2 ) . '/includes/class-capacity-manager.php';
	require dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php';
	require dirname( __DIR__, 2 ) . '/includes/class-registration-self-service.php';
	require dirname( __DIR__, 2 ) . '/includes/class-checkin-manager.php';
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
 require dirname(__DIR__, 2) . '/includes/class-jetform-integration.php';
 class Review_Context { public $values = []; public function update_request($value,$key){$this->values[$key]=$value;} }
 function jet_fb_context(){return $GLOBALS['review_context'];}
 function esc_html($value){return $value;}
 $GLOBALS['options']=[];
 $GLOBALS['meta']=['event_capacity'=>10,'remaining'=>10,'event_schedule_status'=>'scheduled','registration_type'=>'website_registration','registration_enabled'=>'true','start_date'=>time()+3600];
 $GLOBALS['review_context']=new Review_Context();
 $integration=new JetForm_Integration($repository,$settings,$audit,$capacity);
 $integration->normalise_request(['event_id'=>10,'number_of_attendees'=>4,'first_name'=>'Alex','email'=>'alex@example.test'],new class{public function get_form_id(){return 2774;}});
 $db->item=['_ID'=>1,'event_id'=>10,'registration_status'=>'pending','first_name'=>'Alex','email'=>'alex@example.test'];
 $integration->after_created($db->item,1,$handler);
 echo json_encode(['submitted_party'=>4,'normalized_context_party'=>$GLOBALS['review_context']->values['number_of_attendees'],'persisted_party'=>$db->item['number_of_attendees']??null,'reserved_party'=>$capacity->reservation_state(1)['attendees'],'remaining'=>$GLOBALS['meta']['remaining']]).PHP_EOL;
}
namespace {
 function jet_fb_context(){return $GLOBALS['review_context'];}
 function esc_html($value){return $value;}
}
