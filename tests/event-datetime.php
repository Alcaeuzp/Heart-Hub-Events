<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	function absint( $value ) { return abs( (int) $value ); }
	function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) {
		$date = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() );
		return $date->format( $format );
	}
	function check( $value, $label ) {
		if ( ! $value ) {
			throw new Exception( $label );
		}
		echo "PASS $label\n";
	}
}

namespace HeartHub\EventRegistrations {
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';

	$local = '2026-11-21T08:00';
	$expected = ( new \DateTimeImmutable( $local, \wp_timezone() ) )->getTimestamp();
	\check( Event_Datetime::timestamp( $local ) === $expected, 'legacy local value converts to site-timezone timestamp' );
	\check( Event_Datetime::storage( $local ) === $expected, 'storage returns JetEngine timestamp' );
	\check( Event_Datetime::input( $expected ) === $local, 'timestamp returns to datetime-local input' );
	\check( Event_Datetime::display( $expected, 'j F Y g:i a' ) === '21 November 2026 8:00 am', 'timestamp displays in site timezone' );
	\check( Event_Datetime::timestamp( (string) $expected ) === $expected, 'numeric storage remains unchanged' );
	\check( Event_Datetime::storage( '' ) === '', 'empty value remains empty' );
	\check( Event_Datetime::timestamp( '2026-02-30T10:00' ) === 0, 'invalid date is rejected' );
	\check( Event_Datetime::display( '2026-09-01 19:16:55', Event_Datetime::DATETIME_FORMAT ) === '1 September 2026, 7:16 pm', 'database datetime displays day first with Australian time' );
	\check( Event_Datetime::display( $expected, Event_Datetime::DATE_FORMAT ) === '21 November 2026', 'default event date is Australian regardless of site formatting' );
	\check( Event_Datetime::editor_input( $expected ) === '21/11/2026 08:00 am', 'editor date is day first regardless of browser locale' );
	\check( Event_Datetime::editor_storage( '21/11/2026 08:00 am' ) === $local, 'Australian editor value retains site local time' );
	\check( Event_Datetime::editor_storage( $local ) === $local, 'legacy ISO editor submissions remain supported' );
	\check( Event_Datetime::editor_storage( '03/04/2026 01:30 pm' ) === '2026-04-03T13:30', 'ambiguous dates parse as day/month and afternoon correctly' );
	\check( null === Event_Datetime::editor_storage( '31/02/2026 08:00 am' ), 'Australian editor rejects invalid calendar days' );
}
