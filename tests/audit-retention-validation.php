<?php
/** Support retention, fault-injection and concurrent-mutation regression tests. No live database. */
namespace {
    $audit_root = sys_get_temp_dir() . '/hherm-retention-' . bin2hex( random_bytes( 6 ) ) . '/';
    mkdir( $audit_root . 'wp-admin/includes', 0777, true );
    file_put_contents( $audit_root . 'wp-admin/includes/upgrade.php', '<?php // Isolated dbDelta fixture.' );
    register_shutdown_function( static function () use ( $audit_root ) {
        unlink( $audit_root . 'wp-admin/includes/upgrade.php' );
        rmdir( $audit_root . 'wp-admin/includes' ); rmdir( $audit_root . 'wp-admin' ); rmdir( $audit_root );
    } );
    define( 'ABSPATH', $audit_root );
    define( 'ARRAY_A', 'ARRAY_A' );
    define( 'DAY_IN_SECONDS', 86400 );
    define( 'MINUTE_IN_SECONDS', 60 );
    define( 'HOUR_IN_SECONDS', 3600 );
    class AuditRedirect extends \RuntimeException {}
    class AuditResponse extends \RuntimeException {}
    class WP_Error {
        private $message;
        public function __construct( $code, $message, $data = null ) { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    function absint( $v ) { return abs( (int) $v ); }
    function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
    function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
    function is_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ); }
    function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
    function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
    function sanitize_hex_color( $v ) { return $v; }
    function esc_url_raw( $v ) { return (string) $v; }
    function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
    function esc_attr( $v ) { return esc_html( $v ); }
    function esc_url( $v ) { return $v; }
    function wp_kses_post( $v ) { return $v; }
    function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
    function wp_unslash( $v ) { return $v; }
    function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
    function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
    function add_query_arg( $args, $url, $third = null ) {
        if ( null !== $third ) { $args = array( $args => $url ); $url = $third; }
        return $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
    }
    function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
    function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
    function update_option( $key, $value, $autoload = null ) {
        if ( isset( $GLOBALS['option_hook'] ) ) { $hook = $GLOBALS['option_hook']; unset( $GLOBALS['option_hook'] ); $hook( $key, $value ); }
        if ( $key === ( $GLOBALS['fail_option'] ?? '' ) || ( $GLOBALS['options'][ $key ] ?? null ) === $value ) return false;
        $GLOBALS['options'][ $key ] = $value; return true;
    }
    function add_option( $key, $value, $deprecated = '', $autoload = false ) {
        if ( array_key_exists( $key, $GLOBALS['options'] ) ) return false;
        $GLOBALS['options'][ $key ] = $value;
        if ( isset( $GLOBALS['lock_hook'] ) ) { $hook = $GLOBALS['lock_hook']; unset( $GLOBALS['lock_hook'] ); $hook(); }
        return true;
    }
    function wp_cache_delete( $key, $group = '' ) {}
    function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
    function wp_mail( $to, $subject, $body, $headers ) {
        if ( ! empty( $GLOBALS['throw_mail'] ) ) throw new \RuntimeException( 'Injected transport exception' );
        $GLOBALS['mail'][] = compact( 'to', 'subject', 'body', 'headers' );
        if ( isset( $GLOBALS['mail_hook'] ) ) { $hook = $GLOBALS['mail_hook']; unset( $GLOBALS['mail_hook'] ); $hook(); }
        return empty( $GLOBALS['fail_mail'] );
    }
    function wp_next_scheduled( ...$args ) { return false; }
    function wp_schedule_single_event( ...$args ) { return true; }
    function wp_clear_scheduled_hook( ...$args ) { return 0; }
    function wp_nonce_field( $action, $name = '_wpnonce' ) {}
    function mysql2date( $format, $value ) { return $value; }
    function checked( $left, $right = true ) { if ( $left === $right ) echo 'checked'; }
    function dbDelta( $sql ) { $GLOBALS['wpdb']->ddl = $sql; }
    function current_time( $format ) { return '2026-09-27 10:15:00'; }
    function current_datetime() { return new \DateTimeImmutable( '2026-09-27 10:15:00', wp_timezone() ); }
    function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
    function wp_date( $format, $timestamp, $timezone = null ) { return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
    function get_current_user_id() { return 1; }
    function current_user_can( $cap ) { return empty( $GLOBALS['deny_capability'] ); }
    function check_admin_referer( $action ) { if ( ! empty( $GLOBALS['deny_nonce'] ) ) wp_die( 'Invalid nonce' ); return true; }
    function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce; }
    function wp_validate_redirect( $url, $default ) { return $url; }
    function wp_safe_redirect( $url ) { throw new AuditRedirect( $url ); }
    function wp_send_json_success( $data ) { throw new AuditResponse( json_encode( $data ) ); }
    function wp_send_json_error( $data, $status = null ) { throw new AuditResponse( json_encode( $data ) ); }
    function wp_die( $text, ...$args ) { throw new \RuntimeException( $text ); }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    function wp_salt( $scheme ) { return 'local-audit-salt'; }
    function get_transient( $key ) { return false; }
    function set_transient( $key, $value, $expiry ) { return true; }
    function audit_check( $condition, $text ) { if ( ! $condition ) throw new \RuntimeException( $text ); echo "PASS $text\n"; }
    function has_text( $text, $needle ) { return false !== strpos( $text, $needle ); }
    function audit_redirect( $callback ) { try { $callback(); } catch ( AuditRedirect $redirect ) { return $redirect->getMessage(); } throw new \RuntimeException( 'Expected a redirect' ); }
    function audit_submit( $callback ) { try { $callback(); } catch ( AuditResponse $response ) { return json_decode( $response->getMessage(), true ); } throw new \RuntimeException( 'Expected JSON' ); }
    function audit_blocked( $callback ) { try { $callback(); } catch ( \RuntimeException $error ) { return ! ( $error instanceof AuditRedirect ) && ! ( $error instanceof AuditResponse ); } return false; }
    function audit_private( $object, $method, ...$args ) { $reflection = new \ReflectionMethod( $object, $method ); $reflection->setAccessible( true ); return $reflection->invokeArgs( $object, $args ); }
    class AuditDatabase {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public $last_error = '';
        public $rows = array();
        public $logs = array();
        public $insert_id = 0;
        public $fail_delete = false;
        public $zero_delete = false;
        public $fail_audit_update = false;
        public $fail_applicant_update = false;
        public $fail_audit_read = false;
        public $fail_delete_read = false;
        public $delete_reads = 0;
        public $ddl = '';
        public $columns = array();
        public $index = array();
        public $old_index = false;
        public $fail_drop = false;
        private $log_id = 0;
        public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
        public function get_col( $query ) { return $this->columns; }
        public function insert( $table, $data, $formats ) {
            if ( 'wp_hherm_audit_log' === $table ) { $data['id'] = ++$this->log_id; $this->logs[ $data['id'] ] = $data; return 1; }
            $data['id'] = ++$this->insert_id;
            $this->rows[ $data['id'] ] = $data;
            return 1;
        }
        public function update( $table, $data, $where, ...$args ) {
            $audit = 'wp_hherm_audit_log' === $table;
            if ( $audit ? $this->fail_audit_update : $this->fail_applicant_update ) return false;
            $rows =& $this->rows;
            if ( $audit ) $rows =& $this->logs;
            $changed = 0;
            foreach ( $rows as &$row ) if ( ! array_diff_assoc( $where, $row ) && array_diff_assoc( $data, $row ) ) { $row = array_merge( $row, $data ); $changed++; }
            return $changed;
        }
        private function applicant_rows( $sql, $args ) {
            $rows = array_values( $this->rows );
            if ( has_text( $sql, 'programme_id = %s' ) ) $rows = array_filter( $rows, static function ( $row ) use ( $args ) { return $row['programme_id'] === $args[1]; } );
            if ( has_text( $sql, 'application_type <> %s' ) ) $rows = array_filter( $rows, static function ( $row ) { return 'interest' !== $row['application_type'] && 'interest' !== $row['status']; } );
            if ( has_text( $sql, 'application_type = %s OR status = %s' ) ) $rows = array_filter( $rows, static function ( $row ) { return 'interest' === $row['application_type'] || 'interest' === $row['status']; } );
            return array_values( $rows );
        }
        public function get_var( $query ) {
            list( $sql, $args ) = $query;
            if ( has_text( $sql, 'SHOW INDEX' ) ) return $this->old_index ? 'wp_hherm_support_group_applications' : null;
            $rows = $this->applicant_rows( $sql, $args );
            if ( has_text( $sql, 'COUNT(*)' ) ) return count( $rows );
            if ( has_text( $sql, 'email = %s' ) ) foreach ( $rows as $row ) if ( $row['email'] === $args[2] && $row['application_type'] === $args[3] ) return $row['id'];
            return null;
        }
        public function get_results( $query, $mode ) {
            list( $sql, $args ) = $query; $this->last_error = '';
            if ( has_text( $sql, 'SHOW INDEX' ) ) return $this->index;
            if ( 'wp_hherm_audit_log' === $args[0] ) {
                if ( $this->fail_audit_read ) { $this->last_error = 'Injected read failure'; return null; }
                $rows = array_filter( $this->logs, static function ( $row ) use ( $args ) { return $row['registration_id'] === $args[1] && $row['id'] > $args[2] && in_array( $row['event_type'], array( 'support_customer_email', 'internal_notification_email' ), true ); } );
                return array_slice( array_values( $rows ), 0, 100 );
            }
            $rows = $this->applicant_rows( $sql, $args );
            if ( has_text( $sql, 'SELECT id FROM' ) ) {
                $this->delete_reads++;
                if ( $this->fail_delete_read ) { $this->last_error = 'Injected read failure'; return null; }
                usort( $rows, static function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
                return array_slice( $rows, 0, 100 );
            }
            usort( $rows, static function ( $a, $b ) { return $b['id'] <=> $a['id']; } );
            return array_slice( $rows, $args[ count( $args ) - 1 ], $args[ count( $args ) - 2 ] );
        }
        public function get_row( $query, $mode ) {
            $args = $query[1];
            $row = $this->rows[ $args[1] ] ?? null;
            return $row && $row['programme_id'] === $args[2] ? $row : null;
        }
        public function delete( $table, $where, $formats ) {
            if ( $this->fail_delete ) return false;
            $count = 0;
            foreach ( $this->rows as $id => $row ) if ( ! array_diff_assoc( $where, $row ) ) { unset( $this->rows[ $id ] ); $count++; }
            return $count;
        }
        public function query( $query ) {
            list( $sql, $args ) = $query;
            $this->last_error = '';
            if ( has_text( $sql, 'INSERT IGNORE INTO wp_options' ) ) {
                if ( array_key_exists( $args[0], $GLOBALS['options'] ) ) return 0;
                $GLOBALS['options'][ $args[0] ] = unserialize( $args[1], array( 'allowed_classes' => false ) );
                if ( isset( $GLOBALS['lock_hook'] ) ) { $hook = $GLOBALS['lock_hook']; unset( $GLOBALS['lock_hook'] ); $hook(); }
                return 1;
            }
            if ( has_text( $sql, 'DELETE FROM wp_options' ) ) {
                if ( isset( $GLOBALS['options'][ $args[0] ] ) && maybe_serialize( $GLOBALS['options'][ $args[0] ] ) === $args[1] ) { unset( $GLOBALS['options'][ $args[0] ] ); return 1; }
                return 0;
            }
            if ( has_text( $sql, 'ALTER TABLE' ) ) { if ( $this->fail_drop ) return false; $this->old_index = false; return 0; }
            if ( $this->fail_delete ) return false;
            if ( $this->zero_delete ) return 0;
            $row = $this->rows[ $args[1] ] ?? null;
            if ( $row && $row['programme_id'] === $args[2] && 'interest' !== $row['application_type'] && 'interest' !== $row['status'] ) { unset( $this->rows[ $args[1] ] ); return 1; }
            return 0;
        }
    }
}
namespace HeartHub\EventRegistrations {
    class Plugin { public const CAPABILITY = 'manage_heart_hub_event_registrations'; }
    class Settings {
        public $mode = 'log';
        public function get( $key, $default = '' ) { return array( 'email_mode' => $this->mode, 'notification_email' => 'team@example.test' )[ $key ] ?? $default; }
        public function email( $key, $default = '' ) { return $default; }
    }
    require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
    require dirname( __DIR__ ) . '/includes/class-mutation-lock.php';
    require dirname( __DIR__ ) . '/includes/class-audit-log.php';
    require dirname( __DIR__ ) . '/includes/class-email-service.php';
    require dirname( __DIR__ ) . '/includes/class-public-form-token.php';
    require dirname( __DIR__ ) . '/includes/class-support-groups.php';
    $GLOBALS['wpdb'] = new \AuditDatabase();
    $wpdb = $GLOBALS['wpdb'];
    $settings = new Settings();
    $service = new Email_Service( $settings, new Audit_Log() );
    $groups = new Support_Groups( $settings, $service );
    $programme = array( 'id' => 'completed-cohort', 'name' => 'September cohort', 'sessions' => array( array( 'date' => '2026-09-01', 'start_time' => '10:00', 'end_time' => '11:00' ) ) );
    $GLOBALS['options'] = array( 'hherm_support_group_programmes' => array( $programme ), 'hherm_support_group_settings' => array( 'registration_programme_id' => 'completed-cohort' ), 'admin_email' => 'admin@example.test' );
    $applicant = array( 'id' => 1, 'programme_id' => 'completed-cohort', 'application_type' => 'registration', 'first_name' => 'Audit', 'last_name' => 'Example', 'email' => 'audit@example.test', 'phone' => '0400000000', 'reason' => 'Synthetic sensitive support reason', 'attended_before' => 0, 'status' => 'pending', 'created_at' => '2026-09-27 10:15:00' );
    $wpdb->rows[1] = $applicant;
    $applicant['email_status'] = '';
    $applicant['internal_email_status'] = '';
    $wpdb->rows[1] = $applicant;
    $service->send_support_group_submission( $applicant, $programme, 'https://example.test/wp-admin/?applicant_id=1' );
    $audit = new Audit_Log();
    $audit->write( 1, 'support_customer_email', 'logged', array( 'programme_id' => 'completed-cohort', 'to' => 'audit@example.test', 'html' => 'Legacy support email payload' ) );
    $audit->write( 1, 'capacity', 'reserved', array( 'event_id' => 88, 'party' => 2 ) );
    $audit->write( 1, 'internal_notification_email', 'sent', array( 'source' => 'event', 'event_id' => 88, 'to' => 'event-person@example.test', 'programme_id' => 'completed-cohort' ) );
    $event_logs = array_slice( $wpdb->logs, 3, null, true );
    $_POST = array( 'programme_id' => 'completed-cohort', 'applicant_id' => 1, 'confirm_delete' => 'yes' );
    \audit_redirect( array( $groups, 'delete_applicant' ) );
    \audit_check( ! isset( $wpdb->rows[1] ) && ! \has_text( json_encode( $wpdb->logs ), 'Synthetic sensitive support reason' ) && ! \has_text( json_encode( $wpdb->logs ), 'audit@example.test' ), 'applicant deletion erases support email recipient and sensitive body' );
    \audit_check( $event_logs === array_slice( $wpdb->logs, 3, null, true ), 'colliding event registration ledger and internal notification are unchanged' );
    \audit_check( 5 === count( $wpdb->logs ) && 'logged' === $wpdb->logs[1]['status'], 'support delivery history remains after personal-data redaction' );
    \audit_check( ! \has_text( $wpdb->logs[3]['details'], 'Legacy support email payload' ), 'known legacy support-customer emails are redacted without an explicit source field' );

