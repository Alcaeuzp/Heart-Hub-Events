<?php
namespace {
	require __DIR__ . '/support/option-lock.php';
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error { public function __construct( ...$args ) {} }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_email( $value ) { return trim( (string) $value ); }
	function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function absint( $value ) { return abs( (int) $value ); }
	function current_time( $type ) { return '2026-09-24 10:00:00'; }
	function add_action( ...$args ) {}
	function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return $default; } }
	class Public_Page_Theme {}
	class Audit_Log { public $entries = array(); public function write( ...$args ) { $this->entries[] = $args; return true; } }
	class Capacity_Manager {
		public $adjustments = array();
		public function with_event_lock( $event_id, $callback ) { return $callback(); }
		public function reservation_state( $id ) { return array( 'reserved' => true, 'attendees' => 2 ); }
		public function partially_configured() { return false; }
		public function enabled() { return true; }
		public function attendee_key() { return 'number_of_attendees'; }
		public function attendees_from( array $item ) { return max( 1, (int) ( $item['number_of_attendees'] ?? 1 ) ); }
		public function adjust_reserved( $id, $event_id, $old, $new ) { $this->adjustments[] = array( $old, $new ); return true; }
	}
	class CCT_Repository {
		public $writes = array();
		public function update_registration( int $id, array $fields ) { $this->writes[] = $fields; return $fields; }
	}
	require dirname( __DIR__ ) . '/includes/class-registration-self-service.php';

	$repository = new CCT_Repository();
	$capacity = new Capacity_Manager();
	$audit = new Audit_Log();
	$service = new Registration_Self_Service( new Settings(), $repository, $audit, $capacity, new Public_Page_Theme() );
	$update = new \ReflectionMethod( $service, 'update_for_review' );
	$update->setAccessible( true );
	$item = array( '_ID' => 5, 'event_id' => 10, 'registration_status' => 'approved', 'first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'Alex@Example.test', 'phone' => '', 'organisation' => 'Smith &amp; Co', 'reason_for_attending' => "Line one\nLine two", 'number_of_attendees' => 2 );
	// What the browser posts back after the form displays those stored values unchanged.
	$as_displayed = array( 'first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'Alex@Example.test', 'phone' => '', 'organisation' => 'Smith & Co', 'reason_for_attending' => "Line one\r\nLine two", 'number_of_attendees' => '2' );
	$run = function ( array $changes ) use ( $update, $service, $item ) {
		$token = Mutation_Lock::acquire( 'hherm_review_lock_5' );
		try { return $update->invoke( $service, $item, 5, 10, $changes, $token ); }
		finally { Mutation_Lock::release( 'hherm_review_lock_5', $token ); }
	};

	$result = $run( $as_displayed );
	\check( array( 'notice' => 'unchanged' ) === $result && array() === $repository->writes && array() === $capacity->adjustments && array() === $audit->entries, 'unchanged resubmission keeps the approved place without writes' );
	$result = $run( array( 'email' => 'alex@example.test' ) + $as_displayed );
	\check( 'unchanged' === $result['notice'] && array() === $repository->writes, 'email letter-case alone is not treated as a change' );
	$result = $run( array() );
	\check( 'unchanged' === $result['notice'], 'an empty submission changes nothing' );

	$result = $run( array( 'phone' => '0400 000 000' ) + $as_displayed );
	\check( 'pending' === $result['notice'] && 'pending' === $repository->writes[0]['registration_status'] && array() === $capacity->adjustments, 'a detail change is still sent back for review' );
	$repository->writes = array();
	$result = $run( array( 'number_of_attendees' => '3' ) + $as_displayed );
	\check( 'pending' === $result['notice'] && array( array( 2, 3 ) ) === $capacity->adjustments && 3 === $repository->writes[0]['number_of_attendees'], 'a party-size change still adjusts capacity and goes to review' );
	$repository->writes = array();
	$result = $run( array( 'reason_for_attending' => "Line one\r\nLine two changed" ) + $as_displayed );
	\check( 'pending' === $result['notice'] && 1 === count( $repository->writes ), 'a reason change is still detected' );
	$result = $run( array( 'email' => 'not-an-email' ) + $as_displayed );
	\check( is_wp_error( $result ), 'an invalid email is still rejected' );
}
