<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'DAY_IN_SECONDS', 86400 );
	function wp_salt( $scheme ) { return 'test-salt'; }
	define( 'HHERM_URL', 'https://example.test/wp-content/plugins/heart-hub-event-registration-manager/' );
	define( 'HHERM_VERSION', '1.24.2' );
	$GLOBALS['hherm_options'] = array(
		'hherm_support_group_settings' => array( 'registration_programme_id' => 'next-programme' ),
		'hherm_support_group_programmes' => array(
			array(
				'id' => 'started-programme',
				'name' => 'Started programme',
				'sessions' => array(
					array( 'date' => '2026-08-20', 'start_time' => '17:30', 'end_time' => '18:30' ),
					array( 'date' => '2026-09-19', 'start_time' => '17:30', 'end_time' => '18:30' ),
				),
			),
			array(
				'id' => 'next-programme',
				'name' => 'Next programme',
				'sessions' => array(
					array( 'date' => '2026-09-20', 'start_time' => '14:00', 'end_time' => '15:00' ),
					array( 'date' => '2026-10-20', 'start_time' => '17:30', 'end_time' => '18:30' ),
					array( 'date' => '2026-11-20', 'start_time' => '17:30', 'end_time' => '18:30' ),
				),
			),
		),
	);
	function get_option( $key, $default = false ) { return $GLOBALS['hherm_options'][ $key ] ?? $default; }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function shortcode_atts( $defaults, $attributes ) { return array_merge( $defaults, (array) $attributes ); }
	function current_datetime() { return new \DateTimeImmutable( '2026-09-20 12:00:00', wp_timezone() ); }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	function wp_enqueue_style( ...$args ) {}
	function wp_enqueue_script( ...$args ) {}
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $value ) { return (string) $value; }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function get_permalink() { return 'https://example.test/support-groups/'; }
	function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="valid">'; }
	function add_shortcode( ...$args ) {}
	function add_action( ...$args ) {}
	function check( $value, $message ) { if ( ! $value ) throw new \Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	class Plugin { public const CAPABILITY = 'manage_heart_hub_event_registrations'; }
	class Settings { public function email( string $key, $fallback = '' ) { return $fallback; } public function get( string $key, $fallback = '' ) { return $fallback; } }
	require dirname( __DIR__ ) . '/includes/class-public-form-token.php';
	require dirname( __DIR__ ) . '/includes/class-support-groups.php';
	$support_groups = new Support_Groups( new Settings() );
	\check( 'Next programme' === $support_groups->programme_name_shortcode(), 'programme name shortcode returns the selected programme name' );
	\check( '20 September 2026' === $support_groups->programme_start_date_shortcode(), 'start date shortcode returns the selected programme first date' );
	\check( 'true' === $support_groups->registration_open_shortcode(), 'selected future programme keeps registration open before its first meeting' );
	\check( 'false' === $support_groups->registration_open_shortcode( array( 'programme_id' => 'started-programme' ) ), 'programme registration closes after its first meeting starts' );
	$html = $support_groups->schedule_shortcode();
	\check( false !== strpos( $html, '20 September' ), 'the selected programme includes a meeting later today' );
	\check( false !== strpos( $html, '20 October' ), 'the selected programme includes later meetings' );
	\check( false === strpos( $html, '19 September' ), 'past meeting dates are omitted from the public schedule' );
	$underway = $support_groups->schedule_shortcode( array( 'programme_id' => 'started-programme' ) );
	\check( false !== strpos( $underway, 'Current support group is underway.' ), 'a started programme replaces its date list with the underway message' );
	\check( false !== strpos( $underway, 'expression of interest' ), 'the underway message directs visitors to the expression-of-interest flow' );
	\check( false === strpos( $underway, '<time' ), 'a started programme no longer exposes its remaining dates' );
	\check( 'false' === $support_groups->registration_open_shortcode( array( 'programme_id' => 'missing' ) ), 'a missing programme safely closes registration' );
	$GLOBALS['hherm_options'][ Support_Groups::OPTION_PROGRAMMES ] = array();
	$GLOBALS['hherm_options'][ Support_Groups::OPTION_SETTINGS ] = array( 'registration_programme_id' => '' );
	$form = $support_groups->registration_form_shortcode();
	\check( false !== strpos( $form, 'value="future-support-group"' ) && false !== strpos( $form, 'Register expression of interest' ), 'support interest form remains available before a future programme exists' );
	\check( false !== strpos( $form, 'name="' . Public_Form_Token::FIELD . '"' ), 'support form carries a cache-tolerant form token' );
}