    $wpdb->rows[1] = $applicant;
    $audit->write( 1, 'internal_notification_email', 'logged', array( 'programme_id' => 'completed-cohort', 'html' => 'ambiguous personal payload' ) );
    $url = \audit_redirect( array( $groups, 'delete_applicant' ) );
    \audit_check( isset( $wpdb->rows[1] ) && \has_text( $url, 'action-failed' ) && \has_text( json_encode( $wpdb->logs ), 'ambiguous personal payload' ), 'ambiguous legacy internal source fails closed without deleting applicant or payload' );
    array_pop( $wpdb->logs );
    $wpdb->fail_audit_update = true;
    $url = \audit_redirect( array( $groups, 'delete_applicant' ) );
    \audit_check( isset( $wpdb->rows[1] ) && \has_text( $url, 'action-failed' ), 'audit-redaction write failure prevents completed applicant deletion' );
    $wpdb->fail_audit_update = false;
    $wpdb->fail_audit_read = true;
    $url = \audit_redirect( array( $groups, 'delete_applicant' ) );
    \audit_check( isset( $wpdb->rows[1] ) && \has_text( $url, 'action-failed' ), 'audit-read failure prevents completed applicant deletion' );
    $wpdb->fail_audit_read = false;

    $wpdb->rows[1] = $applicant;
    $wpdb->fail_delete = true;
    $_POST = array( 'programme_id' => 'completed-cohort', 'confirm_delete' => 'yes' );
    $url = \audit_redirect( array( $groups, 'delete_programme' ) );
    \audit_check( isset( $wpdb->rows[1] ) && $groups->selected_programme( 'completed-cohort' ) && \has_text( $url, 'action-failed' ), 'failed applicant deletion keeps the programme and applicant accessible and reports failure' );
    $wpdb->fail_delete = false;
    $wpdb->fail_delete_read = true;
    $url = \audit_redirect( array( $groups, 'delete_programme' ) );
    \audit_check( isset( $wpdb->rows[1] ) && $groups->selected_programme( 'completed-cohort' ) && \has_text( $url, 'action-failed' ), 'failed deletion scan does not remove the programme' );
    $wpdb->fail_delete_read = false;
    $wpdb->zero_delete = true;
    $before_reads = $wpdb->delete_reads;
    $url = \audit_redirect( array( $groups, 'delete_programme' ) );
    \audit_check( $before_reads + 1 === $wpdb->delete_reads && \has_text( $url, 'action-failed' ), 'zero-row deletion stops with an error instead of looping' );
    $wpdb->zero_delete = false;

