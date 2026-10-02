<?php
namespace {
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ); }
function shortcode_atts( $defaults, $attributes ) { return array_merge( $defaults, $attributes ); }
function absint( $value ) { return abs( (int) $value ); }
function get_the_ID() { return $GLOBALS['current_event_id'] ?? 0; }
function get_post_meta( $event_id, $key ) { return $GLOBALS['event_meta'][ $event_id ][ $key ] ?? ''; }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }
function esc_url( $url ) { return $url; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function number_format_i18n( $value, $decimals ) { return number_format( $value, $decimals, '.', ',' ); }
function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}
namespace HeartHub\EventRegistrations {
class Settings {}
require dirname( __DIR__ ) . '/includes/class-event-public-display.php';

$display = new Event_Public_Display( new Settings() );
$GLOBALS['event_meta'][ 31 ] = array(
	'external_fee_enabled' => 'true',
	'external_fee_amount'  => '25.5',
	'external_fee_url'     => 'https://payments.example.test/fundraiser',
	'registration_fee'     => '$10',
	'sponsorship_cost'     => '$100',
);
$external = $display->fee_shortcode( array( 'event_id' => 31 ) );
\check( false !== strpos( $external, 'External fee' ) && false !== strpos( $external, '$25.50' ), 'external fee renders its formatted amount' );
\check( false !== strpos( $external, 'payments.example.test/fundraiser' ), 'external fee renders its safe link' );
\check( false === strpos( $external, 'Registration fee' ) && false === strpos( $external, 'Sponsorship cost' ), 'external fee suppresses internal fundraiser prices' );
$GLOBALS['current_event_id'] = 31;
$widget = new class { public $field = 'registration_fee'; public function get_name() { return 'jet-listing-dynamic-field'; } public function get_settings_for_display() { return array( 'dynamic_field_post_meta_custom' => $this->field ); } };
\check( false !== strpos( $display->replace_external_fee_dynamic_fields( 'Entry: Free', $widget ), '$25.50' ), 'existing Elementor fee widget switches to the external fee' );
$widget->field = 'sponsorship_cost';
\check( '' === $display->replace_external_fee_dynamic_fields( 'Sponsorship: $100', $widget ), 'existing sponsorship widget is suppressed for external fees' );

$GLOBALS['event_meta'][ 31 ]['external_fee_enabled'] = 'false';
$internal = $display->fee_shortcode( array( 'event_id' => 31 ) );
\check( false !== strpos( $internal, 'Registration fee' ) && false !== strpos( $internal, '$10' ), 'internal registration fee remains available when external fees are disabled' );
\check( false !== strpos( $internal, 'Sponsorship cost' ) && false !== strpos( $internal, '$100' ), 'internal sponsorship cost remains available when external fees are disabled' );
}
