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
        if ( ! empty( $GLOBALS['race_mode'] ) ) \Fiber::suspend( array( 'phase' => 'mail_handoff', 'to' => $to ) );
        return empty( $GLOBALS['fail_mail'] );
    }
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
    function get_transient( $key ) { $snapshot = $GLOBALS['transients'][ $key ] ?? false; if ( ! empty( $GLOBALS['race_mode'] ) && 'hherm_feedback_cron_lock' === $key ) \Fiber::suspend( array( 'phase' => 'lock_read', 'snapshot' => $snapshot ) ); return $snapshot; }
    function set_transient( $key, $value, $expiry ) { $GLOBALS['transients'][ $key ] = $value; return true; }
    function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
    function get_posts( $args ) { return array( 42 ); }
    function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'events', 'post_status' => 'publish', 'post_content' => '' ); }
    function get_post_meta( $id, $key, $single = true ) { return array( 'end_date__time' => time() - 60 )[ $key ] ?? ''; }
    function get_the_title( $id ) { return 'Review event'; }
    function get_permalink( $id ) { return 'https://example.test/events/' . $id; }
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
        public function get( $key, $default = '' ) { return array( 'email_mode' => 'send', 'notification_email' => 'team@example.test' )[ $key ] ?? $default; }
        public function email( $key, $default = '' ) { return 'feedback_body' === $key ? '<p>{feedback_url}</p>{feedback_button}' : $default; }
        public function automation_enabled( $type ) { return true; }
        public function automation_delay( $type ) { return 0; }
        public function feedback_send_window() { return 86400; }
    }
    class CCT_Repository {
        public $item = array( '_ID' => 7, 'event_id' => 42, 'registration_status' => 'approved', 'email' => 'attendee@example.test' );
        public function get( $id ) { return $this->item; }
        public function attendance_list( $id ) { return array( $this->item + array( 'attendance_status' => 'attended' ) ); }
    }
    class Public_Page_Theme {}
    class Event_Public_Display { public static function normalise_date_status( $current, $legacy ) { return 'confirmed'; } }
    $root = dirname( __DIR__, 2 );
    require $root . '/includes/class-event-datetime.php';
    require $root . '/includes/class-mutation-lock.php';
    require $root . '/includes/class-audit-log.php';
    require $root . '/includes/class-email-service.php';
    require $root . '/includes/class-public-form-token.php';
    require $root . '/includes/class-support-groups.php';
    require $root . '/includes/class-post-event-feedback.php';
    $GLOBALS['wpdb'] = new \AuditDatabase(); $wpdb = $GLOBALS['wpdb'];
    $programme = array( 'id' => 'cohort', 'name' => 'Review cohort', 'sessions' => array( array( 'date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '11:00' ) ) );
    $GLOBALS['options'] = array( 'hherm_support_group_programmes' => array( $programme ), 'hherm_support_group_settings' => array( 'registration_programme_id' => 'cohort' ), 'admin_email' => 'admin@example.test' );
    $wpdb->rows[1] = array( 'id' => 1, 'programme_id' => 'cohort', 'application_type' => 'registration', 'first_name' => 'Review', 'last_name' => 'Guest', 'email' => 'guest@example.test', 'phone' => '0400000000', 'reason' => 'Synthetic review', 'attended_before' => 0, 'status' => 'pending', 'created_at' => '2026-10-02 10:15:00', 'email_status' => '', 'internal_email_status' => '' );
    $settings = new Settings(); $audit = new Audit_Log(); $email = new Email_Service( $settings, $audit ); $groups = new Support_Groups( $settings, $email );
    $_POST = array( 'programme_id' => 'cohort', 'applicant_id' => 1 ); $GLOBALS['fail_mail'] = true;
    $first_url = \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( 'confirmed' === $wpdb->rows[1]['status'] && 'failed' === $wpdb->rows[1]['email_status'], 'transport failure leaves applicant confirmed with failed confirmation email' );
    unset( $GLOBALS['fail_mail'] ); $before = count( $GLOBALS['mail'] );
    $second_url = \audit_redirect( array( $groups, 'confirm_applicant' ) );
    \audit_check( $before === count( $GLOBALS['mail'] ) && 'failed' === $wpdb->rows[1]['email_status'] && \has_text( $second_url, 'already-confirmed' ), 'retrying confirmation after transport recovers does not resend or repair failed mail' );
    echo json_encode( array( 'first_redirect' => $first_url, 'retry_redirect' => $second_url, 'email_status' => $wpdb->rows[1]['email_status'], 'mail_attempts' => count( $GLOBALS['mail'] ) ), JSON_UNESCAPED_SLASHES ) . "\n";
    $repository = new CCT_Repository(); $feedback = new Post_Event_Feedback( $settings, $repository, $audit, $email, new Public_Page_Theme() );
    $GLOBALS['fail_option'] = 'hherm_feedback_link_7';
    $url = \audit_private( $feedback, 'feedback_url', $repository->item );
    parse_str( parse_url( $url, PHP_URL_QUERY ), $params );
    $item = $repository->item; $item['feedback_url'] = $url;
    $sent = $email->send_feedback_request( $item, array( 'id' => 42, 'title' => 'Review event' ) );
    $resolved = \audit_private( $feedback, 'resolve', 7, $params['hherm_feedback_token'] );
    \audit_check( 'sent' === $sent['status'] && \is_wp_error( $resolved ), 'feedback token storage failure still hands off email containing a link that the plugin rejects' );
    unset( $GLOBALS['fail_option'] );
    $url = \audit_private( $feedback, 'feedback_url', $repository->item ); parse_str( parse_url( $url, PHP_URL_QUERY ), $params );
    \audit_check( ! \is_wp_error( \audit_private( $feedback, 'resolve', 7, $params['hherm_feedback_token'] ) ), 'same token flow resolves successfully when option storage works' );

    // Two real workers each take their false lock snapshot before either writes the transient.
    // Both suspend before wp_mail returns, so neither successful audit exists during eligibility checks.
    $GLOBALS['mail'] = array(); $GLOBALS['wpdb']->logs = array(); $GLOBALS['transients'] = array(); $GLOBALS['race_mode'] = true;
    $worker_a = new \Fiber( static function () use ( $feedback ) { $feedback->send_due_reminders(); } );
    $worker_b = new \Fiber( static function () use ( $feedback ) { $feedback->send_due_reminders(); } );
    $a_read = $worker_a->start(); $b_read = $worker_b->start();
    \audit_check( false === $a_read['snapshot'] && false === $b_read['snapshot'], 'both feedback workers see an available transient lock before either sets it' );
    $a_send = $worker_a->resume(); $b_send = $worker_b->resume();
    \audit_check( 'mail_handoff' === $a_send['phase'] && 'mail_handoff' === $b_send['phase'] && 2 === count( $GLOBALS['mail'] ), 'interleaved workers both hand off a feedback request for the same registration' );
    $worker_a->resume(); $worker_b->resume(); unset( $GLOBALS['race_mode'] );
    $tokens = array();
    foreach ( $GLOBALS['mail'] as $message ) { preg_match( '/hherm_feedback_token=([a-f0-9]{48})/', $message['body'], $match ); $tokens[] = $match[1]; }
    \audit_check( $tokens[0] !== $tokens[1] && \is_wp_error( \audit_private( $feedback, 'resolve', 7, $tokens[0] ) ) && ! \is_wp_error( \audit_private( $feedback, 'resolve', 7, $tokens[1] ) ), 'duplicate request rotates the bearer token and invalidates the first emailed feedback link' );
}