    $wpdb->rows = array();
    $wpdb->fail_delete = false;
    $GLOBALS['options']['hherm_support_group_programmes'] = array( $programme );
    $GLOBALS['options']['hherm_support_group_settings'] = array( 'registration_programme_id' => 'completed-cohort' );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array( 'programme_id' => 'completed-cohort', 'return_url' => 'https://example.test/', 'hherm_support_group_nonce' => 'valid', 'hherm_ajax' => '1', 'first_name' => 'Future', 'last_name' => 'Applicant', 'email' => 'future@example.test', 'attended_before' => 'no', 'reason' => 'Interested in the NEXT support group' );
    $result = \audit_submit( array( $groups, 'submit_registration' ) );
    $saved = reset( $wpdb->rows );
    \audit_check( 'interest' === $result['result'] && 'interest' === $saved['application_type'] && 'future-support-group' === $saved['programme_id'], 'post-cutoff interest belongs to the independent future-interest pool' );
    $wpdb->rows[20] = array_merge( $applicant, array( 'id' => 20, 'application_type' => 'interest', 'status' => 'interest', 'email' => 'legacy@example.test' ) );
    $wpdb->rows[21] = array_merge( $applicant, array( 'id' => 21, 'application_type' => 'registration', 'status' => 'interest', 'email' => 'legacy-status@example.test' ) );
    $wpdb->rows[22] = array_merge( $applicant, array( 'id' => 22 ) );
    $submission = $_POST;
    $GLOBALS['option_hook'] = static function ( $key, $value ) use ( $submission, $groups ) {
        $before = $_POST; $_POST = $submission;
        $result = \audit_submit( array( $groups, 'submit_registration' ) ); $_POST = $before;
        \audit_check( 'busy' === $result['result'], 'submission racing programme removal is rejected while its mutation lock is held' );
    };
    $_POST = array( 'programme_id' => 'completed-cohort', 'confirm_delete' => 'yes' );
    \audit_redirect( array( $groups, 'delete_programme' ) );
    \audit_check( isset( $wpdb->rows[ $saved['id'] ], $wpdb->rows[20], $wpdb->rows[21] ) && ! isset( $wpdb->rows[22] ), 'programme deletion keeps new and both legacy interest formats while removing registrations' );
    $_GET = array( 'programme_id' => 'completed-cohort', 'applicant_id' => 20 );
    ob_start(); $groups->render_editor(); $html = ob_get_clean();
    \audit_check( \has_text( $html, 'legacy@example.test' ) && \has_text( $html, 'Delete applicant' ), 'legacy interest remains viewable and manageable after its programme is removed' );
    $_GET['applicant_id'] = 21;
    ob_start(); $groups->render_editor(); $html = ob_get_clean();
    \audit_check( \has_text( $html, 'Back to expressions of interest' ) && \has_text( $html, '<dd>Expression of interest</dd>' ), 'legacy status-only interest displays its correct type and independent navigation' );
    $_POST = array( 'programme_id' => 'completed-cohort', 'applicant_id' => 21 );
    \audit_redirect( array( $groups, 'decline_applicant' ) );
    \audit_check( 'interest' === $wpdb->rows[21]['application_type'] && 'declined' === $wpdb->rows[21]['status'], 'declining a legacy interest preserves its interest identity' );
    $_GET = array();

