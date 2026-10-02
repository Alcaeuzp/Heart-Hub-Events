<?php
/** Feedback delivery, token persistence and Unicode regressions. No live mail or WordPress. */
require __DIR__ . '/support/email-delivery-fixture.php';
require dirname( __DIR__ ) . '/includes/class-post-event-feedback.php';

use HeartHub\EventRegistrations\Audit_Log;
use HeartHub\EventRegistrations\CCT_Repository;
use HeartHub\EventRegistrations\Email_Service;
use HeartHub\EventRegistrations\Post_Event_Feedback;
use HeartHub\EventRegistrations\Public_Page_Theme;
use HeartHub\EventRegistrations\Settings;

class Feedback_Repository extends CCT_Repository {
	public $snapshot;
	public $list_reads = 0;
	public function attendance_list( $id ) { ++$this->list_reads; return array( $this->snapshot ); }
}
function feedback_check( $condition, $message ) { email_check( $condition, $message ); }
function feedback_private( $object, $method, ...$args ) { return email_private( $object, $method, ...$args ); }
function feedback_token( $body ) {
	if ( ! preg_match( '/hherm_feedback_token=([a-f0-9]{48})/', $body, $match ) ) throw new \RuntimeException( 'Missing feedback token in mail.' );
	return $match[1];
}
function fixture(): array {
	email_reset();
	$GLOBALS['meta'][42] = array( 'start_date' => time() - 3600, 'end_date__time' => time() - 120 );
	$settings = new Settings(); $repository = new Feedback_Repository(); $audit = new Audit_Log();
	$settings->templates['feedback_body'] = '<p>{feedback_url}</p>{feedback_button}';
	$settings->templates['feedback_subject'] = 'Feedback for {event_name}';
	$repository->items[7] = array( '_ID' => 7, 'event_id' => 42, 'registration_status' => 'approved', 'attendance_status' => 'attended', 'first_name' => 'Guest', 'email' => 'attendee@example.test' );
	$repository->snapshot = $repository->items[7];
	$email = new Email_Service( $settings, $audit, null, $repository );
	return array( new Post_Event_Feedback( $settings, $repository, $audit, $email, new Public_Page_Theme() ), $settings, $repository, $audit, $email );
}

	list( $feedback, $settings, $repository, $audit, $email ) = fixture();
	// A second worker enters while the first is inside mail handoff, before its sent audit exists.
	$GLOBALS['mail_hook'] = static function () use ( $feedback, $email ) {
		$feedback->send_due_reminders();
		\feedback_private( $feedback, 'send_reminder', 7, $email->event_context( 42 ) );
	};
	$feedback->send_due_reminders();
	\feedback_check( 1 === $repository->list_reads && 1 === count( $GLOBALS['mail'] ), 'overlapping global and per-registration workers produce one mail handoff before the sent audit exists' );
	\feedback_check( ! isset( $GLOBALS['options']['hherm_feedback_cron_lock'] ) && ! isset( $GLOBALS['options']['hherm_feedback_delivery_7'] ), 'delivery releases both owned option locks' );
	$token = \feedback_token( $GLOBALS['mail'][0]['body'] );
	\feedback_check( ! \is_wp_error( \feedback_private( $feedback, 'resolve', 7, $token ) ), 'the sole emailed token resolves to the approved attendee' );
	$feedback->send_due_reminders();
	\feedback_check( 1 === count( $GLOBALS['mail'] ), 'a successful feedback delivery suppresses later cron runs' );

	list( $feedback, $settings, $repository ) = fixture();
	\feedback_private( $feedback, 'feedback_url', $repository->items[7] );
	// An expired link would be replaced if an obsolete worker reached token creation.
	$GLOBALS['options']['hherm_feedback_link_7']['expires_at'] = time() - 1;
	$old_record = $GLOBALS['options']['hherm_feedback_link_7'];
	$replacement = array( 'token' => str_repeat( 'c', 32 ), 'expires_at' => time() + HOUR_IN_SECONDS );
	$GLOBALS['audit_read_hook'] = static function () use ( $replacement ) { $GLOBALS['options']['hherm_feedback_delivery_7'] = $replacement; };
	$feedback->send_due_reminders();
	\feedback_check( ! $GLOBALS['mail'] && $old_record === $GLOBALS['options']['hherm_feedback_link_7'], 'a worker that loses lock ownership during its audit read stops before replacing a feedback token or sending mail' );
	\feedback_check( $replacement === $GLOBALS['options']['hherm_feedback_delivery_7'], 'an obsolete feedback worker cannot release its replacement owner’s lock' );

	foreach ( array( 'withdrawn', 'missing_attendance', 'already_replied', 'different_event' ) as $case ) {
		list( $feedback, $settings, $repository ) = fixture();
		if ( 'withdrawn' === $case ) $repository->items[7]['registration_status'] = 'withdrawn';
		if ( 'missing_attendance' === $case ) $repository->items[7]['attendance_status'] = '';
		if ( 'already_replied' === $case ) $repository->items[7]['feedback_score'] = 4;
		if ( 'different_event' === $case ) $repository->items[7]['event_id'] = 43;
		$feedback->send_due_reminders();
		\feedback_check( ! $GLOBALS['mail'] && ! isset( $GLOBALS['options']['hherm_feedback_link_7'] ), 'eligibility is re-read before creating a link: ' . $case );
	}
	list( $feedback, $settings, $repository ) = fixture();
	$repository->items[7]['email'] = 'updated@example.test';
	$feedback->send_due_reminders();
	\feedback_check( 'updated@example.test' === $GLOBALS['mail'][0]['to'], 'delivery uses the freshly read customer email rather than the attendance snapshot' );

	list( $feedback, $settings, $repository, $audit ) = fixture();
	$GLOBALS['fail_update']['hherm_feedback_link_7'] = true;
	$feedback->send_due_reminders();
	\feedback_check( ! $GLOBALS['mail'] && $audit->has_status( 7, 'feedback_email', 'failed' ) && ! $audit->has_status( 7, 'feedback_email', 'sent' ), 'failed token persistence prevents mail handoff and records a retryable failure' );
	unset( $GLOBALS['fail_update']['hherm_feedback_link_7'] ); $feedback->send_due_reminders();
	$token = \feedback_token( $GLOBALS['mail'][0]['body'] );
	\feedback_check( 1 === count( $GLOBALS['mail'] ) && ! \is_wp_error( \feedback_private( $feedback, 'resolve', 7, $token ) ), 'token storage recovery sends a valid link on the next due reminder run' );

	list( $feedback, $settings, $repository, $audit ) = fixture();
	$GLOBALS['fail_mail'] = true; $feedback->send_due_reminders();
	$first_record = $GLOBALS['options']['hherm_feedback_link_7'];
	$first_token = \feedback_token( $GLOBALS['mail'][0]['body'] );
	\feedback_check( $audit->has_status( 7, 'feedback_email', 'failed' ) && ! $audit->has_status( 7, 'feedback_email', 'sent' ), 'transport rejection records failure without marking feedback sent' );
	unset( $GLOBALS['fail_mail'] ); $feedback->send_due_reminders();
	\feedback_check( 2 === count( $GLOBALS['mail'] ) && $first_token === \feedback_token( $GLOBALS['mail'][1]['body'] ) && $first_record === $GLOBALS['options']['hherm_feedback_link_7'], 'transport recovery reuses the persisted feedback token and expiry' );
	\feedback_check( ! \is_wp_error( \feedback_private( $feedback, 'resolve', 7, $first_token ) ), 'the first attempted email URL remains usable after retry' );
	$feedback->send_due_reminders();
	\feedback_check( 2 === count( $GLOBALS['mail'] ), 'successful retry is not resent by later reminder runs' );

	list( $feedback, $settings, $repository, $audit ) = fixture();
	$GLOBALS['throw_mail'] = true; $feedback->send_due_reminders();
	$first_record = $GLOBALS['options']['hherm_feedback_link_7'];
	\feedback_check( $audit->has_status( 7, 'feedback_email', 'failed' ) && ! isset( $GLOBALS['options']['hherm_feedback_delivery_7'] ), 'transport exception is recorded and releases the registration delivery lock' );
	unset( $GLOBALS['throw_mail'] ); $feedback->send_due_reminders();
	\feedback_check( 2 === count( $GLOBALS['mail'] ) && $first_record === $GLOBALS['options']['hherm_feedback_link_7'], 'transport exception recovery reuses the token instead of invalidating the first URL' );

	list( $feedback, $settings, $repository, $audit ) = fixture();
	$settings->values['email_mode'] = 'log'; $feedback->send_due_reminders();
	$first_record = $GLOBALS['options']['hherm_feedback_link_7']; $feedback->send_due_reminders();
	\feedback_check( ! $GLOBALS['mail'] && 1 === count( $GLOBALS['wpdb']->logs ) && $audit->has_status( 7, 'feedback_email', 'logged' ) && $first_record === $GLOBALS['options']['hherm_feedback_link_7'], 'log-only delivery is recorded once and does not rotate its link' );

	list( $feedback, $settings, $repository, $audit ) = fixture();
	$old_token = str_repeat( 'a', 48 );
	$legacy = array( 'token_hash' => hash( 'sha256', $old_token ), 'event_id' => 42, 'expires_at' => time() + DAY_IN_SECONDS );
	$GLOBALS['options']['hherm_feedback_link_7'] = $legacy;
	$feedback->send_due_reminders();
	\feedback_check( ! $GLOBALS['mail'] && $legacy === $GLOBALS['options']['hherm_feedback_link_7'] && $audit->has_status( 7, 'feedback_email', 'failed' ), 'a legacy hash-only feedback link is preserved when its raw token cannot safely be recovered' );
	\feedback_check( ! \is_wp_error( \feedback_private( $feedback, 'resolve', 7, $old_token ) ), 'a previously emailed legacy URL continues to resolve after a reminder attempt' );

	list( $feedback, $settings, $repository, $audit ) = fixture();
	$cases = array( '1251 Unicode characters above the old byte limit' => 'a' . str_repeat( '😀', 1250 ), 'exactly 5000 Unicode characters' => str_repeat( '—', 5000 ) );
	foreach ( $cases as $label => $text ) {
		$_POST = array( 'hherm_feedback_nonce' => 'valid', 'feedback' => $text, 'feedback_score' => '5' );
		$result = \feedback_private( $feedback, 'submit', $repository->items[7], str_repeat( 'b', 48 ) );
		$last = end( $repository->updates );
		\feedback_check( ! \is_wp_error( $result ) && $text === $last[1] && 1 === preg_match( '//u', $last[1] ), $label . ' are saved intact' );
	}
	foreach ( array( str_repeat( 'a', 5001 ), str_repeat( '😀', 5001 ) ) as $text ) {
		$count = count( $repository->updates ); $audit_count = count( $GLOBALS['wpdb']->logs );
		$_POST = array( 'hherm_feedback_nonce' => 'valid', 'feedback' => $text, 'feedback_score' => '5' );
		$result = \feedback_private( $feedback, 'submit', $repository->items[7], str_repeat( 'b', 48 ) );
		\feedback_check( \is_wp_error( $result ) && 'hherm_feedback_length' === $result->get_error_code() && $count === count( $repository->updates ) && $audit_count === count( $GLOBALS['wpdb']->logs ), '5001 ' . ( 'a' === $text[0] ? 'ASCII' : 'Unicode' ) . ' characters are rejected before the feedback write or saved audit' );
	}
