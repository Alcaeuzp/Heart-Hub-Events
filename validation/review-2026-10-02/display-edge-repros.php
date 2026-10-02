<?php
namespace {
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_html_class( $v ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function esc_url_raw( $v, $protocols = null ) { return preg_match( '#^https?://#', (string) $v ) ? (string) $v : ''; }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function shortcode_atts( $defaults, $atts, $tag = '' ) { return array_merge( $defaults, $atts ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
function wp_date( $fmt, $ts, $tz = null ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?: wp_timezone() )->format( $fmt ); }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['review_posts'][$id]['meta'][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['review_posts'][$id]['meta'][$key] ); }
function get_the_title( $id ) { return $GLOBALS['review_posts'][$id]['title'] ?? ''; }
function get_the_ID() { return 1; }
function get_post_status( $id ) { return 'publish'; }
function get_post_field( $field, $id ) { return ''; }
function get_permalink( $id ) { return 'https://example.test/events/' . $id . '/'; }
function get_term_by( $field, $value, $taxonomy ) { return 'fundraising-event' === $value ? (object) array( 'term_id' => 50 ) : false; }
function has_term( $terms, $taxonomy, $id ) { return (bool) array_intersect( (array) $terms, $GLOBALS['review_posts'][$id]['terms'] ?? array() ); }
function is_wp_error( $v ) { return false; }
function get_posts( $args ) {
    $ids = array();
    foreach ( $GLOBALS['review_posts'] as $id => $post ) {
        if ( ! isset( $args['meta_key'] ) ) continue;
        $raw = $post['meta'][$args['meta_key']] ?? '';
        // WP_Meta_Query NUMERIC generates CAST(meta_value AS SIGNED), reproduced here.
        $numeric = (int) $raw;
        if ( $numeric >= $args['meta_query'][0]['value'] && $numeric < $args['meta_query'][1]['value'] ) $ids[] = $id;
    }
    return $ids;
}
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function sanitize_hex_color( $v ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $v ) ? $v : null; }
}
namespace HeartHub\EventRegistrations {
class Settings { public function get( $key, $default = '' ) { return array( 'attendee_count_field' => 'number_of_attendees', 'event_remaining_capacity' => 'current_event_capacity' )[$key] ?? $default; } }
class Calendar_Schedule {
    public const TYPE_META = 'hherm_calendar_entry_type';
    public const MASTER_META = 'hherm_calendar_series_master';
    public const RULE_META = 'hherm_calendar_recurrence_rule';
    public const SCHEDULE_STATUS_META = 'hherm_calendar_schedule_status';
}
$root = dirname( __DIR__, 2 );
require $root . '/includes/class-event-datetime.php';
require $root . '/includes/class-event-types.php';
require $root . '/includes/class-event-public-display.php';
require $root . '/includes/class-calendar-settings.php';
require $root . '/includes/class-calendar-display.php';
$settings = new Settings();
$public = new Event_Public_Display( $settings );
$calendar = new Calendar_Display( $settings, $public );
$status = new \ReflectionMethod( $calendar, 'registration_status' );
$range = new \ReflectionMethod( $calendar, 'events_for_range' );
$render = new \ReflectionMethod( $calendar, 'render_calendar' );
$status->setAccessible( true ); $range->setAccessible( true ); $render->setAccessible( true );
$GLOBALS['review_posts'] = array( 1 => array( 'title' => 'Cancelled fundraiser', 'meta' => array( 'event_cancelled' => 'true', 'registration_enabled' => 'false', 'registration_type' => 'external_registration', 'external_registration_url' => 'https://organiser.test/book' ) ) );
echo 'CANCELLED_EXTERNAL_CTA=' . $public->registration_cta_shortcode( array( 'event_id' => 1 ) ) . "\n";
$now = time();
$GLOBALS['review_posts'][2] = array( 'title' => 'In progress', 'meta' => array( 'start_date' => $now - 3600, 'end_date__time' => $now + 3600, 'registration_close' => $now + 1800 ) );
echo 'AFTER_START_PUBLIC_REGISTER=' . json_encode( $public->show_register_button( 2 ) ) . "\n";
echo 'AFTER_START_CALENDAR_STATUS=' . json_encode( $status->invoke( $calendar, 2, $now - 3600, false ) ) . "\n";
$month = ( new \DateTimeImmutable( 'first day of this month 00:00', wp_timezone() ) )->modify( '+1 month' );
$start = $month->modify( '+3 days 09:00' )->getTimestamp();
$end = $month->modify( '+7 days 17:00' )->getTimestamp();
$GLOBALS['review_posts'] = array( 3 => array( 'title' => 'Five day fundraiser', 'terms' => array( 50 ), 'meta' => array( 'start_date' => $start, 'end_date__time' => $end ) ) );
$options = array( 'week_start' => 1, 'months_behind' => 12, 'months_ahead' => 36, 'show_popups' => 1, 'show_times' => 1, 'density' => 'comfortable' );
$html = $render->invoke( $calendar, $month, $options, false, '', 'https://example.test/events/' );
preg_match_all( '/class="hherm-calendar__popup" id="([^"]+)"/', $html, $matches );
echo 'FIVE_DAY_POPUP_IDS=' . json_encode( array_count_values( $matches[1] ) ) . "\n";
$all_html = $html . $render->invoke( $calendar, $month, $options, false, '', 'https://example.test/events/', 'fundraisers' );
preg_match_all( '/class="hherm-calendar__popup" id="([^"]+)"/', $all_html, $matches );
echo 'TWO_CALENDARS_POPUP_IDS=' . json_encode( array_count_values( $matches[1] ) ) . "\n";
$legacy = $month->modify( '+4 days 10:00' )->format( 'Y-m-d\TH:i' );
$GLOBALS['review_posts'] = array( 4 => array( 'title' => 'Imported ISO date event', 'meta' => array( 'start_date' => $legacy ) ) );
echo 'LEGACY_PARSED_TIMESTAMP=' . Event_Datetime::timestamp( $legacy ) . "\n";
echo 'LEGACY_CALENDAR_OCCURRENCES=' . count( $range->invoke( new Calendar_Display( $settings ), $month, $month->modify( '+1 month' ) ) ) . "\n";
$GLOBALS['review_posts'][5] = array( 'title' => 'Zero places', 'meta' => array( 'event_capacity' => 0, 'current_event_capacity' => 0 ) );
echo 'ZERO_CAPACITY_CALENDAR_STATUS=' . json_encode( $status->invoke( new Calendar_Display( $settings ), 5, $now + 86400, false ) ) . "\n";
}