    $GLOBALS['options']['hherm_support_group_programmes'] = array( $programme );
    $wpdb->rows[30] = array_merge( $applicant, array( 'id' => 30 ) );
    $_POST = array( 'programme_id' => 'completed-cohort', 'applicant_id' => 30 );
    $settings->mode = 'send';
    $GLOBALS['mail_hook'] = static function () use ( $groups ) {
        $before = $_POST; $_POST['confirm_delete'] = 'yes';
        \audit_check( \audit_blocked( array( $groups, 'delete_applicant' ) ), 'applicant deletion cannot race confirmation delivery and recreate personal audit data' );
        $_POST = $before;
    };
    $url = \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( 'confirmed' === $wpdb->rows[30]['status'] && 'sent' === $wpdb->rows[30]['email_status'] && \has_text( $url, 'confirmed' ), 'pending applicant approval persists confirmation and sends its email' );
    $mail_count = count( $GLOBALS['mail'] );
    \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( $mail_count === count( $GLOBALS['mail'] ), 'repeated confirmation does not send a duplicate email' );
    $wpdb->rows[31] = array_merge( $applicant, array( 'id' => 31 ) );
    $_POST['applicant_id'] = 31; $GLOBALS['fail_mail'] = true;
    $url = \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( 'confirmed' === $wpdb->rows[31]['status'] && 'failed' === $wpdb->rows[31]['email_status'] && \has_text( $url, 'confirmed-email-failed' ), 'failed email preserves approval and reports delivery failure' );
    unset( $GLOBALS['fail_mail'] ); $settings->mode = 'log';
    $wpdb->rows[33] = array_merge( $applicant, array( 'id' => 33 ) );
    $_POST['applicant_id'] = 33; $GLOBALS['throw_mail'] = true; $settings->mode = 'send';
    $url = \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( 'confirmed' === $wpdb->rows[33]['status'] && 'failed' === $wpdb->rows[33]['email_status'] && \has_text( $url, 'confirmed-email-failed' ), 'transport exception preserves approval and records failed delivery instead of crashing' );
    unset( $GLOBALS['throw_mail'] ); $settings->mode = 'log';
    $wpdb->rows[32] = array_merge( $applicant, array( 'id' => 32 ) );
    $_POST['applicant_id'] = 32; $wpdb->fail_applicant_update = true;
    $url = \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( 'pending' === $wpdb->rows[32]['status'] && \has_text( $url, 'action-failed' ), 'failed approval write does not send a confirmation or change pending status' );
    $wpdb->fail_applicant_update = false;
    $GLOBALS['deny_nonce'] = true;
    \audit_check( \audit_blocked( array( $groups, 'confirm_applicant' ) ) && 'pending' === $wpdb->rows[32]['status'], 'confirmation rejects an invalid nonce without mutation' );
    unset( $GLOBALS['deny_nonce'] );

