<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {}
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function esc_html( $value ) { return (string) $value; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish', 'post_content' => '' ); }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function get_current_user_id() { return 7; }
	function current_time( $type ) { return '2026-09-21 20:00:00'; }
	function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { $date = new DateTimeImmutable( '@' . $timestamp ); return $date->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	function jet_fb_context() { return $GLOBALS['context']; }
	function check( $value, $label ) {
		if ( ! $value ) {
			throw new Exception( $label );
		}
		echo "PASS $label\n";
	}

	class Test_Context {
		public $values = array();
		public function update_request( $value, $key ) { $this->values[ $key ] = $value; }
	}
	class Test_Handler {
		public function get_form_id() { return 2774; }
	}
}

namespace HeartHub\EventRegistrations {
	class Settings {
		public function get( $key, $default = '' ) { return array( 'events_cpt' => 'events', 'event_start' => 'start_date' )[ $key ] ?? $default; }
	}
	class Audit_Log {}
	class CCT_Repository {}
	class Capacity_Manager {
		public function partially_configured() { return false; }
		public function attendees_from( $request ) { return 1; }
		public function enabled() { return false; }
		public function attendee_key() { return 'number_of_attendees'; }
		public function abandon_registration( $event_id ) {}
	}
	class Event_Public_Display {
		public static function normalise_registration_type( $value ) { return $value ?: 'website_registration'; }
	}

	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-jetform-integration.php';

	$GLOBALS['meta'] = array(
		'registration_enabled' => 'true',
		'registration_type'    => 'website_registration',
		'event_schedule_status' => 'scheduled',
	);
	$GLOBALS['context'] = new \Test_Context();
	$integration = new JetForm_Integration( new CCT_Repository(), new Settings(), new Audit_Log(), new Capacity_Manager() );
	$handler = new \Test_Handler();
	$integration->normalise_request(
		array(
			'event_id'             => 42,
			'dietaryrequirements'  => 'yes',
			'please_let_us_know'   => 'Gluten <b>free</b>',
		),
		$handler
	);
	\check( $GLOBALS['context']->values['dietaryrequirements'] === 'yes', 'JetForm context receives dietary choice' );
	\check( $GLOBALS['context']->values['please_let_us_know'] === 'Gluten free', 'JetForm context receives sanitised dietary details' );

	$GLOBALS['context'] = new \Test_Context();
	$integration->normalise_request(
		array(
			'event_id'             => 42,
			'dietaryrequirements'  => 'no',
			'please_let_us_know'   => 'Discard this value',
		),
		$handler
	);
	\check( $GLOBALS['context']->values['please_let_us_know'] === '', 'JetForm context clears details when choice is no' );

	$rejected = false;
	try {
		$integration->normalise_request( array( 'event_id' => 42, 'dietaryrequirements' => 'sometimes' ), $handler );
	} catch ( \RuntimeException $error ) {
		$rejected = true;
	}
	\check( $rejected, 'JetForm rejects an invalid dietary choice' );

	$GLOBALS['meta']['registration_open'] = time() + 3600;
	$rejected = false;
	try {
		$integration->normalise_request( array( 'event_id' => 42 ), $handler );
	} catch ( \RuntimeException $error ) {
		$rejected = false !== strpos( $error->getMessage(), 'not open yet' );
	}
	\check( $rejected, 'JetForm blocks registration before its opening time with expression-of-interest guidance' );

	$GLOBALS['context'] = new \Test_Context();
	$integration->normalise_interest_request( array( 'event_id' => 42 ), $handler );
	\check( 'interest' === $GLOBALS['context']->values['registration_status'], 'scheduled future event accepts an expression of interest' );
	\check( 'expression_of_interest' === $GLOBALS['context']->values['registration_type'], 'future-event interest is stored with an explicit application type' );
}
