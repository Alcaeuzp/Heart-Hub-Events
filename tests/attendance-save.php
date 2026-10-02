<?php
namespace {
	require __DIR__ . '/support/option-lock.php';
	define( 'ABSPATH', __DIR__ );
	class Redirect extends Exception { public $url; public function __construct( $url ) { $this->url = $url; } }
	class WP_Error { public function __construct( ...$args ) {} public function get_error_message() { return 'error'; } }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function current_user_can( $capability ) { return true; }
	function check_admin_referer( ...$args ) { return true; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_email( $value ) { return (string) $value; }
	function wp_unslash( $value ) { return $value; }
	function map_deep( $value, $callback ) { return is_array( $value ) ? array_map( static function ( $item ) use ( $callback ) { return map_deep( $item, $callback ); }, $value ) : $callback( $value ); }
	function get_post( $id ) { return 10 === (int) $id ? (object) array( 'ID' => 10, 'post_type' => 'events', 'post_status' => 'publish' ) : null; }
	function metadata_exists( ...$args ) { return false; }
	function get_post_meta( ...$args ) { return ''; }
	function get_the_title( $id ) { return 'Workshop'; }
	function get_posts( $args ) { return array( get_post( 10 ) ); }
	function add_query_arg( $args, $url ) { return $url . '&' . http_build_query( $args ); }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
	function wp_safe_redirect( $url ) { throw new Redirect( $url ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_url( $value ) { return (string) $value; }
	function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
	function selected( $a, $b ) { echo (string) $a === (string) $b ? ' selected' : ''; }
	function checked( $a, $b ) { echo (string) $a === (string) $b ? ' checked' : ''; }
	function wp_nonce_field( ...$args ) {}
	function wp_timezone() { return new DateTimeZone( 'Australia/Perth' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return gmdate( $format, $timestamp ); }
	function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	class Plugin { public const CAPABILITY = 'manage_heart_hub_event_registrations'; }
	class Calendar_Schedule { public const TYPE_META = 'hherm_calendar_entry_type'; }
	class Settings { public function get( $key, $default = '' ) { return 'events_cpt' === $key ? 'events' : $default; } }
	class Audit_Log { public $entries = array(); public function write( ...$args ) { $this->entries[] = $args; return true; } }
	class Checkin_Manager { public function render_event_panel( $id ) {} }
	class Checkin_Page { public function render_event_content( $id ) {} }
	class CCT_Repository {
		public $items = array();
		public $writes = array();
		public $on_get;
		public function get( int $id ) { if ( $this->on_get ) { $callback = $this->on_get; $this->on_get = null; $callback(); } return $this->items[ $id ] ?? new \WP_Error(); }
		public function attendance_list( int $event_id ) { return array_values( $this->items ); }
		public function update_attendance( int $id, string $status ) { $this->writes[] = $id; $this->items[ $id ]['attendance_status'] = $status; return $this->items[ $id ]; }
	}
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-attendance-manager.php';

	$repository = new CCT_Repository();
	$audit = new Audit_Log();
	$manager = new Attendance_Manager( new Settings(), $repository, $audit, new Checkin_Manager(), new Checkin_Page() );
	$row = static function ( int $id, string $status ) { return array( '_ID' => $id, 'event_id' => 10, 'registration_status' => 'approved', 'attendance_status' => $status, 'first_name' => 'Person', 'last_name' => (string) $id ); };

	// Render: each row carries the status staff saw, and the selector no longer claims to save.
	$repository->items = array( 1 => $row( 1, 'attended' ), 2 => $row( 2, '' ) );
	$_GET = array( 'event_id' => 10 );
	ob_start(); $manager->render(); $html = ob_get_clean();
	\check( false !== strpos( $html, 'name="attendance_original[1]" value="attended"' ) && false !== strpos( $html, 'name="attendance_original[2]" value="unknown"' ), 'register records the status shown for each row' );
	\check( false === strpos( $html, 'Save register' ) && false !== strpos( $html, 'Switch event' ), 'event selector no longer presents itself as a save button' );

	// Save: register opened while rows 1–4 were unrecorded; attendees then self checked in (rows 1 and 3).
	$repository->items = array(
		1 => $row( 1, 'attended' ),   // Checked in after the register opened; staff left it untouched.
		2 => $row( 2, 'unknown' ),    // Staff marks a no-show.
		3 => $row( 3, 'partial' ),    // Checked in after opening, but staff also chose no-show: conflict.
		4 => $row( 4, 'attended' ),   // Staff saw Attended and deliberately corrects it.
		5 => $row( 5, 'unknown' ),    // Legacy form without original values keeps old behaviour.
		6 => $row( 6, '' ),           // Never recorded; untouched.
		7 => $row( 7, 'attended' ),   // Staff and attendee reached the same value.
	);
	$_POST = array(
		'event_id'            => 10,
		'attendance'          => array( 1 => 'unknown', 2 => 'no-show', 3 => 'no-show', 4 => 'unknown', 5 => 'attended', 6 => 'unknown', 7 => 'attended' ),
		'attendance_original' => array( 1 => 'unknown', 2 => 'unknown', 3 => 'unknown', 4 => 'attended', 6 => 'unknown', 7 => 'unknown' ),
	);
	try { $manager->handle(); $url = ''; } catch ( \Redirect $redirect ) { $url = $redirect->url; }
	\check( 'attended' === $repository->items[1]['attendance_status'], 'untouched row keeps an attendee self check-in made after the register opened' );
	\check( 'no-show' === $repository->items[2]['attendance_status'], 'a staff change to an unchanged row is saved' );
	\check( 'partial' === $repository->items[3]['attendance_status'], 'a conflicting staff change does not overwrite a newer check-in' );
	\check( 'unknown' === $repository->items[4]['attendance_status'], 'a deliberate staff correction is saved' );
	\check( 'attended' === $repository->items[5]['attendance_status'], 'a submission without original values keeps the previous save behaviour' );
	\check( array( 2, 4, 5 ) === $repository->writes, 'only intended rows are written' );
	\check( false !== strpos( $url, 'attendance_saved=3' ) && false !== strpos( $url, 'attendance_conflicts=1' ) && false === strpos( $url, 'attendance_error' ), 'redirect reports saved and conflicting rows separately' );
	\check( 3 === count( $audit->entries ), 'each saved change is audited once' );

	$_GET = array( 'event_id' => 10, 'attendance_saved' => 3, 'attendance_conflicts' => 1 );
	ob_start(); $manager->render(); $html = ob_get_clean();
	\check( false !== strpos( $html, '1 attendance records changed after this register was opened' ), 'conflict notice explains what was not overwritten' );

	// An attendee withdraws after the register was opened; staff leave that row untouched.
	$repository->writes = array();
	$repository->items[2]['registration_status'] = 'declined';
	$_POST = array( 'event_id' => 10, 'attendance' => array( 2 => 'no-show', 4 => 'attended' ), 'attendance_original' => array( 2 => 'no-show', 4 => 'unknown' ) );
	try { $manager->handle(); } catch ( \Redirect $redirect ) { $url = $redirect->url; }
	\check( array( 4 ) === $repository->writes && false === strpos( $url, 'attendance_error' ), 'an untouched row for a withdrawn attendee is not reported as a failure' );
	$repository->items[2]['registration_status'] = 'approved';

	// Rows that fail validation are still counted as failures.
	$repository->writes = array();
	$repository->items[8] = array( '_ID' => 8, 'event_id' => 99, 'registration_status' => 'approved', 'attendance_status' => 'unknown' );
	$_POST = array( 'event_id' => 10, 'attendance' => array( 8 => 'attended', 9 => 'attended', 2 => 'bogus' ), 'attendance_original' => array( 8 => 'unknown', 9 => 'unknown', 2 => 'no-show' ) );
	try { $manager->handle(); } catch ( \Redirect $redirect ) { $url = $redirect->url; }
	\check( array() === $repository->writes && false !== strpos( $url, 'attendance_error' ), 'other-event, missing and invalid rows are rejected without writes' );
	$held = Mutation_Lock::acquire( 'hherm_review_lock_4' );
	$_POST = array( 'event_id' => 10, 'attendance' => array( 4 => 'unknown' ), 'attendance_original' => array( 4 => 'attended' ) );
	try { $manager->handle(); } catch ( \Redirect $redirect ) { $url = $redirect->url; }
	\check( array() === $repository->writes && false !== strpos( $url, 'attendance_conflicts=1' ), 'staff attendance cannot write through another registration mutation lock' );
	Mutation_Lock::release( 'hherm_review_lock_4', $held );
	try { $manager->handle(); } catch ( \Redirect $redirect ) { $url = $redirect->url; }
	\check( array( 4 ) === $repository->writes && 'unknown' === $repository->items[4]['attendance_status'], 'staff attendance can retry after a competing registration mutation finishes' );
	$repository->writes = array(); $repository->items[4]['attendance_status'] = 'attended';
	$repository->on_get = static function () { $GLOBALS['options']['hherm_review_lock_4'] = array( 'token' => 'replacement', 'expires_at' => time() + 300 ); };
	try { $manager->handle(); } catch ( \Redirect $redirect ) { $url = $redirect->url; }
	\check( array() === $repository->writes && 'attended' === $repository->items[4]['attendance_status'] && false !== strpos( $url, 'attendance_conflicts=1' ) && 'replacement' === $GLOBALS['options']['hherm_review_lock_4']['token'], 'staff attendance detects lease replacement during its read and preserves the new owner and attendance' );
}