    $wpdb->rows = array();
    for ( $i = 1; $i <= 1051; $i++ ) $wpdb->rows[$i] = array_merge( $applicant, array( 'id' => $i, 'application_type' => 'interest', 'status' => 'interest' ) );
    $page = \audit_private( $groups, 'interest_applicants', 22 );
    \audit_check( 1 === count( $page ) && 1 === $page[0]['id'], 'interest pagination reaches records beyond the old 1000-row cap' );
    $page = \audit_private( $groups, 'applicants_for', 'completed-cohort', 11 );
    \audit_check( 50 === count( $page ) && 551 === $page[0]['id'], 'programme pagination reaches records beyond the old 500-row cap' );
    $_GET['applicants_page'] = 22;
    ob_start(); \audit_private( $groups, 'render_interest_applicants' ); $html = ob_get_clean();
    \audit_check( \has_text( $html, '1051 total' ) && \has_text( $html, 'Page 22 of 22' ), 'paginated lists display actual totals and navigation' );
    $_GET = array();

    $_POST = array( 'programme_id' => 'completed-cohort', 'confirm_delete' => 'yes' );
    $GLOBALS['fail_option'] = 'hherm_support_group_programmes';
    $url = \audit_redirect( array( $groups, 'delete_programme' ) );
    \audit_check( $groups->selected_programme( 'completed-cohort' ) && \has_text( $url, 'action-failed' ), 'failed programme-option deletion reports failure and preserves access' );
    unset( $GLOBALS['fail_option'] );
    $_POST = $submission;
    $GLOBALS['lock_hook'] = static function () { $GLOBALS['options']['hherm_support_group_programmes'] = array(); };
    $result = \audit_submit( array( $groups, 'submit_registration' ) );
    \audit_check( 'closed' === $result['result'], 'programme existence is revalidated after obtaining the mutation lock' );

