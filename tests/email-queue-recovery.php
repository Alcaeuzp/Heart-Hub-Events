<?php
/** Bounded queue recovery against SQLite, including scheduler failures and stale generations. */
namespace {
	if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) { echo "SKIP email-queue-recovery: pdo_sqlite is required\n"; exit( 0 ); }
	define( 'ABSPATH', __DIR__ ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 );
	class Queue_Test_DB {
		public $options = 'wp_options'; public $pdo; public $enumerations = 0; public $rows_read = 0;
		public function __construct() { $this->pdo = new \PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION ); $this->pdo->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' ); }
		public function prepare( $sql, ...$args ) { return preg_replace_callback( '/%[sd]/', function ( $match ) use ( &$args ) { $value = array_shift( $args ); return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value ); }, $sql ); }
		public function query( $sql ) { return $this->pdo->exec( preg_replace( '/^INSERT IGNORE /', 'INSERT OR IGNORE ', $sql ) ); }
		public function get_var( $sql ) { $value = $this->pdo->query( $sql )->fetchColumn(); return false === $value ? null : $value; }
		public function get_col( $sql ) {
			++$this->enumerations;
			// MySQL implicitly treats backslash as LIKE's escape character; SQLite requires it explicitly.
			$sql = str_replace( ' AND option_name >', ' ESCAPE ' . $this->pdo->quote( '\\' ) . ' AND option_name >', $sql );
			$rows = $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_COLUMN ); $this->rows_read += count( $rows ); return $rows;
		}
		public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	}
	$GLOBALS['wpdb'] = new Queue_Test_DB(); $GLOBALS['cron'] = array(); $GLOBALS['option_reads'] = 0; $GLOBALS['hooks'] = array(); $GLOBALS['fail_schedule'] = false;
	function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
	function get_option( $name, $default = false ) { global $wpdb; ++$GLOBALS['option_reads']; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', $name ) ); if ( null === $value ) return $default; $decoded = @unserialize( $value ); return false === $decoded && 'b:0;' !== $value ? $value : $decoded; }
	function add_option( $name, $value, ...$unused ) { global $wpdb; return 1 === $wpdb->query( $wpdb->prepare( 'INSERT OR IGNORE INTO wp_options (option_name,option_value) VALUES (%s,%s)', $name, maybe_serialize( $value ) ) ); }
	function update_option( $name, $value, ...$unused ) { global $wpdb; return 1 === $wpdb->query( $wpdb->prepare( 'INSERT OR REPLACE INTO wp_options (option_name,option_value) VALUES (%s,%s)', $name, maybe_serialize( $value ) ) ); }
	function delete_option( $name ) { global $wpdb; return 1 === $wpdb->query( $wpdb->prepare( 'DELETE FROM wp_options WHERE option_name = %s', $name ) ); }
	function wp_cache_delete( ...$unused ) {}
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	class WP_Error { public function __construct( ...$args ) {} }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function add_action( $hook, $callback, ...$unused ) { $GLOBALS['hooks'][ $hook ] = $callback; }
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['cron'][ $hook ][ serialize( $args ) ] ?? false; }
	function wp_schedule_event( $at, $interval, $hook ) { return wp_schedule_single_event( $at, $hook ); }
	function wp_schedule_single_event( $at, $hook, $args = array(), ...$unused ) { if ( $GLOBALS['fail_schedule'] ) return false; $GLOBALS['cron'][ $hook ][ serialize( $args ) ] = $at; return true; }
	function wp_clear_scheduled_hook( $hook, $args = array(), ...$unused ) { unset( $GLOBALS['cron'][ $hook ][ serialize( $args ) ] ); }
	function wp_unschedule_hook( $hook, ...$unused ) { unset( $GLOBALS['cron'][ $hook ] ); }
	function check( $value, $message ) { if ( ! $value ) throw new \RuntimeException( $message ); echo "PASS $message\n"; }
}
namespace HeartHub\EventRegistrations {
	class Settings { const OPTION_AUTOMATION = 'hherm_email_automation'; }
	class CCT_Repository {}
	class Email_Service {}
	class Audit_Log { public $entries = array(); public function write( $id, $type, $status, $details ) { $this->entries[] = compact( 'id', 'type', 'status', 'details' ); } }
	require dirname( __DIR__ ) . '/includes/class-email-automation.php';
	$audit = new Audit_Log(); $automation = new Email_Automation( new Settings(), new CCT_Repository(), $audit, new Email_Service() ); $automation->register();
	\update_option( 'hherm_email_job_lock_migrated_v2', 1 );
	for ( $i = 1; $i <= 250; ++$i ) {
		$key = 'decision_' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT );
		\update_option( 'hherm_email_job_lock_job_' . $key, array( 'generation' => 'current', 'due_at' => time() + 600 ) );
		\wp_schedule_single_event( time() + 600, Email_Automation::CRON_HOOK, array( $key, 'current' ) );
	}
	$reads = $GLOBALS['option_reads'];
	call_user_func( $GLOBALS['hooks']['init'] );
	\check( 0 === $GLOBALS['wpdb']->enumerations && $reads === $GLOBALS['option_reads'], 'ordinary init does not enumerate or read any queued job' );
	\check( (bool) \wp_next_scheduled( Email_Automation::RECOVERY_HOOK ), 'init establishes periodic recovery' );
	$automation->ensure_scheduled();
	\check( 100 === $GLOBALS['wpdb']->rows_read && 'decision_0100' === \get_option( 'hherm_email_recovery_cursor' ), 'first maintenance batch visits only 100 jobs and persists its cursor' );
	\check( (bool) \wp_next_scheduled( 'hherm_continue_email_recovery' ), 'remaining work has a scheduled continuation' );
	$automation->ensure_scheduled();
	\check( 200 === $GLOBALS['wpdb']->rows_read && 'decision_0200' === \get_option( 'hherm_email_recovery_cursor' ), 'second batch advances without revisiting prior jobs' );
	$automation->ensure_scheduled();
	\check( 250 === $GLOBALS['wpdb']->rows_read && false === \get_option( 'hherm_email_recovery_cursor' ), 'final batch clears the cursor for the next maintenance cycle' );

	\wp_clear_scheduled_hook( Email_Automation::CRON_HOOK, array( 'decision_0001', 'current' ) );
	$GLOBALS['fail_schedule'] = true; $automation->ensure_scheduled();
	\check( is_array( \get_option( 'hherm_email_job_lock_job_decision_0001' ) ) && 'schedule_failed' === $audit->entries[0]['status'], 'scheduler rejection retains the job and records an actionable failure' );
	$GLOBALS['fail_schedule'] = false; \delete_option( 'hherm_email_recovery_cursor' ); $automation->ensure_scheduled();
	\check( (bool) \wp_next_scheduled( Email_Automation::CRON_HOOK, array( 'decision_0001', 'current' ) ), 'later maintenance recovers an unscheduled job after scheduler failure' );
	$automation->send_queued( 'decision_0001', 'obsolete' );
	\check( (bool) \wp_next_scheduled( Email_Automation::CRON_HOOK, array( 'decision_0001', 'current' ) ) && is_array( \get_option( 'hherm_email_job_lock_job_decision_0001' ) ), 'an obsolete cron generation cannot delete the current job' );
	\delete_option( 'hherm_email_job_lock_migrated_v2' );
	\delete_option( 'hherm_email_recovery_cursor' );
	\update_option( Email_Automation::QUEUE_OPTION, array( 'decision_0999' => array( 'due_at' => time() + 600 ) ) );
	$automation->ensure_scheduled();
	\check( (bool) \wp_next_scheduled( Email_Automation::CRON_HOOK, array( 'decision_0250', 'current' ) ), 'legacy migration preserves existing schedules beyond the current recovery batch' );
	\check( is_array( \get_option( 'hherm_email_job_lock_job_decision_0999' ) ) && ! \get_option( Email_Automation::QUEUE_OPTION ), 'legacy queue migration keeps the old job until its replacement is persisted' );

	$lock_name = 'hherm_email_job_lock_' . substr( hash( 'sha256', 'queue_recovery' ), 0, 32 );
	\add_option( $lock_name, array( 'token' => 'other-worker', 'created_at' => time(), 'expires_at' => time() + 600 ) );
	$before = $GLOBALS['wpdb']->enumerations; $automation->ensure_scheduled();
	\check( $before === $GLOBALS['wpdb']->enumerations && 'other-worker' === \get_option( $lock_name )['token'], 'overlapping recovery leaves the active worker lock and cursor alone' );
	Email_Automation::unschedule();
	\check( ! \wp_next_scheduled( Email_Automation::RECOVERY_HOOK ) && ! \wp_next_scheduled( 'hherm_continue_email_recovery' ) && ! \get_option( 'hherm_email_recovery_cursor' ), 'deactivation removes recovery schedules and cursor while preserving job records' );
}
