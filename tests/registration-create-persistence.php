<?php
/** Verify real registration persistence/capacity classes against incomplete CCT mappings. */
namespace {
	define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error {
		private $code; private $message;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function sanitize_key( $value ) { return strtolower( (string) $value ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
	function sanitize_email( $value ) { return trim( (string) $value ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function esc_html( $value ) { return (string) $value; }
	function get_current_user_id() { return 0; }
	function current_time( $format ) { return '2026-10-02 12:00:00'; }
	function get_post( $id ) { return (object) array( 'post_type' => 'events', 'post_status' => 'publish', 'post_content' => '' ); }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { if ( $GLOBALS['fail_meta'] ?? false ) return false; $GLOBALS['meta'][ $key ] = $value; return true; }
	function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'] ); }
	function get_the_title( $id ) { return 'Registration test'; }
	function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	function jet_fb_context() { return $GLOBALS['context']; }
	function check( $condition, $label ) { if ( ! $condition ) throw new \RuntimeException( $label ); echo 'PASS ' . $label . PHP_EOL; }
	require __DIR__ . '/support/option-lock.php';
}
namespace Jet_Engine\Modules\Custom_Content_Types {
	class Module { public static function instance() { return $GLOBALS['module']; } }
}
namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return array( 'attendee_count_field' => 'party', 'event_remaining_capacity' => 'remaining', 'events_cpt' => 'events' )[ $key ] ?? $default; } }
	class Audit_Log {
		public $entries = array(); public $fail_reservation = false;
		public function latest_entry_checked( $id, $type ) { return $this->entries[ $id ][ $type ] ?? array(); }
		public function write( $id, $type, $status, $details = array() ) { if ( $this->fail_reservation && 'capacity_reservation' === $type ) return false; $this->entries[ $id ][ $type ] = compact( 'status', 'details' ); return true; }
	}
	class Email_Automation {
		public $notifications = array();
		public function send_internal_notification( $item, $event, $type, $url ) { $this->notifications[] = $item; }
		public function send_interest_received( $item, $event ) { $this->notifications[] = $item; }
	}
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-event-public-display.php';
	require dirname( __DIR__ ) . '/includes/class-cct-repository.php';
	require dirname( __DIR__ ) . '/includes/class-capacity-manager.php';
	require dirname( __DIR__ ) . '/includes/class-jetform-integration.php';
	$db = new class {
		public $items = array();
		public function set_format_flag( $flag ) {}
		public function get_item( $id ) { return $this->items[ $id ] ?? null; }
		public function query( ...$args ) { return array_values( $this->items ); }
	};
	$handler = new class( $db ) {
		private $db; private $next_id = 1; public $mode = 'save';
		public function __construct( $db ) { $this->db = $db; }
		public function update_item( $fields ) {
			$created = ! isset( $fields['_ID'] ); $id = $fields['_ID'] ?? $this->next_id++; $fields['_ID'] = $id;
			// Model JetEngine accepting only configured CCT columns. user_id and
			// registration_type are optional metadata, absent from this valid schema.
			$fields = array_intersect_key( $fields, array_flip( array( '_ID', 'event_id', 'party', 'registration_date', 'registration_status', 'first_name', 'last_name', 'email', 'phone', 'organisation', 'reason_for_attending', 'attended_before', 'impacted_by_trauma', 'dietaryrequirements', 'please_let_us_know' ) ) );
			if ( in_array( $this->mode, array( 'drop_email', 'delete_failure' ), true ) ) unset( $fields['email'] );
			if ( 'truncate_name' === $this->mode && isset( $fields['first_name'] ) ) $fields['first_name'] = substr( $fields['first_name'], 0, 2 );
			if ( 'drop_dietary' === $this->mode ) unset( $fields['dietaryrequirements'], $fields['please_let_us_know'] );
			$this->db->items[ $id ] = array_replace( $this->db->items[ $id ] ?? array(), $fields );
			if ( $created ) $GLOBALS['integration']->after_created( $fields, $id, $this );
			return $id;
		}
		public function raw_delete_item( $id ) { if ( 'delete_failure' !== $this->mode ) unset( $this->db->items[ $id ] ); }
	};
	$type = new class( $db, $handler ) {
		public $db; private $handler;
		public function __construct( $db, $handler ) { $this->db = $db; $this->handler = $handler; }
		public function get_item_handler() { return $this->handler; }
	};
	$GLOBALS['module'] = (object) array( 'manager' => new class( $type ) { private $type; public function __construct( $type ) { $this->type = $type; } public function get_content_types( $slug ) { return $this->type; } } );
	$settings = new Settings(); $audit = new Audit_Log(); $repository = new CCT_Repository( $settings ); $capacity = new Capacity_Manager( $settings, $audit ); $email = new Email_Automation();
	$integration = new JetForm_Integration( $repository, $settings, $audit, $capacity, $email ); $GLOBALS['integration'] = $integration;
	$request = array( 'event_id' => 42, 'party' => '4', 'first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'alex@example.test', 'phone' => '0400000000', 'organisation' => 'Community', 'reason_for_attending' => 'Meet others', 'attended_before' => '0', 'impacted_by_trauma' => 'yes', 'dietaryrequirements' => 'yes', 'please_let_us_know' => 'Nut allergy' );
	$reset = function () use ( $integration, $db, $handler, $audit, $email ) {
		$integration->cleanup_pending_submission();
		$GLOBALS['options'] = array(); $GLOBALS['fail_meta'] = false; $GLOBALS['meta'] = array( 'event_capacity' => 10, 'remaining' => 10, 'registration_enabled' => 'true', 'registration_type' => 'website_registration', 'event_schedule_status' => 'scheduled', 'start_date' => time() + 3600 );
		$GLOBALS['context'] = new class { public $fields = array(); public function update_request( $value, $key ) { $this->fields[ $key ] = $value; } };
		$db->items = array(); $handler->mode = 'save'; $audit->entries = array(); $audit->fail_reservation = false; $email->notifications = array();
	};
	$submit = function () use ( $integration, $handler, $request ) {
		$integration->normalise_request( $request, new class { public function get_form_id() { return 2774; } } );
		// The CCT action omitted party, contact and dietary field mappings.
		return $handler->update_item( array( 'event_id' => 42, 'registration_status' => 'pending' ) );
	};
	$reset(); $id = $submit(); $saved = $repository->get( $id );
	\check( 4 === $saved['party'] && 4 === $capacity->reservation_state( $id )['attendees'] && 6 === $GLOBALS['meta']['remaining'], 'a four-person submission survives a missing CCT party mapping and reserves four places' );
	foreach ( array_diff( array_keys( $request ), array( 'event_id', 'party' ) ) as $key ) \check( $request[ $key ] === $saved[ $key ], 'submitted ' . $key . ' survives an omitted insert mapping' );
	\check( 1 === count( $email->notifications ) && 'alex@example.test' === $email->notifications[0]['email'] && array() === $GLOBALS['options'], 'notification uses verified details and capacity locks are released' );
	\check( ! array_key_exists( 'user_id', $saved ) && array_key_exists( 'user_id', $GLOBALS['context']->fields ), 'optional JetForm user metadata is supplied without requiring an undocumented CCT column' );
	foreach ( array( 'drop_email', 'truncate_name', 'drop_dietary' ) as $fault ) {
		$reset(); $handler->mode = $fault; $rejected = false;
		try { $submit(); } catch ( \RuntimeException $error ) { $rejected = false !== strpos( $error->getMessage(), 'incomplete application was removed' ); }
		\check( $rejected && array() === $db->items && 10 === $GLOBALS['meta']['remaining'] && array() === $email->notifications && array() === $GLOBALS['options'], $fault . ' fails visibly, removes the incomplete row, sends nothing and consumes no places' );
	}
	$reset(); $handler->mode = 'drop_email'; try { $submit(); } catch ( \RuntimeException $error ) {} $handler->mode = 'save'; $id = $submit();
	\check( 1 === count( $db->items ) && 6 === $GLOBALS['meta']['remaining'] && 1 === count( $email->notifications ), 'retry after correcting storage creates one complete registration and reserves once' );
	$reset(); $handler->mode = 'delete_failure'; $rejected = false;
	try { $submit(); } catch ( \RuntimeException $error ) { $rejected = false !== strpos( $error->getMessage(), 'contact the event organiser before submitting again' ); }
	\check( $rejected && 1 === count( $db->items ) && 10 === $GLOBALS['meta']['remaining'] && array() === $email->notifications && array() === $GLOBALS['options'], 'failed cleanup requires organiser action and never sends success or reserves places' );
	$reset(); $audit->fail_reservation = true; $rejected = false;
	try { $submit(); } catch ( \RuntimeException $error ) { $rejected = true; }
	\check( $rejected && array() === $db->items && 10 === $GLOBALS['meta']['remaining'] && array() === $email->notifications && array() === $GLOBALS['options'], 'failed reservation ledger write restores places and removes the unsuccessful registration' );
	$reset(); $integration->normalise_request( $request, new class { public function get_form_id() { return 2774; } } ); $integration->cleanup_pending_submission();
	\check( array() === $GLOBALS['options'] && 10 === $GLOBALS['meta']['remaining'], 'a later aborted JetForm action releases the unconsumed event lock' );
	$interest_request = array( 'event_id' => 42, 'first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'interest@example.test', 'phone' => '0400000000', 'reason_for_attending' => 'Meet others' );
	$reset(); $GLOBALS['meta']['registration_open'] = time() + 3600;
	$integration->normalise_interest_request( $interest_request, new class {} );
	$id = $handler->update_item( array( 'event_id' => 42, 'registration_status' => 'interest' ) ); $saved = $repository->get( $id );
	\check( 'interest' === $saved['registration_status'] && 1 === $saved['party'] && 'interest@example.test' === $saved['email'] && $interest_request['reason_for_attending'] === $saved['reason_for_attending'], 'JetForm interest preserves customer details in a schema without optional metadata columns' );
	\check( ! array_key_exists( 'user_id', $saved ) && ! array_key_exists( 'registration_type', $saved ) && 'expression_of_interest' === $GLOBALS['context']->fields['registration_type'], 'optional interest application type remains available to configured mappings without becoming mandatory' );
	\check( 1 === count( $email->notifications ) && 10 === $GLOBALS['meta']['remaining'] && array() === $GLOBALS['options'], 'verified JetForm interest notifies once, consumes no places and releases its identity lock' );
	$reset(); $GLOBALS['meta']['registration_open'] = time() + 3600; $handler->mode = 'drop_email'; $rejected = false;
	$integration->normalise_interest_request( $interest_request, new class {} );
	try { $handler->update_item( array( 'event_id' => 42, 'registration_status' => 'interest' ) ); } catch ( \RuntimeException $error ) { $rejected = true; }
	\check( $rejected && array() === $db->items && 10 === $GLOBALS['meta']['remaining'] && array() === $email->notifications && array() === $GLOBALS['options'], 'JetForm interest still rejects a missing customer email when optional metadata is absent' );
	$reset();
	$plugin_interest = $interest_request + array( 'user_id' => 7, 'registration_type' => 'expression_of_interest', 'registration_status' => 'interest', 'registration_date' => current_time( 'mysql' ), 'party' => 1 );
	$id = $repository->create_registration( $plugin_interest );
	\check( ! is_wp_error( $id ) && 1 === count( $db->items ) && $plugin_interest['email'] === $db->items[ $id ]['email'] && ! array_key_exists( 'registration_type', $db->items[ $id ] ), 'plugin-owned interest create verifies customer details while allowing the documented optional metadata columns' );
	\check( array() === $email->notifications && 10 === $GLOBALS['meta']['remaining'], 'plugin-owned insert defers effects until its caller dispatches the verified lifecycle' );
}