    $GLOBALS['options']['hherm_support_group_programmes'] = array( $programme );
    $_POST = array( 'programme_id' => 'completed-cohort', 'programme_name' => 'Changed name', 'session_date' => array(), 'session_start_time' => array(), 'session_end_time' => array(), 'use_for_registration' => 'true' );
    for ( $i = 1; $i <= 6; $i++ ) { $_POST['session_date'][] = '2026-10-0' . $i; $_POST['session_start_time'][] = '10:00'; $_POST['session_end_time'][] = '11:00'; }
    $GLOBALS['fail_option'] = 'hherm_support_group_programmes';
    $url = \audit_redirect( array( $groups, 'save_programme' ) );
    \audit_check( \has_text( $url, 'action-failed' ) && 'September cohort' === $groups->selected_programme( 'completed-cohort' )['name'], 'failed ordinary programme save preserves stored values and reports failure' );
    unset( $GLOBALS['fail_option'] );
    $url = \audit_redirect( array( $groups, 'save_programme' ) );
    $url = \audit_redirect( array( $groups, 'save_programme' ) );
    \audit_check( \has_text( $url, 'hherm_notice=saved' ) && 'Changed name' === $groups->selected_programme( 'completed-cohort' )['name'], 'saving unchanged programme values correctly treats update_option false as a no-op success' );

