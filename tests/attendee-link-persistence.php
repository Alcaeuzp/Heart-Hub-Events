<?php
/** Isolated fault injection for attendee link generation and revocation. */
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	class LinkRedirect extends \RuntimeException {}
	function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) {
		if ( ! empty( $GLOBALS['fail_update'] ) ) return false;
		$GLOBALS['options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		if ( ! empty( $GLOBALS['fail_delete'] ) ) return false;
		unset( $GLOBALS['options'][ $name ] );
		return true;
	}
	function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish' ); }
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $key ] ?? ''; }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function current_user_can( $cap ) { return true; }
	function check_admin_referer( ...$args ) { return true; }
	function get_current_user_id() { return 1; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function home_url( $path ) { return 'https://example.test' . $path; }
	function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function wp_safe_redirect( $url ) { throw new LinkRedirect( $url ); }
	function check( $condition, $message ) { if ( ! $condition ) throw new \RuntimeException( $message ); echo "PASS $message\n"; }
	function redirect_result( $operation ) {
		try { $operation(); } catch ( LinkRedirect $redirect ) { parse_str( parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $args ); return $args; }
		throw new \RuntimeException( 'Expected redirect.' );
	}
}
namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return $default; } }
	class CCT_Repository {}
	class Audit_Log { public $entries = array(); public function write( ...$args ) { $this->entries[] = $args; return true; } }
	class Capacity_Manager {}
	class Public_Page_Theme { public function __construct( ...$args ) {} }
	class Plugin { public const CAPABILITY = 'manage_heart_hub_event_registrations'; }
	class Attendance_Manager { public const PAGE_SLUG = 'hherm-attendance'; }
	require dirname( __DIR__ ) . '/includes/class-checkin-manager.php';
	require dirname( __DIR__ ) . '/includes/class-registration-self-service.php';
	$settings = new Settings(); $audit = new Audit_Log(); $repository = new CCT_Repository();
	$checkin = new Checkin_Manager( $settings, $repository, $audit );
	$GLOBALS['options'] = array(); $GLOBALS['meta'] = array( 'start_date' => time() + 3600, 'end_date__time' => time() + 7200 );
	$_POST = array( 'event_id' => 10 );
	$GLOBALS['fail_update'] = true;
	$result = \redirect_result( array( $checkin, 'create' ) );
	\check( isset( $result['checkin_error'] ) && ! isset( $result['checkin_notice'] ) && ! $audit->entries, 'failed QR token save reports an error without a created audit or success notice' );
	$GLOBALS['fail_update'] = false;
	$result = \redirect_result( array( $checkin, 'create' ) );
	$record = $GLOBALS['options']['hherm_checkin_event_10'];
	\check( 'created' === $result['checkin_notice'] && strlen( $record['token'] ) === 48, 'healthy QR token save creates a persisted usable link' );
	$GLOBALS['fail_delete'] = true; $audit->entries = array();
	$result = \redirect_result( array( $checkin, 'revoke' ) );
	\check( isset( $result['checkin_error'] ) && $record === $GLOBALS['options']['hherm_checkin_event_10'] && ! $audit->entries, 'failed revocation preserves the old link and does not claim it was revoked' );
	$GLOBALS['fail_delete'] = false; $GLOBALS['fail_update'] = true;
	$result = \redirect_result( array( $checkin, 'revoke' ) );
	\check( isset( $result['checkin_error'] ) && ! isset( $GLOBALS['options']['hherm_checkin_event_10'] ), 'failed replacement reports that the old QR link was revoked without inventing a new link' );
	$manage = new Registration_Self_Service( $settings, $repository, $audit, new Capacity_Manager(), new Public_Page_Theme() );
	\check( '' === $manage->manage_url( array( '_ID' => 5, 'event_id' => 10 ) ), 'failed management token save returns no link for email rendering' );
	$GLOBALS['fail_update'] = false;
	$url = $manage->manage_url( array( '_ID' => 5, 'event_id' => 10 ) ); parse_str( parse_url( $url, PHP_URL_QUERY ), $args );
	\check( hash( 'sha256', $args['hherm_manage_token'] ) === $GLOBALS['options']['hherm_registration_manage_5']['token_hash'], 'healthy management link matches the persisted bearer hash' );
}
