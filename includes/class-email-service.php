<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';

final class Email_Service {
	public const RETRY_HOOK = 'hherm_retry_direct_email';
	public const RECOVERY_HOOK = 'hherm_recover_direct_email';
	public const CONTINUE_HOOK = 'hherm_continue_direct_email_recovery';
	private const JOB_PREFIX = 'hherm_direct_email_job_';
	private const LOCK_PREFIX = 'hherm_direct_email_lock_';
	private const RECOVERY_CURSOR = 'hherm_direct_email_recovery_cursor';
	private const MAX_ATTEMPTS = 3;
	private $settings;
	private $audit;
	private $self_service;
	private $repository;
	private $retry_generation = '';

	public function __construct( Settings $settings, Audit_Log $audit, ?Registration_Self_Service $self_service = null, ?CCT_Repository $repository = null ) {
		$this->settings      = $settings;
		$this->audit         = $audit;
		$this->self_service = $self_service;
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( self::RETRY_HOOK, array( $this, 'retry_direct_email' ), 10, 2 );
		add_action( self::RECOVERY_HOOK, array( $this, 'recover_direct_emails' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'recover_direct_emails' ) );
		add_action( 'init', array( __CLASS__, 'schedule_recovery' ), 30 );
	}

	public static function schedule_recovery(): void {
		if ( ! wp_next_scheduled( self::RECOVERY_HOOK ) ) wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::RECOVERY_HOOK );
	}

	public static function unschedule(): void {
		wp_unschedule_hook( self::RETRY_HOOK, true );
		wp_clear_scheduled_hook( self::RECOVERY_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		delete_option( self::RECOVERY_CURSOR );
	}

	public function send_decision( array $item, array $event, string $status ): array {
		return $this->send( $item, $event, $status, 'decision_email' );
	}

	public function send_waitlist( array $item, array $event ): array {
		return $this->send( $item, $event, 'waitlist', 'waitlist_email' );
	}

	public function send_cancellation( array $item, array $event ): array {
		$id = absint( $item['_ID'] ?? 0 );
		if ( $this->audit->has_status( $id, 'cancellation_email', 'sent' ) ) {
			return array( 'status' => 'skipped' );
		}
		return $this->send( $item, $event, 'cancelled', 'cancellation_email' );
	}

	public function send_feedback_request( array $item, array $event ): array {
		$id = absint( $item['_ID'] ?? 0 );
		if ( ! $this->settings->automation_enabled( 'feedback' ) || $this->audit->has_status( $id, 'feedback_email', 'sent' ) || $this->audit->has_status( $id, 'feedback_email', 'logged' ) ) {
			return array( 'status' => 'skipped' );
		}
		return $this->send( $item, $event, 'feedback', 'feedback_email' );
	}

	public function send_schedule_confirmed( array $item, array $event ): array {
		return $this->send( $item, $event, 'scheduled', 'schedule_confirmation_email' );
	}

	public function send_interest_received( array $item, array $event ): array {
		$id = absint( $item['_ID'] ?? 0 );
		$customer = sanitize_email( $item['email'] ?? '' );
		$first_name = sanitize_text_field( (string) ( $item['first_name'] ?? '' ) );
		$event_name = sanitize_text_field( (string) ( $event['title'] ?? '' ) );
		$customer_subject = 'Thank you for your expression of interest';
		$customer_body = $this->branded_html( wp_kses_post( '<p>Hello ' . esc_html( $first_name ) . ',</p><p>Thank you for your expression of interest in <strong>' . esc_html( $event_name ) . '</strong>. We’ll be in touch closer to the event date.</p>' ) );
		$customer_status = $this->deliver_message(
			$customer,
			$customer_subject,
			$customer_body,
			$id,
			'interest_customer_email',
			array( 'source' => 'event', 'event_id' => absint( $event['id'] ?? 0 ), 'registration_status' => 'interest' )
		);
		$record_url = $id ? add_query_arg( array( 'page' => 'heart-hub-event-registrations', 'registration_id' => $id ), admin_url( 'admin.php' ) ) : '';
		$team_status = $this->send_internal_notification( $item, $event, 'event expression of interest', $record_url );
		return array( 'customer' => $customer_status, 'team' => $team_status );
	}

	/**
	 * Send the shared staff notification for any public registration or interest submission.
	 */
	public function send_internal_notification( array $customer, array $context, string $action, string $record_url = '', bool $force_send = false, bool $allow_retry = true ): string {
		$to = sanitize_email( $this->settings->get( 'notification_email', get_option( 'admin_email' ) ) );
		$id = absint( $customer['_ID'] ?? $customer['id'] ?? 0 );
		$subject = $this->internal_subject( $customer, $context, $action, $record_url );
		$body = $this->render_internal_notification( $customer, $context, $action, $record_url );
		return $this->deliver_message(
			$to,
			$subject,
			$body,
			$id,
			$force_send ? 'internal_test_email' : 'internal_notification_email',
			array(
				'source'     => sanitize_key( (string) ( $context['source'] ?? ( isset( $context['programme_id'] ) ? 'support_group' : 'event' ) ) ),
				'event_id'   => absint( $context['id'] ?? 0 ),
				'programme_id' => sanitize_key( (string) ( $context['programme_id'] ?? '' ) ),
				'action'     => sanitize_text_field( $action ),
				'record_url' => esc_url_raw( $record_url ),
			),
			$force_send,
			$allow_retry
		);
	}

	/** Send a real delivery test even while normal email mode is Log only. */
	public function send_internal_test(): string {
		$customer = $this->preview_item() + array(
			'phone'                => '0400 000 000',
			'organisation'         => 'Example community organisation',
			'reason_for_attending' => 'This is a test of the internal notification email.',
			'registration_date'    => current_time( 'mysql' ),
			'registration_status'  => 'pending',
		);
		// A test must not attach its audit entry to the sample registration ID.
		$customer['_ID'] = 0;
		$context = $this->preview_event();
		$context['source'] = 'event';
		$record_url = add_query_arg( array( 'page' => 'hherm', 'tab' => 'emails' ), admin_url( 'admin.php' ) );
		return $this->send_internal_notification( $customer, $context, 'test internal notification', $record_url, true );
	}

	private function send( array $item, array $event, string $status, string $audit_type ): array {
		$id = absint( $item['_ID'] ?? 0 );
		$to = sanitize_email( $item['email'] ?? '' );
		if ( ! is_email( $to ) ) {
			$this->audit->write( $id, $audit_type, 'failed', array( 'reason' => 'Invalid recipient', 'event_id' => absint( $event['id'] ?? 0 ) ) );
			return array( 'status' => 'failed', 'retryable' => false );
		}

		$subject = $this->subject( $item, $event, $status );
		$body = $this->render( $item, $event, $status );
		$headers = $this->mail_headers();

		if ( 'send' !== $this->settings->get( 'email_mode', 'log' ) ) {
			$this->audit->write( $id, $audit_type, 'logged', array( 'to' => $to, 'subject' => $subject, 'html' => $body, 'event_id' => absint( $event['id'] ?? 0 ) ) );
			return array( 'status' => 'logged' );
		}

		$sent = wp_mail( $to, $subject, $body, $headers );
		$this->audit->write( $id, $audit_type, $sent ? 'sent' : 'failed', array( 'to' => $to, 'subject' => $subject, 'event_id' => absint( $event['id'] ?? 0 ) ) );
		return array( 'status' => $sent ? 'sent' : 'failed', 'retryable' => ! $sent );
	}

	public function preview( string $status ): string {
		return $this->render( $this->preview_item(), $this->preview_event(), $status, true );
	}

	public function preview_subject( string $status ): string {
		return $this->subject( $this->preview_item(), $this->preview_event(), $status );
	}

	public function send_support_group( array $applicant, array $programme ): bool {
		$status = $this->send_support_group_customer( $applicant, $programme );
		return in_array( $status, array( 'sent', 'logged' ), true );
	}

	/** Send the confirmation after an administrator has approved a pending applicant. */
	public function send_support_group_confirmation( array $applicant, array $programme ): string {
		return $this->send_support_group_customer( $applicant, $programme );
	}

	public function send_support_group_submission( array $applicant, array $programme, string $record_url = '' ): array {
		$status = $this->support_status( (string) ( $applicant['status'] ?? '' ) );
		$action = 'interest' === $status ? 'support group expression of interest' : 'support group registration';
		$context = array(
			'source'          => 'support_group',
			'programme_id'    => sanitize_key( (string) ( $programme['id'] ?? '' ) ),
			'programme_name'  => sanitize_text_field( (string) ( $programme['name'] ?? 'Future support group' ) ),
			'title'           => sanitize_text_field( (string) ( $programme['name'] ?? 'Future support group' ) ),
			'date'            => $this->support_programme_start( $programme ),
		);
		return array(
			'customer' => $this->send_support_group_customer( $applicant, $programme ),
			'team'     => $this->send_internal_notification( $applicant, $context, $action, $record_url ),
		);
	}

	private function send_support_group_customer( array $applicant, array $programme, bool $allow_retry = true ): string {
		$to = sanitize_email( $applicant['email'] ?? '' );
		$status = $this->support_status( (string) ( $applicant['status'] ?? '' ) );
		return $this->deliver_message(
			$to,
			$this->support_subject( $applicant, $programme, $status ),
			$this->render_support_group( $applicant, $programme, $status ),
			absint( $applicant['id'] ?? 0 ),
			'support_customer_email',
			array( 'source' => 'support_group', 'programme_id' => sanitize_key( (string) ( $programme['id'] ?? '' ) ), 'application_type' => sanitize_key( (string) ( $applicant['application_type'] ?? '' ) ), 'registration_status' => $status ),
			false,
			$allow_retry
		);
	}

	public function preview_support_group( string $status ): string {
		return $this->render_support_group( $this->preview_item(), $this->preview_support_programme(), $this->support_status( $status ) );
	}

	public function preview_support_group_subject( string $status ): string {
		return $this->support_subject( $this->preview_item(), $this->preview_support_programme(), $this->support_status( $status ) );
	}

	public function preview_internal_notification(): string {
		$item = $this->preview_item() + array( 'phone' => '0400 000 000', 'organisation' => 'Example community organisation', 'reason_for_attending' => 'Interested in meeting other members of the community.', 'registration_date' => '22 September 2026 10:15 am', 'registration_status' => 'pending' );
		return $this->render_internal_notification( $item, $this->preview_event(), 'event registration', home_url( '/wp-admin/admin.php?page=heart-hub-event-registrations&registration_id=123' ) );
	}

	public function preview_internal_notification_subject(): string {
		return $this->internal_subject( $this->preview_item(), $this->preview_event(), 'event registration', '' );
	}

	private function preview_item(): array {
		return array(
			'_ID'           => 123,
			'first_name'    => 'Alex',
			'last_name'     => 'Taylor',
			'email'         => 'alex@example.com',
			'number_of_attendees' => 2,
			'decline_reason' => 'Places for this session are currently full.',
			'feedback_url'   => home_url( '/?hherm_feedback=123&hherm_feedback_token=preview' ),
		);
	}

	private function preview_event(): array {
		return array(
			'id'        => 456,
			'title'     => 'Heart Hub Community Workshop',
			'url'       => home_url( '/events/heart-hub-community-workshop/' ),
			'date'      => '24 September 2026',
			'start'     => '10:00 am',
			'end'       => '1:00 pm',
			'venue'     => 'Heart Hub South West',
			'organiser' => 'Heart Hub Team',
			'info'      => 'Please arrive ten minutes before the session begins.',
		);
	}

	private function preview_support_programme(): array {
		return array(
			'name'     => 'September 2026 to February 2027',
			'sessions' => array(
				array( 'date' => '2026-09-28', 'start_time' => '17:30', 'end_time' => '18:30' ),
			),
		);
	}

	public function event_context( int $event_id ): array {
		$post = get_post( $event_id );
		if ( ! $post || 'trash' === $post->post_status || $this->settings->get( 'events_cpt', 'events' ) !== $post->post_type ) return array();
		$start_raw = (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true );
		$end_raw = (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true );
		$date_key = sanitize_key( $this->settings->get( 'event_date', '' ) );
		$date_raw = $date_key ? get_post_meta( $event_id, $date_key, true ) : '';
		$start = $this->parse_event_datetime( $start_raw );
		$end = $this->parse_event_datetime( $end_raw );
		$date = $this->parse_event_datetime( $date_raw );
		if ( ! $date ) $date = $start;
		$date_status = Event_Public_Display::normalise_date_status( get_post_meta( $event_id, 'event_date_status', true ), get_post_meta( $event_id, 'event_schedule_status', true ) );
		$title = (string) get_post_meta( $event_id, 'event_name', true );
		$info = (string) get_post_meta( $event_id, $this->settings->get( 'event_info', '_description' ), true );
		return array(
			'id'        => $event_id,
			'title'     => $title ?: get_the_title( $event_id ),
			'url'       => get_permalink( $event_id ),
			'date'      => 'tbc' === $date_status ? 'Date TBC' : ( $date ? wp_date( Event_Datetime::DATE_FORMAT, $date->getTimestamp(), wp_timezone() ) : '' ),
			'start'     => $start ? wp_date( Event_Datetime::TIME_FORMAT, $start->getTimestamp(), wp_timezone() ) : '',
			'end'       => $end ? wp_date( Event_Datetime::TIME_FORMAT, $end->getTimestamp(), wp_timezone() ) : '',
			'venue'     => (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true ),
			'organiser' => (string) get_post_meta( $event_id, $this->settings->get( 'event_organiser', 'organiser' ), true ),
			'info'      => $info ?: (string) $post->post_content,
			'schedule_status' => sanitize_key( (string) get_post_meta( $event_id, 'event_schedule_status', true ) ),
			'expected_month'  => (string) get_post_meta( $event_id, 'expected_month', true ),
		);
	}

	private function parse_event_datetime( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = absint( $value );
			return $timestamp ? ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() ) : false;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) return false;
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			if ( $date ) return $date;
		}
		return false;
	}

	private function subject( array $item, array $event, string $status ): string {
		$key = 'approved' === $status ? 'approval_subject' : ( 'scheduled' === $status ? 'schedule_confirmation_subject' : ( 'cancelled' === $status ? 'cancellation_subject' : ( 'waitlist' === $status ? 'waitlist_subject' : ( 'feedback' === $status ? 'feedback_subject' : 'decline_subject' ) ) ) );
		$template = (string) $this->settings->email( $key );
		return sanitize_text_field( wp_strip_all_tags( strtr( $template, $this->plain_tokens( $item, $event ) ) ) );
	}

	private function render( array $item, array $event, string $status, bool $preview = false ): string {
		$key = 'approved' === $status ? 'approval_body' : ( 'scheduled' === $status ? 'schedule_confirmation_body' : ( 'cancelled' === $status ? 'cancellation_body' : ( 'waitlist' === $status ? 'waitlist_body' : ( 'feedback' === $status ? 'feedback_body' : 'decline_body' ) ) ) );
		$template = (string) $this->settings->email( $key );
		$tokens = $this->tokens( $item, $event );
		$manage_button = '';
		if ( 'approved' === $status && $this->self_service ) {
			$manage_url = $preview ? home_url( '/?hherm_manage_registration=123&hherm_manage_token=preview' ) : $this->self_service->manage_url( $item, $event );
			$tokens['{manage_registration_url}']    = esc_url( $manage_url );
			$tokens['{manage_registration_button}'] = $this->manage_button( $manage_url );
			$manage_button = $tokens['{manage_registration_button}'];
		}
		if ( 'feedback' === $status ) {
			$feedback_url = esc_url( $item['feedback_url'] ?? home_url( '/' ) );
			$tokens['{feedback_url}'] = $feedback_url;
			$button_colour = sanitize_hex_color( $this->settings->email( 'primary_color' ) ) ?: '#4f87ad';
			$tokens['{feedback_button}'] = '<p style="margin:26px 0"><a href="' . $feedback_url . '" style="display:inline-block;background:' . esc_attr( $button_colour ) . ';color:#fff;text-decoration:none;border-radius:7px;padding:12px 18px;font-weight:bold">Share your feedback</a></p>';
		}
		$content = wp_kses_post( strtr( $template, $tokens ) );
		if ( 'approved' === $status && $manage_button && false === strpos( $template, '{manage_registration_button}' ) ) {
			$content .= $manage_button;
		}
		return $this->branded_html( $content );
	}

	private function support_status( string $status ): string {
		$status = sanitize_key( $status );
		return in_array( $status, array( 'confirmed', 'pending', 'interest' ), true ) ? $status : 'pending';
	}

	private function support_template_key( string $status, string $suffix ): string {
		return 'support_' . $this->support_status( $status ) . '_' . $suffix;
	}

	private function support_subject( array $applicant, array $programme, string $status ): string {
		$template = (string) $this->settings->email( $this->support_template_key( $status, 'subject' ) );
		return sanitize_text_field( wp_strip_all_tags( strtr( $template, $this->support_plain_tokens( $applicant, $programme ) ) ) );
	}

	private function render_support_group( array $applicant, array $programme, string $status ): string {
		$template = (string) $this->settings->email( $this->support_template_key( $status, 'body' ) );
		$content = wp_kses_post( strtr( $template, $this->support_tokens( $applicant, $programme ) ) );
		return $this->branded_html( $content );
	}

	private function support_tokens( array $applicant, array $programme ): array {
		$name = trim( (string) ( $applicant['first_name'] ?? '' ) . ' ' . (string) ( $applicant['last_name'] ?? '' ) );
		$contact = sanitize_email( $this->settings->get( 'contact_email', get_option( 'admin_email' ) ) );
		$tokens = array(
			'{first_name}'       => esc_html( $applicant['first_name'] ?? '' ),
			'{applicant_name}'   => esc_html( $name ),
			'{programme_name}'   => esc_html( $programme['name'] ?? '' ),
			'{programme_start}'  => esc_html( $this->support_programme_start( $programme ) ),
			'{contact_email}'    => $contact,
			'{events_url}'       => esc_url( $this->settings->get( 'events_url' ) ),
		);
		$signature_enabled = in_array( strtolower( (string) $this->settings->email( 'signature_enabled', 'true' ) ), array( '1', 'true', 'yes', 'on' ), true );
		$signature = $signature_enabled ? wp_kses_post( strtr( (string) $this->settings->email( 'signature_body', '' ), $tokens ) ) : '';
		$tokens['{signature}'] = $signature;
		$tokens['[email_signature]'] = $signature;
		return $tokens;
	}

	private function support_plain_tokens( array $applicant, array $programme ): array {
		$plain = array();
		foreach ( $this->support_tokens( $applicant, $programme ) as $key => $value ) {
			$plain[ $key ] = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $plain;
	}

	private function support_programme_start( array $programme ): string {
		$session = $programme['sessions'][0] ?? array();
		$date = sanitize_text_field( (string) ( $session['date'] ?? '' ) );
		$time = sanitize_text_field( (string) ( $session['start_time'] ?? '' ) );
		if ( ! $date || ! $time ) {
			return '';
		}
		try {
			$date_time = new \DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
			return wp_date( Event_Datetime::DATETIME_FORMAT, $date_time->getTimestamp(), wp_timezone() );
		} catch ( \Exception $error ) {
			return '';
		}
	}

	private function internal_subject( array $customer, array $context, string $action, string $record_url ): string {
		$template = (string) $this->settings->email( 'internal_notification_subject', 'New {notification_action}: {item_name}' );
		return sanitize_text_field( wp_strip_all_tags( strtr( $template, $this->internal_plain_tokens( $customer, $context, $action, $record_url ) ) ) );
	}

	private function render_internal_notification( array $customer, array $context, string $action, string $record_url ): string {
		$template = (string) $this->settings->email( 'internal_notification_body', '<p>A new <strong>{notification_action}</strong> has been received and is ready for review.</p>{customer_details}{item_details}{record_button}{signature}' );
		$content = wp_kses_post( strtr( $template, $this->internal_tokens( $customer, $context, $action, $record_url ) ) );
		return $this->branded_html( $content );
	}

	private function internal_tokens( array $customer, array $context, string $action, string $record_url ): array {
		$first_name = $this->scalar_text( $customer['first_name'] ?? '' );
		$last_name = $this->scalar_text( $customer['last_name'] ?? '' );
		$name = trim( $first_name . ' ' . $last_name );
		$email = sanitize_email( $customer['email'] ?? '' );
		$phone = $this->scalar_text( $customer['phone'] ?? '' );
		$source = sanitize_key( (string) ( $context['source'] ?? ( isset( $context['programme_id'] ) ? 'support_group' : 'event' ) ) );
		$item_type = 'support_group' === $source ? 'Support group' : 'Event';
		$item_name = $this->scalar_text( $context['programme_name'] ?? $context['title'] ?? '' );
		$submitted = $this->scalar_text( $customer['registration_date'] ?? $customer['created_at'] ?? '' );
		$status = sanitize_key( (string) ( $customer['registration_status'] ?? $customer['status'] ?? '' ) );
		$party_key = sanitize_key( $this->settings->get( 'attendee_count_field', 'number_of_attendees' ) ) ?: 'number_of_attendees';
		$party_size = max( 1, absint( $customer[ $party_key ] ?? $customer['number_of_attendees'] ?? 1 ) );
		$amount_key = sanitize_key( $this->settings->get( 'payment_amount_field', 'amount_paid' ) );
		$payment_status_key = sanitize_key( $this->settings->get( 'payment_status_field', 'payment_status' ) );
		$payment_method_key = sanitize_key( $this->settings->get( 'payment_method_field', 'payment_method' ) );
		$payment_amount = $amount_key ? $this->scalar_text( $customer[ $amount_key ] ?? '' ) : '';
		$payment_status = $payment_status_key ? $this->scalar_text( $customer[ $payment_status_key ] ?? '' ) : '';
		$payment_method = $payment_method_key ? $this->scalar_text( $customer[ $payment_method_key ] ?? '' ) : '';
		$reason = $this->scalar_text( $customer['reason_for_attending'] ?? $customer['reason'] ?? '' );
		$customer_rows = array(
			'Name'                 => $name,
			'Email'                => $email,
			'Phone'                => $phone,
			'Organisation'         => $this->scalar_text( $customer['organisation'] ?? '' ),
			'Party size'           => 'support_group' === $source ? '' : (string) $party_size,
			'Attended support group before' => array_key_exists( 'attended_before', $customer ) ? ( ! empty( $customer['attended_before'] ) ? 'Yes' : 'No' ) : '',
			'Impacted by trauma'   => $this->scalar_text( $customer['impacted_by_trauma'] ?? '' ),
			'Reason / notes'       => $reason,
			'Dietary requirements' => $this->scalar_text( $customer['dietaryrequirements'] ?? '' ),
			'Dietary details'      => $this->scalar_text( $customer['please_let_us_know'] ?? '' ),
		);
		$item_rows = array(
			'Action'         => sanitize_text_field( $action ),
			'Type'           => $item_type,
			$item_type       => $item_name,
			'Date / start'   => $this->scalar_text( $context['date'] ?? '' ),
			'Status'         => $status ? ucwords( str_replace( array( '-', '_' ), ' ', $status ) ) : '',
			'Submitted'      => $submitted,
			'Amount paid'    => $payment_amount,
			'Payment status' => $payment_status,
			'Payment method' => $payment_method,
		);
		$tokens = array(
			'{notification_action}' => esc_html( sanitize_text_field( $action ) ),
			'{customer_name}'       => esc_html( $name ),
			'{customer_email}'      => esc_html( $email ),
			'{customer_phone}'      => esc_html( $phone ),
			'{customer_details}'    => $this->details_table( 'Customer details', $customer_rows ),
			'{item_type}'           => esc_html( $item_type ),
			'{item_name}'           => esc_html( $item_name ),
			'{item_details}'        => $this->details_table( 'Registration details', $item_rows ),
			'{event_name}'          => esc_html( 'support_group' === $source ? '' : $item_name ),
			'{programme_name}'      => esc_html( 'support_group' === $source ? $item_name : '' ),
			'{status}'              => esc_html( $status ),
			'{party_size}'          => esc_html( $party_size ),
			'{submitted_at}'        => esc_html( $submitted ),
			'{reason}'              => esc_html( $reason ),
			'{record_url}'          => esc_url( $record_url ),
			'{record_button}'       => $this->record_button( $record_url ),
			'{contact_email}'       => sanitize_email( $this->settings->get( 'contact_email', get_option( 'admin_email' ) ) ),
			'{events_url}'          => esc_url( $this->settings->get( 'events_url', home_url( '/events/' ) ) ),
		);
		$signature_enabled = in_array( strtolower( (string) $this->settings->email( 'signature_enabled', 'true' ) ), array( '1', 'true', 'yes', 'on' ), true );
		$signature = $signature_enabled ? wp_kses_post( strtr( (string) $this->settings->email( 'signature_body', '' ), $tokens ) ) : '';
		$tokens['{signature}'] = $signature;
		$tokens['[email_signature]'] = $signature;
		return $tokens;
	}

	private function internal_plain_tokens( array $customer, array $context, string $action, string $record_url ): array {
		$plain = array();
		foreach ( $this->internal_tokens( $customer, $context, $action, $record_url ) as $key => $value ) {
			$plain[ $key ] = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $plain;
	}

	private function details_table( string $heading, array $rows ): string {
		$html = '<h2 style="margin:24px 0 8px;font-size:18px">' . esc_html( $heading ) . '</h2><table role="presentation" style="border-collapse:collapse;width:100%;margin:0 0 18px">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<tr><th style="width:34%;text-align:left;vertical-align:top;padding:8px;border-bottom:1px solid #ddd">' . esc_html( $label ) . '</th><td style="padding:8px;border-bottom:1px solid #ddd">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	private function record_button( string $url ): string {
		if ( ! $url ) {
			return '';
		}
		$button_colour = sanitize_hex_color( $this->settings->email( 'primary_color' ) ) ?: '#4f87ad';
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( $button_colour ) . ';color:#fff;text-decoration:none;border-radius:7px;padding:12px 18px;font-weight:bold">View customer record</a></p><p style="margin:-14px 0 18px;color:#55707f;font-size:13px">If the button does not work, copy this link into your browser:<br><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
	}

	private function scalar_text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private function deliver_message( string $to, string $subject, string $body, int $record_id, string $audit_type, array $details = array(), bool $force_send = false, bool $allow_retry = true ): string {
		$recoverable = $allow_retry && ! $force_send && $record_id > 0 && in_array( $details['source'] ?? '', array( 'event', 'support_group' ), true );
		if ( ! $recoverable ) return $this->handoff_message( $to, $subject, $body, $record_id, $audit_type, $details, $force_send );
		$job = array( 'record_id' => $record_id, 'audit_type' => $audit_type, 'details' => $details );
		$key = $this->direct_job_key( $record_id, $audit_type, $details );
		$name = self::LOCK_PREFIX . $key;
		$lock = Mutation_Lock::acquire( $name, HOUR_IN_SECONDS );
		if ( is_wp_error( $lock ) ) return 'failed';
		try {
			if ( ! $this->remove_direct_job( $key ) ) return 'failed';
			try {
				$status = $this->handoff_message( $to, $subject, $body, $record_id, $audit_type, $details );
			} catch ( \Throwable $error ) {
				$status = 'failed';
				$this->audit->write( $record_id, $audit_type, 'failed', $details + array( 'reason' => 'The mail transport raised an error.' ) );
			}
			if ( 'failed' === $status && is_email( $to ) && Mutation_Lock::owns( $name, $lock ) ) $this->queue_direct_retry( $key, $job, 1 );
			return $status;
		} finally {
			Mutation_Lock::release( $name, $lock );
		}
	}

	private function handoff_message( string $to, string $subject, string $body, int $record_id, string $audit_type, array $details = array(), bool $force_send = false ): string {
		if ( ! is_email( $to ) ) {
			$this->audit->write( $record_id, $audit_type, 'failed', array_merge( $details, array( 'reason' => 'Invalid recipient' ) ) );
			return 'failed';
		}
		$audit_details = array_merge( $details, array( 'to' => $to, 'subject' => $subject ) );
		if ( $this->retry_generation ) $audit_details['delivery_generation'] = $this->retry_generation;
		if ( ! $force_send && 'send' !== $this->settings->get( 'email_mode', 'log' ) ) {
			$audit_details['html'] = $body;
			$this->audit->write( $record_id, $audit_type, 'logged', $audit_details );
			return 'logged';
		}
		$sent = wp_mail( $to, $subject, $body, $this->mail_headers() );
		$this->audit->write( $record_id, $audit_type, $sent ? 'sent' : 'failed', $audit_details );
		return $sent ? 'sent' : 'failed';
	}

	/** Jobs contain record identifiers only; customer details are rebuilt at delivery time. */
	private function queue_direct_retry( string $key, array $job, int $attempts ): void {
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			// If cleanup fails, both the stored marker and delivery audit prevent an
			// old descriptor from starting a fresh round of transport attempts.
			$job['attempts'] = $attempts;
			$job['terminal'] = true;
			update_option( self::JOB_PREFIX . $key, $job, false );
			$this->audit->write( absint( $job['record_id'] ?? 0 ), (string) ( $job['audit_type'] ?? '' ), 'failed_terminal', array( 'reason' => 'Maximum automatic delivery attempts reached.', 'delivery_generation' => (string) ( $job['generation'] ?? '' ) ) + (array) ( $job['details'] ?? array() ) );
			$this->remove_direct_job( $key );
			return;
		}
		$job['attempts'] = $attempts;
		$job['generation'] = bin2hex( random_bytes( 16 ) );
		$job['due_at'] = time() + $attempts * 15 * MINUTE_IN_SECONDS;
		update_option( self::JOB_PREFIX . $key, $job, false );
		if ( get_option( self::JOB_PREFIX . $key, array() ) !== $job ) {
			$this->audit->write( absint( $job['record_id'] ), $job['audit_type'], 'schedule_failed', array( 'reason' => 'The direct email retry could not be stored.' ) + $job['details'] );
			return;
		}
		if ( ! $this->schedule_direct_job( $key, $job ) ) $this->audit->write( absint( $job['record_id'] ), $job['audit_type'], 'schedule_failed', array( 'reason' => 'WP-Cron rejected the direct email retry.' ) + $job['details'] );
	}

	private function schedule_direct_job( string $key, array $job ): bool {
		$args = array( $key, (string) ( $job['generation'] ?? '' ) );
		if ( ! $args[1] ) return false;
		return (bool) wp_next_scheduled( self::RETRY_HOOK, $args ) || true === wp_schedule_single_event( max( time() + 5, absint( $job['due_at'] ?? 0 ) ), self::RETRY_HOOK, $args, true );
	}

	private function direct_job_key( int $id, string $audit_type, array $details ): string {
		return hash( 'sha256', $audit_type . '|' . $id . '|' . ( $details['source'] ?? '' ) . '|' . ( $details['programme_id'] ?? '' ) . '|' . ( $details['registration_status'] ?? '' ) . '|' . ( $details['action'] ?? '' ) );
	}

	private function remove_direct_job( string $key ): bool {
		$job = get_option( self::JOB_PREFIX . $key, array() );
		if ( is_array( $job ) && ! empty( $job['generation'] ) ) wp_clear_scheduled_hook( self::RETRY_HOOK, array( $key, $job['generation'] ), true );
		delete_option( self::JOB_PREFIX . $key );
		return ! get_option( self::JOB_PREFIX . $key, false );
	}

	public function retry_direct_email( string $key, string $generation ): void {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) return;
		$job = get_option( self::JOB_PREFIX . $key, array() );
		if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['generation'] ?? '' ), $generation ) ) return;
		$details = (array) ( $job['details'] ?? array() );
		$locks = array();
		if ( 'support_group' === ( $details['source'] ?? '' ) ) $locks[] = 'hherm_support_lock_' . substr( hash( 'sha256', (string) ( $details['programme_id'] ?? '' ) ), 0, 32 );
		$locks[] = self::LOCK_PREFIX . $key;
		$owned = array();
		try {
			foreach ( $locks as $name ) {
				$lock = Mutation_Lock::acquire( $name, HOUR_IN_SECONDS );
				if ( is_wp_error( $lock ) ) { $this->schedule_direct_job( $key, $job ); return; }
				$owned[ $name ] = $lock;
			}
			$job = get_option( self::JOB_PREFIX . $key, array() );
			if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['generation'] ?? '' ), $generation ) ) return;
			if ( ! empty( $job['delivered'] ) || ! empty( $job['terminal'] ) ) { $this->remove_direct_job( $key ); return; }
			if ( absint( $job['due_at'] ?? 0 ) > time() ) { $this->schedule_direct_job( $key, $job ); return; }
			$entry = $this->audit->latest_entry_checked( absint( $job['record_id'] ?? 0 ), (string) ( $job['audit_type'] ?? '' ) );
			foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
			if ( is_wp_error( $entry ) ) { $this->queue_direct_retry( $key, $job, absint( $job['attempts'] ?? 0 ) + 1 ); return; }
			$entry_details = (array) ( $entry['details'] ?? array() );
			if ( 'failed_terminal' === ( $entry['status'] ?? '' ) && is_array( $entry_details ) && $generation === ( $entry_details['delivery_generation'] ?? '' ) ) { $this->remove_direct_job( $key ); return; }
			if ( in_array( $entry['status'] ?? '', array( 'sent', 'logged' ), true ) && is_array( $entry_details ) && $generation === ( $entry_details['delivery_generation'] ?? '' ) ) {
				$this->record_support_retry_status( $job, (string) $entry['status'] );
				foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
				$this->remove_direct_job( $key );
				return;
			}
			$record = $this->direct_source_record( $job );
			foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
			if ( is_wp_error( $record ) ) {
				if ( 'hherm_direct_stale' === $record->get_error_code() ) { $this->remove_direct_job( $key ); return; }
				$this->queue_direct_retry( $key, $job, absint( $job['attempts'] ?? 0 ) + 1 );
				return;
			}
			$this->retry_generation = $generation;
			$status = $this->deliver_direct_retry( $job, $record );
			$this->retry_generation = '';
			foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
			if ( 'stale' === $status ) { $this->remove_direct_job( $key ); return; }
			if ( in_array( $status, array( 'sent', 'logged' ), true ) ) {
				// Keep a terminal marker if option deletion fails, so maintenance never resends it.
				$job['delivered'] = true; update_option( self::JOB_PREFIX . $key, $job, false );
				$this->record_support_retry_status( $job, $status );
				foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
				$this->remove_direct_job( $key );
			} else {
				$this->record_support_retry_status( $job, 'failed' );
				foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
				$this->queue_direct_retry( $key, $job, absint( $job['attempts'] ?? 0 ) + 1 );
			}
		} catch ( \Throwable $error ) {
			if ( isset( $owned[ self::LOCK_PREFIX . $key ] ) && Mutation_Lock::owns( self::LOCK_PREFIX . $key, $owned[ self::LOCK_PREFIX . $key ] ) ) {
				$this->record_support_retry_status( $job, 'failed' );
				$this->audit->write( absint( $job['record_id'] ?? 0 ), (string) ( $job['audit_type'] ?? '' ), 'failed', array( 'reason' => 'The mail transport raised an error during retry.' ) + (array) ( $job['details'] ?? array() ) );
				foreach ( $owned as $name => $lock ) if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
				$this->queue_direct_retry( $key, $job, absint( $job['attempts'] ?? 0 ) + 1 );
			}
		} finally {
			$this->retry_generation = '';
			foreach ( array_reverse( $owned, true ) as $name => $lock ) Mutation_Lock::release( $name, $lock );
		}
	}

	private function direct_source_record( array $job ) {
		$details = (array) ( $job['details'] ?? array() ); $id = absint( $job['record_id'] ?? 0 );
		if ( 'support_group' === ( $details['source'] ?? '' ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry reads the current plugin-owned applicant under its programme lock.
			$item = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND programme_id = %s LIMIT 1', Support_Groups::table(), $id, (string) ( $details['programme_id'] ?? '' ) ), ARRAY_A );
			if ( ! empty( $wpdb->last_error ) ) return new \WP_Error( 'hherm_direct_unavailable', 'Applicant storage is unavailable.' );
			if ( ! is_array( $item ) ) return new \WP_Error( 'hherm_direct_stale', 'Applicant no longer exists.' );
			if ( 'support_customer_email' === ( $job['audit_type'] ?? '' ) && ( $item['status'] ?? '' ) !== ( $details['registration_status'] ?? '' ) ) return new \WP_Error( 'hherm_direct_stale', 'Applicant status changed.' );
			return $item;
		}
		if ( ! $this->repository ) $this->repository = new CCT_Repository( $this->settings );
		$item = $this->repository->get( $id );
		if ( is_wp_error( $item ) ) return 'hherm_not_found' === $item->get_error_code() ? new \WP_Error( 'hherm_direct_stale', 'Registration no longer exists.' ) : $item;
		if ( absint( $item['event_id'] ?? 0 ) !== absint( $details['event_id'] ?? 0 ) || ( 'interest_customer_email' === ( $job['audit_type'] ?? '' ) && 'interest' !== ( $item['registration_status'] ?? '' ) ) ) return new \WP_Error( 'hherm_direct_stale', 'Registration context changed.' );
		return $item;
	}

	private function deliver_direct_retry( array $job, array $item ): string {
		$details = (array) $job['details']; $id = absint( $job['record_id'] );
		if ( 'support_group' === ( $details['source'] ?? '' ) ) {
			$groups = new Support_Groups( $this->settings ); $programme = array();
			foreach ( $groups->programmes() as $candidate ) if ( $candidate['id'] === ( $details['programme_id'] ?? '' ) ) { $programme = $candidate; break; }
			if ( ! $programme && 'future-support-group' === ( $details['programme_id'] ?? '' ) ) $programme = array( 'id' => 'future-support-group', 'name' => 'Future support group', 'sessions' => array() );
			if ( ! $programme ) return 'stale';
			if ( 'support_customer_email' === $job['audit_type'] ) return $this->send_support_group_customer( $item, $programme, false );
			$context = array( 'source' => 'support_group', 'programme_id' => $programme['id'], 'programme_name' => $programme['name'], 'title' => $programme['name'], 'date' => $this->support_programme_start( $programme ) );
			$url = add_query_arg( array( 'page' => 'hherm-support-group-editor', 'programme_id' => $programme['id'], 'applicant_id' => $id ), admin_url( 'admin.php' ) );
		} else {
			$context = $this->event_context( absint( $details['event_id'] ?? 0 ) );
			if ( ! $context ) return 'stale';
			if ( 'interest_customer_email' === $job['audit_type'] ) {
				$body = $this->branded_html( '<p>Hello ' . esc_html( $item['first_name'] ?? '' ) . ',</p><p>Thank you for your expression of interest in <strong>' . esc_html( $context['title'] ?? '' ) . '</strong>. We’ll be in touch closer to the event date.</p>' );
				return $this->handoff_message( sanitize_email( $item['email'] ?? '' ), 'Thank you for your expression of interest', $body, $id, 'interest_customer_email', $details );
			}
			$context['source'] = 'event';
			$url = add_query_arg( array( 'page' => 'heart-hub-event-registrations', 'registration_id' => $id ), admin_url( 'admin.php' ) );
		}
		return $this->send_internal_notification( $item, $context, (string) ( $details['action'] ?? 'registration' ), $url, false, false );
	}

	private function record_support_retry_status( array $job, string $status ): void {
		$details = (array) ( $job['details'] ?? array() );
		if ( 'support_group' !== ( $details['source'] ?? '' ) ) return;
		$field = 'support_customer_email' === ( $job['audit_type'] ?? '' ) ? 'email_status' : 'internal_email_status';
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reflects recovery on the existing applicant record while holding its programme lock.
		$wpdb->update( Support_Groups::table(), array( $field => $status ), array( 'id' => absint( $job['record_id'] ), 'programme_id' => (string) $details['programme_id'] ), array( '%s' ), array( '%d', '%s' ) );
	}

	public function recover_direct_emails(): void {
		$name = self::LOCK_PREFIX . 'recovery'; $lock = Mutation_Lock::acquire( $name, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $lock ) ) return;
		try {
			global $wpdb; $after = (string) get_option( self::RECOVERY_CURSOR, '' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded enumeration of plugin-owned retry descriptors.
			$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT 100", $wpdb->esc_like( self::JOB_PREFIX ) . '%', $after ) );
			foreach ( $names as $option ) {
				$job = get_option( $option, array() ); $key = substr( $option, strlen( self::JOB_PREFIX ) );
				if ( is_array( $job ) && ! empty( $job['generation'] ) ) $this->schedule_direct_job( $key, $job );
			}
			if ( count( $names ) === 100 ) {
				update_option( self::RECOVERY_CURSOR, end( $names ), false );
				if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
			} else delete_option( self::RECOVERY_CURSOR );
		} finally { Mutation_Lock::release( $name, $lock ); }
	}

	/** Called under the support mutation lock before personal-data deletion. */
	public function forget_support_emails( int $id, string $programme_id ): bool {
		$base = array( 'source' => 'support_group', 'programme_id' => $programme_id );
		foreach ( array( 'confirmed', 'pending', 'interest' ) as $status ) if ( ! $this->remove_direct_job( $this->direct_job_key( $id, 'support_customer_email', $base + array( 'registration_status' => $status ) ) ) ) return false;
		foreach ( array( 'support group registration', 'support group expression of interest' ) as $action ) if ( ! $this->remove_direct_job( $this->direct_job_key( $id, 'internal_notification_email', $base + array( 'action' => $action ) ) ) ) return false;
		return true;
	}

	private function mail_headers(): array {
		$from_name = sanitize_text_field( $this->settings->email( 'brand_name', 'Heart Hub South West' ) );
		return array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . sanitize_email( get_option( 'admin_email' ) ) . '>',
		);
	}

	private function branded_html( string $content ): string {
		$brand = esc_html( $this->settings->email( 'brand_name', 'Heart Hub South West' ) );
		$logo_url = esc_url( $this->settings->email( 'logo_url' ) );
		$icon_url = esc_url( $this->settings->email( 'icon_url' ) );
		$primary = sanitize_hex_color( $this->settings->email( 'primary_color' ) ) ?: '#7c3151';
		$background = sanitize_hex_color( $this->settings->email( 'background_color' ) ) ?: '#f4f1ec';
		$text = sanitize_hex_color( $this->settings->email( 'text_color' ) ) ?: '#292929';
		$logo = $logo_url ? '<img src="' . $logo_url . '" alt="' . $brand . '" style="display:block;max-width:220px;max-height:80px;margin:0 0 12px">' : '';
		$icon = $icon_url ? '<img src="' . $icon_url . '" alt="" width="52" height="52" style="display:block;width:52px;height:52px;object-fit:contain;border-radius:10px">' : '';
		$heading = $logo . '<h1 style="margin:0;font-size:24px;color:#fff">' . $brand . '</h1>';
		$header = $icon ? '<table role="presentation" style="border-collapse:collapse;width:100%"><tr><td style="width:66px;padding:0 14px 0 0;vertical-align:middle">' . $icon . '</td><td style="padding:0;vertical-align:middle">' . $heading . '</td></tr></table>' : $heading;
		return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:' . esc_attr( $background ) . ';font-family:Arial,sans-serif;color:' . esc_attr( $text ) . '"><div style="max-width:640px;margin:0 auto;padding:28px"><div style="background:' . esc_attr( $primary ) . ';color:#fff;padding:22px;border-radius:12px 12px 0 0">' . $header . '</div><div style="background:#fff;padding:26px;border-radius:0 0 12px 12px;line-height:1.55">' . $content . '</div></div></body></html>';
	}

	private function manage_button( string $url ): string {
		if ( ! $url ) {
			return '';
		}
		$button_colour = sanitize_hex_color( $this->settings->email( 'primary_color' ) ) ?: '#4f87ad';
		return '<p style="margin:26px 0 8px"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( $button_colour ) . ';color:#fff;text-decoration:none;border-radius:7px;padding:12px 18px;font-weight:bold">Manage or withdraw your attendance</a></p><p style="margin:0;color:#55707f;font-size:13px">Use this secure link to update your group details or withdraw your place before the event.</p>';
	}

	private function tokens( array $item, array $event ): array {
		$name = trim( (string) ( $item['first_name'] ?? '' ) . ' ' . (string) ( $item['last_name'] ?? '' ) );
		$time = trim( (string) ( $event['start'] ?? '' ) . ( ! empty( $event['end'] ) ? ' – ' . (string) $event['end'] : '' ) );
		$contact = sanitize_email( $this->settings->get( 'contact_email' ) );
		$attendee_key = sanitize_key( $this->settings->get( 'attendee_count_field', 'number_of_attendees' ) );
		$tokens = array(
			'{first_name}'     => esc_html( $item['first_name'] ?? '' ),
			'{applicant_name}' => esc_html( $name ),
			'{event_name}'     => esc_html( $event['title'] ?? '' ),
			'{event_url}'      => esc_url( $event['url'] ?? $this->settings->get( 'events_url' ) ),
			'{event_date}'     => esc_html( $event['date'] ?? '' ),
			'{event_time}'     => esc_html( $time ),
			'{venue}'          => esc_html( $event['venue'] ?? '' ),
			'{organiser}'      => esc_html( $event['organiser'] ?? '' ),
			'{party_size}'     => esc_html( max( 1, absint( $item[ $attendee_key ] ?? $item['number_of_attendees'] ?? $item['attendees'] ?? 1 ) ) ),
			'{event_info}'     => ! empty( $event['info'] ) ? wp_kses_post( wpautop( $event['info'] ) ) : '',
			'{event_details}'  => $this->event_table( $event ),
			'{decline_reason}' => esc_html( $item['decline_reason'] ?? '' ),
			'{events_url}'     => esc_url( $this->settings->get( 'events_url' ) ),
			'{contact_email}'  => $contact,
		);
		$signature_enabled = in_array( strtolower( (string) $this->settings->email( 'signature_enabled', 'true' ) ), array( '1', 'true', 'yes', 'on' ), true );
		$signature = $signature_enabled ? wp_kses_post( strtr( (string) $this->settings->email( 'signature_body', '' ), $tokens ) ) : '';
		$tokens['{signature}'] = $signature;
		$tokens['[email_signature]'] = $signature;
		return $tokens;
	}

	private function plain_tokens( array $item, array $event ): array {
		$plain = array();
		foreach ( $this->tokens( $item, $event ) as $key => $value ) {
			$plain[ $key ] = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $plain;
	}

	private function event_table( array $event ): string {
		$rows = array(
			'Event'     => $event['title'] ?? '',
			'Date'      => $event['date'] ?? '',
			'Time'      => trim( (string) ( $event['start'] ?? '' ) . ( ! empty( $event['end'] ) ? ' – ' . (string) $event['end'] : '' ) ),
			'Venue'     => $event['venue'] ?? '',
			'Organiser' => $event['organiser'] ?? '',
		);
		$html = '<table role="presentation" style="border-collapse:collapse;width:100%;margin:20px 0">';
		foreach ( $rows as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$html .= '<tr><th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">' . esc_html( $label ) . '</th><td style="padding:8px;border-bottom:1px solid #ddd">' . esc_html( $value ) . '</td></tr>';
			}
		}
		return $html . '</table>' . ( ! empty( $event['info'] ) ? wp_kses_post( wpautop( $event['info'] ) ) : '' );
	}
}
