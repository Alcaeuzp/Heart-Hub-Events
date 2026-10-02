<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Email_Page {
	private $settings;
	private $email;

	public function __construct( Settings $settings, Email_Service $email ) {
		$this->settings = $settings;
		$this->email    = $email;
	}

	public function register(): void {
		add_action( 'admin_post_hherm_send_test_email', array( $this, 'send_test_email' ) );
	}

	public function send_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send a test email.', 'heart-hub-event-registration-manager' ) );
		}
		check_admin_referer( 'hherm_send_test_email' );
		$status = $this->email->send_internal_test();
		$url = add_query_arg(
			array(
				'page'              => 'hherm',
				'tab'               => 'emails',
				'hherm_test_email'  => 'sent' === $status ? 'sent' : 'failed',
			),
			admin_url( 'admin.php' )
		) . '#hherm-internal-test';
		wp_safe_redirect( $url );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only tab selection; each save uses a Settings API nonce.
	public function render( bool $embedded = false ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have access to email templates.', 'heart-hub-event-registration-manager' ),
				esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ),
				array( 'response' => 403 )
			);
		}

		$templates = $this->settings->emails();
		$definitions = $this->template_definitions();
		$tabs = array( 'brand' => 'Brand', 'signature' => 'Signature' );
		foreach ( $definitions as $slug => $definition ) {
			$tabs[ $slug ] = $definition['tab'];
		}
		$active = isset( $_GET['email_template'] ) ? sanitize_key( wp_unslash( $_GET['email_template'] ) ) : 'approval';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'approval';
		}
		$base_args = $embedded ? array( 'page' => 'hherm', 'tab' => 'emails' ) : array( 'page' => 'hherm-email-templates' );
		?>
		<div class="<?php echo $embedded ? 'hherm-email-editor hherm-settings-email-templates' : 'wrap hherm-email-editor'; ?>">
			<?php if ( $embedded ) : ?>
			<section class="hherm-settings-section hherm-email-template-intro"><div class="hherm-settings-heading"><div><span class="hherm-eyebrow">Content</span><h2>Email templates</h2><p>Select a template to edit it alongside its rendered preview. Each tab saves independently.</p></div></div></section>
			<?php else : ?>
			<h1>Email templates</h1><p>Select a template to edit it alongside its rendered preview. Each tab saves independently.</p>
			<?php endif; ?>
			<?php $this->render_test_panel(); ?>
			<nav class="hherm-email-tabs" id="hherm-email-templates" aria-label="Email template sections">
				<?php foreach ( $tabs as $slug => $label ) : $url = add_query_arg( array_merge( $base_args, array( 'email_template' => $slug ) ), admin_url( 'admin.php' ) ) . '#hherm-email-templates'; ?>
				<a class="<?php echo $slug === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $slug === $active ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'brand' === $active ) {
				$this->render_brand( $templates );
			} elseif ( 'signature' === $active ) {
				$this->render_signature( $templates );
			} else {
				$this->render_template( $active, $definitions[ $active ], $templates );
			}
			?>
		</div>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function render_brand( array $templates ): void {
		$option = Settings::OPTION_EMAILS;
		$this->workspace_open( 'Brand styling', 'These shared settings wrap every event and support-group email.' );
		?>
		<form method="post" action="options.php" class="hherm-email-form">
			<?php settings_fields( 'hherm_emails' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option ); ?>[_scope]" value="brand">
			<label for="hherm-brand-name"><strong>Brand name</strong></label><input class="regular-text" id="hherm-brand-name" name="<?php echo esc_attr( $option ); ?>[brand_name]" value="<?php echo esc_attr( $templates['brand_name'] ); ?>">
			<label for="hherm-logo-url"><strong>Logo URL</strong></label><div class="hherm-email-media-field"><input class="large-text" type="url" id="hherm-logo-url" name="<?php echo esc_attr( $option ); ?>[logo_url]" value="<?php echo esc_attr( $templates['logo_url'] ); ?>"><button type="button" class="button" data-hherm-media data-target="hherm-logo-url">Choose logo</button></div><p class="description">Use an HTTPS image from the WordPress Media Library.</p>
			<label for="hherm-icon-url"><strong>Header icon</strong></label><div class="hherm-email-media-field"><input class="large-text" type="url" id="hherm-icon-url" name="<?php echo esc_attr( $option ); ?>[icon_url]" value="<?php echo esc_attr( $templates['icon_url'] ); ?>"><button type="button" class="button" data-hherm-media data-target="hherm-icon-url">Choose icon</button></div><p class="description">A square icon displayed in the coloured email header.</p>
			<fieldset class="hherm-email-colours"><legend><strong>Colours</strong></legend><label>Header <input type="color" name="<?php echo esc_attr( $option ); ?>[primary_color]" value="<?php echo esc_attr( $templates['primary_color'] ); ?>"></label><label>Background <input type="color" name="<?php echo esc_attr( $option ); ?>[background_color]" value="<?php echo esc_attr( $templates['background_color'] ); ?>"></label><label>Text <input type="color" name="<?php echo esc_attr( $option ); ?>[text_color]" value="<?php echo esc_attr( $templates['text_color'] ); ?>"></label></fieldset>
			<?php submit_button( 'Save branding', 'primary', 'submit', false ); ?>
		</form>
		<?php
		$this->workspace_close( $this->email->preview_subject( 'approved' ), $this->email->preview( 'approved' ), 'Brand styling preview' );
	}

	private function render_signature( array $templates ): void {
		$option = Settings::OPTION_EMAILS;
		$this->workspace_open( 'Reusable signature', 'Insert it into a template with {signature} or [email_signature].' );
		?>
		<form method="post" action="options.php" class="hherm-email-form">
			<?php settings_fields( 'hherm_emails' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option ); ?>[_scope]" value="signature">
			<label class="hherm-email-toggle"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[signature_enabled]" value="true" <?php checked( 'true', $templates['signature_enabled'] ); ?>> Apply the reusable signature wherever its shortcode appears</label>
			<?php wp_editor( $templates['signature_body'], 'hherm_signature_body', array( 'textarea_name' => $option . '[signature_body]', 'textarea_rows' => 12, 'media_buttons' => false ) ); ?>
			<p class="description">Available placeholders: <code>{contact_email}</code>, <code>{events_url}</code>.</p>
			<?php submit_button( 'Save signature', 'primary', 'submit', false ); ?>
		</form>
		<?php
		$this->workspace_close( $this->email->preview_subject( 'approved' ), $this->email->preview( 'approved' ), 'Signature preview' );
	}

	private function render_template( string $slug, array $definition, array $templates ): void {
		$option = Settings::OPTION_EMAILS;
		if ( 'internal' === $definition['preview_type'] ) {
			$preview_subject = $this->email->preview_internal_notification_subject();
			$preview_body = $this->email->preview_internal_notification();
		} elseif ( 'support' === $definition['preview_type'] ) {
			$preview_subject = $this->email->preview_support_group_subject( $definition['preview_status'] );
			$preview_body = $this->email->preview_support_group( $definition['preview_status'] );
		} else {
			$preview_subject = $this->email->preview_subject( $definition['preview_status'] );
			$preview_body = $this->email->preview( $definition['preview_status'] );
		}
		$this->workspace_open( $definition['label'], $definition['description'] );
		?>
		<form method="post" action="options.php" class="hherm-email-form">
			<?php settings_fields( 'hherm_emails' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option ); ?>[_scope]" value="<?php echo esc_attr( $slug ); ?>">
			<label for="hherm-<?php echo esc_attr( $slug ); ?>-subject"><strong>Subject</strong></label>
			<input class="large-text" id="hherm-<?php echo esc_attr( $slug ); ?>-subject" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $definition['subject_key'] ); ?>]" value="<?php echo esc_attr( $templates[ $definition['subject_key'] ] ); ?>">
			<label class="hherm-email-body-label" for="<?php echo esc_attr( $definition['editor_id'] ); ?>"><strong>Email content</strong></label>
			<?php wp_editor( $templates[ $definition['body_key'] ], $definition['editor_id'], array( 'textarea_name' => $option . '[' . $definition['body_key'] . ']', 'textarea_rows' => 17, 'media_buttons' => false ) ); ?>
			<p class="description"><strong>Available placeholders:</strong> <?php foreach ( $definition['placeholders'] as $placeholder ) : ?><code><?php echo esc_html( $placeholder ); ?></code> <?php endforeach; ?></p>
			<?php submit_button( 'Save template', 'primary', 'submit', false ); ?>
		</form>
		<?php
		$this->workspace_close( $preview_subject, $preview_body, $definition['label'] . ' preview' );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result notice; the send action itself verifies a dedicated nonce.
	private function render_test_panel(): void {
		$result = isset( $_GET['hherm_test_email'] ) ? sanitize_key( wp_unslash( $_GET['hherm_test_email'] ) ) : '';
		$recipient = sanitize_email( $this->settings->get( 'notification_email', get_option( 'admin_email' ) ) );
		?>
		<section class="hherm-internal-test" id="hherm-internal-test">
			<div><strong>Internal notification delivery</strong><p>Notifications are addressed to <code><?php echo esc_html( $recipient ); ?></code>. Save a changed address above before testing.</p><?php if ( 'sent' === $result ) : ?><div class="notice notice-success inline"><p>Test email sent successfully.</p></div><?php elseif ( 'failed' === $result ) : ?><div class="notice notice-error inline"><p>WordPress could not send the test email. Check the address and mail service configuration.</p></div><?php endif; ?></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hherm_send_test_email"><?php wp_nonce_field( 'hherm_send_test_email' ); ?><button type="submit" class="button button-secondary">Send test email</button><p class="description">The test is sent immediately even when Email mode is Log only.</p></form>
		</section>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function workspace_open( string $heading, string $description ): void {
		?>
		<div class="hherm-email-workspace"><section class="hherm-email-pane hherm-email-builder"><header><h2><?php echo esc_html( $heading ); ?></h2><p><?php echo esc_html( $description ); ?></p></header>
		<?php
	}

	private function workspace_close( string $preview_subject, string $preview_html, string $preview_title ): void {
		?>
		</section><section class="hherm-email-pane hherm-email-preview"><header><span>Rendered preview</span><h2><?php echo esc_html( $preview_subject ); ?></h2></header><iframe sandbox title="<?php echo esc_attr( $preview_title ); ?>" srcdoc="<?php echo esc_attr( $preview_html ); ?>"></iframe></section></div>
		<?php
	}

	private function template_definitions(): array {
		$event_placeholders = array( '{first_name}', '{applicant_name}', '{event_name}', '{event_url}', '{event_date}', '{event_time}', '{venue}', '{organiser}', '{party_size}', '{event_info}', '{event_details}', '{decline_reason}', '{events_url}', '{contact_email}', '{manage_registration_url}', '{manage_registration_button}', '{feedback_url}', '{feedback_button}', '{signature}', '[email_signature]' );
		$support_placeholders = array( '{first_name}', '{applicant_name}', '{programme_name}', '{programme_start}', '{contact_email}', '{events_url}', '{signature}', '[email_signature]' );
		$internal_placeholders = array( '{notification_action}', '{customer_name}', '{customer_email}', '{customer_phone}', '{customer_details}', '{item_type}', '{item_name}', '{item_details}', '{event_name}', '{programme_name}', '{status}', '{party_size}', '{submitted_at}', '{reason}', '{record_url}', '{record_button}', '{contact_email}', '{events_url}', '{signature}', '[email_signature]' );
		return array(
			'approval' => array( 'tab' => 'Approval', 'label' => 'Approval email', 'description' => 'Sent when an event registration is approved.', 'subject_key' => 'approval_subject', 'body_key' => 'approval_body', 'editor_id' => 'hherm_approval_body', 'preview_type' => 'event', 'preview_status' => 'approved', 'placeholders' => $event_placeholders ),
			'decline' => array( 'tab' => 'Decline', 'label' => 'Decline email', 'description' => 'Sent when an event registration is declined.', 'subject_key' => 'decline_subject', 'body_key' => 'decline_body', 'editor_id' => 'hherm_decline_body', 'preview_type' => 'event', 'preview_status' => 'declined', 'placeholders' => $event_placeholders ),
			'waitlist' => array( 'tab' => 'Waitlist', 'label' => 'Waitlist email', 'description' => 'Sent when an applicant is placed on an event waiting list.', 'subject_key' => 'waitlist_subject', 'body_key' => 'waitlist_body', 'editor_id' => 'hherm_waitlist_body', 'preview_type' => 'event', 'preview_status' => 'waitlist', 'placeholders' => $event_placeholders ),
			'scheduled' => array( 'tab' => 'Date confirmed', 'label' => 'Date confirmed email', 'description' => 'Sent to expressions of interest when a TBA event receives a confirmed schedule.', 'subject_key' => 'schedule_confirmation_subject', 'body_key' => 'schedule_confirmation_body', 'editor_id' => 'hherm_schedule_confirmation_body', 'preview_type' => 'event', 'preview_status' => 'scheduled', 'placeholders' => $event_placeholders ),
			'cancellation' => array( 'tab' => 'Cancellation', 'label' => 'Event cancellation email', 'description' => 'Sent to registered attendees when an event is cancelled.', 'subject_key' => 'cancellation_subject', 'body_key' => 'cancellation_body', 'editor_id' => 'hherm_cancellation_body', 'preview_type' => 'event', 'preview_status' => 'cancelled', 'placeholders' => $event_placeholders ),
			'feedback' => array( 'tab' => 'Feedback', 'label' => 'Post-event feedback request', 'description' => 'Sent automatically after an attended event when no feedback has been recorded.', 'subject_key' => 'feedback_subject', 'body_key' => 'feedback_body', 'editor_id' => 'hherm_feedback_body', 'preview_type' => 'event', 'preview_status' => 'feedback', 'placeholders' => $event_placeholders ),
			'support-confirmed' => array( 'tab' => 'Support confirmed', 'label' => 'Support group confirmation', 'description' => 'Sent when a returning attendee is confirmed automatically.', 'subject_key' => 'support_confirmed_subject', 'body_key' => 'support_confirmed_body', 'editor_id' => 'hherm_support_confirmed_body', 'preview_type' => 'support', 'preview_status' => 'confirmed', 'placeholders' => $support_placeholders ),
			'support-pending' => array( 'tab' => 'Support review', 'label' => 'Support group review acknowledgement', 'description' => 'Sent when a first-time attendee’s registration requires review.', 'subject_key' => 'support_pending_subject', 'body_key' => 'support_pending_body', 'editor_id' => 'hherm_support_pending_body', 'preview_type' => 'support', 'preview_status' => 'pending', 'placeholders' => $support_placeholders ),
			'support-interest' => array( 'tab' => 'Support interest', 'label' => 'Support group expression of interest', 'description' => 'Sent when someone registers interest after the current programme has started.', 'subject_key' => 'support_interest_subject', 'body_key' => 'support_interest_body', 'editor_id' => 'hherm_support_interest_body', 'preview_type' => 'support', 'preview_status' => 'interest', 'placeholders' => $support_placeholders ),
			'internal-notification' => array( 'tab' => 'Internal', 'label' => 'Internal notification', 'description' => 'Sent to the configured staff address for every new event or support-group registration and expression of interest.', 'subject_key' => 'internal_notification_subject', 'body_key' => 'internal_notification_body', 'editor_id' => 'hherm_internal_notification_body', 'preview_type' => 'internal', 'preview_status' => 'internal', 'placeholders' => $internal_placeholders ),
		);
	}
}
