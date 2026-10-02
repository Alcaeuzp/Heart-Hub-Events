<?php
/** Focused public form regression checks using isolated WordPress/JetEngine doubles. */
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error {
		private $code;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ) : ''; }
	function sanitize_textarea_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
	function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
	function wp_verify_nonce( $nonce, $action ) { return hash_equals( 'valid:' . $action, $nonce ); }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
	function add_option( $key, $value, ...$args ) { if ( isset( $GLOBALS['options'][ $key ] ) ) return false; $GLOBALS['options'][ $key ] = $value; return true; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $key ] = $value; return true; }
	function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'] ?? array() ); }
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish' ); }
	function get_the_title( $id ) { return 'Example event'; }
	function get_current_user_id() { return 0; }
	function current_time( $format ) { return gmdate( 'Y-m-d H:i:s' ); }
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return gmdate( $format, $timestamp ); }
	function check( $condition, $label ) { if ( ! $condition ) throw new Exception( $label ); echo "PASS $label\n"; }
	require __DIR__ . '/support/option-lock.php';
}
namespace HeartHub\EventRegistrations {
	class Settings {
		public function get( $key, $default = '' ) { return array( 'attendee_count_field' => 'party', 'event_remaining_capacity' => 'remaining', 'events_cpt' => 'events' )[ $key ] ?? $default; }
	}
	class Audit_Log {
		public $entries = array();
		public function latest_entry( $id, $type ) { return $this->entries[ $id ][ $type ] ?? array(); }
		public function latest_entry_checked( $id, $type ) { return $this->latest_entry( $id, $type ); }
		public function latest_status( $id, $type ) { return $this->latest_entry( $id, $type )['status'] ?? ''; }
		public function write( $id, $type, $status, $details = array() ) { $this->entries[ $id ][ $type ] = compact( 'status', 'details' ); return true; }
	}
	class CCT_Repository {
		public $item;
		public $fail = false;
		public $feedback = array();
		public function get( $id ) { return $this->item; }
		public function update_registration( $id, $fields ) { return $this->fail ? new \WP_Error( 'failed' ) : ( $this->item = array_replace( $this->item, $fields ) ); }
		public function update_feedback( $id, $feedback, $score ) { $this->feedback = array( $id, $feedback, $score ); return $this->item; }
	}
	class Public_Page_Theme {}
	class Email_Service {}
	require dirname( __DIR__ ) . '/includes/class-capacity-manager.php';
	require dirname( __DIR__ ) . '/includes/class-registration-self-service.php';
	require dirname( __DIR__ ) . '/includes/class-post-event-feedback.php';
	$settings = new Settings();
	$audit = new Audit_Log();
	$repository = new CCT_Repository();
	$capacity = new Capacity_Manager( $settings, $audit );
	$service = new Registration_Self_Service( $settings, $repository, $audit, $capacity, new Public_Page_Theme() );
	$submit = new \ReflectionMethod( $service, 'process_submission' );
	$resolve = new \ReflectionMethod( $service, 'resolve' );
	if ( PHP_VERSION_ID < 80100 ) { $submit->setAccessible( true ); $resolve->setAccessible( true ); }
	$token = str_repeat( 'a', 48 );
	$reset = function () use ( $repository, $audit, $service, $token ) {
		$GLOBALS['meta'] = array( 'remaining' => 8, 'event_capacity' => 10, 'start_date' => time() + DAY_IN_SECONDS );
		$GLOBALS['options'] = array();
		$repository->fail = false;
		$repository->item = array( '_ID' => 1, 'event_id' => 10, 'party' => 2, 'registration_status' => 'approved', 'first_name' => 'Alex', 'last_name' => 'Guest', 'email' => 'guest@example.test', 'phone' => '123', 'organisation' => 'Community', 'reason_for_attending' => 'Original reason' );
		$audit->entries = array();
		$audit->write( 1, 'capacity_reservation', 'reserved', array( 'event_id' => 10, 'attendees' => 2 ) );
		$_POST = array( 'hherm_manage_nonce' => 'valid:' . $service->nonce_action( 1, $token ), 'hherm_manage_action' => 'update' );
	};
	$send = function () use ( $submit, $service, $repository, $token ) { return $submit->invoke( $service, $repository->item, array( 'id' => 10 ), 1, $token ); };

