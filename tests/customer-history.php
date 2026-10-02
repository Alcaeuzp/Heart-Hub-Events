<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error {}
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => '' ); }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['event_meta'][ $id ][ $key ] ?? ''; }
	function get_the_title( $id ) { return $GLOBALS['event_titles'][ $id ] ?? ''; }
	function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	function check( $value, $label ) { if ( ! $value ) throw new Exception( $label ); echo "PASS $label\n"; }
}

namespace Jet_Engine\Modules\Custom_Content_Types {
	class Module {
		public $manager;
		public static function instance() { return $GLOBALS['cct_module']; }
	}
}

namespace HeartHub\EventRegistrations {
	class Settings {
		public function get( $key, $default = '' ) {
			return array(
				'cct_slug'            => 'event_registrations',
				'events_cpt'          => 'events',
				'event_start'         => 'start_date',
				'event_end'           => 'end_date__time',
				'event_venue'         => 'venue',
				'event_organiser'      => 'organiser',
				'event_info'          => '_description',
				'payment_amount_field'=> 'amount_paid',
				'payment_status_field'=> 'payment_status',
				'payment_method_field'=> 'payment_method',
			)[ $key ] ?? $default;
		}
	}
	class Email_Automation {}
	class Audit_Log {}
	class Capacity_Manager {
		public function attendee_key() { return 'number_of_attendees'; }
		public function enabled() { return false; }
		public function remaining( $id ) { return null; }
	}
	class Event_Public_Display { public static function normalise_date_status( $current, $legacy ) { return 'confirmed'; } }

	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-cct-repository.php';
	require dirname( __DIR__ ) . '/includes/class-rest-controller.php';

	$rows = array(
		array( '_ID' => 10, 'event_id' => 100, 'email' => 'alex@example.test', 'registration_date' => '2026-01-01 09:00:00', 'registration_status' => 'approved', 'attendance_status' => 'attended', 'checked_in_at' => '2026-02-01 10:05:00', 'number_of_attendees' => 2, 'amount_paid' => '25.00', 'payment_status' => 'paid', 'payment_method' => 'card' ),
		array( '_ID' => 11, 'event_id' => 101, 'email' => 'Alex@Example.Test', 'registration_date' => '2026-02-01 09:00:00', 'registration_status' => 'declined', 'number_of_attendees' => 1 ),
		array( '_ID' => 12, 'event_id' => 102, 'email' => 'other@example.test', 'registration_date' => '2026-03-01 09:00:00', 'registration_status' => 'approved' ),
	);
	$db = new class( $rows ) {
		private $rows;
		public function __construct( $rows ) { $this->rows = $rows; }
		public function set_format_flag( $flag ) {}
		public function query( ...$args ) { return $this->rows; }
	};
	$type = new class( $db ) {
		public $db;
		public function __construct( $db ) { $this->db = $db; }
		public function prepare_query_args( $args ) { return $args; }
	};
	$module = new \Jet_Engine\Modules\Custom_Content_Types\Module();
	$module->manager = new class( $type ) {
		private $type;
		public function __construct( $type ) { $this->type = $type; }
		public function get_content_types( $slug ) { return $this->type; }
	};
	$GLOBALS['cct_module'] = $module;
	$GLOBALS['event_titles'] = array( 100 => 'First workshop', 101 => 'Second workshop' );
	$GLOBALS['event_meta'] = array(
		100 => array( 'start_date' => '2026-02-01 10:00:00', 'registration_fee' => '$25' ),
		101 => array( 'start_date' => '2026-03-01 10:00:00', 'registration_fee' => '$10' ),
	);

	$settings = new Settings();
	$repository = new CCT_Repository( $settings );
	$matches = $repository->customer_registrations( 'alex@example.test' );
	\check( 2 === count( $matches ) && 10 === $matches[0]['_ID'] && 11 === $matches[1]['_ID'], 'customer history matches email case-insensitively and excludes other customers' );

	$controller = new REST_Controller( $repository, new Email_Automation(), new Audit_Log(), $settings, new Capacity_Manager() );
	$method = new \ReflectionMethod( $controller, 'customer_history' );
	if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
	$history = $method->invoke( $controller, array( 'email' => 'alex@example.test' ) );
	\check( array_column( $history, 'id' ) === array( 10, 11 ), 'customer history is ordered by event date from oldest to newest' );
	\check( 'attended' === $history[0]['attendance_status'] && 'declined' === $history[1]['registration_status'], 'history retains attendance and declined registrations' );
	\check( '25.00' === $history[0]['payment_amount'] && 'paid' === $history[0]['payment_status'] && '' === $history[1]['payment_amount'], 'history exposes mapped payment data without inventing missing payments' );
}
