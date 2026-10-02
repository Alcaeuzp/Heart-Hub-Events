<?php
namespace {
function test_contains( string $haystack, string $needle ): bool { return '' === $needle || false !== strpos( $haystack, $needle ); }
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return false; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); }
function number_format_i18n( $value, $decimals = 0 ) { return number_format( (float) $value, (int) $decimals, '.', ',' ); }
function shortcode_atts( $defaults, $attributes ) { return array_merge( $defaults, $attributes ); }
function add_shortcode( $name, $callback ) { $GLOBALS['shortcodes'][ $name ] = $callback; }
function apply_filters( $hook, $value ) { return $value; }
function current_datetime() { return new DateTimeImmutable( '2026-09-19 12:00:00', wp_timezone() ); }
function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
function get_posts( $args ) { return array( 10, 20, 30, 40 ); }
function get_the_ID() { return 10; }
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish' ); }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_term_by( $field, $value, $taxonomy ) { return isset( $GLOBALS['terms'][ $value ] ) ? (object) array( 'term_id' => $GLOBALS['terms'][ $value ] ) : false; }
function has_term( $term, $taxonomy, $event_id ) { return in_array( (int) $event_id, $GLOBALS['fundraisers'], true ); }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS["meta"][ $id ][ $key ] ); }
function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
class Calendar_Schedule { public const TYPE_META = 'hherm_calendar_entry_type'; }
class Settings { public function get( $key, $default = '' ) { return $default; } }
class CCT_Repository {
	public function attendance_list( int $event_id ) { return $GLOBALS['attendance'][ $event_id ] ?? array(); }
}

$GLOBALS['terms'] = array( 'workshop' => 22, 'fundraising-event' => 11 );
$GLOBALS['fundraisers'] = array( 10, 30 );
$GLOBALS['meta'] = array(
	10 => array( 'start_date' => '2026-09-01T10:00', 'amount_raised' => '1200.50' ),
	20 => array( 'start_date' => '2026-04-01T10:00' ),
	30 => array( 'start_date' => '2025-08-01T10:00', 'amount_raised' => '999.00' ),
	40 => array( 'start_date' => '2026-08-01T10:00', 'event_cancelled' => 'true', 'amount_raised' => '500.00' ),
);
$GLOBALS['attendance'] = array(
	10 => array(
		array( 'attendance_status' => 'attended', 'number_of_attendees' => 4, 'checked_in_party_size' => 3 ),
		array( 'attendance_status' => 'partial', 'number_of_attendees' => 4, 'checked_in_party_size' => 2 ),
		array( 'attendance_status' => 'no-show', 'number_of_attendees' => 5 ),
	),
	20 => array(
		array( 'attendance_status' => 'attended', 'number_of_attendees' => 2 ),
		array( 'attendance_status' => 'partial', 'number_of_attendees' => 3 ),
	),
);

require dirname( __DIR__ ) . '/includes/class-event-types.php';
require dirname( __DIR__ ) . '/includes/class-event-metrics.php';

$metrics = new Event_Metrics( new Settings(), new CCT_Repository() );
\check( array( 10, 20 ) === $metrics->event_ids( 12 ), 'rolling period includes completed, non-cancelled events only' );
\check( 1200.5 === $metrics->total_funds( 12 ), 'fundraising total uses fundraiser events inside the period' );
\check( 7 === $metrics->total_attendees( 12 ), 'attendance total uses actual checked-in party sizes' );
\check( 5 === $metrics->event_attendees( 10 ), 'per-event attendance excludes no-shows and unknown partial headcounts' );
\check( '1,201' === strip_tags( $metrics->total_funds_shortcode() ), 'fundraising shortcode formats the rolling total without a currency symbol' );
\check( '1,201' === strip_tags( $metrics->event_funds_shortcode() ), 'per-event funds shortcode uses the current event without a currency symbol' );
\check( test_contains( $metrics->event_attendees_shortcode(), '>5<' ), 'per-event attendees shortcode uses the current event by default' );
$metrics->register();
\check( 5 === count( $GLOBALS['shortcodes'] ), 'all impact shortcodes are registered' );
}
