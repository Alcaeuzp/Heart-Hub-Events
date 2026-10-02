<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['actions'] = array();
	$GLOBALS['filters'] = array();
	$GLOBALS['shortcodes'] = array();
	function plugin_dir_path( $file ) { return dirname( $file ) . DIRECTORY_SEPARATOR; }
	function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/heart-hub-event-registration-manager/'; }
	function register_activation_hook( ...$args ) {}
	function register_deactivation_hook( ...$args ) {}
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['actions'][] = $hook; }
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['filters'][] = $hook; }
	function add_shortcode( $tag, $callback ) { $GLOBALS['shortcodes'][] = $tag; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function get_option( $key, $default = false ) { return $default; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function check( $value, $message ) { if ( ! $value ) throw new \Exception( $message ); echo "PASS $message\n"; }
	require dirname( __DIR__ ) . '/heart-hub-event-registration-manager.php';
	\HeartHub\EventRegistrations\Plugin::instance();
	check( 17 === count( array_unique( $GLOBALS['shortcodes'] ) ), 'plugin bootstrap registers all seventeen shortcodes' );
	check( in_array( 'hherm_events_calendar', $GLOBALS['shortcodes'], true ), 'plugin bootstrap registers the events calendar shortcode' );
	check( in_array( 'hherm_fundraisers_calendar', $GLOBALS['shortcodes'], true ), 'plugin bootstrap registers the fundraisers calendar shortcode' );
	foreach ( array( 'admin_menu', 'admin_enqueue_scripts', 'rest_api_init', 'admin_post_hherm_save_event', 'admin_post_hherm_save_attendance', 'admin_post_hherm_save_support_group_programme', 'admin_post_nopriv_hherm_support_group_register', 'admin_post_hherm_send_test_email', 'admin_post_hherm_resend_support_group_confirmation', 'hherm_retry_direct_email', 'hherm_recover_direct_email', 'hherm_continue_direct_email_recovery', 'template_redirect' ) as $hook ) {
		check( in_array( $hook, $GLOBALS['actions'], true ), "plugin bootstrap connects $hook" );
	}
	check( in_array( 'rest_post_dispatch', $GLOBALS['filters'], true ), 'plugin bootstrap connects REST no-cache filtering' );
}
