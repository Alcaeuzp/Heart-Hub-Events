<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {}
	function absint( $value ) { return abs( (int) $value ); }
	function get_posts( $args ) { return array( 42 ); }
	function get_the_title( $id ) { return 'Jack&#8217;s Match 2027'; }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
	function check( $value, $label ) { if ( ! $value ) throw new \Exception( $label ); echo "PASS $label\n"; }
	require dirname( __DIR__ ) . '/includes/class-cct-repository.php';
	$reflection = new \ReflectionClass( '\\HeartHub\\EventRegistrations\\CCT_Repository' );
	$repository = $reflection->newInstanceWithoutConstructor();
	$normalise = $reflection->getMethod( 'normalise_item' );
	$normalise->setAccessible( true );
	$interest = $normalise->invoke( $repository, array( '_ID' => 7, 'registration_status' => 'expression_of_interest', 'event_id' => '42', 'dietaryrequirements' => 'yes', 'please_let_us_know' => 'Gluten free' ) );
	check( 'interest' === $interest['registration_status'], 'legacy expression of interest status is normalised' );
	check( 42 === (int) $interest['event_id'], 'event ID remains available for filtering' );
	check( 'yes' === $interest['dietaryrequirements'] && 'Gluten free' === $interest['please_let_us_know'], 'mapped dietary answers are preserved for the admin detail' );
	require dirname( __DIR__ ) . '/includes/class-rest-controller.php';
	$rest_class = new \ReflectionClass( '\\HeartHub\\EventRegistrations\\REST_Controller' );
	$rest = $rest_class->newInstanceWithoutConstructor();
	$settings = $rest_class->getProperty( 'settings' ); $settings->setAccessible( true );
	$settings->setValue( $rest, new class { public function get( $key, $default = '' ) { return $default; } } );
	$events = $rest_class->getMethod( 'events' ); $events->setAccessible( true );
	check( "Jack\u{2019}s Match 2027" === $events->invoke( $rest )[0]['title'], 'REST event filter decodes the actual encoded event title' );
	$pending = $normalise->invoke( $repository, array( '_ID' => 8, 'event_id' => 42, 'registration_status' => '', 'dietaryrequirements' => 'no' ) );
	check( 'pending' === $pending['registration_status'], 'empty legacy status is normalised to pending for every consumer' );
}
