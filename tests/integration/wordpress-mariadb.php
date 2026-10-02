<?php
/** Native WordPress/MariaDB integration fixture. Disposable Docker site only. */
use HeartHub\EventRegistrations\Audit_Log;
use HeartHub\EventRegistrations\Email_Service;
use HeartHub\EventRegistrations\Mutation_Lock;
use HeartHub\EventRegistrations\Plugin;
use HeartHub\EventRegistrations\Settings;
use HeartHub\EventRegistrations\Support_Groups;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'HHERM_INTEGRATION_TEST' ) ) throw new RuntimeException( 'Requires WP-CLI and HHERM_INTEGRATION_TEST=1 on an isolated database.' );
if ( ! class_exists( Support_Groups::class ) ) throw new RuntimeException( 'Activate the Heart Hub plugin first.' );
final class HHERM_Integration_Redirect extends RuntimeException {}
final class HHERM_Integration_Die extends RuntimeException {}

final class HHERM_Support_WordPress_Test {
	private $passed = 0;
	private $failed = 0;
	private $groups;
	private $audit;
	private $email;
	private $run;
	private $programme;
	private $post_number = 0;
	private $mail_attempts = 0;
	private $saved_options = array();
	private $applicant_ids = array();
	private $audit_ids = array();
	private $checks = array();

