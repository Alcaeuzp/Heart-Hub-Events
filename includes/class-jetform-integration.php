<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';

final class JetForm_Integration {
	public const DIETARY_FIELD = 'dietaryrequirements';
	public const DIETARY_DETAILS_FIELD = 'please_let_us_know';
	private $repository; private $settings; private $audit; private $capacity; private $email; private $capacity_lock = array(); private $pending_registration = array(); private $interest_lock = array(); private $cleanup_registered = false; private $interest_rendered = false;
	public function __construct( CCT_Repository $repository, Settings $settings, Audit_Log $audit, Capacity_Manager $capacity, $email = null ) { $this->repository = $repository; $this->settings = $settings; $this->audit = $audit; $this->capacity = $capacity; $this->email = $email; }
	public function register(): void {
		add_action( 'jet-form-builder/custom-action/heart_hub_registration_created', array( $this, 'normalise_request' ), 10, 2 );
		add_action( 'jet-form-builder/custom-action/heart_hub_interest_created', array( $this, 'normalise_interest_request' ), 10, 2 );
		add_action( 'jet-engine/custom-content-types/created-item/' . sanitize_key( $this->settings->get( 'cct_slug', 'event_registrations' ) ), array( $this, 'after_created' ), 10, 3 );
		add_filter( 'do_shortcode_tag', array( $this, 'replace_future_registration_form' ), 10, 4 );
		add_filter( 'elementor/widget/render_content', array( $this, 'replace_elementor_registration_form' ), 10, 2 );
		add_action( 'admin_post_hherm_event_interest', array( $this, 'submit_interest' ) );
		add_action( 'admin_post_nopriv_hherm_event_interest', array( $this, 'submit_interest' ) );
		add_action( 'wp_footer', array( $this, 'render_future_interest_form' ), 5 );
	}
	public function render_future_interest_form(): void {
		$event_id = $this->queried_event_id();
		if ( ! $this->interest_rendered && $this->interest_is_available( $event_id ) ) echo $this->interest_form( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns escaped plugin-owned form markup.
	}
	public function replace_future_registration_form( string $output, string $tag, array $attributes, array $match ): string {
		if ( 'jet_fb_form' !== $tag || 2774 !== absint( $attributes['form_id'] ?? $attributes['id'] ?? 0 ) ) return $output;
		$event_id = $this->rendered_event_id( $output );
		if ( ! $this->interest_is_available( $event_id ) ) return $output;
		return $this->interest_form( $event_id );
	}
	public function replace_elementor_registration_form( string $content, $widget ): string {
		if ( ! preg_match( '/\bclass=(?:"[^"]*\bjet-form-builder\b[^"]*"|\'[^\']*\bjet-form-builder\b[^\']*\')/i', $content ) || ! preg_match( '/\bdata-form-id=(?:"2774"|\'2774\')/i', $content ) ) return $content;
		$event_id = $this->rendered_event_id( $content );
		return $this->interest_is_available( $event_id ) ? $this->interest_form( $event_id ) : $content;
	}
	public function submit_interest(): void {
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) ), get_permalink( $event_id ) );
		$nonce = sanitize_text_field( wp_unslash( $_POST['hherm_interest_nonce'] ?? '' ) );
		$token = sanitize_text_field( wp_unslash( $_POST[ Public_Form_Token::FIELD ] ?? '' ) );
		if ( ! Public_Form_Token::verify( 'hherm_event_interest_' . $event_id, $nonce, $token ) ) {
			$this->interest_redirect( $return_url, 'expired' );
		}
		if ( ! $this->interest_is_available( $event_id ) ) {
			$this->interest_redirect( $return_url, 'unavailable' );
		}
		$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason_for_attending'] ?? '' ) );
		$honeypot = sanitize_text_field( wp_unslash( $_POST['website'] ?? '' ) );
		if ( $honeypot ) $this->interest_redirect( $return_url, 'received' );
		if ( ! $first_name || ! $last_name || ! is_email( $email ) || strlen( $first_name ) > 100 || strlen( $last_name ) > 100 || strlen( $email ) > 254 || strlen( $phone ) > 50 || strlen( $reason ) > 2000 ) $this->interest_redirect( $return_url, 'invalid' );
		$locked = $this->lock_interest( $event_id, $email );
		if ( is_wp_error( $locked ) ) $this->interest_redirect( $return_url, 'failed' );
		try {
			$duplicate = $this->existing_interest( $event_id, $email );
			if ( is_wp_error( $duplicate ) || ! $this->owns_interest_lock() ) {
				$result = 'failed';
			} elseif ( $duplicate ) {
				$result = 'duplicate';
			} else {
				$item = array( 'event_id' => $event_id, 'user_id' => get_current_user_id(), 'registration_date' => current_time( 'mysql' ), 'registration_status' => 'interest', 'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'phone' => $phone, 'reason_for_attending' => $reason, $this->capacity->attendee_key() ?: 'number_of_attendees' => 1 );
				$item_id = $this->repository->create_registration( $item );
				$result = is_wp_error( $item_id ) ? 'failed' : 'received';
				if ( ! is_wp_error( $item_id ) ) {
					if ( ! $this->owns_interest_lock() ) {
						$this->repository->discard_registration( $item_id );
						$result = 'failed';
					} else {
						try { $this->after_created( $item, $item_id, null ); }
						catch ( \Throwable $error ) { $result = 'failed'; }
					}
				}
			}
		} finally {
			$this->release_interest_lock();
		}
		$this->interest_redirect( $return_url, $result );
	}
	private function existing_interest( int $event_id, string $email ) {
		$existing = $this->repository->event_registrations( $event_id );
		if ( is_wp_error( $existing ) ) return $existing;
		foreach ( $existing as $item ) {
			if ( 'interest' === sanitize_key( $item['registration_status'] ?? '' ) && strtolower( sanitize_email( $item['email'] ?? '' ) ) === strtolower( $email ) ) return true;
		}
		return false;
	}
	private function lock_interest( int $event_id, string $email ) {
		$key = 'hherm_event_interest_lock_' . $event_id . '_' . substr( hash( 'sha256', strtolower( $email ) ), 0, 32 );
		$token = Mutation_Lock::acquire( $key );
		if ( is_wp_error( $token ) ) return $token;
		$this->interest_lock = array( 'key' => $key, 'token' => $token );
		$this->arm_submission_cleanup();
		return true;
	}
	private function release_interest_lock(): void {
		if ( ! $this->interest_lock ) return;
		Mutation_Lock::release( $this->interest_lock['key'], $this->interest_lock['token'] );
		$this->interest_lock = array();
	}
	private function owns_interest_lock(): bool {
		return $this->interest_lock && Mutation_Lock::owns( $this->interest_lock['key'], $this->interest_lock['token'] );
	}
	private function arm_submission_cleanup(): void {
		if ( $this->cleanup_registered ) return;
		$this->cleanup_registered = true;
		register_shutdown_function( array( $this, 'cleanup_pending_submission' ) );
	}
	/** Also release locks when a later JetForm action fails or a redirect exits PHP. */
	public function cleanup_pending_submission(): void {
		if ( $this->capacity_lock ) $this->capacity->abandon_registration( (int) $this->capacity_lock['event_id'] );
		$this->capacity_lock = array();
		$this->pending_registration = array();
		$this->release_interest_lock();
	}
	private function interest_is_available( int $event_id ): bool {
		$post = get_post( $event_id );
		if ( ! $post || 'publish' !== $post->post_status || $this->settings->get( 'events_cpt', 'events' ) !== $post->post_type ) return false;
		if ( 'website_registration' !== Event_Public_Display::normalise_registration_type( get_post_meta( $event_id, 'registration_type', true ) ) ) return false;
		if ( in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true ) ) return false;
		$date_status = Event_Public_Display::normalise_date_status( get_post_meta( $event_id, 'event_date_status', true ), get_post_meta( $event_id, 'event_schedule_status', true ) );
		$opens = $this->datetime_timestamp( get_post_meta( $event_id, 'registration_open', true ) );
		$closes = $this->datetime_timestamp( get_post_meta( $event_id, 'registration_close', true ) );
		if ( $closes && time() >= $closes ) return false;
		$enabled = strtolower( trim( (string) get_post_meta( $event_id, 'registration_enabled', true ) ) );
		if ( 'tbc' !== $date_status && in_array( $enabled, array( '0', 'false', 'no', 'off' ), true ) ) return false;
		return 'tbc' === $date_status || ( $opens && time() < $opens );
	}
	private function queried_event_id(): int {
		$cpt = sanitize_key( (string) $this->settings->get( 'events_cpt', 'events' ) );
		return $cpt && is_singular( $cpt ) ? absint( get_queried_object_id() ) : 0;
	}
	private function rendered_event_id( string $content ): int {
		if ( preg_match_all( '/<input\b[^>]*>/i', $content, $inputs ) ) {
			foreach ( $inputs[0] as $input ) {
				if ( ! preg_match( '/\bname=(?:"event_id"|\'event_id\')/i', $input ) || ! preg_match( '/\bvalue=(?:"(\d+)"|\'(\d+)\')/i', $input, $match ) ) continue;
				$event_id = absint( $match[1] ?: ( $match[2] ?? 0 ) );
				if ( $this->is_event_post( $event_id ) ) return $event_id;
			}
		}
		if ( function_exists( 'jet_engine' ) ) {
			$engine = jet_engine();
			$data = isset( $engine->listings->data ) ? $engine->listings->data : null;
			if ( $data && method_exists( $data, 'get_object_by_context' ) ) {
				$object = $data->get_object_by_context( 'default_object' );
				if ( is_object( $object ) && isset( $object->ID ) && $this->is_event_post( absint( $object->ID ) ) ) return absint( $object->ID );
			}
		}
		$event_id = absint( get_the_ID() );
		return $this->is_event_post( $event_id ) ? $event_id : $this->queried_event_id();
	}
	private function is_event_post( int $event_id ): bool {
		$post = $event_id ? get_post( $event_id ) : null;
		return (bool) ( $post && 'publish' === $post->post_status && $this->settings->get( 'events_cpt', 'events' ) === $post->post_type );
	}
	private function interest_form( int $event_id ): string {
		$this->interest_rendered = true;
		$result = sanitize_key( wp_unslash( $_GET['hherm_interest'] ?? '' ) );
		$messages = array( 'received' => 'Thank you for your expression of interest in this event. We’ll be in touch closer to the event date.', 'duplicate' => 'We already have an expression of interest from this email address.', 'invalid' => 'Please enter your first name, last name and a valid email address.', 'unavailable' => 'Expressions of interest are no longer available for this event.', 'expired' => 'This form has expired. Please refresh the page and submit your details again.', 'failed' => 'We could not save your expression of interest. Please contact the Heart Hub team.' );
		ob_start(); ?>
		<div id="event-expression-interest" class="hherm-event-interest-form">
			<h2><?php echo esc_html__( 'This event is not open yet', 'heart-hub-event-registration-manager' ); ?></h2>
			<p><?php echo esc_html__( 'If you would like to show an expression of interest in attending, please fill out these details.', 'heart-hub-event-registration-manager' ); ?></p>
			<?php if ( isset( $messages[ $result ] ) ) : ?><p class="hherm-event-interest-form__notice" role="status"><?php echo esc_html( $messages[ $result ] ); ?></p><?php endif; ?>
			<?php if ( 'received' !== $result ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hherm_event_interest"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><input type="hidden" name="return_url" value="<?php echo esc_url( get_permalink( $event_id ) ); ?>"><?php wp_nonce_field( 'hherm_event_interest_' . $event_id, 'hherm_interest_nonce' ); ?><?php echo Public_Form_Token::field( 'hherm_event_interest_' . $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped hidden input. ?>
				<label>First name <input type="text" name="first_name" required maxlength="100" autocomplete="given-name"></label><label>Last name <input type="text" name="last_name" required maxlength="100" autocomplete="family-name"></label><label>Email <input type="email" name="email" required maxlength="254" autocomplete="email"></label><label>Phone <input type="tel" name="phone" maxlength="50" autocomplete="tel"></label><label>What interests you about this event? <textarea name="reason_for_attending" rows="4" maxlength="2000"></textarea></label><label class="hherm-interest-hp" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label><button type="submit">Submit expression of interest</button>
			</form><?php endif; ?>
		</div><?php return (string) ob_get_clean();
	}
	private function interest_redirect( string $url, string $result ): void {
		wp_safe_redirect( add_query_arg( 'hherm_interest', sanitize_key( $result ), $url ) . '#event-expression-interest' );
		exit;
	}
	public function normalise_interest_request( $request, $handler ): void {
		$this->cleanup_pending_submission();
		$event_id = absint( $request['event_id'] ?? 0 );
		$post = get_post( $event_id );
		$cpt = $this->settings->get( 'events_cpt' );
		$cancelled = in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true );
		$schedule_status = sanitize_key( (string) get_post_meta( $event_id, 'event_schedule_status', true ) );
		if ( ! $schedule_status ) $schedule_status = get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ) ? 'scheduled' : 'tba';
		$registration_type = Event_Public_Display::normalise_registration_type( get_post_meta( $event_id, 'registration_type', true ) );
		$opens = $this->datetime_timestamp( get_post_meta( $event_id, 'registration_open', true ) );
		$interest_period = 'tba' === $schedule_status || ( $opens && time() < $opens );
		if ( ! $post || ( $cpt && $cpt !== $post->post_type ) || 'publish' !== $post->post_status || $cancelled || ! $interest_period || 'website_registration' !== $registration_type ) {
			$this->form_error( 'Expressions of interest are no longer available for this event.' );
		}
		$fields = $this->submitted_fields( (array) $request ) + array( 'event_id' => $event_id, 'user_id' => get_current_user_id(), 'registration_date' => current_time( 'mysql' ), 'registration_status' => 'interest', 'registration_type' => 'expression_of_interest', $this->capacity->attendee_key() ?: 'number_of_attendees' => 1 );
		if ( ! empty( $fields['email'] ) ) {
			$locked = $this->lock_interest( $event_id, $fields['email'] );
			if ( is_wp_error( $locked ) ) $this->form_error( 'Your expression of interest is being saved. Please wait and try again.' );
			$duplicate = $this->existing_interest( $event_id, $fields['email'] );
			if ( is_wp_error( $duplicate ) || $duplicate || ! $this->owns_interest_lock() ) {
				$this->release_interest_lock();
				$this->form_error( true === $duplicate ? 'We already have an expression of interest from this email address.' : 'We could not check existing expressions of interest. Please try again.' );
			}
		}
		$this->pending_registration = $fields;
		// The documented interest schema does not require these integration columns.
		// Supply them to configured form mappings without treating their absence as
		// lost customer details; registration_status still identifies an interest.
		unset( $this->pending_registration['user_id'], $this->pending_registration['registration_type'] );
		try {
			if ( function_exists( 'jet_fb_context' ) ) {
				$context = jet_fb_context();
				foreach ( $fields as $key => $value ) $context->update_request( $value, $key );
				$this->normalise_display_context( $context, $event_id );
			}
		} catch ( \Throwable $error ) {
			$this->cleanup_pending_submission();
			throw $error;
		}
	}
	public function normalise_request( $request, $handler ): void {
		if ( method_exists( $handler, 'get_form_id' ) && 2774 !== (int) $handler->get_form_id() ) { return; }
		$this->cleanup_pending_submission();
		$event_id = absint( $request['event_id'] ?? 0 ); $post = get_post( $event_id ); $cpt = $this->settings->get( 'events_cpt' );
		$cancelled = in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true );
		if ( ! $post || ( $cpt && $cpt !== $post->post_type ) || 'publish' !== $post->post_status || $cancelled ) {
			if ( class_exists( '\\Jet_Form_Builder\\Exceptions\\Action_Exception' ) ) { throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'The selected event is unavailable.' ); }
			throw new \RuntimeException( 'The selected event is unavailable.' );
		}
		$enabled = strtolower( trim( (string) get_post_meta( $event_id, 'registration_enabled', true ) ) );
		if ( 'website_registration' !== Event_Public_Display::normalise_registration_type( get_post_meta( $event_id, 'registration_type', true ) ) ) {
			$this->form_error( 'Website registration is not available for this event.' );
		}
		$schedule_status = sanitize_key( (string) get_post_meta( $event_id, 'event_schedule_status', true ) );
		if ( ! $schedule_status ) $schedule_status = get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ) ? 'scheduled' : 'tba';
		if ( 'tba' === $schedule_status ) {
			$this->form_error( 'This event does not have a confirmed date yet. Please use the expression of interest form.' );
		}
		if ( in_array( $enabled, array( '0', 'false', 'no', 'off' ), true ) ) {
			$this->form_error( 'Registrations for this event are closed.' );
		}
		$now = time();
		$opens = $this->datetime_timestamp( get_post_meta( $event_id, 'registration_open', true ) );
		$closes = $this->datetime_timestamp( get_post_meta( $event_id, 'registration_close', true ) );
		if ( $opens && $now < $opens ) {
			$this->form_error( 'Sorry, this event is not open yet. If you would like to show an expression of interest in attending, please fill out the expression of interest form.' );
		}
		if ( $closes && $now >= $closes ) {
			$this->form_error( 'Registrations for this event are not currently open.' );
		}
		if ( $this->capacity->partially_configured() ) { $this->form_error( 'Event capacity is not fully configured. Please contact the site administrator.' ); }
		$attendees = $this->capacity->attendees_from( (array) $request );
		if ( is_wp_error( $attendees ) ) { $this->form_error( $attendees->get_error_message() ); }
		$dietary = $this->dietary_fields( (array) $request );
		$fields = $this->submitted_fields( (array) $request );
		$registration_status = 'pending';
		if ( $this->capacity->enabled() ) {
			$attendees = $this->capacity->attendees_from( (array) $request );
			if ( is_wp_error( $attendees ) ) { $this->form_error( $attendees->get_error_message() ); }
			$locked = $this->capacity->lock_for_registration( $event_id, $attendees );
			if ( is_wp_error( $locked ) ) {
				if ( 'hherm_event_at_capacity' === $locked->get_error_code() && $this->waitlist_enabled( $event_id ) ) {
					$registration_status = 'waitlist';
				} else {
					$this->form_error( $locked->get_error_message() );
				}
			} else {
				$this->capacity_lock = array( 'event_id' => $event_id, 'attendees' => $attendees );
				$this->arm_submission_cleanup();
			}
		}
		$fields += array( 'event_id' => $event_id, 'user_id' => get_current_user_id(), 'registration_date' => current_time( 'mysql' ), 'registration_status' => $registration_status, $this->capacity->attendee_key() ?: 'number_of_attendees' => $attendees, self::DIETARY_FIELD => $dietary['choice'], self::DIETARY_DETAILS_FIELD => $dietary['details'] );
		$this->pending_registration = $fields;
		unset( $this->pending_registration['user_id'] );
		try {
			if ( function_exists( 'jet_fb_context' ) ) {
				$context = jet_fb_context();
				foreach ( $fields as $key => $value ) $context->update_request( $value, $key );
				$this->normalise_display_context( $context, $event_id );
			}
		} catch ( \Throwable $error ) {
			$this->cleanup_pending_submission();
			throw $error;
		}
	}
	public function after_created( $item, $item_id, $handler ): void {
		$registration_id = absint( $item_id );
		if ( $this->repository->defer_created_registration( $registration_id ) ) return;
		$expected = $this->pending_registration;
		$this->pending_registration = array();
		$event_id = absint( $expected['event_id'] ?? ( is_array( $item ) ? ( $item['event_id'] ?? 0 ) : ( $item->event_id ?? 0 ) ) );
		try {
		if ( $event_id && $item_id ) {
			if ( $this->interest_lock && ! $this->owns_interest_lock() ) $this->reject_created_registration( $registration_id, $event_id, new \WP_Error( 'hherm_interest_lock_lost', 'Your expression of interest could not be saved safely. Please try again.' ) );
			// Repair missing form mappings from the validated submission, then verify
			// persisted values before reserving places or reporting success by email.
			$item_array = $expected ? $this->repository->update_registration( $registration_id, $expected ) : $this->repository->get( $registration_id );
			if ( is_wp_error( $item_array ) ) $this->reject_created_registration( $registration_id, $event_id, $item_array );
			if ( $this->interest_lock && ! $this->owns_interest_lock() ) $this->reject_created_registration( $registration_id, $event_id, new \WP_Error( 'hherm_interest_lock_lost', 'Your expression of interest could not be saved safely. Please try again.' ) );
			$is_interest = 'interest' === sanitize_key( $item_array['registration_status'] ?? '' ) || 'expression_of_interest' === sanitize_key( $item_array['registration_type'] ?? '' );
			$party_key = $this->capacity->attendee_key() ?: 'number_of_attendees';
			$party = $this->capacity->attendees_from( $item_array );
			if ( ! is_wp_error( $party ) && (string) ( $item_array[ $party_key ] ?? '' ) !== (string) $party ) {
				$normalised = $this->repository->update_registration( $registration_id, array( $party_key => $party ) );
				if ( is_wp_error( $normalised ) ) $this->reject_created_registration( $registration_id, $event_id, $normalised );
				$item_array = $normalised;
			}
			if ( $this->capacity->enabled() && ! $is_interest ) {
				$attendees = $this->capacity->attendees_from( $item_array );
				if ( is_wp_error( $attendees ) ) {
					$this->reject_created_registration( $registration_id, $event_id, $attendees );
				}
				$status = sanitize_key( $item_array['registration_status'] ?? 'pending' );
				if ( 'waitlist' === $status ) {
					if ( $this->capacity_lock ) { $this->capacity->abandon_registration( (int) $this->capacity_lock['event_id'] ); $this->capacity_lock = array(); }
					$result = true;
					$this->audit->write( $registration_id, 'capacity_waitlist', 'waitlist', array( 'event_id' => $event_id, 'attendees' => $attendees ) );
				} elseif ( $this->capacity_lock && $event_id === (int) $this->capacity_lock['event_id'] ) {
					$result = $this->capacity->complete_registration( $registration_id, $event_id, $attendees );
					$this->capacity_lock = array();
				} else {
					$result = $this->capacity->ensure_reserved( $registration_id, $event_id, $attendees );
				}
				if ( is_wp_error( $result ) ) $this->reject_created_registration( $registration_id, $event_id, $result, true );
			}
			$connected = $this->repository->connect_relation( $event_id, $registration_id );
			$this->audit->write( $registration_id, 'relation', $connected ? 'connected' : 'not_configured', array( 'event_id' => $event_id ) );
			if ( $this->interest_lock && ! $this->owns_interest_lock() ) $this->reject_created_registration( $registration_id, $event_id, new \WP_Error( 'hherm_interest_lock_lost', 'Your expression of interest could not be saved safely. Please try again.' ) );
			$event_context = $this->email_event( $event_id );
			$event_context['source'] = 'event';
			if ( $this->email && 'waitlist' === sanitize_key( $item_array['registration_status'] ?? '' ) ) {
				$this->email->send_waitlist( $item_array, $event_context );
			}
			if ( $this->email && $is_interest && method_exists( $this->email, 'send_interest_received' ) ) {
				$this->email->send_interest_received( $item_array, $event_context );
			} elseif ( $this->email && method_exists( $this->email, 'send_internal_notification' ) ) {
				$record_url = add_query_arg( array( 'page' => 'heart-hub-event-registrations', 'registration_id' => $registration_id ), admin_url( 'admin.php' ) );
				$this->email->send_internal_notification( $item_array, $event_context, 'event registration', $record_url );
			}
		} elseif ( $this->capacity_lock ) {
			$this->capacity->abandon_registration( (int) $this->capacity_lock['event_id'] );
			$this->capacity_lock = array();
		}
		} finally {
			$this->cleanup_pending_submission();
		}
	}
	private function reject_created_registration( int $id, int $event_id, \WP_Error $error, bool $capacity_attempted = false ): void {
		$this->audit->write( $id, 'registration_error', $error->get_error_code(), array( 'event_id' => $event_id ) );
		if ( $capacity_attempted ) {
			// Retain a record for staff reconciliation if a failed capacity rollback
			// cannot establish how many places remain reserved.
			if ( 'hherm_capacity_rollback_failed' === $error->get_error_code() ) $this->form_error( $error->get_error_message() );
			$released = $this->capacity->release( $id, $event_id, 0 );
			if ( is_wp_error( $released ) ) $this->form_error( $released->get_error_message() );
		}
		$discarded = $this->repository->discard_registration( $id );
		$this->form_error( is_wp_error( $discarded ) ? $discarded->get_error_message() : 'We could not save all your registration details. The incomplete application was removed. Please contact the event organiser or try again.' );
	}
	private function submitted_fields( array $request ): array {
		$fields = array();
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'organisation', 'reason_for_attending', 'attended_before', 'impacted_by_trauma' ) as $key ) {
			if ( ! array_key_exists( $key, $request ) ) continue;
			if ( ! is_scalar( $request[ $key ] ) ) $this->form_error( 'Applicant details must be submitted as text.' );
			$fields[ $key ] = 'email' === $key ? sanitize_email( (string) $request[ $key ] ) : ( 'reason_for_attending' === $key ? sanitize_textarea_field( (string) $request[ $key ] ) : sanitize_text_field( (string) $request[ $key ] ) );
		}
		if ( isset( $fields['email'] ) && ! is_email( $fields['email'] ) ) $this->form_error( 'Please enter a valid email address.' );
		return $fields;
	}
	private function waitlist_enabled( int $event_id ): bool { return in_array( strtolower( (string) get_post_meta( $event_id, 'allow_waitlist', true ) ), array( '1', 'true', 'yes', 'on' ), true ); }
	private function dietary_fields( array $request ): array {
		$choice_raw = $request[ self::DIETARY_FIELD ] ?? '';
		$details_raw = $request[ self::DIETARY_DETAILS_FIELD ] ?? '';
		if ( ! is_scalar( $choice_raw ) || ! is_scalar( $details_raw ) ) {
			$this->form_error( 'Dietary requirements must be submitted as text.' );
		}

		$choice = sanitize_key( (string) $choice_raw );
		if ( in_array( $choice, array( '1', 'true', 'on' ), true ) ) {
			$choice = 'yes';
		} elseif ( in_array( $choice, array( '0', 'false', 'off' ), true ) ) {
			$choice = 'no';
		} elseif ( ! in_array( $choice, array( '', 'yes', 'no' ), true ) ) {
			$this->form_error( 'Choose Yes or No for dietary requirements.' );
		}

		$details = 'yes' === $choice ? sanitize_textarea_field( (string) $details_raw ) : '';
		if ( strlen( $details ) > 1000 ) {
			$this->form_error( 'Dietary requirement details must be 1,000 characters or fewer.' );
		}

		return array( 'choice' => $choice, 'details' => $details );
	}
	private function datetime_timestamp( $value ): int { return Event_Datetime::timestamp( $value ); }
	private function normalise_display_context( $context, int $event_id ): void {
		$start = get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true );
		$end = get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true );
		$context->update_request( Event_Datetime::display( $start, Event_Datetime::DATETIME_FORMAT ), 'event_start' );
		$context->update_request( Event_Datetime::display( $end, Event_Datetime::DATETIME_FORMAT ), 'event_end' );
		$context->update_request( wp_date( Event_Datetime::DATETIME_FORMAT, time(), wp_timezone() ), 'registration_date_display' );
	}
	private function email_event( int $event_id ): array { $post = get_post( $event_id ); $start = (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ); $end = (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true ); $name = (string) get_post_meta( $event_id, 'event_name', true ); $info = (string) get_post_meta( $event_id, $this->settings->get( 'event_info', '_description' ), true ); return array( 'id' => $event_id, 'title' => $name ?: get_the_title( $event_id ), 'date' => $this->format_datetime( $start, Event_Datetime::DATE_FORMAT ), 'start' => $this->format_datetime( $start, Event_Datetime::TIME_FORMAT ), 'end' => $this->format_datetime( $end, Event_Datetime::TIME_FORMAT ), 'venue' => (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true ), 'organiser' => (string) get_post_meta( $event_id, $this->settings->get( 'event_organiser', 'organiser' ), true ), 'info' => $info ?: ( $post ? $post->post_content : '' ) ); }
	private function format_datetime( string $value, string $format ): string { return Event_Datetime::display( $value, $format ); }
	private function form_error( string $message ): void {
		if ( class_exists( '\Jet_Form_Builder\Exceptions\Action_Exception' ) ) {
			throw new \Jet_Form_Builder\Exceptions\Action_Exception( esc_html( $message ) );
		}
		throw new \RuntimeException( esc_html( $message ) );
	}
}
