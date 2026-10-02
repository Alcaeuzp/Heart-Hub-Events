<?php
/**
 * Uninstall Heart Hub Event Registration Manager.
 *
 * Removes only data owned by this plugin. Existing JetEngine CCT items,
 * Events posts, relations, forms, pages, queries, and listings are preserved.
 *
 * @package HeartHubEventRegistrationManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin-owned data for the current site.
 */
function hherm_uninstall_site(): void {
	global $wpdb;

	delete_option( 'hherm_settings' );
	delete_option( 'hherm_owner_roles' );
	delete_option( 'hherm_email_templates' );
	delete_option( 'hherm_email_automation' );
	delete_option( 'hherm_public_page_settings' );
	delete_option( 'hherm_support_group_sessions' );
	delete_option( 'hherm_support_group_programmes' );
	delete_option( 'hherm_support_group_settings' );
	delete_option( 'hherm_support_group_schema' );
	delete_option( 'hherm_calendar_display' );
	delete_option( 'hherm_calendar_design_version' );
	delete_option( 'hherm_data_version' );
	delete_option( 'hherm_scheduled_email_jobs' );
	delete_option( 'hherm_email_recovery_cursor' );
	delete_option( 'hherm_direct_email_recovery_cursor' );
	delete_option( 'hherm_feedback_cron_lock' );
	delete_transient( 'hherm_feedback_cron_lock' );
	delete_transient( 'hherm_schedule_status_check' );
	wp_clear_scheduled_hook( 'hherm_send_feedback_reminders' );
	wp_clear_scheduled_hook( 'hherm_categorize_past_events' );
	wp_unschedule_hook( 'hherm_send_scheduled_email', true );
	wp_clear_scheduled_hook( 'hherm_recover_email_jobs' );
	wp_clear_scheduled_hook( 'hherm_continue_email_recovery' );
	wp_unschedule_hook( 'hherm_retry_direct_email', true );
	wp_clear_scheduled_hook( 'hherm_recover_direct_email' );
	wp_clear_scheduled_hook( 'hherm_continue_direct_email_recovery' );

	foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'manage_heart_hub_event_registrations' );
		}
	}

	$lock_pattern = $wpdb->esc_like( 'hherm_review_lock_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned temporary options during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $lock_pattern ) );
	$capacity_lock_pattern = $wpdb->esc_like( 'hherm_capacity_lock_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned temporary capacity locks during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $capacity_lock_pattern ) );
	$support_lock_pattern = $wpdb->esc_like( 'hherm_support_lock_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned temporary support-group mutation locks during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $support_lock_pattern ) );
	$email_job_lock_pattern = $wpdb->esc_like( 'hherm_email_job_lock_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned temporary email job locks during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $email_job_lock_pattern ) );
	foreach ( array( 'hherm_event_interest_lock_', 'hherm_direct_email_job_', 'hherm_direct_email_lock_', 'hherm_feedback_delivery_' ) as $option_prefix ) {
		$owned_pattern = $wpdb->esc_like( $option_prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only this plugin's submission locks, retry descriptors and feedback delivery locks.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $owned_pattern ) );
	}
	$checkin_pattern = $wpdb->esc_like( 'hherm_checkin_event_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned temporary check-in links during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $checkin_pattern ) );
	$manage_pattern = $wpdb->esc_like( 'hherm_registration_manage_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned attendee self-service links during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $manage_pattern ) );
	$feedback_pattern = $wpdb->esc_like( 'hherm_feedback_link_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned feedback links during uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $feedback_pattern ) );
	foreach ( array( '_transient_hherm_ci_', '_transient_timeout_hherm_ci_', '_transient_hherm_sg_', '_transient_timeout_hherm_sg_', '_transient_hherm_calendar_retry_', '_transient_timeout_hherm_calendar_retry_', '_transient_hherm_event_retry_', '_transient_timeout_hherm_event_retry_' ) as $transient_prefix ) {
		$transient_pattern = $wpdb->esc_like( $transient_prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only plugin-owned check-in rate-limit transients during uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_pattern ) );
	}

	$audit_table = $wpdb->prefix . 'hherm_audit_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes only this plugin-owned audit table, including its personal data.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $audit_table ) );
	$support_group_table = $wpdb->prefix . 'hherm_support_group_applications';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin-owned support-group applicant table containing personal data.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $support_group_table ) );
}

if ( is_multisite() ) {
	$hherm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $hherm_site_ids as $hherm_site_id ) {
		switch_to_blog( (int) $hherm_site_id );
		hherm_uninstall_site();
		restore_current_blog();
	}
} else {
	hherm_uninstall_site();
}
