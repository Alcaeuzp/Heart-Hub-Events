<?php
// Run with: php -d extension=pdo_sqlite tests/decision-email.php (add -d extension_dir=... if needed).
// Exercises the real REST review, Email_Automation and Audit_Log SQL against SQLite.
namespace {
	if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
		echo "SKIP decision-email: pdo_sqlite is not loaded (php -d extension=pdo_sqlite tests/decision-email.php)\n";
		exit( 0 );
	}
	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	final class Test_Wpdb {
		public $prefix = 'wp_';
		public $options = 'wp_options';
		public $insert_id = 0;
		public $pdo;
		public function __construct() {
			$this->pdo = new PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$this->pdo->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' );
			$this->pdo->exec( 'CREATE TABLE wp_hherm_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, registration_id INTEGER, user_id INTEGER, event_type TEXT, status TEXT, details TEXT, created_at TEXT)' );
		}
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) $args = $args[0];
			$pdo = $this->pdo;
			return preg_replace_callback( '/%[ids]/', static function ( $match ) use ( &$args, $pdo ) {
				$value = array_shift( $args );
				if ( '%i' === $match[0] ) return '"' . str_replace( '"', '', (string) $value ) . '"';
				if ( '%d' === $match[0] ) return (string) (int) $value;
				return $pdo->quote( (string) $value );
			}, $query );
		}
		public function query( $sql ) { return $this->pdo->exec( preg_replace( '/^INSERT IGNORE /', 'INSERT OR IGNORE ', $sql ) ); }
		public function get_var( $sql ) { $value = $this->pdo->query( $sql )->fetchColumn(); return false === $value ? null : $value; }
		public function get_row( $sql, $output = null ) { $row = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC ); return $row ?: null; }
		public function get_col( $sql ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN ); }
		public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
		public function insert( $table, $data, $formats = null ) {
			$columns = implode( ', ', array_keys( $data ) );
			$values = implode( ', ', array_map( array( $this->pdo, 'quote' ), array_map( 'strval', array_values( $data ) ) ) );
			$result = $this->pdo->exec( "INSERT INTO {$table} ({$columns}) VALUES ({$values})" );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return $result;
		}
	}
	$GLOBALS['wpdb'] = new Test_Wpdb();

	function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
	function maybe_unserialize( $value ) { $data = @unserialize( (string) $value ); return false === $data && 'b:0;' !== $value ? $value : $data; }
	function get_option( $name, $default = false ) { global $wpdb; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', $name ) ); return null === $value ? $default : maybe_unserialize( $value ); }
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) { global $wpdb; return 1 === $wpdb->query( $wpdb->prepare( 'INSERT OR IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, %s)', $name, maybe_serialize( $value ), 'no' ) ); }
	function update_option( $name, $value, $autoload = null ) { global $wpdb; $wpdb->query( $wpdb->prepare( 'INSERT OR REPLACE INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, %s)', $name, maybe_serialize( $value ), 'no' ) ); return true; }
	function delete_option( $name ) { global $wpdb; return (bool) $wpdb->query( $wpdb->prepare( 'DELETE FROM wp_options WHERE option_name = %s', $name ) ); }
	function wp_cache_delete( ...$args ) { return true; }
	function wp_generate_uuid4() { return sprintf( '%s-%s-%s-%s-%s', bin2hex( random_bytes( 4 ) ), bin2hex( random_bytes( 2 ) ), bin2hex( random_bytes( 2 ) ), bin2hex( random_bytes( 2 ) ), bin2hex( random_bytes( 6 ) ) ); }
	function wp_next_scheduled( ...$args ) { return false; }
	function wp_schedule_single_event( ...$args ) { return true; }
	function wp_clear_scheduled_hook( ...$args ) { return 0; }
	function add_action( ...$args ) {}
	function add_filter( ...$args ) {}
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_email( $value ) { return trim( (string) $value ); }
	function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
	function get_current_user_id() { return 1; }
	function get_userdata( $id ) { return (object) array( 'display_name' => 'Staff' ); }
	function rest_ensure_response( $value ) { return $value; }
	function get_post( $id ) { return 10 === (int) $id ? (object) array( 'ID' => 10, 'post_type' => 'events', 'post_status' => 'publish', 'post_excerpt' => '', 'post_content' => '' ) : null; }
	function get_post_meta( $id, $key, $single = true ) { return ''; }
	function get_the_title( $id ) { return 'Workshop'; }
	function wp_timezone() { return new DateTimeZone( 'Australia/Perth' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	class WP_Error { private $code; public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; } public function get_error_code() { return $this->code; } public function get_error_message() { return (string) $this->code; } }
	class WP_REST_Request extends ArrayObject { public function has_param( $key ) { return $this->offsetExists( $key ); } public function get_param( $key ) { return $this[ $key ] ?? null; } public function get_header( $key ) { return null; } }
	function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	class Settings {
		const OPTION_AUTOMATION = 'hherm_email_automation';
		public $delay = 0;
		public function get( $key, $default = '' ) { return 'events_cpt' === $key ? 'events' : $default; }
		public function automation_enabled( $type ) { return true; }
		public function automation_delay( $type ) { return $this->delay; }
	}
	class CCT_Repository {
		public $items = array();
		public $read_errors = array();
		public $read_hook;
		public function get( int $id ) { if ( $this->read_hook ) { $hook = $this->read_hook; $this->read_hook = null; $hook(); } return isset( $this->read_errors[ $id ] ) ? new \WP_Error( $this->read_errors[ $id ] ) : ( $this->items[ $id ] ?? new \WP_Error( 'hherm_not_found' ) ); }
		public function update_review( int $id, string $status, string $notes ) { $this->items[ $id ]['registration_status'] = $status; $this->items[ $id ]['reviewed_date'] = gmdate( 'Y-m-d H:i:s' ) . '.' . microtime( true ); return $this->items[ $id ]; }
	}
	class Email_Service {
		public $delivered = array();
		private $audit;
		public $result = 'sent';
		public function __construct( Audit_Log $audit ) { $this->audit = $audit; }
		public function event_context( int $id ) { return array( 'id' => $id, 'title' => 'Workshop' ); }
		public function send_decision( array $item, array $event, string $status ): array {
			// Mirrors Email_Service::send(): the delivery outcome is recorded in the audit ledger.
			$this->audit->write( (int) $item['_ID'], 'decision_email', $this->result, array() );
			if ( 'sent' === $this->result ) $this->delivered[] = array( (int) $item['_ID'], $status );
			return array( 'status' => $this->result, 'retryable' => 'failed' === $this->result );
		}
	}
	require dirname( __DIR__ ) . '/includes/class-audit-log.php';
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-event-public-display.php';
	require dirname( __DIR__ ) . '/includes/class-capacity-manager.php';
	require dirname( __DIR__ ) . '/includes/class-email-automation.php';
	require dirname( __DIR__ ) . '/includes/class-rest-controller.php';

	$settings = new Settings();
	$audit = new Audit_Log();
	$repository = new CCT_Repository();
	$mail = new Email_Service( $audit );
	$automation = new Email_Automation( $settings, $repository, $audit, $mail );
	$controller = new REST_Controller( $repository, $automation, $audit, $settings, new Capacity_Manager( $settings, $audit ) );
	$review = function ( int $id, string $status ) use ( $controller ) {
		$key = 'approved' === $status ? 'approval_notes' : 'decline_reason';
		return $controller->review( new \WP_REST_Request( array( 'id' => $id, 'status' => $status, $key => '' ) ) );
	};

	$repository->items[1] = array( '_ID' => 1, 'event_id' => 10, 'email' => 'alex@example.test', 'registration_status' => 'pending', 'registration_date' => '2026-09-01 10:00:00' );
	$result = $review( 1, 'approved' );
	\check( ! is_wp_error( $result ) && array( array( 1, 'approved' ) ) === $mail->delivered, 'first approval sends its decision email' );

	// Attendee changes details through the manage link, which returns the registration to pending.
	$repository->items[1]['registration_status'] = 'pending';
	$result = $review( 1, 'declined' );
	\check( ! is_wp_error( $result ) && array( 1, 'declined' ) === end( $mail->delivered ) && 2 === count( $mail->delivered ), 're-review after resubmission sends the new decline email' );

	$again = $automation->send_decision( $repository->items[1], array( 'id' => 10 ), 'declined' );
	\check( 'skipped' === $again['status'] && 2 === count( $mail->delivered ), 'repeating the same decision does not email twice' );

	$repository->items[1]['registration_status'] = 'pending';
	$result = $review( 1, 'approved' );
	\check( 3 === count( $mail->delivered ) && array( 1, 'approved' ) === end( $mail->delivered ), 'a third review cycle is emailed as well' );

	// Legacy ledgers: a delivered email with no decision marker, or an older marker, stays delivered.
	$audit->write( 2, 'decision_email', 'sent', array() );
	$legacy = $automation->send_decision( array( '_ID' => 2, 'event_id' => 10, 'email' => 'b@example.test' ), array( 'id' => 10 ), 'approved' );
	\check( 'skipped' === $legacy['status'], 'legacy delivery without a decision marker is not resent' );
	$audit->write( 3, 'decision', 'approved', array() );
	$audit->write( 3, 'decision_email', 'logged', array() );
	$legacy = $automation->send_decision( array( '_ID' => 3, 'event_id' => 10, 'email' => 'c@example.test' ), array( 'id' => 10 ), 'approved' );
	\check( 'skipped' === $legacy['status'], 'logged delivery after the latest decision is not resent' );

	// Delivery states that are not final never block the next attempt.
	$audit->write( 4, 'decision', 'approved', array() );
	$audit->write( 4, 'decision_email', 'failed', array() );
	$audit->write( 4, 'decision_email', 'retry_scheduled', array() );
	\check( ! $audit->has_status_since( 4, 'decision_email', array( 'sent', 'logged', 'failed_terminal' ), 'decision' ), 'failed and retry entries do not count as delivered' );
	$audit->write( 4, 'decision_email', 'failed_terminal', array() );
	\check( $audit->has_status_since( 4, 'decision_email', array( 'sent', 'logged', 'failed_terminal' ), 'decision' ), 'terminal failure stops further automatic attempts for that decision' );
	\check( ! $audit->has_status_since( 4, 'decision_email', array(), 'decision' ), 'empty status list never matches' );
	\check( ! $audit->has_status_since( 99, 'decision_email', array( 'sent' ), 'decision' ), 'another registration ledger is not consulted' );

	// Cancellation idempotency is unchanged.
	$audit->write( 5, 'cancellation_email', 'sent', array() );
	$cancel = $automation->send_cancellation( array( '_ID' => 5, 'event_id' => 10, 'email' => 'd@example.test' ), array( 'id' => 10 ) );
	\check( 'skipped' === $cancel['status'], 'cancellation emails remain one per registration' );

	// A temporary read outage must not be mistaken for a deleted/stale registration.
	$settings->delay = 60;
	$repository->items[6] = array( '_ID' => 6, 'event_id' => 10, 'email' => 'retry@example.test', 'registration_status' => 'approved', 'reviewed_date' => '2026-09-27 12:00:00' );
	$automation->send_decision( $repository->items[6], array( 'id' => 10 ), 'approved' );
	$option = 'hherm_email_job_lock_job_decision_6';
	$job = \get_option( $option ); $job['due_at'] = time() - 1; \update_option( $option, $job );
	$before_delivery = count( $mail->delivered );
	$repository->read_errors[6] = 'hherm_read_failed';
	$automation->send_queued( 'decision_6', $job['generation'] );
	$retry = \get_option( $option );
	\check( is_array( $retry ) && 1 === $retry['attempts'] && $retry['generation'] !== $job['generation'] && count( $mail->delivered ) === $before_delivery, 'temporary repository read failure retains a retryable email job without sending stale data' );
	unset( $repository->read_errors[6] ); $retry['due_at'] = time() - 1; \update_option( $option, $retry );
	$automation->send_queued( 'decision_6', $retry['generation'] );
	\check( ! \get_option( $option ) && count( $mail->delivered ) === $before_delivery + 1 && array( 6, 'approved' ) === end( $mail->delivered ), 'queued decision delivers once after repository storage recovers' );
	$missing = array( '_ID' => 7, 'event_id' => 10, 'email' => 'deleted@example.test', 'registration_status' => 'approved' );
	$automation->send_decision( $missing, array( 'id' => 10 ), 'approved' );
	$job = \get_option( 'hherm_email_job_lock_job_decision_7' ); $job['due_at'] = time() - 1; \update_option( 'hherm_email_job_lock_job_decision_7', $job );
	$automation->send_queued( 'decision_7', $job['generation'] );
	\check( ! \get_option( 'hherm_email_job_lock_job_decision_7' ) && 'skipped_stale' === $audit->latest_status( 7, 'decision_email' ), 'a definitively missing registration still cancels its stale email job' );
	$repository->items[8] = array( '_ID' => 8, 'event_id' => 10, 'email' => 'guest@example.test', 'registration_status' => 'approved', 'reviewed_date' => '2026-09-27 12:00:00' );
	$automation->send_decision( $repository->items[8], array( 'id' => 10 ), 'approved' );
	$job_name = 'hherm_email_job_lock_job_decision_8'; $job = \get_option( $job_name ); $job['due_at'] = time() - 1; \update_option( $job_name, $job );
	$lock_name = 'hherm_email_job_lock_' . substr( hash( 'sha256', 'decision_8' ), 0, 32 );
	$repository->read_errors[8] = 'hherm_read_failed';
	$repository->read_hook = static function () use ( $lock_name, $job_name, $job ) {
		\update_option( $lock_name, array( 'token' => 'new-worker', 'expires_at' => time() + 600 ) );
		$job['generation'] = 'new-generation'; \update_option( $job_name, $job );
	};
	$automation->send_queued( 'decision_8', $job['generation'] );
	\check( 'new-generation' === \get_option( $job_name )['generation'] && 'new-worker' === \get_option( $lock_name )['token'], 'stale queue worker cannot replace a newer retry job after its storage read outlives the lease' );
}
