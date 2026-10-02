<?php
/** Fault-injection adapter for real email, audit and support-group code. No live mail/database. */
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	class WP_Error {
		private $code; private $message;
		public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	class Email_Test_Redirect extends \RuntimeException {}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
	function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
	function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function sanitize_hex_color( $value ) { return $value; }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_url( $value ) { return (string) $value; }
	function esc_url_raw( $value ) { return (string) $value; }
	function wp_kses_post( $value ) { return $value; }
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
	function wpautop( $value ) { return '<p>' . $value . '</p>'; }
	function wp_unslash( $value ) { return $value; }
	function wp_parse_args( $value, $defaults = array() ) { return array_merge( $defaults, (array) $value ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_cache_delete( ...$args ) {}
	function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $value, $autoload = null ) {
		if ( ! empty( $GLOBALS['fail_update'][ $key ] ) ) return false;
		$GLOBALS['options'][ $key ] = $value; return true;
	}
	function delete_option( $key ) {
		if ( ! empty( $GLOBALS['fail_delete'][ $key ] ) ) return false;
		unset( $GLOBALS['options'][ $key ] ); return true;
	}
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function admin_url( $path = '' ) { return home_url( '/wp-admin/' . $path ); }
	function add_query_arg( $args, $url, $third = null ) { if ( null !== $third ) { $args = array( $args => $url ); $url = $third; } return $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
	function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
	function current_datetime() { return new \DateTimeImmutable( 'now', wp_timezone() ); }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function wp_date( $format, $timestamp, $zone = null ) { return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone ?: wp_timezone() )->format( $format ); }
	function get_current_user_id() { return 1; }
	function current_user_can( $capability ) { return empty( $GLOBALS['deny_capability'] ); }
	function check_admin_referer( $action ) { $GLOBALS['nonce_actions'][] = $action; if ( ! empty( $GLOBALS['deny_nonce'] ) ) wp_die( 'Invalid nonce.' ); return true; }
	function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce; }
	function wp_die( $text, ...$args ) { throw new \RuntimeException( $text ); }
	function wp_safe_redirect( $url ) { throw new Email_Test_Redirect( $url ); }
	function add_action( ...$args ) { $GLOBALS['actions'][] = $args; }
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['cron'][ $hook ][ serialize( $args ) ] ?? false; }
	function wp_schedule_single_event( $time, $hook, $args = array(), $wp_error = false ) {
		if ( ! empty( $GLOBALS['reject_cron'] ) ) return $wp_error ? new WP_Error( 'schedule_failed' ) : false;
		$GLOBALS['cron'][ $hook ][ serialize( $args ) ] = $time; return true;
	}
	function wp_schedule_event( $time, $recurrence, $hook ) { return wp_schedule_single_event( $time, $hook ); }
	function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) { unset( $GLOBALS['cron'][ $hook ][ serialize( $args ) ] ); return 1; }
	function wp_unschedule_hook( $hook, $wp_error = false ) { unset( $GLOBALS['cron'][ $hook ] ); return 1; }
	function wp_mail( $to, $subject, $body, $headers ) {
		$GLOBALS['mail'][] = compact( 'to', 'subject', 'body', 'headers' );
		if ( isset( $GLOBALS['mail_hook'] ) ) { $hook = $GLOBALS['mail_hook']; unset( $GLOBALS['mail_hook'] ); $hook(); }
		if ( ! empty( $GLOBALS['throw_mail'] ) ) throw new \RuntimeException( 'Transport exception.' );
		return empty( $GLOBALS['fail_mail'] ) && ! in_array( $to, $GLOBALS['fail_to'] ?? array(), true );
	}
	function get_posts( $args ) { return array_keys( $GLOBALS['posts'] ); }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function get_the_title( $id ) { return $GLOBALS['titles'][ $id ] ?? 'Community workshop'; }
	function get_permalink( $id ) { return home_url( '/events/' . $id ); }
	function email_check( $value, $label ) { if ( ! $value ) throw new \RuntimeException( $label ); echo "PASS $label\n"; }
	function email_private( $object, $method, ...$args ) { $reflection = new \ReflectionMethod( $object, $method ); $reflection->setAccessible( true ); return $reflection->invokeArgs( $object, $args ); }
	function email_redirect( $callback ) { try { $callback(); } catch ( Email_Test_Redirect $error ) { return $error->getMessage(); } throw new \RuntimeException( 'Expected redirect.' ); }
	function email_blocked( $callback ) { try { $callback(); } catch ( \RuntimeException $error ) { return ! ( $error instanceof Email_Test_Redirect ); } return false; }
	function email_jobs() { return array_filter( $GLOBALS['options'], static function ( $name ) { return 0 === strpos( $name, 'hherm_direct_email_job_' ); }, ARRAY_FILTER_USE_KEY ); }
	function email_job_for( $audit_type ) {
		foreach ( email_jobs() as $name => $job ) if ( $job['audit_type'] === $audit_type ) return array( substr( $name, strlen( 'hherm_direct_email_job_' ) ), $job );
		throw new \RuntimeException( 'Missing job: ' . $audit_type );
	}
	function email_due( $key ) { $name = 'hherm_direct_email_job_' . $key; $GLOBALS['options'][ $name ]['due_at'] = time() - 1; return $GLOBALS['options'][ $name ]; }
	function email_reset() {
		$GLOBALS['options'] = array( 'admin_email' => 'admin@example.test' );
		$GLOBALS['mail'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['fail_update'] = array(); $GLOBALS['fail_delete'] = array();
		foreach ( array( 'fail_mail', 'throw_mail', 'fail_to', 'mail_hook', 'reject_cron', 'audit_read_hook', 'deny_capability', 'deny_nonce' ) as $key ) unset( $GLOBALS[ $key ] );
		$GLOBALS['posts'] = array( 42 => (object) array( 'ID' => 42, 'post_type' => 'events', 'post_status' => 'publish', 'post_content' => '' ) );
		$GLOBALS['meta'] = array( 42 => array( 'end_date__time' => time() - 60 ) ); $GLOBALS['titles'] = array();
		$GLOBALS['wpdb'] = new Email_Test_Database();
	}
	final class Email_Test_Database {
		public $prefix = 'wp_'; public $options = 'wp_options'; public $last_error = '';
		public $logs = array(); public $rows = array(); public $fail_audit_read = false; public $fail_applicant_read = false; public $fail_applicant_update = false;
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
		public function insert( $table, $data, $formats = null ) { $data['id'] = count( $this->logs ) + 1; $this->logs[] = $data; return 1; }
		public function get_row( $query, $mode = null ) {
			list( $sql, $args ) = $query; $this->last_error = '';
			if ( 'wp_hherm_audit_log' === $args[0] ) {
				if ( $this->fail_audit_read ) { $this->last_error = 'Audit unavailable.'; return null; }
				$result = null;
				foreach ( array_reverse( $this->logs ) as $row ) if ( $row['registration_id'] === $args[1] && $row['event_type'] === $args[2] ) { $result = $row; break; }
				if ( isset( $GLOBALS['audit_read_hook'] ) ) { $hook = $GLOBALS['audit_read_hook']; unset( $GLOBALS['audit_read_hook'] ); $hook(); }
				return $result;
			}
			if ( $this->fail_applicant_read ) { $this->last_error = 'Applicant unavailable.'; return null; }
			$row = $this->rows[ $args[1] ] ?? null;
			return $row && $row['programme_id'] === $args[2] ? $row : null;
		}
		public function get_var( $query ) {
			list( $sql, $args ) = $query;
			$result = null;
			foreach ( $this->logs as $row ) if ( $row['registration_id'] === $args[1] && $row['event_type'] === $args[2] && $row['status'] === $args[3] ) { $result = 1; break; }
			if ( isset( $GLOBALS['audit_read_hook'] ) ) { $hook = $GLOBALS['audit_read_hook']; unset( $GLOBALS['audit_read_hook'] ); $hook(); }
			return $result;
		}
		public function get_col( $query ) {
			$args = $query[1]; $prefix = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), rtrim( $args[0], '%' ) );
			$names = array_filter( array_keys( $GLOBALS['options'] ), static function ( $name ) use ( $prefix, $args ) { return 0 === strpos( $name, $prefix ) && strcmp( $name, $args[1] ) > 0; } );
			sort( $names, SORT_STRING ); return array_slice( $names, 0, 100 );
		}
		public function get_results( $query, $mode = null ) {
			list( $sql, $args ) = $query; $this->last_error = '';
			if ( 'wp_hherm_audit_log' === $args[0] ) return array_values( array_filter( $this->logs, static function ( $row ) use ( $args ) { return $row['registration_id'] === $args[1] && $row['id'] > $args[2] && in_array( $row['event_type'], array( $args[3], $args[4] ), true ); } ) );
			return array_values( array_filter( $this->rows, static function ( $row ) use ( $args ) { return $row['programme_id'] === $args[1] && 'interest' !== $row['application_type'] && 'interest' !== $row['status']; } ) );
		}
		public function update( $table, $data, $where, ...$formats ) {
			if ( 'wp_hherm_support_group_applications' === $table && $this->fail_applicant_update ) return false;
			$rows =& $this->rows;
			if ( 'wp_hherm_audit_log' === $table ) $rows =& $this->logs;
			$count = 0;
			foreach ( $rows as &$row ) if ( ! array_diff_assoc( $where, $row ) && array_diff_assoc( $data, $row ) ) { $row = array_merge( $row, $data ); $count++; }
			return $count;
		}
		public function delete( $table, $where, ...$formats ) { $count = 0; foreach ( $this->rows as $id => $row ) if ( ! array_diff_assoc( $where, $row ) ) { unset( $this->rows[ $id ] ); $count++; } return $count; }
		public function query( $query ) {
			list( $sql, $args ) = $query;
			if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_options' ) ) {
				if ( array_key_exists( $args[0], $GLOBALS['options'] ) ) return 0;
				$GLOBALS['options'][ $args[0] ] = unserialize( $args[1] ); return 1;
			}
			if ( 0 === strpos( $sql, 'DELETE FROM wp_options' ) ) {
				if ( isset( $GLOBALS['options'][ $args[0] ] ) && maybe_serialize( $GLOBALS['options'][ $args[0] ] ) === $args[1] ) { unset( $GLOBALS['options'][ $args[0] ] ); return 1; } return 0;
			}
			if ( false !== strpos( $sql, 'DELETE FROM %i' ) ) return $this->delete( $args[0], array( 'id' => $args[1], 'programme_id' => $args[2] ) );
			throw new \RuntimeException( 'Unexpected SQL in email fixture: ' . $sql );
		}
	}
	email_reset();
}
namespace HeartHub\EventRegistrations {
	class Plugin { public const CAPABILITY = 'manage_heart_hub_event_registrations'; }
	class Settings {
		public $values = array( 'email_mode' => 'send', 'notification_email' => 'team@example.test' ); public $templates = array(); public $feedback_enabled = true;
		public function get( $key, $fallback = '' ) { return $this->values[ $key ] ?? $fallback; }
		public function email( $key, $fallback = '' ) { return $this->templates[ $key ] ?? $fallback; }
		public function automation_enabled( $type ) { return $this->feedback_enabled; }
		public function automation_delay( $type ) { return 0; }
		public function feedback_send_window() { return DAY_IN_SECONDS; }
	}
	class CCT_Repository {
		public $items = array(); public $read_hook; public $updates = array();
		public function get( $id ) { $result = $this->items[ $id ] ?? new \WP_Error( 'hherm_not_found', 'Missing record.' ); if ( $this->read_hook ) { $hook = $this->read_hook; $this->read_hook = null; $hook(); } return $result; }
		public function attendance_list( $id ) { return array_values( $this->items ); }
		public function update_feedback( $id, $feedback, $score ) { $this->updates[] = array( $id, $feedback, $score ); return true; }
	}
	class Public_Page_Theme {}
	class Event_Public_Display { public static function normalise_date_status( $current, $legacy ) { return 'confirmed'; } }
	require dirname( __DIR__, 2 ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__, 2 ) . '/includes/class-audit-log.php';
	require dirname( __DIR__, 2 ) . '/includes/class-email-service.php';
	require dirname( __DIR__, 2 ) . '/includes/class-support-groups.php';
}
