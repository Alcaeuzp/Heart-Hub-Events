<?php
/** Run uninstall against two disposable SQLite sites; never connects to WordPress. */
if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) { echo "SKIP uninstall-retention: pdo_sqlite is required\n"; exit( 0 ); }
define( 'WP_UNINSTALL_PLUGIN', true );
class Uninstall_Test_DB {
	public $pdo; public $prefix = 'wp_1_'; public $options = 'wp_1_options';
	public function __construct() { $this->pdo = new PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION ); }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, ...$args ) { return preg_replace_callback( '/%[isd]/', function ( $match ) use ( &$args ) { $value = array_shift( $args ); return '%i' === $match[0] ? '"' . str_replace( '"', '""', $value ) . '"' : ( '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value ) ); }, $sql ); }
	public function query( $sql ) { if ( false !== strpos( $sql, ' LIKE ' ) ) $sql .= ' ESCAPE ' . $this->pdo->quote( '\\' ); return $this->pdo->exec( $sql ); }
}
$wpdb = new Uninstall_Test_DB(); $GLOBALS['site_stack'] = array(); $GLOBALS['removed_caps'] = array(); $GLOBALS['cleared_hooks'] = array();
function is_multisite() { return true; }
function get_sites( $args ) { return array( 1, 2 ); }
function switch_to_blog( $id ) { global $wpdb; $GLOBALS['site_stack'][] = array( $wpdb->prefix, $wpdb->options ); $wpdb->prefix = 'wp_' . $id . '_'; $wpdb->options = $wpdb->prefix . 'options'; }
function restore_current_blog() { global $wpdb; list( $wpdb->prefix, $wpdb->options ) = array_pop( $GLOBALS['site_stack'] ); }
function delete_option( $name ) { global $wpdb; return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) ); }
function delete_transient( $name ) { delete_option( '_transient_' . $name ); delete_option( '_transient_timeout_' . $name ); }
function wp_clear_scheduled_hook( $hook ) { global $wpdb; $GLOBALS['cleared_hooks'][ $wpdb->prefix ][] = $hook; }
function wp_unschedule_hook( $hook, ...$unused ) { wp_clear_scheduled_hook( $hook ); }
function wp_roles() { return (object) array( 'roles' => array( 'administrator' => array(), 'editor' => array() ) ); }
function get_role( $name ) { return new class( $name ) { private $name; public function __construct( $name ) { $this->name = $name; } public function remove_cap( $cap ) { global $wpdb; $GLOBALS['removed_caps'][ $wpdb->prefix ][] = array( $this->name, $cap ); } }; }
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); echo "PASS $message\n"; }
$owned = array( 'hherm_settings', 'hherm_calendar_design_version', 'hherm_email_recovery_cursor', 'hherm_direct_email_recovery_cursor', 'hherm_direct_email_job_123', 'hherm_direct_email_lock_123', 'hherm_event_interest_lock_10_123', 'hherm_feedback_cron_lock', 'hherm_feedback_delivery_7', 'hherm_support_group_programmes', 'hherm_review_lock_1', 'hherm_capacity_lock_2', 'hherm_support_lock_programmes', 'hherm_support_lock_123', 'hherm_email_job_lock_job_decision_1', 'hherm_registration_manage_1', 'hherm_feedback_link_1', 'hherm_checkin_event_1', '_transient_hherm_schedule_status_check', '_transient_timeout_hherm_schedule_status_check', '_transient_hherm_event_retry_1_token', '_transient_timeout_hherm_event_retry_1_token' );
foreach ( array( 1, 2 ) as $site ) {
	$prefix = 'wp_' . $site . '_';
	$wpdb->pdo->exec( "CREATE TABLE {$prefix}options (option_name TEXT PRIMARY KEY, option_value TEXT)" );
	foreach ( array_merge( $owned, array( 'unrelated_setting', 'hhermXreviewXlock_1' ) ) as $name ) $wpdb->query( $wpdb->prepare( "INSERT INTO {$prefix}options VALUES (%s,%s)", $name, 'fixture' ) );
	foreach ( array( 'hherm_audit_log', 'hherm_support_group_applications', 'jet_cct_event_registrations', 'posts' ) as $table ) {
		$wpdb->pdo->exec( "CREATE TABLE {$prefix}{$table} (id INTEGER, content TEXT)" );
		$wpdb->pdo->exec( "INSERT INTO {$prefix}{$table} VALUES (1,'saved record')" );
	}
}
require dirname( __DIR__ ) . '/uninstall.php';
foreach ( array( 1, 2 ) as $site ) {
	$prefix = 'wp_' . $site . '_';
	$remaining = $wpdb->pdo->query( "SELECT option_name FROM {$prefix}options ORDER BY option_name" )->fetchAll( PDO::FETCH_COLUMN );
	check( array( 'hhermXreviewXlock_1', 'unrelated_setting' ) === $remaining, "site $site removes owned settings, recovery state and temporary locks without broad LIKE matches" );
	foreach ( array( 'jet_cct_event_registrations', 'posts' ) as $table ) check( 'saved record' === $wpdb->pdo->query( "SELECT content FROM {$prefix}{$table} WHERE id = 1" )->fetchColumn(), "site $site retains $table records" );
	foreach ( array( 'hherm_audit_log', 'hherm_support_group_applications' ) as $table ) check( false === $wpdb->pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '{$prefix}{$table}'" )->fetchColumn(), "site $site removes documented plugin-owned $table table" );
	check( 2 === count( $GLOBALS['removed_caps'][ $prefix ] ), "site $site removes the plugin capability from each role" );
	check( in_array( 'hherm_recover_email_jobs', $GLOBALS['cleared_hooks'][ $prefix ], true ) && in_array( 'hherm_continue_email_recovery', $GLOBALS['cleared_hooks'][ $prefix ], true ), "site $site removes recovery schedules" );
	check( in_array( 'hherm_retry_direct_email', $GLOBALS['cleared_hooks'][ $prefix ], true ) && in_array( 'hherm_recover_direct_email', $GLOBALS['cleared_hooks'][ $prefix ], true ) && in_array( 'hherm_continue_direct_email_recovery', $GLOBALS['cleared_hooks'][ $prefix ], true ), "site $site removes direct notification retry and recovery schedules" );
}
check( 'wp_1_' === $wpdb->prefix && ! $GLOBALS['site_stack'], 'multisite uninstall restores the original site context' );
