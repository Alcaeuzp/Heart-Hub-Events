<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
	function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}
namespace HeartHub\EventRegistrations {
	class Settings {}
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-event-public-display.php';

	$display = new Event_Public_Display( new Settings() );
	$now = time();
	$GLOBALS['meta'] = array( 'registration_open' => $now + 3600, 'registration_close' => $now + 7200 );
	\check( 'upcoming' === $display->registration_phase( 42, $now ), 'future registration opening is an upcoming interest period' );
	$GLOBALS['meta'] = array( 'registration_open' => $now - 3600, 'registration_close' => $now + 3600 );
	\check( 'open' === $display->registration_phase( 42, $now ), 'registration period between its bounds is open' );
	$GLOBALS['meta'] = array( 'registration_close' => $now );
	\check( 'closed' === $display->registration_phase( 42, $now ), 'registration period is closed at its closing time' );
}
