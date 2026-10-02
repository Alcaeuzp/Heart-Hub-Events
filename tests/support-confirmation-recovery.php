<?php
/** Manual recovery UI, source isolation and failed deletion regression coverage. */
require __DIR__ . '/support/email-delivery-fixture.php';

use HeartHub\EventRegistrations\Audit_Log;
use HeartHub\EventRegistrations\Email_Service;
use HeartHub\EventRegistrations\Settings;
use HeartHub\EventRegistrations\Support_Groups;

function mysql2date( $format, $value ) { return ( new DateTimeImmutable( $value, wp_timezone() ) )->format( $format ); }
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $action ) . '">'; }

function support_recovery_fixture() {
	email_reset();
	$GLOBALS['nonce_actions'] = array();
	$settings = new Settings();
	$audit = new Audit_Log();
	$email = new Email_Service( $settings, $audit );
	$programme = array( 'id' => 'cohort', 'name' => 'Community cohort', 'sessions' => array( array( 'date' => '2030-11-01', 'start_time' => '10:00', 'end_time' => '11:00' ) ) );
	$GLOBALS['options'][ Support_Groups::OPTION_PROGRAMMES ] = array( $programme );
	$GLOBALS['options'][ Support_Groups::OPTION_SETTINGS ] = array( 'registration_programme_id' => 'cohort' );
	$GLOBALS['wpdb']->rows[3] = array( 'id' => 3, 'programme_id' => 'cohort', 'application_type' => 'registration', 'first_name' => 'Sam', 'last_name' => 'Guest', 'email' => 'sam@example.test', 'phone' => '', 'reason' => '', 'attended_before' => 0, 'status' => 'confirmed', 'email_status' => 'failed', 'internal_email_status' => '', 'created_at' => '2026-10-02 10:00:00' );
	$_POST = array( 'programme_id' => 'cohort', 'applicant_id' => 3 );
	return array( new Support_Groups( $settings, $email ), $email, $audit, $programme );
}

function support_recovery_html( $groups, $programme, $applicant ) {
	ob_start();
	try { email_private( $groups, 'render_applicant_detail', $programme, $applicant, 'confirmed-email-failed' ); return ob_get_contents(); }
	finally { ob_end_clean(); }
}

list( $groups, $email, $audit, $programme ) = support_recovery_fixture();
$applicant = $GLOBALS['wpdb']->rows[3];
$html = support_recovery_html( $groups, $programme, $applicant );
email_check( false !== strpos( $html, 'value="hherm_resend_support_group_confirmation"' ) && false !== strpos( $html, 'value="hherm_resend_support_group_confirmation_cohort_3"' ) && false === strpos( $html, 'value="hherm_confirm_support_group_applicant"' ), 'failed confirmed applicant exposes a record-bound recovery form without an approval form' );
foreach ( array( 'sent', 'logged' ) as $delivery ) {
	$applicant['email_status'] = $delivery;
	$html = support_recovery_html( $groups, $programme, $applicant );
	email_check( false === strpos( $html, 'value="hherm_resend_support_group_confirmation"' ), $delivery . ' applicant hides the recovery form' );
}
$applicant['email_status'] = 'failed'; $applicant['status'] = 'pending';
$html = support_recovery_html( $groups, $programme, $applicant );
email_check( false === strpos( $html, 'value="hherm_resend_support_group_confirmation"' ) && false !== strpos( $html, 'value="hherm_confirm_support_group_applicant"' ), 'pending applicant still requires approval rather than confirmation recovery' );
$applicant['status'] = 'confirmed'; $applicant['application_type'] = 'interest';
$html = support_recovery_html( $groups, $programme, $applicant );
email_check( false === strpos( $html, 'value="hherm_resend_support_group_confirmation"' ), 'future-interest record cannot expose confirmation recovery even with a confirmed legacy status' );

list( $groups, $email, $audit, $programme ) = support_recovery_fixture();
$other_programme = $programme; $other_programme['id'] = 'another-cohort';
$GLOBALS['options'][ Support_Groups::OPTION_PROGRAMMES ][] = $other_programme;
$_POST['programme_id'] = 'another-cohort';
$redirect = email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( empty( $GLOBALS['mail'] ) && 'failed' === $GLOBALS['wpdb']->rows[3]['email_status'] && false !== strpos( $redirect, 'action-failed' ) && 'hherm_resend_support_group_confirmation_another-cohort_3' === end( $GLOBALS['nonce_actions'] ), 'recovery binds its nonce and applicant lookup to the submitted programme' );
$_POST['programme_id'] = 'cohort'; $_POST['applicant_id'] = 99;
$redirect = email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( empty( $GLOBALS['mail'] ) && false !== strpos( $redirect, 'action-failed' ), 'recovery rejects an applicant ID absent from the programme' );

list( $groups, $email, $audit, $programme ) = support_recovery_fixture();
$GLOBALS['wpdb']->rows[3]['status'] = 'pending';
email_redirect( array( $groups, 'confirm_applicant' ) );
$before = count( $GLOBALS['mail'] );
$redirect = email_redirect( array( $groups, 'confirm_applicant' ) );
email_check( 1 === $before && $before === count( $GLOBALS['mail'] ) && 'confirmed' === $GLOBALS['wpdb']->rows[3]['status'] && false !== strpos( $redirect, 'already-confirmed' ), 'repeated approval requests preserve one confirmation handoff' );

list( $groups, $email, $audit, $programme ) = support_recovery_fixture();
$GLOBALS['wpdb']->rows[3]['email_status'] = 'logged';
$redirect = email_redirect( array( $groups, 'resend_confirmation' ) );
email_check( empty( $GLOBALS['mail'] ) && false !== strpos( $redirect, 'already-confirmed' ), 'manual recovery treats Log only delivery as complete' );

foreach ( array( 'different-programme', 'previous-pending-status' ) as $case ) {
	list( $groups, $email, $audit, $programme ) = support_recovery_fixture();
	$details = array( 'source' => 'support_group', 'programme_id' => 'cohort', 'registration_status' => 'confirmed' );
	if ( 'different-programme' === $case ) $details['programme_id'] = 'another-cohort';
	else $details['registration_status'] = 'pending';
	$audit->write( 3, 'support_customer_email', 'sent', $details );
	email_redirect( array( $groups, 'resend_confirmation' ) );
	email_check( 1 === count( $GLOBALS['mail'] ) && 'sent' === $GLOBALS['wpdb']->rows[3]['email_status'], $case . ' successful audit cannot suppress the current confirmation' );
}

foreach ( array( 'delete_applicant', 'delete_programme' ) as $action ) {
	list( $groups, $email, $audit, $programme ) = support_recovery_fixture();
	$GLOBALS['fail_mail'] = true;
	$email->send_support_group_submission( $GLOBALS['wpdb']->rows[3], $programme );
	$jobs = email_jobs(); $logs = $GLOBALS['wpdb']->logs;
	foreach ( $jobs as $name => $job ) $GLOBALS['fail_delete'][ $name ] = true;
	$_POST['confirm_delete'] = 'yes';
	$redirect = email_redirect( array( $groups, $action ) );
	email_check( isset( $GLOBALS['wpdb']->rows[3] ) && $logs === $GLOBALS['wpdb']->logs && $jobs === email_jobs() && array( $programme ) === get_option( Support_Groups::OPTION_PROGRAMMES ) && false !== strpos( $redirect, 'action-failed' ), $action . ' preserves applicant data and programme when recovery descriptor cleanup fails' );
}
