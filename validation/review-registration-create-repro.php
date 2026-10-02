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
	require dirname( __DIR__ ) . '/tests/support/option-lock.php';
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
 $handler->mode = 'truncate';
 $create = $repository->create_registration(array('event_id'=>10,'first_name'=>'Longer Customer Name','email'=>'customer@example.test','registration_status'=>'interest'));
 $read = $repository->get($create);
 echo json_encode(array('result'=>$create,'stored_first_name'=>$read['first_name'],'submitted_first_name'=>'Longer Customer Name','returned_error'=>is_wp_error($create))) . PHP_EOL;
 $handler->mode = 'omit_dietary';
 $create = $repository->create_registration(array('event_id'=>10,'first_name'=>'Alex','email'=>'customer@example.test','registration_status'=>'interest','dietaryrequirements'=>'yes','please_let_us_know'=>'Life threatening nut allergy'));
 $read = $repository->get($create);
 echo json_encode(array('result'=>$create,'dietary_present'=>isset($read['dietaryrequirements']),'dietary_details_present'=>isset($read['please_let_us_know']),'returned_error'=>is_wp_error($create))) . PHP_EOL;
}
