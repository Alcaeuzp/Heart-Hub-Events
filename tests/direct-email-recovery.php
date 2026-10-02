<?php
/** Recovery, concurrency, stale-source and failed-cleanup regressions. No live mail. */
require __DIR__ . '/support/email-delivery-fixture.php';

use HeartHub\EventRegistrations\Audit_Log;
use HeartHub\EventRegistrations\CCT_Repository;
use HeartHub\EventRegistrations\Email_Service;
use HeartHub\EventRegistrations\Settings;
use HeartHub\EventRegistrations\Support_Groups;

function direct_fixture() {
	email_reset();
	$settings = new Settings(); $audit = new Audit_Log(); $repository = new CCT_Repository();
	$repository->items[7] = array( '_ID' => 7, 'event_id' => 42, 'first_name' => 'Original', 'last_name' => 'Guest', 'email' => 'original@example.test', 'registration_status' => 'interest', 'phone' => '0400000000' );
	return array( $settings, $audit, $repository, new Email_Service( $settings, $audit, null, $repository ) );
}
function support_fixture( $email ) {
	$programme = array( 'id' => 'cohort', 'name' => 'Community cohort', 'sessions' => array( array( 'date' => '2030-11-01', 'start_time' => '10:00', 'end_time' => '11:00' ) ) );
	$GLOBALS['options'][ Support_Groups::OPTION_PROGRAMMES ] = array( $programme );
	$GLOBALS['options'][ Support_Groups::OPTION_SETTINGS ] = array( 'registration_programme_id' => 'cohort' );
	$GLOBALS['wpdb']->rows[3] = array( 'id' => 3, 'programme_id' => 'cohort', 'application_type' => 'registration', 'first_name' => 'Sam', 'last_name' => 'Guest', 'email' => 'sam@example.test', 'status' => 'pending', 'email_status' => '', 'internal_email_status' => '', 'created_at' => '2026-10-02 10:00:00' );
	$_POST = array( 'programme_id' => 'cohort', 'applicant_id' => 3 );
	return $programme;
}

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_mail'] = true; $GLOBALS['reject_cron'] = true;
$result = $email->send_interest_received( $repository->items[7], array( 'id' => 42, 'title' => 'Original workshop' ) );
email_check( array( 'customer' => 'failed', 'team' => 'failed' ) === $result && 2 === count( email_jobs() ), 'both failed interest messages retain independent recovery descriptors' );
$encoded = json_encode( email_jobs() );
email_check( false === strpos( $encoded, 'original@example.test' ) && false === strpos( $encoded, '0400000000' ) && false === strpos( $encoded, 'Original' ), 'recovery options retain identifiers without customer details or message payloads' );
unset( $GLOBALS['reject_cron'], $GLOBALS['fail_mail'] ); $email->recover_direct_emails();
email_check( 2 === count( $GLOBALS['cron'][ Email_Service::RETRY_HOOK ] ), 'hourly recovery repairs retries rejected by WP-Cron' );
list( $customer_key, $customer_job ) = email_job_for( 'interest_customer_email' );
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $customer_key, 'old-generation' );
email_check( $before === count( $GLOBALS['mail'] ) && 2 === count( email_jobs() ), 'stale cron generations cannot deliver or replace current jobs' );
$email->retry_direct_email( $customer_key, $customer_job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ), 'early cron callbacks reschedule without another mail attempt' );
$repository->items[7]['email'] = 'updated@example.test'; $repository->items[7]['first_name'] = 'Updated'; $GLOBALS['titles'][42] = 'Updated workshop';
$customer_job = email_due( $customer_key ); $email->retry_direct_email( $customer_key, $customer_job['generation'] );
$mail = end( $GLOBALS['mail'] );
email_check( 'updated@example.test' === $mail['to'] && false !== strpos( $mail['body'], 'Updated workshop' ) && false !== strpos( $mail['body'], 'Updated' ), 'interest recovery uses the latest recipient and event details' );
list( $staff_key, $staff_job ) = email_job_for( 'internal_notification_email' );
$settings->values['notification_email'] = 'new-team@example.test'; $settings->templates['internal_notification_subject'] = 'Updated template: {customer_name}';
$staff_job = email_due( $staff_key ); $email->retry_direct_email( $staff_key, $staff_job['generation'] );
$mail = end( $GLOBALS['mail'] );
email_check( 'new-team@example.test' === $mail['to'] && 'Updated template: Updated Guest' === $mail['subject'] && empty( email_jobs() ), 'staff recovery uses current settings and templates then removes its job' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key );
$repository->items[7]['registration_status'] = 'approved'; $before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && empty( email_jobs() ), 'interest acknowledgement is discarded after registration status changes' );
$repository->items[7]['registration_status'] = 'interest'; $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key ); unset( $repository->items[7] );
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && empty( email_jobs() ), 'deleted customer records are discarded without sending retained personal details' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key ); $GLOBALS['wpdb']->fail_audit_read = true;
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && 2 === get_option( 'hherm_direct_email_job_' . $key )['attempts'], 'unavailable delivery history fails closed and consumes a bounded retry' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key );
$GLOBALS['audit_read_hook'] = static function () use ( $key ) { $GLOBALS['options'][ 'hherm_direct_email_lock_' . $key ] = array( 'token' => 'replacement', 'expires_at' => time() + 3600 ); $GLOBALS['options'][ 'hherm_direct_email_job_' . $key ]['generation'] = 'replacement-generation'; };
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && 'replacement-generation' === get_option( 'hherm_direct_email_job_' . $key )['generation'], 'a replaced lock owner stops after a slow audit read without mutating the successor job' );
unset( $GLOBALS['options'][ 'hherm_direct_email_lock_' . $key ] ); $job = email_due( $key );
$repository->read_hook = static function () use ( $key ) { $GLOBALS['options'][ 'hherm_direct_email_lock_' . $key ] = array( 'token' => 'replacement', 'expires_at' => time() + 3600 ); };
$email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && $job === get_option( 'hherm_direct_email_job_' . $key ), 'a replaced lock owner stops after a slow source read' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' );
unset( $GLOBALS['fail_to'] ); $job = email_due( $key ); $name = 'hherm_direct_email_job_' . $key;
$GLOBALS['fail_update'][ $name ] = true; $GLOBALS['fail_delete'][ $name ] = true;
$email->retry_direct_email( $key, $job['generation'] ); $before = count( $GLOBALS['mail'] );
$entry = $audit->latest_entry_checked( 7, 'interest_customer_email' );
email_check( 'sent' === $entry['status'] && is_array( $entry['details'] ) && $job['generation'] === $entry['details']['delivery_generation'], 'successful retry records its generation using the actual decoded audit API' );
$email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ), 'successful delivery audit suppresses replay when descriptor marker and deletion both fail' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key ); $email->retry_direct_email( $key, $job['generation'] );
$job = email_due( $key ); $name = 'hherm_direct_email_job_' . $key; $GLOBALS['fail_update'][ $name ] = true; $GLOBALS['fail_delete'][ $name ] = true;
$email->retry_direct_email( $key, $job['generation'] ); $before = count( $GLOBALS['mail'] ); $entry = $audit->latest_entry_checked( 7, 'interest_customer_email' );
email_check( 'failed_terminal' === $entry['status'] && $job['generation'] === $entry['details']['delivery_generation'] && 4 === $before, 'three customer transport attempts exhaust recovery even when terminal option writes fail' );
$email->recover_direct_emails(); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ), 'terminal generation audit prevents a fourth attempt after failed cleanup' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key ); unset( $GLOBALS['fail_to'] );
$GLOBALS['mail_hook'] = static function () use ( $email, $key, $job ) { $email->retry_direct_email( $key, $job['generation'] ); };
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before + 1 === count( $GLOBALS['mail'] ) && empty( email_jobs() ), 'overlapping direct-email workers hand off only one message' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['throw_mail'] = true; $result = $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
email_check( array( 'customer' => 'failed', 'team' => 'failed' ) === $result && 2 === count( email_jobs() ), 'transport exceptions produce bounded recovery jobs instead of breaking submission' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $job = email_due( $key ); unset( $GLOBALS['posts'][42] );
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && empty( email_jobs() ), 'recovery discards a message whose event has been removed' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$GLOBALS['fail_to'] = array( 'original@example.test' ); $email->send_interest_received( $repository->items[7], array( 'id' => 42 ) );
list( $key, $job ) = email_job_for( 'interest_customer_email' ); $GLOBALS['options'] = array( 'admin_email' => 'admin@example.test' ); $GLOBALS['cron'] = array();
for ( $index = 0; $index < 101; ++$index ) $GLOBALS['options'][ 'hherm_direct_email_job_' . str_pad( dechex( $index ), 64, '0', STR_PAD_LEFT ) ] = $job;
$email->recover_direct_emails();
email_check( 100 === count( $GLOBALS['cron'][ Email_Service::RETRY_HOOK ] ) && (bool) get_option( 'hherm_direct_email_recovery_cursor' ) && (bool) wp_next_scheduled( Email_Service::CONTINUE_HOOK ), 'recovery scans bounded batches and schedules continuation for more jobs' );
$email->recover_direct_emails();
email_check( 101 === count( $GLOBALS['cron'][ Email_Service::RETRY_HOOK ] ) && false === get_option( 'hherm_direct_email_recovery_cursor' ), 'continuation reaches descriptors beyond the first recovery batch' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$programme = support_fixture( $email ); $groups = new Support_Groups( $settings, $email ); $GLOBALS['fail_mail'] = true;
$redirect = email_redirect( array( $groups, 'confirm_applicant' ) );
email_check( 'confirmed' === $GLOBALS['wpdb']->rows[3]['status'] && 'failed' === $GLOBALS['wpdb']->rows[3]['email_status'] && false !== strpos( $redirect, 'confirmed-email-failed' ) && 1 === count( email_jobs() ), 'failed support confirmation keeps approval and queues delivery recovery' );
$GLOBALS['deny_capability'] = true; $before = count( $GLOBALS['mail'] );
email_check( email_blocked( array( $groups, 'resend_confirmation' ) ) && $before === count( $GLOBALS['mail'] ), 'manual confirmation recovery requires the support management capability' );
unset( $GLOBALS['deny_capability'] ); $GLOBALS['deny_nonce'] = true;
email_check( email_blocked( array( $groups, 'resend_confirmation' ) ) && $before === count( $GLOBALS['mail'] ), 'manual confirmation recovery requires a valid action nonce' );
unset( $GLOBALS['deny_nonce'], $GLOBALS['fail_mail'] ); $GLOBALS['wpdb']->fail_audit_read = true;
$redirect = email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( $before === count( $GLOBALS['mail'] ) && false !== strpos( $redirect, 'action-failed' ), 'manual recovery fails closed when the delivery audit cannot be read' );
$GLOBALS['wpdb']->fail_audit_read = false; $redirect = email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( 'sent' === $GLOBALS['wpdb']->rows[3]['email_status'] && $before + 1 === count( $GLOBALS['mail'] ) && empty( email_jobs() ), 'authorized manual resend repairs failed confirmation without repeating approval' );
$before = count( $GLOBALS['mail'] ); email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( $before === count( $GLOBALS['mail'] ), 'manual confirmation recovery skips a completed delivery' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$programme = support_fixture( $email ); $groups = new Support_Groups( $settings, $email );
$GLOBALS['mail_hook'] = static function () { $GLOBALS['wpdb']->fail_applicant_update = true; };
$redirect = email_redirect( array( $groups, 'confirm_applicant' ) ); $before = count( $GLOBALS['mail'] );
email_check( 'pending' === $GLOBALS['wpdb']->rows[3]['email_status'] && false !== strpos( $redirect, 'confirmed-status-failed' ), 'successful confirmation can be identified when its applicant status write fails' );
$GLOBALS['wpdb']->fail_applicant_update = false; email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( $before === count( $GLOBALS['mail'] ) && 'sent' === $GLOBALS['wpdb']->rows[3]['email_status'], 'manual resend reconciles a matching successful audit without duplicate mail' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$programme = support_fixture( $email ); $GLOBALS['wpdb']->rows[3]['status'] = 'confirmed'; $GLOBALS['fail_mail'] = true;
$email->send_support_group_submission( $GLOBALS['wpdb']->rows[3], $programme );
email_check( 2 === count( email_jobs() ), 'support submissions retain separate customer and internal retries' );
list( $key, $job ) = email_job_for( 'support_customer_email' ); $job = email_due( $key ); unset( $GLOBALS['fail_mail'] );
$GLOBALS['wpdb']->rows[3]['email'] = 'updated-support@example.test'; $settings->templates['support_confirmed_subject'] = 'Latest cohort template';
$email->retry_direct_email( $key, $job['generation'] ); $mail = end( $GLOBALS['mail'] );
email_check( 'updated-support@example.test' === $mail['to'] && 'Latest cohort template' === $mail['subject'] && 'sent' === $GLOBALS['wpdb']->rows[3]['email_status'], 'automatic support confirmation recovery refreshes recipient and template and repairs its display status' );
$groups = new Support_Groups( $settings, $email ); $_POST['confirm_delete'] = 'yes'; email_redirect( array( $groups, 'delete_applicant' ) );
email_check( empty( email_jobs() ) && ! isset( $GLOBALS['wpdb']->rows[3] ), 'applicant deletion erases associated recovery jobs before personal data removal' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$programme = support_fixture( $email ); $GLOBALS['wpdb']->rows[3]['status'] = 'confirmed'; $GLOBALS['fail_mail'] = true;
$email->send_support_group_submission( $GLOBALS['wpdb']->rows[3], $programme );
$groups = new Support_Groups( $settings, $email ); $_POST['confirm_delete'] = 'yes'; email_redirect( array( $groups, 'delete_programme' ) );
email_check( empty( email_jobs() ) && empty( $GLOBALS['wpdb']->rows ) && empty( get_option( Support_Groups::OPTION_PROGRAMMES ) ), 'programme deletion erases each removed applicant recovery job' );

list( $settings, $audit, $repository, $email ) = direct_fixture();
$programme = support_fixture( $email ); $GLOBALS['wpdb']->rows[3]['status'] = 'confirmed'; $GLOBALS['fail_mail'] = true; $email->send_support_group_confirmation( $GLOBALS['wpdb']->rows[3], $programme );
list( $key, $job ) = email_job_for( 'support_customer_email' ); $job = email_due( $key );
$lock_name = 'hherm_support_lock_' . substr( hash( 'sha256', 'cohort' ), 0, 32 ); $GLOBALS['options'][ $lock_name ] = array( 'token' => 'admin-update', 'expires_at' => time() + 3600 );
$before = count( $GLOBALS['mail'] ); $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && $job === get_option( 'hherm_direct_email_job_' . $key ), 'support recovery waits for a concurrent programme mutation without consuming an attempt' );
unset( $GLOBALS['options'][ $lock_name ] ); $GLOBALS['wpdb']->rows[3]['status'] = 'declined'; $email->retry_direct_email( $key, $job['generation'] );
email_check( $before === count( $GLOBALS['mail'] ) && empty( email_jobs() ), 'support confirmation recovery discards a subsequently declined applicant' );