	public function run(): void {
		global $wpdb;
		$this->run = 'integration-' . bin2hex( random_bytes( 5 ) );
		$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! $administrators ) throw new RuntimeException( 'An administrator is required.' );
		wp_set_current_user( $administrators[0]->ID );
		get_role( 'administrator' )->add_cap( Plugin::CAPABILITY );
		foreach ( array( Settings::OPTION, Support_Groups::OPTION_PROGRAMMES, Support_Groups::OPTION_SETTINGS ) as $name ) $this->saved_options[$name] = get_option( $name, null );
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), array( 'email_mode' => 'log', 'notification_email' => 'staff@example.test' ) ) );
		$this->programme = array( 'id' => $this->run, 'name' => 'Integration cohort', 'sessions' => array() );
		for ( $i = 1; $i <= 6; $i++ ) $this->programme['sessions'][] = array( 'date' => gmdate( 'Y-m-d', time() + ( 30 + $i ) * DAY_IN_SECONDS ), 'start_time' => '10:00', 'end_time' => '11:00' );
		update_option( Support_Groups::OPTION_PROGRAMMES, array( $this->programme ), false );
		update_option( Support_Groups::OPTION_SETTINGS, array( 'registration_programme_id' => $this->run ), false );
		$settings = new Settings();
		$this->audit = new Audit_Log();
		$this->email = new Email_Service( $settings, $this->audit );
		$this->groups = new Support_Groups( $settings, $this->email );
		$redirect = static function ( $url ) { throw new HHERM_Integration_Redirect( $url ); };
		$die = static function () { return static function ( $message ) { throw new HHERM_Integration_Die( is_wp_error( $message ) ? $message->get_error_message() : wp_strip_all_tags( (string) $message ) ); }; };
		$mail = function () { $this->mail_attempts++; return true; };
		add_filter( 'wp_redirect', $redirect, -9999 );
		add_filter( 'wp_die_handler', $die, 9999 );
		add_filter( 'pre_wp_mail', $mail, 9999 );
		$previous_errors = $wpdb->suppress_errors( true );
		try {
			WP_CLI::line( 'WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . '; database ' . $wpdb->db_version() );
			$this->schema_tests();
			$this->save_tests();
			$this->submission_tests();
			$this->deletion_tests();
			$this->ledger_read_tests();
			$this->lock_tests();
			$this->check( 0 === $this->mail_attempts, 'Log-only support workflow never attempts outbound mail' );
		} catch ( Throwable $error ) {
			$this->check( false, 'Unhandled ' . get_class( $error ) . ': ' . $error->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect, -9999 ); remove_filter( 'wp_die_handler', $die, 9999 ); remove_filter( 'pre_wp_mail', $mail, 9999 );
			foreach ( $this->saved_options as $name => $value ) { if ( null === $value ) delete_option( $name ); else update_option( $name, $value ); }
			foreach ( array_unique( $this->applicant_ids ) as $id ) $wpdb->delete( Support_Groups::table(), array( 'id' => $id ), array( '%d' ) );
			foreach ( array_unique( $this->audit_ids ) as $id ) $wpdb->delete( Audit_Log::table(), array( 'id' => $id ), array( '%d' ) );
			$wpdb->suppress_errors( $previous_errors );
			$report = array( 'timestamp_utc' => gmdate( 'c' ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->db_version(), 'passed' => $this->passed, 'failed' => $this->failed, 'checks' => $this->checks, 'limitations' => array( 'JetEngine/JetFormBuilder are not installed or exercised by this support fixture.', 'Outbound mail is intercepted and normal sends use Log only mode.' ) );
			file_put_contents( '/results/wordpress-mariadb.json', wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		}
		WP_CLI::line( sprintf( 'SUPPORT INTEGRATION: %d passed; %d failed. JetEngine is not installed or exercised.', $this->passed, $this->failed ) );
		if ( $this->failed ) throw new RuntimeException( 'Support integration checks failed.' );
	}

	private function check( bool $condition, string $message ): void {
		if ( $condition ) $this->passed++; else $this->failed++;
		$this->checks[] = array( 'passed' => $condition, 'message' => $message );
		WP_CLI::line( ( $condition ? 'PASS ' : 'FAIL ' ) . $message );
	}
	private function action( string $method, array $post ): array {
		$_POST = $post;
		$_REQUEST = $post;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( ++$this->post_number % 200 + 1 );
		try { $this->groups->{$method}(); } catch ( HHERM_Integration_Redirect $result ) { parse_str( (string) wp_parse_url( $result->getMessage(), PHP_URL_QUERY ), $query ); return $query; }
		throw new RuntimeException( $method . ' did not redirect.' );
	}
	private function insert( array $overrides = array() ): array {
		global $wpdb;
		$row = array_merge( array( 'programme_id' => $this->run, 'first_name' => 'Integration', 'last_name' => 'Applicant', 'email' => $this->run . '-' . bin2hex( random_bytes( 4 ) ) . '@example.test', 'phone' => '0400000000', 'attended_before' => 0, 'reason' => 'Synthetic sensitive support reason', 'application_type' => 'registration', 'status' => 'pending', 'email_status' => '', 'internal_email_status' => '', 'created_at' => current_time( 'mysql' ) ), $overrides );
		if ( false === $wpdb->insert( Support_Groups::table(), $row ) ) throw new RuntimeException( 'Fixture insert failed: ' . $wpdb->last_error );
		$row['id'] = (int) $wpdb->insert_id; $this->applicant_ids[] = $row['id']; return $row;
	}
	private function applicant( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Support_Groups::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
	private function log( int $id, string $event, string $status, array $details ): void {
		global $wpdb;
		if ( ! $this->audit->write( $id, $event, $status, $details ) ) throw new RuntimeException( 'Audit fixture insert failed.' );
		$this->audit_ids[] = (int) $wpdb->insert_id;
	}
	private function collect_logs( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE registration_id = %d ORDER BY id', Audit_Log::table(), $id ), ARRAY_A );
		foreach ( $rows as $row ) $this->audit_ids[] = (int) $row['id']; return $rows;
	}
	private function sql_failure( string $needle, callable $operation ) {
		$filter = static function ( $sql ) use ( $needle ) { return false !== strpos( $sql, $needle ) ? 'SELECT HHERM_INJECTED_FAILURE()' : $sql; };
		add_filter( 'query', $filter, 9999 );
		try { return $operation(); } finally { remove_filter( 'query', $filter, 9999 ); }
	}
	private function schema_tests(): void {
		global $wpdb;
		$table = Support_Groups::table();
		$this->check( Support_Groups::install(), 'Native dbDelta installs/verifies the support schema' );
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		$this->check( in_array( 'internal_email_status', $columns, true ) && in_array( 'application_type', $columns, true ), 'Real table contains delivery and application-type columns' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX programme_email_type, ADD UNIQUE KEY programme_email_type (programme_id(36), email(90), application_type(20))', $table ) );
		update_option( Support_Groups::SCHEMA_OPTION, '4' );
		$result = $this->sql_failure( 'DROP INDEX programme_email_type, ADD UNIQUE KEY programme_email_type (programme_id, email, application_type)', static function () { return Support_Groups::install(); } );
		$prefix = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'programme_email_type' ), ARRAY_A );
		$this->check( ! $result && '4' === get_option( Support_Groups::SCHEMA_OPTION ) && array_filter( array_column( $prefix, 'Sub_part' ) ), 'Failed atomic prefix-index replacement preserves the prior key and schema marker' );
		$result = Support_Groups::install();
		$full = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'programme_email_type' ), ARRAY_A );
		$this->check( $result && '5' === get_option( Support_Groups::SCHEMA_OPTION ) && ! array_filter( array_column( $full, 'Sub_part' ) ), 'Native migration replaces prefix uniqueness with complete values before marking schema 5' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY programme_email (programme_id(36), email(90))', $table ) );
		delete_option( Support_Groups::SCHEMA_OPTION );
		$result = $this->sql_failure( 'DROP INDEX `programme_email`', static function () { return Support_Groups::install(); } );
		$this->check( ! $result && ! get_option( Support_Groups::SCHEMA_OPTION ), 'Failed real ALTER TABLE leaves schema version pending' );
		$this->check( Support_Groups::install() && ! $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'programme_email' ) ), 'Retry removes the old unique key after verifying its replacement' );
	}
	private function save_post( string $name ): array {
		return array( 'programme_id' => $this->run, 'programme_name' => $name, 'session_date' => array_column( $this->programme['sessions'], 'date' ), 'session_start_time' => array_column( $this->programme['sessions'], 'start_time' ), 'session_end_time' => array_column( $this->programme['sessions'], 'end_time' ), 'use_for_registration' => 'true', '_wpnonce' => wp_create_nonce( 'hherm_save_support_group_programme' ) );
	}
	private function save_tests(): void {
		$result = $this->action( 'save_programme', $this->save_post( 'Integration saved cohort' ) );
		$this->check( 'saved' === ( $result['hherm_notice'] ?? '' ) && 'Integration saved cohort' === $this->groups->selected_programme( $this->run )['name'], 'Programme handler persists through native options and reads back correctly' );
		$result = $this->action( 'save_programme', $this->save_post( 'Integration saved cohort' ) );
		$this->check( 'saved' === ( $result['hherm_notice'] ?? '' ), 'Unchanged native option save is accepted' );
		$result = $this->sql_failure( "WHERE `option_name` = 'hherm_support_group_programmes'", function () { return $this->action( 'save_programme', $this->save_post( 'Must not persist' ) ); } );
		$this->check( 'action-failed' === ( $result['hherm_notice'] ?? '' ) && 'Integration saved cohort' === $this->groups->selected_programme( $this->run )['name'], 'Real programme-option SQL failure preserves saved values' );
	}
	private function submit( string $email, array $overrides = array() ): array {
		global $wpdb;
		$post = array_merge( array( 'programme_id' => $this->run, 'return_url' => home_url( '/' ), 'hherm_support_group_nonce' => wp_create_nonce( 'hherm_support_group_register_' . $this->run ), 'first_name' => 'Public', 'last_name' => 'Applicant', 'email' => $email, 'phone' => '0400000000', 'attended_before' => 'no', 'reason' => 'Synthetic public support reason' ), $overrides );
		$result = $this->action( 'submit_registration', $post );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id DESC LIMIT 1', Support_Groups::table(), strtolower( $email ) ), ARRAY_A );
		if ( is_array( $row ) ) { $this->applicant_ids[] = (int) $row['id']; $this->collect_logs( (int) $row['id'] ); }
		return array( $result, $row );
	}
	private function submission_tests(): void {
		list( $result, $row ) = $this->submit( $this->run . '-ordinary@example.test' );
		$this->check( 'pending' === ( $result['hherm_support_group_result'] ?? '' ) && is_array( $row ) && 'logged' === $row['email_status'] && 'logged' === $row['internal_email_status'], 'Public submission persists applicant and both delivery states in MariaDB' );
		list( $duplicate ) = $this->submit( $this->run . '-ordinary@example.test' );
		$this->check( 'duplicate' === ( $duplicate['hherm_support_group_result'] ?? '' ), 'Real public handler rejects an exact duplicate email' );
		$approval_post = array( 'programme_id' => $this->run, 'applicant_id' => $row['id'], '_wpnonce' => wp_create_nonce( 'hherm_confirm_support_group_applicant_' . $this->run . '_' . $row['id'] ) );
		$approval = $this->action( 'confirm_applicant', $approval_post );
		$approved = $this->applicant( (int) $row['id'] );
		$delivery_count = count( $this->collect_logs( (int) $row['id'] ) );
		$this->check( 'confirmed' === ( $approval['hherm_notice'] ?? '' ) && 'confirmed' === $approved['status'] && 'logged' === $approved['email_status'], 'Native nonce-protected approval persists its status and confirmation delivery' );
		$this->action( 'confirm_applicant', $approval_post );
		$this->check( $delivery_count === count( $this->collect_logs( (int) $row['id'] ) ), 'Repeated native approval does not create another confirmation delivery' );
		$approval_post['_wpnonce'] = 'invalid';
		$rejected = false;
		try { $this->action( 'confirm_applicant', $approval_post ); } catch ( HHERM_Integration_Die $error ) { $rejected = true; }
		$this->check( $rejected && $delivery_count === count( $this->collect_logs( (int) $row['id'] ) ), 'Real WordPress rejects an invalid approval nonce without a new email' );
		$name = str_repeat( '界', 40 ); $reason = str_repeat( '界', 1700 );
		list( $result, $unicode ) = $this->submit( $this->run . '-unicode@example.test', array( 'first_name' => $name, 'reason' => $reason ) );
		$this->check( 'pending' === ( $result['hherm_support_group_result'] ?? '' ) && is_array( $unicode ) && $name === $unicode['first_name'] && $reason === $unicode['reason'], 'Unicode fields below character limits are stored losslessly' );
		list( $result, $bounded ) = $this->submit( $this->run . '-bounded@example.test', array( 'first_name' => str_repeat( '界', 101 ), 'reason' => str_repeat( '界', 5001 ) ) );
		$this->check( 'pending' === ( $result['hherm_support_group_result'] ?? '' ) && is_array( $bounded ) && str_repeat( '界', 100 ) === $bounded['first_name'] && str_repeat( '界', 5000 ) === $bounded['reason'], 'Character limits truncate overlong Unicode safely at complete characters' );
		list( $result, $array_row ) = $this->submit( $this->run . '-array@example.test', array( 'email' => array( 'unexpected' ) ) );
		$this->check( 'invalid' === ( $result['hherm_support_group_result'] ?? '' ) && ! $array_row, 'Array-valued public email is rejected without a PHP type error or insertion' );
		$oversized = str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 60 ) . '.example.test';
		list( $result, $oversized_row ) = $this->submit( $oversized );
		$this->check( (bool) is_email( $oversized ) && strlen( $oversized ) > 190 && 'invalid' === ( $result['hherm_support_group_result'] ?? '' ) && ! $oversized_row, 'Valid-format email beyond the database field limit is rejected before insertion' );
		$email_a = str_repeat( 'a', 60 ) . '@' . str_repeat( 'd', 50 ) . '.example.test';
		$email_b = str_repeat( 'a', 60 ) . '@' . str_repeat( 'd', 49 ) . 'e.example.test';
		$this->check( (bool) is_email( $email_a ) && (bool) is_email( $email_b ) && substr( $email_a, 0, 90 ) === substr( $email_b, 0, 90 ), 'Long-address boundary examples are valid and share the first 90 characters' );
		list( $result_a, $row_a ) = $this->submit( $email_a ); list( $result_b, $row_b ) = $this->submit( $email_b );
		$this->check( 'pending' === ( $result_a['hherm_support_group_result'] ?? '' ) && 'pending' === ( $result_b['hherm_support_group_result'] ?? '' ) && $row_a && $row_b && $row_a['id'] !== $row_b['id'], 'Distinct valid emails sharing a 90-character prefix can both register' );
	}
	private function deletion_tests(): void {
		global $wpdb;
		$row = $this->insert(); $this->email->send_support_group_submission( $row, $this->programme ); $this->collect_logs( $row['id'] );
		$this->log( $row['id'], 'capacity', 'reserved', array( 'event_id' => 991234, 'party' => 2 ) );
		$this->log( $row['id'], 'internal_notification_email', 'sent', array( 'source' => 'event', 'event_id' => 991234, 'to' => 'event-person@example.test' ) );
		$event_id = end( $this->audit_ids );
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Audit_Log::table(), $event_id ), ARRAY_A );
		$post = array( 'programme_id' => $this->run, 'applicant_id' => $row['id'], 'confirm_delete' => 'yes', '_wpnonce' => wp_create_nonce( 'hherm_delete_support_group_applicant_' . $this->run . '_' . $row['id'] ) );
		$result = $this->action( 'delete_applicant', $post ); $logs = $this->collect_logs( $row['id'] );
		$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Audit_Log::table(), $event_id ), ARRAY_A );
		$this->check( ! $this->applicant( $row['id'] ) && 'applicant-deleted' === ( $result['hherm_notice'] ?? '' ), 'Applicant deletion removes its actual MariaDB row' );
		$this->check( false === strpos( wp_json_encode( $logs ), $row['email'] ) && false === strpos( wp_json_encode( $logs ), $row['reason'] ), 'Deletion removes support personal payloads from the actual audit table' );
		$this->check( $before === $after && $this->audit->has_status( $row['id'], 'capacity', 'reserved' ), 'Numeric ID collisions preserve real event payload and capacity history' );
		$ambiguous = $this->insert();
		$this->log( $ambiguous['id'], 'internal_notification_email', 'logged', array( 'programme_id' => $this->run, 'html' => 'Ambiguous historical payload' ) );
		$post['applicant_id'] = $ambiguous['id']; $post['_wpnonce'] = wp_create_nonce( 'hherm_delete_support_group_applicant_' . $this->run . '_' . $ambiguous['id'] );
		$result = $this->action( 'delete_applicant', $post );
		$this->check( 'action-failed' === ( $result['hherm_notice'] ?? '' ) && (bool) $this->applicant( $ambiguous['id'] ), 'Ambiguous historical logs block deletion without removing unrelated data' );
		$wpdb->delete( Audit_Log::table(), array( 'id' => end( $this->audit_ids ) ), array( '%d' ) );
		$legacy = $this->insert( array( 'application_type' => 'interest', 'status' => 'interest' ) );
		$legacy_status = $this->insert( array( 'application_type' => 'registration', 'status' => 'interest' ) );
		$unicode_interest = $this->insert( array( 'application_type' => 'interest', 'status' => 'interest', 'reason' => str_repeat( '🌱', 121 ) ) );
		$future = $this->insert( array( 'programme_id' => 'future-support-group', 'application_type' => 'interest', 'status' => 'interest' ) );
		$post = array( 'programme_id' => $this->run, 'confirm_delete' => 'yes', '_wpnonce' => wp_create_nonce( 'hherm_delete_support_group_programme_' . $this->run ) );
		$result = $this->sql_failure( 'DELETE FROM `' . Support_Groups::table() . '` WHERE id =', function () use ( $post ) { return $this->action( 'delete_programme', $post ); } );
		$this->check( 'action-failed' === ( $result['hherm_notice'] ?? '' ) && (bool) $this->groups->selected_programme( $this->run ) && (bool) $this->applicant( $ambiguous['id'] ), 'Real applicant-delete SQL failure preserves access to remaining records' );
		$result = $this->action( 'delete_programme', $post );
		$this->check( 'deleted' === ( $result['hherm_notice'] ?? '' ) && ! $this->groups->selected_programme( $this->run ) && (bool) $this->applicant( $legacy['id'] ) && (bool) $this->applicant( $legacy_status['id'] ) && (bool) $this->applicant( $future['id'] ), 'Real programme deletion retains legacy interests and the future pool' );
		$_GET = array( 'programme_id' => $this->run, 'applicant_id' => $legacy_status['id'] );
		ob_start(); $this->groups->render_editor(); $html = ob_get_clean(); $_GET = array();
		$this->check( false !== strpos( $html, 'Back to expressions of interest' ) && false !== strpos( $html, esc_html( $legacy_status['email'] ) ), 'Real WordPress renderer keeps legacy-interest details accessible after cohort deletion' );
		$render = new ReflectionMethod( $this->groups, 'render_interest_applicants' ); $render->setAccessible( true );
		ob_start(); $render->invoke( $this->groups ); $html = ob_get_clean();
		$this->check( false !== strpos( $html, str_repeat( '🌱', 117 ) . '…' ), 'Interest-list preview truncates emoji at complete characters' );
	}
	private function ledger_read_tests(): void {
		global $wpdb;
		$row = $this->insert();
		$this->check( array() === $this->audit->latest_entry_checked( $row['id'], 'capacity' ), 'Strict ledger read distinguishes a genuinely absent database row' );
		$this->log( $row['id'], 'capacity', 'reserved', array( 'event_id' => 991235, 'party' => 3 ) );
		$entry = $this->audit->latest_entry_checked( $row['id'], 'capacity' );
		$this->check( is_array( $entry ) && 'reserved' === $entry['status'] && 3 === $entry['details']['party'], 'Strict ledger read returns persisted operational history' );
		$failed = $this->sql_failure( 'SELECT status, details FROM', function () use ( $row ) { return $this->audit->latest_entry_checked( $row['id'], 'capacity' ); } );
		$this->check( is_wp_error( $failed ) && 'hherm_audit_read_failed' === $failed->get_error_code() && false === strpos( $failed->get_error_message(), 'HHERM_INJECTED_FAILURE' ), 'Actual ledger SELECT failure returns a safe error rather than an absent reservation' );
		$legacy = $this->sql_failure( 'SELECT status, details FROM', function () use ( $row ) { return $this->audit->latest_entry( $row['id'], 'capacity' ); } );
		$this->check( array() === $legacy, 'Noncritical legacy ledger wrapper preserves its array contract on database failure' );
		$wpdb->update( Audit_Log::table(), array( 'details' => '{broken JSON' ), array( 'id' => end( $this->audit_ids ) ) );
		$this->check( is_wp_error( $this->audit->latest_entry_checked( $row['id'], 'capacity' ) ), 'Corrupted operational details fail closed rather than becoming an empty history' );
	}
	private function lock_tests(): void {
		global $wpdb;
		$name = 'hherm_support_lock_' . $this->run; delete_option( $name );
		$first = Mutation_Lock::acquire( $name );
		$this->check( is_string( $first ) && Mutation_Lock::owns( $name, $first ), 'Native option lock establishes token ownership' );
		$this->check( is_wp_error( Mutation_Lock::acquire( $name ) ), 'Native unique option key rejects another live owner' );
		Mutation_Lock::release( $name, 'wrong-owner' );
		$this->check( Mutation_Lock::owns( $name, $first ), 'Wrong-token release cannot remove a native lock' );
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array( 'token' => $first, 'expires_at' => time() - 5 ) ) ), array( 'option_name' => $name ) );
		$second = Mutation_Lock::acquire( $name ); Mutation_Lock::release( $name, $first );
		$this->check( is_string( $second ) && $second !== $first && Mutation_Lock::owns( $name, $second ), 'Expired native lock is reclaimed without allowing stale-owner deletion' );
		$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$replacement = array( 'token' => bin2hex( random_bytes( 16 ) ), 'expires_at' => time() + 300 ); $interleave = true;
		$filter = static function ( $query ) use ( $name, $replacement, $connection, &$interleave, $wpdb ) {
			if ( $interleave && false !== strpos( $query, 'DELETE FROM ' . $wpdb->options ) && false !== strpos( $query, $name ) ) { $interleave = false; $connection->update( $wpdb->options, array( 'option_value' => maybe_serialize( $replacement ) ), array( 'option_name' => $name ) ); }
			return $query;
		};
		add_filter( 'query', $filter, 9999 );
		try { Mutation_Lock::release( $name, $second ); } finally { remove_filter( 'query', $filter, 9999 ); }
		$this->check( ! $interleave && Mutation_Lock::owns( $name, $replacement['token'] ), 'Atomic SQL compare-delete preserves a replacement from a second database connection' );
		Mutation_Lock::release( $name, $replacement['token'] );
		$this->check( false === get_option( $name, false ), 'Native owner release removes its temporary lock' );
	}
}
( new HHERM_Support_WordPress_Test() )->run();