    $wpdb->rows = array();
    for ( $i = 1; $i <= 101; $i++ ) $wpdb->rows[$i] = array_merge( $applicant, array( 'id' => $i ) );
    $wpdb->rows[200] = array_merge( $applicant, array( 'id' => 200, 'application_type' => 'interest', 'status' => 'interest' ) );
    $before_reads = $wpdb->delete_reads;
    $_POST = array( 'programme_id' => 'completed-cohort', 'confirm_delete' => 'yes' );
    $url = \audit_redirect( array( $groups, 'delete_programme' ) );
    \audit_check( array( 200 ) === array_keys( $wpdb->rows ) && $wpdb->delete_reads === $before_reads + 2 && \has_text( $url, 'hherm_notice=deleted' ), 'programme deletion crosses its 100-row batch boundary and retains legacy interest' );
    for ( $i = 0; $i < 205; $i++ ) $audit->write( 7777, 'support_customer_email', 'logged', array( 'source' => 'support_group', 'programme_id' => 'completed-cohort', 'html' => 'Batch personal payload' ) );
    \audit_check( $audit->erase_support_applicant_personal_data( 7777, 'completed-cohort' ) && ! \has_text( json_encode( $wpdb->logs ), 'Batch personal payload' ), 'audit redaction crosses multiple 100-row batches without skipping retained ledger rows' );

