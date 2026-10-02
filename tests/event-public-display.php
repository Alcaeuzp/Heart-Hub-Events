<?php
namespace {
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); }
function esc_url( $value ) { return esc_attr( $value ); }
function esc_url_raw( $value ) { return $value; }
function shortcode_atts( $defaults, $attributes, $tag = '' ) { return array_merge( $defaults, $attributes ); }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
function get_the_ID() { return 42; }
function get_permalink( $id ) { return 'https://example.test/events/' . $id . '/'; }
function apply_filters( $hook, $value, ...$arguments ) { return $value; }
function wp_timezone() { return new DateTimeZone( 'Australia/Sydney' ); }
function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}
namespace HeartHub\EventRegistrations {
class Settings {}
require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
require dirname( __DIR__ ) . '/includes/class-event-public-display.php';

\check( 'website_registration' === Event_Public_Display::normalise_registration_type( '' ), 'missing registration type defaults to website registration' );
\check( 'website_registration' === Event_Public_Display::normalise_registration_type( 'internal' ), 'legacy internal registration alias remains compatible' );
\check( 'external_registration' === Event_Public_Display::normalise_registration_type( 'external' ), 'external registration alias is normalised' );
\check( 'no_registration' === Event_Public_Display::normalise_registration_type( 'none' ), 'no-registration alias is normalised' );
\check( Event_Public_Display::supports_registration_display_switches( 'website_registration' ), 'website registration supports display switches' );
\check( ! Event_Public_Display::supports_registration_display_switches( 'external_registration' ), 'external registration disables display switches' );
\check( ! Event_Public_Display::supports_registration_display_switches( 'no_registration' ), 'no registration disables display switches' );
\check( Event_Public_Display::website_registration_available( 'website_registration', 'confirmed' ), 'confirmed website event can enable registration' );
\check( ! Event_Public_Display::website_registration_available( 'website_registration', 'tbc' ), 'TBC website event locks registration' );
\check( Event_Public_Display::interest_contact_available( 'website_registration', 'tbc' ), 'TBC website event can show interest contact button' );
\check( ! Event_Public_Display::interest_contact_available( 'website_registration', 'confirmed' ), 'confirmed website event disables interest contact button' );
\check( ! Event_Public_Display::interest_contact_available( 'external_registration', 'tbc' ), 'external event cannot use website interest contact button' );
\check( 'confirmed' === Event_Public_Display::normalise_date_status( '', '' ), 'missing date status defaults to confirmed' );
\check( 'tbc' === Event_Public_Display::normalise_date_status( '', 'tba' ), 'legacy TBA date status remains compatible' );
\check( Event_Public_Display::normalise_flag( '', true ), 'missing display flag defaults on' );
\check( ! Event_Public_Display::normalise_flag( 'false', true ), 'explicit false display flag stays off' );
$display = new Event_Public_Display( new Settings() );
$GLOBALS['meta'] = array( 'registration_type' => 'external_registration', 'external_registration_url' => 'https://organiser.test/book' );
\check( false !== strpos( $display->registration_cta_shortcode(), 'https://organiser.test/book' ), 'active external events retain their registration link' );
$GLOBALS['meta']['event_cancelled'] = 'true';
\check( '' === $display->registration_cta_shortcode(), 'cancelled external events have no registration CTA' );
$GLOBALS['meta'] = array( 'event_cancelled' => 'true', 'event_date_status' => 'tbc', 'show_interest_contact_button' => 'true' );
\check( ! $display->show_interest_contact_button( 42 ) && '' === $display->registration_cta_shortcode(), 'cancelled TBC events have no interest CTA' );
$GLOBALS['meta'] = array( 'event_cancelled' => 'true', 'registration_enabled' => 'true', 'show_remaining_spots' => 'true' );
\check( ! $display->show_register_button( 42 ) && ! $display->show_remaining_spots( 42 ) && '' === $display->registration_cta_shortcode(), 'cancellation overrides stale website registration and remaining-spots flags' );
$GLOBALS['meta'] = array( 'registration_open' => time() + 3600, 'registration_enabled' => 'true' );
\check( $display->show_interest_contact_button( 42 ), 'active upcoming events still expose their interest CTA' );
$GLOBALS['meta']['event_cancelled'] = 'yes';
\check( ! $display->show_interest_contact_button( 42 ) && '' === $display->registration_cta_shortcode(), 'cancellation suppresses interest during a future registration opening' );
}
