<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const OPTION = 'hherm_settings';
	public const OPTION_OWNER_ROLES = 'hherm_owner_roles';
	public const OPTION_EMAILS = 'hherm_email_templates';
	public const OPTION_AUTOMATION = 'hherm_email_automation';
	public const OPTION_PAGES = 'hherm_public_page_settings';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function defaults(): array {
		return array(
			'cct_slug'        => 'event_registrations',
			'events_cpt'      => 'events',
			'relation_id'     => 0,
			'event_date'      => '',
			'event_start'     => 'start_date',
			'event_end'       => 'end_date__time',
			'event_venue'     => 'venue',
			'event_organiser' => 'organiser',
			'event_info'      => '_description',
			'attendee_count_field'     => 'number_of_attendees',
			'event_remaining_capacity' => 'current_event_capacity',
			'feedback_field'           => 'event_feedback',
			'payment_amount_field'     => 'amount_paid',
			'payment_status_field'     => 'payment_status',
			'payment_method_field'     => 'payment_method',
			'checkin_feedback_enabled' => 'false',
			'events_url'      => home_url( '/events/' ),
			'contact_email'   => get_option( 'admin_email' ),
			'notification_email' => get_option( 'admin_email' ),
			'email_mode'      => 'log',
		);
	}

	public function all(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), $this->defaults() );
		if ( empty( $settings['events_cpt'] ) ) {
			$settings['events_cpt'] = 'events';
		}
		$confirmed_event_fields = array( 'event_start' => 'start_date', 'event_end' => 'end_date__time', 'event_venue' => 'venue', 'event_organiser' => 'organiser', 'event_info' => '_description' );
		foreach ( $confirmed_event_fields as $setting => $field_key ) {
			if ( empty( $settings[ $setting ] ) ) {
				$settings[ $setting ] = $field_key;
			}
		}
		if ( empty( $settings['feedback_field'] ) ) {
			$settings['feedback_field'] = 'event_feedback';
		}
		if ( empty( $settings['notification_email'] ) ) {
			$settings['notification_email'] = get_option( 'admin_email' );
		}
		return $settings;
	}

	public function get( string $key, $fallback = '' ) {
		$settings = $this->all();
		return $settings[ $key ] ?? $fallback;
	}

	public function register_settings(): void {
		register_setting( 'hherm', self::OPTION, array( $this, 'sanitize' ) );
		register_setting( 'hherm', self::OPTION_OWNER_ROLES, array( $this, 'sanitize_roles' ) );
		register_setting( 'hherm', self::OPTION_AUTOMATION, array( $this, 'sanitize_automation' ) );
		register_setting( 'hherm', self::OPTION_PAGES, array( $this, 'sanitize_pages' ) );
		register_setting( 'hherm_emails', self::OPTION_EMAILS, array( $this, 'sanitize_emails' ) );
	}

	public function automation_defaults(): array {
		return array(
			'approval'     => array( 'enabled' => 'true', 'amount' => 0, 'unit' => 'minutes' ),
			'decline'      => array( 'enabled' => 'true', 'amount' => 0, 'unit' => 'minutes' ),
			'waitlist'     => array( 'enabled' => 'true', 'amount' => 0, 'unit' => 'minutes' ),
			'cancellation' => array( 'enabled' => 'true', 'amount' => 0, 'unit' => 'minutes' ),
			'schedule_confirmation' => array( 'enabled' => 'true', 'amount' => 0, 'unit' => 'minutes' ),
			'feedback'     => array( 'enabled' => 'true', 'amount' => 24, 'unit' => 'hours', 'window_amount' => 7, 'window_unit' => 'days' ),
		);
	}

	public function automations(): array {
		$saved = (array) get_option( self::OPTION_AUTOMATION, array() );
		$out = $this->automation_defaults();
		foreach ( $out as $type => $defaults ) {
			if ( isset( $saved[ $type ] ) && is_array( $saved[ $type ] ) ) {
				$out[ $type ] = wp_parse_args( $saved[ $type ], $defaults );
			}
		}
		return $out;
	}

	public function automation( string $type ): array {
		$all = $this->automations();
		return $all[ sanitize_key( $type ) ] ?? array();
	}

	public function automation_enabled( string $type ): bool {
		$settings = $this->automation( $type );
		return 'true' === ( $settings['enabled'] ?? 'false' );
	}

	public function automation_delay( string $type ): int {
		$settings = $this->automation( $type );
		return $this->duration_seconds( absint( $settings['amount'] ?? 0 ), (string) ( $settings['unit'] ?? 'minutes' ) );
	}

	public function feedback_send_window(): int {
		$settings = $this->automation( 'feedback' );
		return max( HOUR_IN_SECONDS, $this->duration_seconds( absint( $settings['window_amount'] ?? 7 ), (string) ( $settings['window_unit'] ?? 'days' ) ) );
	}

	public function page_defaults(): array {
		return array(
			'header_logo_url'        => '',
			'header_logo_alt'        => '',
			'header_text'            => '',
			'footer_text'            => '',
			'logo_width'             => 150,
			'header_background_color'=> '#35657f',
			'header_text_color'      => '#ffffff',
			'page_background_color'  => '#f4f8fb',
			'surface_color'          => '#ffffff',
			'heading_color'          => '#16384a',
			'text_color'             => '#2c4a5a',
			'muted_color'            => '#55707f',
			'accent_color'           => '#35657f',
			'button_text_color'      => '#ffffff',
			'border_color'           => '#dbe6ee',
			'checkin_label'          => 'Event check-in',
			'checkin_intro'          => 'Let us know you’ve arrived and a volunteer will sign you in.',
			'checkin_help'           => 'Can’t find your email? Speak to a volunteer at the check-in desk or email {contact_email}.',
			'checkin_success_heading'=> 'You’re checked in',
			'checkin_success_text'   => 'Your attendance has been recorded. Thank you for coming to {event_name}.',
			'feedback_label'         => 'Event feedback',
			'feedback_intro'         => 'We would value your honest feedback. Your response is stored with your event registration.',
			'feedback_success_heading'=> 'Thank you',
			'feedback_success_text'  => 'Your feedback has been received and will help us improve future events.',
			'manage_label'           => 'Attendance management',
			'manage_intro'           => 'Use this page to update your group details or withdraw your place before the event’s cancellation deadline.',
		);
	}

	public function pages(): array {
		return wp_parse_args( (array) get_option( self::OPTION_PAGES, array() ), $this->page_defaults() );
	}

	public function page( string $key, $fallback = '' ) {
		$pages = $this->pages();
		return $pages[ $key ] ?? $fallback;
	}

	public function email_defaults(): array {
		return array(
			'brand_name'       => 'Heart Hub South West',
			'logo_url'         => '',
			'icon_url'         => '',
			'primary_color'    => '#4f87ad',
			'background_color' => '#f4f8fb',
			'text_color'       => '#16384a',
			'approval_subject' => 'Your place at {event_name} is confirmed',
			'signature_enabled' => 'true',
			'signature_body'    => '<p>Warm wishes,<br>Heart Hub South West</p>',
			'approval_body'    => '<p>Hello {first_name},</p><p>We are pleased to confirm that your place has been approved.</p>{event_details}{manage_registration_button}<p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'decline_subject'  => 'About your registration for {event_name}',
			'decline_body'     => '<p>Hello {first_name},</p><p>We are sorry that we cannot confirm your place at this time.</p><p><strong>Reason:</strong> {decline_reason}</p><p>Future events may become available. You are welcome to <a href="{events_url}">view upcoming events</a> and apply again.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'waitlist_subject'  => 'You are on the waiting list for {event_name}',
			'waitlist_body'     => '<p>Hello {first_name},</p><p>We have added your group of {party_size} to the waiting list for <strong>{event_name}</strong>.</p>{event_details}<p>You do not need to register again. We will contact you if places become available.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'cancellation_subject' => '{event_name} has been cancelled',
			'cancellation_body'    => '<p>Hello {first_name},</p><p>We are sorry to let you know that <strong>{event_name}</strong> has been cancelled.</p>{event_details}<p>You can <a href="{events_url}">view other upcoming events</a>.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'schedule_confirmation_subject' => 'A date has been confirmed for {event_name}',
			'schedule_confirmation_body'    => '<p>Hello {first_name},</p><p>You asked us to let you know when a date was confirmed for <strong>{event_name}</strong>. The event is now scheduled.</p>{event_details}<p><a href="{event_url}">View the event and register</a>.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'feedback_subject'     => 'Thank you for attending {event_name}',
			'feedback_body'        => '<p>Hello {first_name},</p><p>Thank you for attending <strong>{event_name}</strong>. We hope you found the event worthwhile.</p><p>Your feedback helps us improve future events. If you have a moment, we would be grateful if you shared your experience.</p>{feedback_button}<p>If the button does not work, copy this link into your browser:<br><a href="{feedback_url}">{feedback_url}</a></p>{signature}',
			'support_confirmed_subject' => 'Your upcoming support group registration is confirmed',
			'support_confirmed_body'    => '<p>Hello {first_name},</p><p>This is your confirmation for the upcoming support group.</p><p><strong>{programme_name}</strong> starts on {programme_start}.</p><p>Someone will be in contact with you closer to the date and provide more information on how to connect.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'support_pending_subject'   => 'Thank you for registering for our upcoming support group',
			'support_pending_body'      => '<p>Hello {first_name},</p><p>Thank you for registering for our upcoming support group.</p><p>Someone will be in contact with you to confirm your registration.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'support_interest_subject'  => 'Thank you for your support group expression of interest',
			'support_interest_body'     => '<p>Hello {first_name},</p><p>Thank you for registering your expression of interest for our next online support group.</p><p>Someone will contact you when the next programme is available.</p><p>If you have any questions, contact us at <a href="mailto:{contact_email}">{contact_email}</a>.</p>{signature}',
			'internal_notification_subject' => 'New {notification_action}: {item_name}',
			'internal_notification_body'    => '<p>A new <strong>{notification_action}</strong> has been received and is ready for review.</p>{customer_details}{item_details}{record_button}{signature}',
		);
	}

	public function emails(): array {
		return wp_parse_args( (array) get_option( self::OPTION_EMAILS, array() ), $this->email_defaults() );
	}

	public function email( string $key, $fallback = '' ) {
		$emails = $this->emails();
		return $emails[ $key ] ?? $fallback;
	}

	public function sanitize_emails( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$defaults = $this->email_defaults();
		$scope = sanitize_key( (string) ( $input['_scope'] ?? '' ) );
		$scope_fields = array(
			'approval'          => array( 'approval_subject', 'approval_body' ),
			'decline'           => array( 'decline_subject', 'decline_body' ),
			'waitlist'          => array( 'waitlist_subject', 'waitlist_body' ),
			'scheduled'         => array( 'schedule_confirmation_subject', 'schedule_confirmation_body' ),
			'cancellation'      => array( 'cancellation_subject', 'cancellation_body' ),
			'feedback'          => array( 'feedback_subject', 'feedback_body' ),
			'support-confirmed' => array( 'support_confirmed_subject', 'support_confirmed_body' ),
			'support-pending'   => array( 'support_pending_subject', 'support_pending_body' ),
			'support-interest'  => array( 'support_interest_subject', 'support_interest_body' ),
			'internal-notification' => array( 'internal_notification_subject', 'internal_notification_body' ),
		);
		if ( 'brand' === $scope ) {
			$out = $this->emails();
			$out['brand_name'] = sanitize_text_field( $input['brand_name'] ?? $out['brand_name'] );
			$out['logo_url'] = esc_url_raw( $input['logo_url'] ?? '' );
			$out['icon_url'] = esc_url_raw( $input['icon_url'] ?? '' );
			foreach ( array( 'primary_color', 'background_color', 'text_color' ) as $key ) {
				$value = sanitize_hex_color( $input[ $key ] ?? '' );
				$out[ $key ] = $value ?: $out[ $key ];
			}
			return wp_parse_args( $out, $defaults );
		}
		if ( 'signature' === $scope ) {
			$out = $this->emails();
			$out['signature_enabled'] = isset( $input['signature_enabled'] ) ? 'true' : 'false';
			$out['signature_body'] = wp_kses_post( $input['signature_body'] ?? $out['signature_body'] );
			return wp_parse_args( $out, $defaults );
		}
		if ( isset( $scope_fields[ $scope ] ) ) {
			$out = $this->emails();
			list( $subject_key, $body_key ) = $scope_fields[ $scope ];
			$out[ $subject_key ] = sanitize_text_field( $input[ $subject_key ] ?? $out[ $subject_key ] );
			$out[ $body_key ] = wp_kses_post( $input[ $body_key ] ?? $out[ $body_key ] );
			return wp_parse_args( $out, $defaults );
		}

		// Backward-compatible full save for requests created by versions before per-template forms.
		$out = $this->emails();
		$out['brand_name']       = sanitize_text_field( $input['brand_name'] ?? $out['brand_name'] );
		$out['logo_url']         = esc_url_raw( $input['logo_url'] ?? '' );
		$out['icon_url']         = esc_url_raw( $input['icon_url'] ?? '' );
		$out['signature_enabled'] = isset( $input['signature_enabled'] ) ? 'true' : 'false';
		$out['signature_body']    = wp_kses_post( $input['signature_body'] ?? $out['signature_body'] );
		$out['approval_subject'] = sanitize_text_field( $input['approval_subject'] ?? $out['approval_subject'] );
		$out['decline_subject']  = sanitize_text_field( $input['decline_subject'] ?? $out['decline_subject'] );
		$out['waitlist_subject'] = sanitize_text_field( $input['waitlist_subject'] ?? $out['waitlist_subject'] );
		$out['cancellation_subject'] = sanitize_text_field( $input['cancellation_subject'] ?? $out['cancellation_subject'] );
		$out['schedule_confirmation_subject'] = sanitize_text_field( $input['schedule_confirmation_subject'] ?? $out['schedule_confirmation_subject'] );
		$out['feedback_subject'] = sanitize_text_field( $input['feedback_subject'] ?? $out['feedback_subject'] );
		$out['approval_body']    = wp_kses_post( $input['approval_body'] ?? $out['approval_body'] );
		$out['decline_body']     = wp_kses_post( $input['decline_body'] ?? $out['decline_body'] );
		$out['waitlist_body']    = wp_kses_post( $input['waitlist_body'] ?? $out['waitlist_body'] );
		$out['cancellation_body'] = wp_kses_post( $input['cancellation_body'] ?? $out['cancellation_body'] );
		$out['schedule_confirmation_body'] = wp_kses_post( $input['schedule_confirmation_body'] ?? $out['schedule_confirmation_body'] );
		$out['feedback_body'] = wp_kses_post( $input['feedback_body'] ?? $out['feedback_body'] );
		$out['support_confirmed_subject'] = sanitize_text_field( $input['support_confirmed_subject'] ?? $out['support_confirmed_subject'] );
		$out['support_confirmed_body'] = wp_kses_post( $input['support_confirmed_body'] ?? $out['support_confirmed_body'] );
		$out['support_pending_subject'] = sanitize_text_field( $input['support_pending_subject'] ?? $out['support_pending_subject'] );
		$out['support_pending_body'] = wp_kses_post( $input['support_pending_body'] ?? $out['support_pending_body'] );
		$out['support_interest_subject'] = sanitize_text_field( $input['support_interest_subject'] ?? $out['support_interest_subject'] );
		$out['support_interest_body'] = wp_kses_post( $input['support_interest_body'] ?? $out['support_interest_body'] );
		$out['internal_notification_subject'] = sanitize_text_field( $input['internal_notification_subject'] ?? $out['internal_notification_subject'] );
		$out['internal_notification_body'] = wp_kses_post( $input['internal_notification_body'] ?? $out['internal_notification_body'] );
		foreach ( array( 'primary_color', 'background_color', 'text_color' ) as $key ) {
			$value = sanitize_hex_color( $input[ $key ] ?? '' );
			$out[ $key ] = $value ?: $out[ $key ];
		}
		return $out;
	}

	public function sanitize_automation( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out = $this->automation_defaults();
		foreach ( $out as $type => $defaults ) {
			$row = isset( $input[ $type ] ) && is_array( $input[ $type ] ) ? $input[ $type ] : array();
			$unit = $this->sanitize_duration_unit( $row['unit'] ?? $defaults['unit'] );
			$amount = min( absint( $row['amount'] ?? $defaults['amount'] ), max( 0, (int) floor( YEAR_IN_SECONDS / $this->unit_seconds( $unit ) ) ) );
			$out[ $type ] = array(
				'enabled' => isset( $row['enabled'] ) ? 'true' : 'false',
				'amount'  => $amount,
				'unit'    => $unit,
			);
			if ( 'feedback' === $type ) {
				$window_unit = $this->sanitize_duration_unit( $row['window_unit'] ?? $defaults['window_unit'] );
				$window_max = max( 1, (int) floor( YEAR_IN_SECONDS / $this->unit_seconds( $window_unit ) ) );
				$out[ $type ]['window_amount'] = max( 1, min( absint( $row['window_amount'] ?? $defaults['window_amount'] ), $window_max ) );
				$out[ $type ]['window_unit'] = $window_unit;
			}
		}
		return $out;
	}

	public function sanitize_pages( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out = $this->page_defaults();
		$out['header_logo_url'] = esc_url_raw( $input['header_logo_url'] ?? '' );
		foreach ( array( 'header_logo_alt', 'header_text', 'footer_text', 'checkin_label', 'feedback_label', 'manage_label', 'checkin_success_heading', 'feedback_success_heading' ) as $key ) {
			$out[ $key ] = sanitize_text_field( $input[ $key ] ?? $out[ $key ] );
		}
		foreach ( array( 'checkin_intro', 'checkin_help', 'checkin_success_text', 'feedback_intro', 'feedback_success_text', 'manage_intro' ) as $key ) {
			$out[ $key ] = sanitize_textarea_field( $input[ $key ] ?? $out[ $key ] );
		}
		foreach ( array( 'header_background_color', 'header_text_color', 'page_background_color', 'surface_color', 'heading_color', 'text_color', 'muted_color', 'accent_color', 'button_text_color', 'border_color' ) as $key ) {
			$value = sanitize_hex_color( $input[ $key ] ?? '' );
			$out[ $key ] = $value ?: $out[ $key ];
		}
		$low_contrast = array();
		foreach ( array(
			array( 'header_background_color', 'header_text_color', 'header text' ),
			array( 'accent_color', 'button_text_color', 'button text' ),
			array( 'page_background_color', 'heading_color', 'headings on the page' ),
			array( 'surface_color', 'heading_color', 'headings on cards' ),
			array( 'page_background_color', 'text_color', 'body text on the page' ),
			array( 'surface_color', 'text_color', 'body text on cards' ),
			array( 'page_background_color', 'muted_color', 'muted text on the page' ),
			array( 'surface_color', 'muted_color', 'muted text on cards' ),
			array( 'page_background_color', 'accent_color', 'accent text on the page' ),
			array( 'surface_color', 'accent_color', 'accent links on cards' ),
		) as $pair ) {
			if ( $this->contrast_ratio( $out[ $pair[0] ], $out[ $pair[1] ] ) < 4.5 ) $low_contrast[] = $pair[2];
		}
		$accent_dark = $this->shade_hex( $out['accent_color'], -18 );
		$accent_soft = $this->tint_hex( $out['accent_color'], 88 );
		foreach ( array(
			array( $out['page_background_color'], $accent_dark, 'links on the page' ),
			array( $out['surface_color'], $accent_dark, 'links and controls on cards' ),
			array( $accent_dark, $out['button_text_color'], 'button text on hover' ),
			array( $accent_soft, $accent_dark, 'accent text on highlighted areas' ),
			array( $accent_soft, $out['heading_color'], 'headings on highlighted areas' ),
			array( $accent_soft, $out['text_color'], 'body text on highlighted areas' ),
		) as $pair ) {
			if ( $this->contrast_ratio( $pair[0], $pair[1] ) < 4.5 ) $low_contrast[] = $pair[2];
		}
		if ( $low_contrast ) {
			add_settings_error( self::OPTION_PAGES, 'hherm_page_contrast', 'The colours were saved, but these combinations may be difficult to read: ' . implode( ', ', $low_contrast ) . '.', 'warning' );
		}
		$out['logo_width'] = min( 300, max( 40, absint( $input['logo_width'] ?? $out['logo_width'] ) ) );
		return $out;
	}

	private function contrast_ratio( string $background, string $foreground ): float {
		$values = array();
		foreach ( array( $background, $foreground ) as $hex ) {
			$hex = ltrim( $hex, '#' );
			$channels = array();
			foreach ( array( 0, 2, 4 ) as $offset ) {
				$value = hexdec( substr( $hex, $offset, 2 ) ) / 255;
				$channels[] = $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
			}
			$values[] = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
		}
		return ( max( $values ) + 0.05 ) / ( min( $values ) + 0.05 );
	}

	private function shade_hex( string $hex, int $percent ): string {
		$hex = ltrim( $hex, '#' );
		$factor = ( 100 + $percent ) / 100;
		$parts = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$parts[] = str_pad( dechex( min( 255, max( 0, (int) round( hexdec( substr( $hex, $offset, 2 ) ) * $factor ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return '#' . implode( '', $parts );
	}

	private function tint_hex( string $hex, int $percent ): string {
		$hex = ltrim( $hex, '#' );
		$ratio = min( 100, max( 0, $percent ) ) / 100;
		$parts = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$channel = hexdec( substr( $hex, $offset, 2 ) );
			$parts[] = str_pad( dechex( (int) round( $channel + ( 255 - $channel ) * $ratio ) ), 2, '0', STR_PAD_LEFT );
		}
		return '#' . implode( '', $parts );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out = $this->defaults();
		foreach ( array( 'cct_slug', 'events_cpt', 'event_date', 'event_start', 'event_end', 'event_venue', 'event_organiser', 'event_info', 'attendee_count_field', 'event_remaining_capacity', 'feedback_field', 'payment_amount_field', 'payment_status_field', 'payment_method_field' ) as $key ) {
			$out[ $key ] = sanitize_key( $input[ $key ] ?? '' );
		}
		if ( ( ( '' !== $out['attendee_count_field'] ) xor ( '' !== $out['event_remaining_capacity'] ) ) ) {
			add_settings_error( self::OPTION, 'hherm_capacity_fields_incomplete', 'Enter both capacity field keys, or leave both blank to disable capacity automation.' );
			$previous = wp_parse_args( (array) get_option( self::OPTION, array() ), $this->defaults() );
			$previous_attendee = sanitize_key( $previous['attendee_count_field'] ?? '' );
			$previous_capacity = sanitize_key( $previous['event_remaining_capacity'] ?? '' );
			if ( ( '' !== $previous_attendee ) xor ( '' !== $previous_capacity ) ) {
				$previous_attendee = $this->defaults()['attendee_count_field'];
				$previous_capacity = $this->defaults()['event_remaining_capacity'];
			}
			$out['attendee_count_field'] = $previous_attendee;
			$out['event_remaining_capacity'] = $previous_capacity;
		}
		if ( empty( $out['events_cpt'] ) ) {
			$out['events_cpt'] = 'events';
		}
		$out['relation_id']   = absint( $input['relation_id'] ?? 0 );
		$out['events_url']    = esc_url_raw( $input['events_url'] ?? '' );
		$out['contact_email'] = sanitize_email( $input['contact_email'] ?? '' );
		$out['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );
		if ( ! is_email( $out['notification_email'] ) ) {
			$out['notification_email'] = sanitize_email( get_option( 'admin_email' ) );
		}
		$out['checkin_feedback_enabled'] = isset( $input['checkin_feedback_enabled'] ) && 'true' === $input['checkin_feedback_enabled'] ? 'true' : 'false';
		$out['email_mode']    = ( isset( $input['email_mode'] ) && 'send' === $input['email_mode'] ) ? 'send' : 'log';
		return $out;
	}

	private function duration_seconds( int $amount, string $unit ): int {
		return min( YEAR_IN_SECONDS, max( 0, $amount ) * $this->unit_seconds( $this->sanitize_duration_unit( $unit ) ) );
	}

	private function sanitize_duration_unit( $unit ): string {
		$unit = sanitize_key( (string) $unit );
		return in_array( $unit, array( 'minutes', 'hours', 'days' ), true ) ? $unit : 'minutes';
	}

	private function unit_seconds( string $unit ): int {
		if ( 'days' === $unit ) {
			return DAY_IN_SECONDS;
		}
		if ( 'hours' === $unit ) {
			return HOUR_IN_SECONDS;
		}
		return MINUTE_IN_SECONDS;
	}

	public function sanitize_roles( $roles ): array {
		$valid = array_keys( wp_roles()->roles );
		$selected = array_values( array_intersect( array_map( 'sanitize_key', (array) $roles ), $valid ) );
		$previous = (array) get_option( self::OPTION_OWNER_ROLES, array() );
		foreach ( array_diff( $previous, $selected ) as $removed ) {
			$role = get_role( $removed );
			if ( $role && 'administrator' !== $removed ) {
				$role->remove_cap( Plugin::CAPABILITY );
			}
		}
		return $selected;
	}

	public function render( string $active_tab = 'emails' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$valid_tabs = array( 'emails', 'appearance', 'contact-access', 'event-setup', 'shortcodes' );
		$active_tab = in_array( $active_tab, $valid_tabs, true ) ? $active_tab : 'emails';
		$s              = $this->all();
		$automation     = $this->automations();
		$pages          = $this->pages();
		$roles          = wp_roles()->roles;
		$selected_roles = (array) get_option( self::OPTION_OWNER_ROLES, array() );
		$automation_labels = array(
			'approval'     => array( 'Approval', 'After a registration is approved.' ),
			'decline'      => array( 'Decline', 'After a registration is declined.' ),
			'waitlist'     => array( 'Waitlist', 'After a registration is placed on the waitlist.' ),
			'cancellation' => array( 'Event cancellation', 'After an event is cancelled.' ),
			'schedule_confirmation' => array( 'Date confirmed', 'When a date-to-be-announced event is first changed to Scheduled.' ),
			'feedback'     => array( 'Post-event feedback', 'After the event end time, for attended or partially attended registrations.' ),
		);
		$save_labels = array(
			'emails'         => 'Save email settings',
			'appearance'     => 'Save appearance',
			'contact-access' => 'Save contact and access',
			'event-setup'    => 'Save event setup',
		);
		?>
		<form class="hherm-settings-form" method="post" action="options.php">
		<?php settings_fields( 'hherm' ); ?>

		<div class="hherm-settings-tab-panel <?php echo 'emails' === $active_tab ? 'is-active' : ''; ?>" data-settings-panel="emails">
		<section class="hherm-settings-section" id="hherm-email-delivery"><div class="hherm-settings-heading"><div><span class="hherm-eyebrow">Delivery</span><h2>Automatic emails</h2><p>Enable each trigger and choose how long WordPress waits before it sends or logs that email.</p></div><span class="hherm-settings-mode <?php echo 'send' === $s['email_mode'] ? 'is-live' : ''; ?>"><?php echo 'send' === $s['email_mode'] ? 'Live sending' : 'Log only'; ?></span></div>
		<table class="widefat striped hherm-automation-table"><thead><tr><th>Email</th><th>Automatic</th><th>Send after</th></tr></thead><tbody>
		<?php foreach ( $automation_labels as $type => $copy ) : $row = $automation[ $type ]; ?>
		<tr><th scope="row"><strong><?php echo esc_html( $copy[0] ); ?></strong><small><?php echo esc_html( $copy[1] ); ?></small></th><td><label class="hherm-toggle-label"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_AUTOMATION ); ?>[<?php echo esc_attr( $type ); ?>][enabled]" value="true" <?php checked( 'true', $row['enabled'] ); ?>> Enabled</label></td><td><div class="hherm-duration"><input type="number" min="0" step="1" name="<?php echo esc_attr( self::OPTION_AUTOMATION ); ?>[<?php echo esc_attr( $type ); ?>][amount]" value="<?php echo esc_attr( $row['amount'] ); ?>" aria-label="<?php echo esc_attr( $copy[0] . ' delay amount' ); ?>"><select name="<?php echo esc_attr( self::OPTION_AUTOMATION ); ?>[<?php echo esc_attr( $type ); ?>][unit]" aria-label="<?php echo esc_attr( $copy[0] . ' delay unit' ); ?>"><?php foreach ( array( 'minutes', 'hours', 'days' ) as $unit ) : ?><option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $row['unit'], $unit ); ?>><?php echo esc_html( ucfirst( $unit ) ); ?></option><?php endforeach; ?></select></div><?php if ( 'feedback' === $type ) : ?><div class="hherm-window-setting"><span>Keep checking for</span><input type="number" min="1" step="1" name="<?php echo esc_attr( self::OPTION_AUTOMATION ); ?>[feedback][window_amount]" value="<?php echo esc_attr( $row['window_amount'] ); ?>" aria-label="Feedback send window amount"><select name="<?php echo esc_attr( self::OPTION_AUTOMATION ); ?>[feedback][window_unit]" aria-label="Feedback send window unit"><?php foreach ( array( 'hours', 'days' ) as $unit ) : ?><option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $row['window_unit'], $unit ); ?>><?php echo esc_html( ucfirst( $unit ) ); ?></option><?php endforeach; ?></select><span>after it becomes due</span></div><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<div class="hherm-settings-inline"><label for="hherm-notification-email"><strong>Internal notification email</strong></label><input class="regular-text" type="email" id="hherm-notification-email" name="<?php echo esc_attr( self::OPTION ); ?>[notification_email]" value="<?php echo esc_attr( $s['notification_email'] ); ?>" required><p class="description">New event registrations, event expressions of interest, support-group registrations, and support-group expressions of interest are sent here.</p></div>
		<div class="hherm-settings-inline"><label for="hherm-email-mode"><strong>Email mode</strong></label><select id="hherm-email-mode" name="<?php echo esc_attr( self::OPTION ); ?>[email_mode]"><option value="log" <?php selected( $s['email_mode'], 'log' ); ?>>Log only (safe default)</option><option value="send" <?php selected( $s['email_mode'], 'send' ); ?>>Send real email</option></select><p class="description">Internal notifications follow this mode. Delays are minimums. WP-Cron runs when the site receives traffic; post-event feedback is checked hourly. Saving timing changes also updates unsent queued emails.</p></div></section>
		</div>

		<div class="hherm-settings-tab-panel <?php echo 'appearance' === $active_tab ? 'is-active' : ''; ?>" data-settings-panel="appearance">
		<section class="hherm-settings-section"><h2>Feedback at check-in</h2><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[checkin_feedback_enabled]" value="true" <?php checked( 'true', $s['checkin_feedback_enabled'] ); ?>> Offer an optional rating and comments when guests check in</label><p class="description">Off by default so guests can check in before experiencing the event. Post-event feedback emails are controlled in the Emails tab.</p></section>
		<section class="hherm-settings-section" id="hherm-public-pages"><div class="hherm-settings-heading"><div><span class="hherm-eyebrow">Appearance</span><h2>Temporary attendee pages</h2><p>Shared branding and copy for check-in, registration management, and post-event feedback links.</p></div></div>
		<div class="hherm-settings-grid"><div class="hherm-settings-panel"><h3>Header and footer</h3><label for="hherm-page-logo">Header logo</label><div class="hherm-media-field"><input class="large-text" type="url" id="hherm-page-logo" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[header_logo_url]" value="<?php echo esc_attr( $pages['header_logo_url'] ); ?>"><button type="button" class="button" data-hherm-media data-target="hherm-page-logo">Choose logo</button></div><p class="description">Leave blank for a text-only header.</p>
		<label for="hherm-page-logo-alt">Logo alternative text</label><input class="regular-text" id="hherm-page-logo-alt" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[header_logo_alt]" value="<?php echo esc_attr( $pages['header_logo_alt'] ); ?>">
		<label for="hherm-page-logo-width">Logo width</label><div class="hherm-number-suffix"><input type="number" min="40" max="300" id="hherm-page-logo-width" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[logo_width]" value="<?php echo esc_attr( $pages['logo_width'] ); ?>"><span>px</span></div>
		<label for="hherm-page-header-text">Header text</label><input class="regular-text" id="hherm-page-header-text" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[header_text]" value="<?php echo esc_attr( $pages['header_text'] ); ?>" placeholder="<?php echo esc_attr( $this->email( 'brand_name', 'Heart Hub South West' ) ); ?>"><p class="description">Blank inherits the brand name from Email Templates.</p>
		<label for="hherm-page-footer-text">Footer text</label><input class="regular-text" id="hherm-page-footer-text" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[footer_text]" value="<?php echo esc_attr( $pages['footer_text'] ); ?>"><p class="description">Blank uses the header text and contact email.</p></div>
		<div class="hherm-settings-panel"><h3>Colours</h3><div class="hherm-colour-grid"><?php $colours = array( 'header_background_color' => 'Header', 'header_text_color' => 'Header text', 'page_background_color' => 'Page', 'surface_color' => 'Cards', 'heading_color' => 'Headings', 'text_color' => 'Body text', 'muted_color' => 'Muted text', 'accent_color' => 'Buttons and links', 'button_text_color' => 'Button text', 'border_color' => 'Borders' ); foreach ( $colours as $key => $label ) : ?><label><span><?php echo esc_html( $label ); ?></span><input type="color" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $pages[ $key ] ); ?>"></label><?php endforeach; ?></div><p class="description">Choose contrasting header/button and text colours so attendee pages remain easy to read.</p></div></div>

		<div class="hherm-copy-groups">
		<?php $copy_groups = array(
			'Check-in page' => array( 'checkin_label' => 'Header label', 'checkin_intro' => 'Introduction', 'checkin_help' => 'Help text', 'checkin_success_heading' => 'Success heading', 'checkin_success_text' => 'Success text' ),
			'Feedback page' => array( 'feedback_label' => 'Header label', 'feedback_intro' => 'Introduction', 'feedback_success_heading' => 'Success heading', 'feedback_success_text' => 'Success text' ),
			'Registration management page' => array( 'manage_label' => 'Header label', 'manage_intro' => 'Introduction' ),
		); foreach ( $copy_groups as $group => $fields ) : ?><fieldset class="hherm-copy-group"><legend><?php echo esc_html( $group ); ?></legend><?php foreach ( $fields as $key => $label ) : ?><label for="hherm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><?php if ( false !== strpos( $key, 'intro' ) || false !== strpos( $key, '_text' ) || 'checkin_help' === $key ) : ?><textarea id="hherm-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[<?php echo esc_attr( $key ); ?>]" rows="3"><?php echo esc_textarea( $pages[ $key ] ); ?></textarea><?php else : ?><input id="hherm-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_PAGES ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $pages[ $key ] ); ?>"><?php endif; ?><?php endforeach; ?></fieldset><?php endforeach; ?>
		</div><p class="description">Copy supports <code>{event_name}</code> and <code>{contact_email}</code> where relevant. Form labels and security messages remain managed by the plugin.</p></section>
		</div>

		<div class="hherm-settings-tab-panel <?php echo 'contact-access' === $active_tab ? 'is-active' : ''; ?>" data-settings-panel="contact-access">
		<section class="hherm-settings-section"><div class="hherm-settings-heading"><div><span class="hherm-eyebrow">General</span><h2>Contact and access</h2></div></div><table class="form-table" role="presentation"><tr><th scope="row"><label for="hherm-events-url">Public events page URL</label></th><td><input class="regular-text" type="url" id="hherm-events-url" name="<?php echo esc_attr( self::OPTION ); ?>[events_url]" value="<?php echo esc_attr( $s['events_url'] ); ?>"></td></tr><tr><th scope="row"><label for="hherm-contact-email">Heart Hub contact email</label></th><td><input class="regular-text" type="email" id="hherm-contact-email" name="<?php echo esc_attr( self::OPTION ); ?>[contact_email]" value="<?php echo esc_attr( $s['contact_email'] ); ?>"></td></tr><tr><th scope="row">Owner roles</th><td><?php foreach ( $roles as $slug => $role ) : ?><label class="hherm-role-option"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_OWNER_ROLES ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_roles, true ) ); ?>> <?php echo esc_html( $role['name'] ); ?></label><?php endforeach; ?><p class="description">Selected roles can review registrations and manage events when their role can create or edit the Events post type. Administrators always receive access.</p></td></tr></table></section>
		</div>

		<div class="hherm-settings-tab-panel <?php echo 'event-setup' === $active_tab ? 'is-active' : ''; ?>" data-settings-panel="event-setup">
		<details class="hherm-settings-section hherm-technical-settings"><summary><span><strong>Technical integration</strong><small>JetEngine CCT, relation, event, and payment field identifiers</small></span></summary><table class="form-table" role="presentation"><?php $fields = array( 'cct_slug' => 'Registration CCT slug', 'events_cpt' => 'Events CPT slug', 'relation_id' => 'Events → registrations relation ID', 'event_date' => 'Event date meta key', 'event_start' => 'Start time meta key', 'event_end' => 'End time meta key', 'event_venue' => 'Venue meta key', 'event_organiser' => 'Organiser meta key', 'event_info' => 'Event information meta key', 'attendee_count_field' => 'Registration CCT attendee-count field key', 'event_remaining_capacity' => 'Event remaining-capacity meta key', 'feedback_field' => 'Registration CCT feedback field key', 'payment_amount_field' => 'Registration CCT amount-paid field key', 'payment_status_field' => 'Registration CCT payment-status field key', 'payment_method_field' => 'Registration CCT payment-method field key' ); foreach ( $fields as $key => $label ) : ?><tr><th scope="row"><label for="hherm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input class="regular-text" id="hherm-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>"><?php if ( 'event_remaining_capacity' === $key ) : ?><p class="description">Leave both capacity field keys blank to disable capacity automation.</p><?php elseif ( 'payment_amount_field' === $key ) : ?><p class="description">These optional CCT mappings make existing per-registration payment data visible in customer history. The plugin does not process payments.</p><?php endif; ?></td></tr><?php endforeach; ?></table></details>
		<section class="hherm-settings-section hherm-installation-checklist"><h2>Installation checklist</h2><ol><li>Confirm the Events CPT slug and event meta keys above.</li><li>Confirm the attendee field is <code>number_of_attendees</code> and remaining-capacity field is <code>current_event_capacity</code>.</li><li>Add a CCT textarea field named <code>event_feedback</code>, or enter its actual key above.</li><li>Map any existing per-registration payment amount, status, and method fields above. Leave a key blank when that value is not stored.</li><li>Confirm the JetEngine relation ID.</li><li>Select the nominated owner role under Contact & Access if anyone other than administrators should review registrations or manage events.</li><li>In form 2774, add a Call Hook named <code>heart_hub_registration_created</code> before the CCT insert action so protected values are normalised.</li><li>Set the internal notification recipient and test delivery under Emails. Remove any older duplicate JetFormBuilder admin notification after verifying the plugin notification.</li><li>Open <strong>Heart Hub Events</strong> in the WordPress admin menu to manage events and registrations.</li></ol></section>
		</div>

		<div class="hherm-settings-tab-panel <?php echo 'shortcodes' === $active_tab ? 'is-active' : ''; ?>" data-settings-panel="shortcodes">
		<section class="hherm-settings-section"><div class="hherm-settings-heading"><div><span class="hherm-eyebrow">Front end</span><h2>Shortcodes</h2><p>Copy these into WordPress or JetEngine text and shortcode elements. Per-event shortcodes use the current event automatically unless an <code>event_id</code> is supplied.</p></div></div>
		<div class="hherm-shortcode-grid">
		<?php $shortcodes = array(
			'[hherm_events_calendar]' => 'Month calendar of events, fundraisers and Calendar Schedule entries. Past events show as Completed. Supports title and show_title; appearance is set in Calendar Settings.',
			'[hherm_fundraisers_calendar]' => 'The same calendar showing only fundraisers and raffles, with a Next fundraiser banner. Reaches back 36 months by default. Supports title, show_title and months_behind (up to 120).',
			'[hherm_total_funds_raised]' => 'Total raised by fundraising events in the last 12 months. Supports months and decimals. Outputs a number only; add any currency symbol before the shortcode.',
			'[hherm_total_attendees]' => 'Total confirmed attendees across completed events in the last 12 months. Supports months.',
			'[hherm_events_run]' => 'Number of events run in the last 12 months. Supports months.',
			'[hherm_event_funds_raised]' => 'Amount raised by the current or selected event. Supports event_id and decimals. Outputs a number only; add any currency symbol before the shortcode.',
			'[hherm_event_attendees]' => 'Confirmed attendee count for the current or selected event. Supports event_id.',
			'[hherm_event_date]' => 'Formatted event date or Date TBC. Supports event_id and format.',
			'[hherm_event_registration_cta]' => 'Correct registration or contact button for the event registration mode.',
			'[hherm_event_remaining_spots]' => 'Remaining-capacity text for the current or selected event.',
			'[hherm_event_fee]' => 'The event’s registration fee and sponsorship cost, or its external fee and payment link. Supports event_id and label attributes (registration_label, sponsorship_label, external_label, external_link_label).',
			'[hherm_event_what_to_expect]' => 'The event’s What to Expect section when enabled.',
			'[hherm_support_groups]' => 'Meeting dates for the selected six-session support group programme. At the registration cutoff, the date list becomes a current-programme-underway message. Supports programme_id, date_format, time_format, empty_text, and underway_text.',
			'[hherm_support_group_name]' => 'The selected support programme name as plain text. Supports programme_id and empty_text.',
			'[hherm_support_group_start_date]' => 'The selected support programme’s first meeting date as plain text. Supports programme_id, date_format, and empty_text.',
			'[hherm_support_group_registration_open]' => 'Returns true before the selected programme’s first meeting starts and false from that cutoff onward. Use it in page-builder dynamic visibility rules.',
			'[hherm_support_group_registration_form]' => 'The title-free built-in form for the selected programme. It becomes an expression-of-interest form after the first meeting starts. Supports programme_id.',
		); foreach ( $shortcodes as $shortcode => $description ) : ?>
			<div class="hherm-shortcode-card"><code><?php echo esc_html( $shortcode ); ?></code><p><?php echo esc_html( $description ); ?></p></div>
		<?php endforeach; ?>
		</div></section>
		</div>

		<?php if ( isset( $save_labels[ $active_tab ] ) ) : ?><div class="hherm-settings-save"><?php submit_button( $save_labels[ $active_tab ], 'primary', 'submit', false ); ?></div><?php endif; ?>
		</form><?php
	}
}
