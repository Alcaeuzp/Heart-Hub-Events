<?php
namespace {
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function is_wp_error( $value ) { return false; }
function get_term_by( $field, $value, $taxonomy ) { return isset( $GLOBALS['terms'][ $value ] ) ? (object) array( 'term_id' => $GLOBALS['terms'][ $value ] ) : false; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $name, $default = false ) { return $default; }
function jet_engine() { return $GLOBALS['jet_engine']; }
function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}
namespace HeartHub\EventRegistrations {
class Settings { public function get( $key, $default = '' ) { return 'events_cpt' === $key ? 'events' : $default; } }
class Readiness_Test_Meta_Boxes {
	public $fields = array();
	public function get_fields_for_context( $context, $post_type ) { return 'post_type' === $context && 'events' === $post_type ? $this->fields : array(); }
}
require dirname( __DIR__ ) . '/includes/class-event-types.php';
require dirname( __DIR__ ) . '/includes/class-event-type-readiness.php';

$valid_fields = Event_Type_Readiness::required_fields();
\check( array() === Event_Type_Readiness::evaluate( array( 'workshop' => 11, 'fundraising' => 22 ), $valid_fields ), 'complete event-type configuration is ready' );

$issues = Event_Type_Readiness::evaluate( array( 'workshop' => 0, 'fundraising' => 0 ), array() );
$codes = array_column( $issues, 'code' );
\check( 12 === count( $issues ), 'all missing release requirements are reported' );
\check( in_array( 'missing_workshop_term', $codes, true ) && in_array( 'missing_fundraising_term', $codes, true ), 'missing Workshop and Fundraising terms are distinct' );
\check( in_array( 'missing_amount_raised', $codes, true ), 'missing fundraiser amount field reported' );
\check( in_array( 'missing_registration_fee', $codes, true ) && in_array( 'missing_sponsorship_cost', $codes, true ) && in_array( 'missing_raffle', $codes, true ), 'missing fundraising fields are distinct' );
\check( in_array( 'missing_co_hosted_event', $codes, true ) && in_array( 'missing_show_what_to_expect', $codes, true ), 'missing content toggle fields are distinct' );
\check( in_array( 'missing_registration_enabled', $codes, true ) && in_array( 'missing_show_remaining_spots', $codes, true ) && in_array( 'missing_allow_waitlist', $codes, true ) && in_array( 'missing_show_interest_contact_button', $codes, true ), 'missing registration toggle fields are distinct' );
$fundraising_issue = current( array_filter( $issues, static function ( $issue ) { return 'missing_fundraising_term' === $issue['code']; } ) );
\check( false !== strpos( $fundraising_issue['message'], 'fundraising-event' ), 'readiness guidance names the live fundraising-event slug' );

$invalid_fields = $valid_fields;
$invalid_fields['amount_raised'] = 'text';
$invalid_fields['registration_fee'] = 'TEXT';
$invalid_fields['sponsorship_cost'] = 'textarea';
$invalid_fields['raffle'] = 'checkbox';
$invalid_fields['registration_enabled'] = 'checkbox';
$issues = Event_Type_Readiness::evaluate( array( 'workshop' => 11, 'fundraising' => 22 ), $invalid_fields );
$codes = array_column( $issues, 'code' );
\check( ! in_array( 'invalid_registration_fee_type', $codes, true ), 'field type comparison is normalised' );
\check( in_array( 'invalid_sponsorship_cost_type', $codes, true ) && in_array( 'invalid_raffle_type', $codes, true ), 'incorrect fundraising field types are reported' );
\check( in_array( 'invalid_amount_raised_type', $codes, true ), 'amount raised must be numeric' );
\check( in_array( 'invalid_registration_enabled_type', $codes, true ), 'registration enabled must be a switcher' );

$meta_boxes = new Readiness_Test_Meta_Boxes();
$meta_boxes->fields = array(
	array( 'name' => 'amount_raised', 'type' => 'number' ),
	array( 'name' => 'registration_fee', 'type' => 'text' ),
	array( 'name' => 'sponsorship_cost', 'type' => 'text' ),
	array( 'name' => 'raffle', 'type' => 'switcher' ),
	array( 'name' => 'co_hosted_event', 'type' => 'switcher' ),
	array( 'name' => 'show_what_to_expect', 'type' => 'switcher' ),
	array( 'name' => 'registration_enabled', 'type' => 'switcher' ),
	array( 'name' => 'show_remaining_spots', 'type' => 'switcher' ),
	array( 'name' => 'allow_waitlist', 'type' => 'switcher' ),
	array( 'name' => 'show_interest_contact_button', 'type' => 'switcher' ),
);
$GLOBALS['jet_engine'] = (object) array( 'meta_boxes' => $meta_boxes );
$GLOBALS['terms'] = array( 'workshop' => 11, 'fundraising-event' => 22 );
\check( array() === ( new Event_Type_Readiness( new Settings() ) )->issues(), 'runtime checker reads JetEngine fields for the Events post type' );
}