	$reset();
	$_POST += array( 'first_name' => '<b>Sam</b>', 'last_name' => addslashes( "O'Neil" ), 'email' => 'sam@example.test', 'number_of_attendees' => '4', 'reason_for_attending' => addslashes( "Guest's reason\nSecond line" ), 'registration_status' => 'declined', 'event_id' => 99 );
	$result = $send();
	\check( ! \is_wp_error( $result ) && 'pending' === $result['notice'] && 'pending' === $repository->item['registration_status'], 'self-service update still returns the registration to review' );
	\check( 'Sam' === $repository->item['first_name'] && "O'Neil" === $repository->item['last_name'] && "Guest's reason\nSecond line" === $repository->item['reason_for_attending'], 'self-service fields are unslashed and sanitized without losing apostrophes or textarea newlines' );
	\check( 4 === $repository->item['party'] && 6 === $GLOBALS['meta']['remaining'] && 10 === $repository->item['event_id'], 'self-service uses the configured party field, adjusts capacity, and ignores unapproved fields' );

	$reset(); $send();
	\check( 'Alex' === $repository->item['first_name'] && 2 === $repository->item['party'] && 8 === $GLOBALS['meta']['remaining'], 'omitted self-service fields retain their existing values' );
	foreach ( array( 'missing', 'wrong-token', 'invalid-party', 'decimal-party', 'too-large', 'over-capacity', 'invalid-email' ) as $case ) {
		$reset();
		if ( 'missing' === $case ) unset( $_POST['hherm_manage_nonce'] );
		if ( 'wrong-token' === $case ) $_POST['hherm_manage_nonce'] = 'valid:' . $service->nonce_action( 1, str_repeat( 'b', 48 ) );
		if ( 'invalid-party' === $case ) $_POST['number_of_attendees'] = array( '4' );
		if ( 'decimal-party' === $case ) $_POST['number_of_attendees'] = '1.5';
		if ( 'too-large' === $case ) $_POST['number_of_attendees'] = '1001';
		if ( 'over-capacity' === $case ) $_POST['number_of_attendees'] = '11';
		if ( 'invalid-email' === $case ) $_POST['email'] = 'invalid';
		$before = $repository->item;
		\check( \is_wp_error( $send() ) && $before === $repository->item && 8 === $GLOBALS['meta']['remaining'], 'self-service rejects ' . $case . ' without changing the record or capacity' );
	}
	$reset(); $_POST['number_of_attendees'] = '0'; $send();
	\check( 1 === $repository->item['party'] && 9 === $GLOBALS['meta']['remaining'], 'self-service retains the existing minimum-one attendee rule' );
	$reset(); $_POST['number_of_attendees'] = '4'; $repository->fail = true;
	\check( \is_wp_error( $send() ) && 8 === $GLOBALS['meta']['remaining'] && 2 === $capacity->reservation_state( 1 )['attendees'], 'self-service restores reserved capacity when persistence fails' );
	$reset(); $_POST['hherm_manage_action'] = 'withdraw'; $result = $send();
	\check( 'withdrawn' === $result['notice'] && 'declined' === $repository->item['registration_status'] && 10 === $GLOBALS['meta']['remaining'], 'nonce-verified withdrawal still releases the reservation' );

	$reset();
	$GLOBALS['options']['hherm_registration_manage_1'] = array( 'token_hash' => hash( 'sha256', $token ), 'event_id' => 10, 'expires_at' => time() + 3600 );
	$_POST = array();
	\check( ! \is_wp_error( $resolve->invoke( $service, 1, $token ) ), 'valid bearer link opens without requiring a form nonce' );
	\check( \is_wp_error( $resolve->invoke( $service, 1, str_repeat( 'b', 48 ) ) ), 'incorrect bearer token is rejected' );
	$GLOBALS['options']['hherm_registration_manage_1']['expires_at'] = time() - 1;
	\check( \is_wp_error( $resolve->invoke( $service, 1, $token ) ), 'expired bearer link is rejected' );

	$feedback = new Post_Event_Feedback( $settings, $repository, $audit, new Email_Service(), new Public_Page_Theme() );
	$feedback_submit = new \ReflectionMethod( $feedback, 'submit' );
	$feedback_nonce = new \ReflectionMethod( $feedback, 'nonce_action' );
	if ( PHP_VERSION_ID < 80100 ) { $feedback_submit->setAccessible( true ); $feedback_nonce->setAccessible( true ); }
	$_POST = array( 'feedback' => addslashes( "Guest's feedback" ), 'feedback_score' => '5', 'hherm_feedback_nonce' => 'invalid' );
	\check( \is_wp_error( $feedback_submit->invoke( $feedback, $repository->item, $token ) ) && ! $repository->feedback, 'feedback with an invalid nonce makes no write' );
	$_POST['hherm_feedback_nonce'] = 'valid:' . $feedback_nonce->invoke( $feedback, 1, $token );
	$feedback_submit->invoke( $feedback, $repository->item, $token );
	\check( array( 1, "Guest's feedback", 5 ) === $repository->feedback, 'nonce-verified feedback retains its rating and unslashed text' );
}