    unset( $GLOBALS['options'][ Support_Groups::SCHEMA_OPTION ] );
    $wpdb->old_index = true;
    \audit_check( ! Support_Groups::install() && ! get_option( Support_Groups::SCHEMA_OPTION ) && $wpdb->old_index, 'failed schema creation neither marks the upgrade complete nor drops the legacy index' );
    $wpdb->columns = array( 'id', 'programme_id', 'first_name', 'last_name', 'email', 'phone', 'attended_before', 'reason', 'application_type', 'status', 'email_status', 'internal_email_status', 'created_at' );
    \audit_check( ! Support_Groups::install() && $wpdb->old_index, 'missing replacement unique index leaves the schema upgrade pending' );
    $wpdb->index = array_map( static function ( $column ) { return array( 'Column_name' => $column, 'Non_unique' => 0 ); }, array( 'programme_id', 'email', 'application_type' ) );
    $wpdb->fail_drop = true;
    \audit_check( ! Support_Groups::install() && ! get_option( Support_Groups::SCHEMA_OPTION ), 'failed legacy-index removal is retried instead of marking schema success' );
    $wpdb->fail_drop = false;
    \audit_check( Support_Groups::install() && '5' === get_option( Support_Groups::SCHEMA_OPTION ) && ! $wpdb->old_index, 'verified schema upgrade records its version only after successful migration' );
    \audit_check( str_repeat( '界', 40 ) === \audit_private( $groups, 'limit_text', str_repeat( '界', 40 ), 100 ) && str_repeat( '界', 100 ) === \audit_private( $groups, 'limit_text', str_repeat( '界', 101 ), 100 ), 'UTF-8 character limits preserve valid text and truncate safely with or without mbstring' );
    \audit_check( ! array_filter( array_keys( $GLOBALS['options'] ), static function ( $name ) { return 0 === strpos( $name, 'hherm_support_lock_' ); } ), 'successful, failed and contended operations release their own support locks' );
}
