<?php
/**
 * Customer persistence regressions using the actual repository/controller.
 * Storage/query doubles model partial writes, errors, SQL scalar types and filters.
 */
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error {
		private $code;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	class WP_REST_Request extends ArrayObject {
		public function has_param( $key ) { return $this->offsetExists( $key ); }
		public function get_param( $key ) { return $this[ $key ] ?? null; }
	}
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
	function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_parse_args( $args, $defaults ) { return array_replace( $defaults, $args ); }
	function get_option( $key ) { return $GLOBALS['options'][ $key ] ?? false; }
	function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
	function add_option( $key, $value, ...$rest ) {
		if ( isset( $GLOBALS['options'][ $key ] ) ) return false;
		$GLOBALS['options'][ $key ] = $value;
		return true;
	}
	function get_current_user_id() { return 1; }
	function current_time( $format ) { return '2026-09-27 12:00:00'; }
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => '' ); }
	function get_post_meta( $id, $key, $single = true ) { return ''; }
	function get_the_title( $id ) { return 'Audit event'; }
	function get_userdata( $id ) { return (object) array( 'display_name' => 'Reviewer' ); }
	function get_posts( $args ) { return array(); }
	function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	function rest_ensure_response( $value ) { return $value; }
	function audit_check( $condition, $label ) {
		if ( ! $condition ) throw new Exception( $label );
		echo $label . "\n";
	}
	require __DIR__ . '/support/option-lock.php';
}
namespace Jet_Engine\Modules\Custom_Content_Types {
	class Module {
		public $manager;
		public static function instance() { return $GLOBALS['audit_module']; }
	}
}
namespace HeartHub\EventRegistrations {
	class Settings {
		public function get( $key, $default = '' ) { return array( 'events_cpt' => 'events', 'attendee_count_field' => 'number_of_attendees' )[ $key ] ?? $default; }
	}
	class Audit_Log {
		public $entries = array();
		public function write( ...$args ) { $this->entries[] = $args; }
	}
	class Email_Automation { public function send_decision( $item, $event, $status ) { return array( 'status' => 'logged' ); } }
	class Capacity_Manager {
		public function with_event_lock( $event_id, $callback ) { return $callback(); }
		public function attendee_key() { return 'number_of_attendees'; }
		public function enabled() { return false; }
		public function partially_configured() { return false; }
		public function attendees_from( $item ) { return max( 1, (int) ( $item['number_of_attendees'] ?? 1 ) ); }
		public function reservation_state( $id ) { return array( 'reserved' => false, 'attendees' => 0 ); }
	}
	class Event_Public_Display {
		public static function normalise_date_status( $current, $legacy ) { return 'confirmed'; }
	}
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-cct-repository.php';
	require dirname( __DIR__ ) . '/includes/class-rest-controller.php';
	$db = new class {
		public $items = array();
		public $query_fault = '';
		public $read_fault = '';
		public function set_format_flag( $flag ) {}
		public function get_item( $id ) { return $this->read_fault ? new \WP_Error( 'temporary_read_error' ) : ( $this->items[ $id ] ?? null ); }
		public function query( $conditions, $limit, $offset, $order = array(), ...$rest ) {
			if ( 'error' === $this->query_fault ) return new \WP_Error( 'temporary_query_error' );
			if ( 'false' === $this->query_fault ) return false;
			if ( 'throw' === $this->query_fault ) throw new \RuntimeException( 'Private database connection information' );
			$rows = array_values( array_filter( $this->items, static function ( $item ) use ( $conditions ) {
				foreach ( $conditions as $condition ) {
					if ( (string) ( $item[ $condition['field'] ] ?? '' ) !== (string) $condition['value'] ) return false;
				}
				return true;
			} ) );
			return $limit ? array_slice( $rows, $offset, $limit ) : $rows;
		}
	};
	$handler = new class( $db ) {
		private $db;
		public $mode = 'save';
		public function __construct( $db ) { $this->db = $db; }
		public function update_item( $fields ) {
			if ( 'throw_before' === $this->mode ) throw new \RuntimeException( 'private database details' );
			if ( 'false' === $this->mode ) return false;
			if ( 'wp_error' === $this->mode ) return new \WP_Error( 'storage_fault' );
			$id = $fields['_ID'] ?? count( $this->db->items ) + 1;
			if ( 'omit_dietary' === $this->mode ) unset( $fields['dietaryrequirements'], $fields['please_let_us_know'] );
			if ( 'truncate' === $this->mode && isset( $fields['first_name'] ) ) $fields['first_name'] = substr( $fields['first_name'], 0, 5 );
			if ( 'rollback_failure' === $this->mode ) {
				unset( $fields['dietaryrequirements'] );
				if ( 'Alex' === ( $fields['first_name'] ?? '' ) ) unset( $fields['first_name'] );
			}
			if ( 'sql_strings' === $this->mode ) $fields = array_map( 'strval', $fields );
			$this->db->items[ $id ] = array_replace( $this->db->items[ $id ] ?? array( '_ID' => $id ), $fields );
			if ( 'throw_after' === $this->mode ) throw new \RuntimeException( 'private hook details' );
			if ( 'wp_error_partial' === $this->mode ) return new \WP_Error( 'storage_fault' );
			return $id;
		}
	};
	$type = new class( $db, $handler ) {
		public $db;
		private $handler;
		public function __construct( $db, $handler ) { $this->db = $db; $this->handler = $handler; }
		public function prepare_query_args( $args ) { return $args; }
		public function get_item_handler() { return $this->handler; }
	};
	$module = new \Jet_Engine\Modules\Custom_Content_Types\Module();
	$module->manager = new class( $type ) {
		private $type;
		public function __construct( $type ) { $this->type = $type; }
		public function get_content_types( $slug ) { return $this->type; }
	};
	$GLOBALS['audit_module'] = $module;
	$settings = new Settings();
	$repository = new CCT_Repository( $settings );
	$audit = new Audit_Log();
	$controller = new REST_Controller( $repository, new Email_Automation(), $audit, $settings, new Capacity_Manager() );
	$db->items[1] = array( '_ID' => 1, 'event_id' => 10, 'email' => 'alex@example.test', 'first_name' => 'Alex', 'registration_status' => 'pending', 'number_of_attendees' => 2, 'dietaryrequirements' => 'no', 'please_let_us_know' => '' );
	$result = $repository->update_registration( 1, array( 'phone' => '0400000000' ) );
	\audit_check( '0400000000' === $result['phone'] && 'Alex' === $result['first_name'], 'PASS: ordinary repository update persists changed fields and retains unrelated fields' );
	$handler->mode = 'false';
	\audit_check( \is_wp_error( $repository->update_registration( 1, array( 'phone' => 'new' ) ) ), 'PASS: false storage result is reported as a failure' );
	$handler->mode = 'wp_error';
	$result = $repository->update_registration( 1, array( 'phone' => 'new' ) );
	\audit_check( \is_wp_error( $result ) && 'storage_fault' === $result->get_error_code() && '0400000000' === $db->items[1]['phone'], 'PASS: WP_Error storage result is preserved and never reported as success' );
	$handler->mode = 'omit_dietary';
	$result = $controller->edit( new \WP_REST_Request( array( 'id' => 1, 'number_of_attendees' => 4, 'first_name' => 'Alicia', 'dietaryrequirements' => 'yes', 'please_let_us_know' => 'Gluten free' ) ) );
	\audit_check( \is_wp_error( $result ) && 2 === $db->items[1]['number_of_attendees'] && 'Alex' === $db->items[1]['first_name'] && 'no' === $db->items[1]['dietaryrequirements'] && array() === $audit->entries, 'PASS: partial dietary save restores other touched fields and never records a successful edit' );
	$handler->mode = 'wp_error_partial';
	$result = $repository->update_registration( 1, array( 'phone' => 'new' ) );
	\audit_check( \is_wp_error( $result ) && '0400000000' === $db->items[1]['phone'], 'PASS: even an error returned after writing is compensated and verified' );
	foreach ( array( 'throw_before', 'throw_after' ) as $fault ) {
		$handler->mode = $fault;
		$result = $repository->update_registration( 1, array( 'phone' => 'new' ) );
		\audit_check( \is_wp_error( $result ) && 'hherm_storage_exception' === $result->get_error_code() && '0400000000' === $db->items[1]['phone'], 'PASS: ' . $fault . ' is reported safely with the original contact value retained' );
	}
	$handler->mode = 'truncate';
	$result = $repository->update_registration( 1, array( 'first_name' => 'Longer Name' ) );
	\audit_check( \is_wp_error( $result ) && 'Alex' === $db->items[1]['first_name'], 'PASS: truncated contact fields fail verification and restore the original value' );
	$handler->mode = 'rollback_failure';
	$result = $repository->update_registration( 1, array( 'first_name' => 'Alicia', 'dietaryrequirements' => 'yes' ) );
	\audit_check( \is_wp_error( $result ) && 'hherm_update_rollback_failed' === $result->get_error_code(), 'PASS: a compensation that cannot restore the old value returns an explicit rollback failure' );
	$db->items[1]['first_name'] = 'Alex';
	$handler->mode = 'sql_strings';
	$result = $repository->update_registration( 1, array( 'number_of_attendees' => 2 ) );
	\audit_check( ! \is_wp_error( $result ) && '2' === $result['number_of_attendees'], 'PASS: numeric SQL strings verify successfully against numeric writes' );
	$handler->mode = 'save';
	$result = $controller->edit( new \WP_REST_Request( array( 'id' => 1, 'first_name' => 'Alicia', 'last_name' => 'Example', 'email' => 'alicia@example.test', 'phone' => '0400123456', 'organisation' => 'Community', 'reason_for_attending' => 'Meet others', 'number_of_attendees' => 3, 'dietaryrequirements' => 'yes', 'please_let_us_know' => 'Gluten free' ) ) );
	$readback = $repository->get( 1 );
	\audit_check( ! \is_wp_error( $result ) && 'Alicia' === $readback['first_name'] && 'Example' === $readback['last_name'] && 'alicia@example.test' === $readback['email'] && '0400123456' === $readback['phone'] && 'Community' === $readback['organisation'] && 'Meet others' === $readback['reason_for_attending'] && 3 === $readback['number_of_attendees'] && 'yes' === $readback['dietaryrequirements'] && 'Gluten free' === $readback['please_let_us_know'], 'PASS: complete applicant edit passes through controller/repository and all requested values read back correctly' );
	$db->items[1]['registration_status'] = '';
	$list = $repository->list( array( 'status' => 'pending' ) );
	$summary = new \ReflectionMethod( $controller, 'summary' );
	$summary->setAccessible( true );
	$summary_item = $summary->invoke( $controller, $list['items'][0] );
	$detail = $controller->show( new \WP_REST_Request( array( 'id' => 1 ) ) );
	\audit_check( 1 === $list['total'] && 'pending' === $summary_item['status'] && 'pending' === $detail['application']['registration_status'], 'PASS: legacy blank status is consistently Pending in list and detail' );
	\audit_check( 1 === count( $repository->cancellation_recipients( 10 ) ), 'PASS: legacy Pending application receives event cancellation notification' );
	$stats = $repository->statistics();
	\audit_check( 1 === $stats['pending'] && 3 === $stats['pending_places'], 'PASS: legacy Pending status contributes to dashboard totals' );
	$decision = $controller->review( new \WP_REST_Request( array( 'id' => 1, 'status' => 'approved', 'approval_notes' => '' ) ) );
	\audit_check( ! \is_wp_error( $decision ) && 'approved' === $db->items[1]['registration_status'], 'PASS: legacy Pending application can be approved and saved' );
	$db->items[1]['registration_status'] = 'expression_of_interest';
	$list = $repository->list( array( 'status' => 'interest' ) );
	$recipients = $repository->interest_recipients( 10 );
	\audit_check( 1 === $list['total'] && 'interest' === $list['items'][0]['registration_status'] && 1 === count( $recipients ), 'PASS: legacy expression_of_interest receives date-confirmed notifications' );
	$db->items[1]['registration_status'] = 'expression-of-interest';
	\audit_check( 1 === count( $repository->interest_recipients( 10 ) ), 'PASS: hyphenated legacy expression-of-interest also receives notifications' );
	$db->items[1]['registration_status'] = 'interest';
	\audit_check( 1 === count( $repository->interest_recipients( 10 ) ), 'PASS: canonical interest status is selected for notifications' );
	unset( $db->items[1]['registration_status'] );
	\audit_check( 'pending' === $repository->get( 1 )['registration_status'], 'PASS: absent status has the same Pending normalization as an empty status' );
	$db->items[1]['registration_date'] = '2026-09-27 10:00:00';
	$filters = array( 'page' => 1, 'per_page' => 20, 'status' => 'pending', 'event_id' => 10, 'search' => '', 'order' => 'asc', 'date_from' => '27/09/2026', 'date_to' => '27/09/2026' );
	$index = $controller->index( new \WP_REST_Request( $filters ) );
	\audit_check( 1 === $index['total'] && 1 === $index['stats']['pending'], 'PASS: REST accepts Australian date filters and includes combined global statistics by default' );
	$index = $controller->index( new \WP_REST_Request( $filters + array( 'include_stats' => 'false' ) ) );
	\audit_check( 1 === $index['total'] && ! isset( $index['stats'] ), 'PASS: REST export flag skips global statistics and preserves date-filtered results' );
	$db->read_fault = 'error';
	$result = $repository->get( 1 );
	\audit_check( \is_wp_error( $result ) && 'temporary_read_error' === $result->get_error_code(), 'PASS: explicit read failure is preserved instead of falsely declaring the registration deleted' );
	$db->read_fault = '';
	$db->query_fault = 'error';
	$result = $controller->show( new \WP_REST_Request( array( 'id' => 1 ) ) );
	\audit_check( ! \is_wp_error( $result ) && ! empty( $result['history_error'] ) && array() === $result['history'] && 1 === $result['application']['_ID'], 'PASS: profile details remain available while failed history is explicitly marked unavailable' );
	$result = $controller->edit( new \WP_REST_Request( array( 'id' => 1, 'phone' => '0400555666' ) ) );
	\audit_check( ! \is_wp_error( $result ) && ! empty( $result['history_error'] ) && '0400555666' === $db->items[1]['phone'], 'PASS: successful applicant save is preserved and history failure is reported separately' );
	foreach ( array( 'error', 'false', 'throw' ) as $fault ) {
		$db->query_fault = $fault;
		foreach ( array( 'customer_registrations' => 'alicia@example.test', 'event_registrations' => 10, 'attendance_list' => 10, 'cancellation_recipients' => 10, 'interest_recipients' => 10 ) as $method => $argument ) {
			$result = $repository->$method( $argument );
			\audit_check( \is_wp_error( $result ), 'PASS: ' . $method . ' reports ' . $fault . ' storage failure instead of an empty recipient/history result' );
		}
	}
	$db->query_fault = '';
	$db->items = array();
	\audit_check( array() === $repository->cancellation_recipients( 10 ) && array() === $repository->customer_registrations( 'alicia@example.test' ), 'PASS: successful empty queries still return empty recipients and history' );
}
