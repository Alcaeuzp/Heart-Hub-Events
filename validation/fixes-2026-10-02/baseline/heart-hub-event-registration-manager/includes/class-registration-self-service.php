<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';

final class Registration_Self_Service {
	private const OPTION_PREFIX = 'hherm_registration_manage_';
	private const TOKEN_LENGTH = 48;
	private const FALLBACK_EXPIRY = 30 * DAY_IN_SECONDS;

	private $settings;
	private $repository;
	private $audit;
	private $capacity;
	private $theme;

	public function __construct( Settings $settings, CCT_Repository $repository, Audit_Log $audit, Capacity_Manager $capacity, Public_Page_Theme $theme ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->audit      = $audit;
		$this->capacity   = $capacity;
		$this->theme      = $theme;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render_public' ), 0 );
	}

	public function manage_url( array $item, array $event = array() ): string {
		$id = absint( $item['_ID'] ?? 0 );
		if ( ! $id ) {
			return '';
		}

		$event_id = absint( $item['event_id'] ?? $event['id'] ?? 0 );
		if ( ! $this->valid_event( $event_id ) ) {
			return '';
		}
		if ( $this->event_cancelled( $event_id ) || ! $this->within_action_window( $event_id ) ) {
			return '';
		}

		$token = bin2hex( random_bytes( self::TOKEN_LENGTH / 2 ) );
		$record = array(
			'token_hash' => hash( 'sha256', $token ),
			'event_id'   => $event_id,
			'expires_at' => $this->action_expiry( $event_id ),
			'created_at' => time(),
		);
		update_option( self::OPTION_PREFIX . $id, $record, false );

		return $this->public_url( $id, $token );
	}

	public function nonce_action( int $registration_id, string $token ): string {
		return 'hherm_manage_registration_' . $registration_id . '_' . substr( hash( 'sha256', $token ), 0, 16 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- resolve() verifies the expiring attendee bearer token; POST mutations verify their nonce in process_submission().
	public function maybe_render_public(): void {
		if ( ! isset( $_GET['hherm_manage_registration'] ) ) {
			return;
		}

		$id    = absint( wp_unslash( $_GET['hherm_manage_registration'] ) );
		$token = isset( $_GET['hherm_manage_token'] ) ? sanitize_text_field( wp_unslash( $_GET['hherm_manage_token'] ) ) : '';
		$resolved = $this->resolve( $id, $token );
		$notice   = isset( $_GET['hherm_manage_notice'] ) ? sanitize_key( wp_unslash( $_GET['hherm_manage_notice'] ) ) : '';

		if ( is_wp_error( $resolved ) ) {
			$this->render_public_page( array(), array(), $resolved->get_error_message(), $notice );
		}

		$item  = $resolved['item'];
		$event = $resolved['event'];
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === strtoupper( $method ) ) {
			$result = $this->process_submission( $item, $event, $id, $token );
			if ( is_wp_error( $result ) ) {
				$this->render_public_page( $item, $event, $result->get_error_message(), '' );
			}
			$this->redirect_with_notice( $id, $token, $result['notice'] );
		}

		$this->render_public_page( $item, $event, '', $notice );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function resolve( int $id, string $token ) {
		if ( $id < 1 || ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			return new \WP_Error( 'hherm_manage_invalid_link', 'This attendance link is invalid or has expired.' );
		}

		$record = (array) get_option( self::OPTION_PREFIX . $id, array() );
		$hash   = hash( 'sha256', $token );
		if ( empty( $record['token_hash'] ) || ! hash_equals( (string) $record['token_hash'], $hash ) || time() >= absint( $record['expires_at'] ?? 0 ) ) {
			if ( $record && time() >= absint( $record['expires_at'] ?? 0 ) ) delete_option( self::OPTION_PREFIX . $id );
			return new \WP_Error( 'hherm_manage_expired_link', 'This attendance link is invalid or has expired.' );
		}

		$item = $this->repository->get( $id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$event_id = absint( $item['event_id'] ?? 0 );
		if ( $event_id !== absint( $record['event_id'] ?? 0 ) || ! $this->valid_event( $event_id ) || $this->event_cancelled( $event_id ) || ! $this->within_action_window( $event_id ) ) {
			delete_option( self::OPTION_PREFIX . $id );
			return new \WP_Error( 'hherm_manage_event_unavailable', 'This attendance link is no longer available.' );
		}

		return array( 'item' => $item, 'event' => $this->event_details( $event_id ) );
	}

	private function process_submission( array $item, array $event, int $id, string $token ) {
		$nonce = isset( $_POST['hherm_manage_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hherm_manage_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, $this->nonce_action( $id, $token ) ) ) {
			return new \WP_Error( 'hherm_manage_bad_nonce', 'We could not complete that request. Please refresh the page and try again.' );
		}
		if ( ! $this->within_action_window( (int) $event['id'] ) ) {
			return new \WP_Error( 'hherm_manage_closed', 'Changes and withdrawals are no longer available for this event.' );
		}
		$lock_name = 'hherm_review_lock_' . $id;
		$lock_token = Mutation_Lock::acquire( $lock_name );
		if ( is_wp_error( $lock_token ) ) return $lock_token;
		try {
			return $this->process_locked_submission( $event, $id, $lock_token );
		} finally {
			Mutation_Lock::release( $lock_name, $lock_token );
		}
	}

	private function process_locked_submission( array $event, int $id, string $lock_token ) {
		$current = $this->repository->get( $id );
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 'approved' !== sanitize_key( $current['registration_status'] ?? '' ) ) {
			return new \WP_Error( 'hherm_manage_not_approved', 'This registration is no longer an active approved attendance.' );
		}
		if ( absint( $current['event_id'] ?? 0 ) !== (int) $event['id'] || $this->event_cancelled( (int) $event['id'] ) || ! $this->within_action_window( (int) $event['id'] ) ) return new \WP_Error( 'hherm_manage_closed', 'Changes and withdrawals are no longer available for this event.' );
		if ( ! empty( $current['checked_in_at'] ) ) return new \WP_Error( 'hherm_manage_checked_in', 'You have already checked in. Please ask the event organiser to change this registration.' );

		$action = isset( $_POST['hherm_manage_action'] ) ? sanitize_key( wp_unslash( $_POST['hherm_manage_action'] ) ) : '';
		if ( 'withdraw' === $action ) {
			return $this->withdraw( $current, $id, (int) $event['id'], $lock_token );
		}
		if ( 'update' === $action ) {
			// Read and sanitize submitted fields only after verifying the form nonce above.
			$changes = array();
			foreach ( array( 'first_name', 'last_name', 'phone', 'organisation' ) as $field ) {
				if ( isset( $_POST[ $field ] ) ) $changes[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
			if ( isset( $_POST['email'] ) ) $changes['email'] = sanitize_email( wp_unslash( $_POST['email'] ) );
			if ( isset( $_POST['reason_for_attending'] ) ) $changes['reason_for_attending'] = sanitize_textarea_field( wp_unslash( $_POST['reason_for_attending'] ) );
			if ( isset( $_POST['number_of_attendees'] ) ) {
				if ( ! is_scalar( $_POST['number_of_attendees'] ) ) return new \WP_Error( 'hherm_invalid_attendees', 'The number of attendees must be a whole number.', array( 'status' => 422 ) );
				$changes['number_of_attendees'] = sanitize_text_field( wp_unslash( $_POST['number_of_attendees'] ) );
			}
			return $this->update_for_review( $current, $id, (int) $event['id'], $changes, $lock_token );
		}

		return new \WP_Error( 'hherm_manage_invalid_action', 'That attendance action is not available.' );
	}

	private function withdraw( array $item, int $id, int $event_id, string $lock_token ) {
		return $this->capacity->with_event_lock( $event_id, function () use ( $item, $id, $event_id, $lock_token ) { return $this->withdraw_locked( $item, $id, $event_id, $lock_token ); } );
	}

	private function withdraw_locked( array $item, int $id, int $event_id, string $lock_token ) {
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		$attendees = $this->party_size( $item );
		$before = $this->capacity->reservation_state( $id );
		if ( is_wp_error( $before ) ) return $before;
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		if ( $this->capacity->enabled() ) {
			$released = $this->capacity->release( $id, $event_id, $attendees );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
		}

		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		$updated = $this->repository->update_registration(
			$id,
			array(
				'registration_status' => 'declined',
				'reviewed_by'         => 0,
				'reviewed_date'       => current_time( 'mysql' ),
				'approval_notes'      => '',
				'decline_reason'      => 'Withdrawn by attendee.',
			),
			static function () use ( $id, $lock_token ) { return Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ); }
		);
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		if ( is_wp_error( $updated ) ) {
			return $this->reconcile_failed_write( $id, $event_id, $before, $updated, $lock_token );
		}

		$this->audit->write( $id, 'self_service', 'withdrawn', array( 'event_id' => $event_id, 'attendees' => $attendees ) );
		return array( 'notice' => 'withdrawn' );
	}

	private function update_for_review( array $item, int $id, int $event_id, array $changes, string $lock_token ) {
		return $this->capacity->with_event_lock( $event_id, function () use ( $item, $id, $event_id, $changes, $lock_token ) { return $this->update_for_review_locked( $item, $id, $event_id, $changes, $lock_token ); } );
	}

	private function update_for_review_locked( array $item, int $id, int $event_id, array $changes, string $lock_token ) {
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		if ( $this->capacity->partially_configured() ) {
			return new \WP_Error( 'hherm_manage_capacity_config', 'This registration cannot be updated until event capacity settings are completed.' );
		}

		$old_party = $this->party_size( $item );
		$party_key = $this->capacity->attendee_key() ?: 'number_of_attendees';
		$new_party = $this->capacity->attendees_from( array( $party_key => $changes['number_of_attendees'] ?? $old_party ) );
		if ( is_wp_error( $new_party ) ) return $new_party;
		if ( $new_party < 1 || $new_party > 1000 ) {
			return new \WP_Error( 'hherm_manage_invalid_party', 'The number of attendees must be between 1 and 1,000.' );
		}

		$email = $changes['email'] ?? sanitize_email( $item['email'] ?? '' );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'hherm_manage_invalid_email', 'Please enter a valid email address.' );
		}

		$fields = array(
			'first_name'          => $changes['first_name'] ?? sanitize_text_field( $item['first_name'] ?? '' ),
			'last_name'           => $changes['last_name'] ?? sanitize_text_field( $item['last_name'] ?? '' ),
			'email'               => $email,
			'phone'               => $changes['phone'] ?? sanitize_text_field( $item['phone'] ?? '' ),
			'organisation'        => $changes['organisation'] ?? sanitize_text_field( $item['organisation'] ?? '' ),
			'reason_for_attending' => $changes['reason_for_attending'] ?? sanitize_textarea_field( $item['reason_for_attending'] ?? '' ),
			'registration_status'  => 'pending',
			'reviewed_by'         => 0,
			'reviewed_date'       => '',
			'approval_notes'      => '',
			'decline_reason'      => '',
		);
		$attendee_key = $this->capacity->attendee_key() ?: 'number_of_attendees';
		$fields[ $attendee_key ] = $new_party;

		if ( $old_party === $new_party && ! $this->details_changed( $item, $fields ) ) {
			// Resubmitting unchanged details must not withdraw an approved place for re-review.
			return array( 'notice' => 'unchanged' );
		}

		$before = $this->capacity->reservation_state( $id );
		if ( is_wp_error( $before ) ) return $before;
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		if ( $this->capacity->enabled() && $old_party !== $new_party ) {
			$adjustment = $this->capacity->adjust_reserved( $id, $event_id, $old_party, $new_party );
			if ( is_wp_error( $adjustment ) ) {
				return $adjustment;
			}
		}

		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		$updated = $this->repository->update_registration( $id, $fields, static function () use ( $id, $lock_token ) { return Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ); } );
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		if ( is_wp_error( $updated ) ) {
			return $this->reconcile_failed_write( $id, $event_id, $before, $updated, $lock_token );
		}

		$this->audit->write( $id, 'self_service', 'resubmitted', array( 'event_id' => $event_id, 'previous_party' => $old_party, 'new_party' => $new_party ) );
		return array( 'notice' => 'pending' );
	}

	/** Reconcile with persisted data, including a storage failure that could not be rolled back. */
	private function reconcile_failed_write( int $id, int $event_id, array $before, \WP_Error $error, string $lock_token ) {
		if ( ! $this->capacity->enabled() ) return $error;
		$current = $this->repository->get( $id );
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $this->lost_lock_error();
		$restored = false;
		if ( ! is_wp_error( $current ) && absint( $current['event_id'] ?? 0 ) === $event_id ) {
			$state = $this->capacity->reservation_state( $id );
			if ( is_wp_error( $state ) ) {
				$this->audit->write( $id, 'capacity_error', 'self_service_rollback_failed', array( 'event_id' => $event_id ) );
				return new \WP_Error( 'hherm_manage_rollback_failed', 'Your changes could not be saved and the reserved places could not be reconciled. Please contact the event organiser before trying again.' );
			}
			$active = in_array( sanitize_key( $current['registration_status'] ?? '' ), array( 'pending', 'approved' ), true );
			if ( $active && ! empty( $before['reserved'] ) ) {
				$party = $this->party_size( $current );
				$restored = $state['reserved'] ? $this->capacity->adjust_reserved( $id, $event_id, $state['attendees'], $party ) : $this->capacity->ensure_reserved( $id, $event_id, $party );
			} else {
				$restored = $this->capacity->release( $id, $event_id, $state['attendees'] );
			}
		}
		if ( true === $restored ) return $error;
		$this->audit->write( $id, 'capacity_error', 'self_service_rollback_failed', array( 'event_id' => $event_id ) );
		return new \WP_Error( 'hherm_manage_rollback_failed', 'Your changes could not be saved and the reserved places could not be reconciled. Please contact the event organiser before trying again.' );
	}

	private function lost_lock_error(): \WP_Error {
		return new \WP_Error( 'hherm_registration_lock_lost', 'This registration changed while your request was being processed. Please ask the event organiser to check its details and reserved places before retrying.', array( 'status' => 409 ) );
	}

	private function details_changed( array $item, array $fields ): bool {
		// Compare as displayed: the browser decodes entities and may send CRLF line endings.
		$normalise = static function ( $value ): string {
			return trim( str_replace( "\r\n", "\n", html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		};
		foreach ( array( 'first_name', 'last_name', 'phone', 'organisation' ) as $key ) {
			if ( $normalise( $fields[ $key ] ) !== $normalise( sanitize_text_field( (string) ( $item[ $key ] ?? '' ) ) ) ) return true;
		}
		if ( $normalise( $fields['reason_for_attending'] ) !== $normalise( sanitize_textarea_field( (string) ( $item['reason_for_attending'] ?? '' ) ) ) ) return true;
		return strtolower( $fields['email'] ) !== strtolower( sanitize_email( (string) ( $item['email'] ?? '' ) ) );
	}

	private function party_size( array $item ): int {
		$key = $this->capacity->attendee_key() ?: 'number_of_attendees';
		return max( 1, absint( $item[ $key ] ?? 1 ) );
	}

	private function render_public_page( array $item, array $event, string $message, string $notice ): void {
		$this->theme->security_headers( $item ? 200 : 404 );
		$events_url = $this->settings->get( 'events_url', home_url( '/events/' ) );
		$active = $item && 'approved' === sanitize_key( $item['registration_status'] ?? '' );
		$withdrawn = $item && 'withdrawn' === $notice;
		$pending   = $item && 'pending' === $notice;
		$event_name = $event['title'] ?? 'Your event attendance';
		$party = $item ? $this->party_size( $item ) : 1;
		$label = $this->theme->copy( 'manage_label' );
		$contact = sanitize_email( $this->settings->get( 'contact_email', get_option( 'admin_email' ) ) ) ?: 'the event organiser';
		?>
		<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( ( $label ?: 'Attendance management' ) . ' — ' . $event_name ); ?></title><?php wp_enqueue_style( 'hherm-public-manage', HHERM_URL . 'assets/registration-self-service.css', array(), HHERM_VERSION ); wp_print_styles( 'hherm-public-manage' ); ?><?php echo $this->theme->style_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></head><body class="hherm-public-manage">
		<?php echo $this->theme->header( $label, 'hherm-manage-header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<main class="hherm-manage-main">
		<?php if ( $withdrawn ) : ?><section class="hherm-manage-card hherm-manage-result"><span class="hherm-manage-eyebrow">Attendance withdrawn</span><h1>Your place has been withdrawn</h1><p>We have released the places reserved for <?php echo esc_html( $event_name ); ?>. If you change your mind, please submit a new registration from the events page.</p><a class="hherm-manage-button" href="<?php echo esc_url( $events_url ); ?>">View upcoming events</a></section>
		<?php elseif ( $pending ) : ?><section class="hherm-manage-card hherm-manage-result"><span class="hherm-manage-eyebrow">Back with the team</span><h1>Your changes have been sent for review</h1><p>We have updated your attendance request for <?php echo esc_html( $event_name ); ?>. Staff will review the changes and email you when a decision is made.</p><a class="hherm-manage-outline" href="<?php echo esc_url( $events_url ); ?>">View upcoming events</a></section>
		<?php elseif ( ! $item ) : ?><section class="hherm-manage-card hherm-manage-result"><span class="hherm-manage-eyebrow">Link unavailable</span><h1>This attendance link has closed</h1><p><?php echo esc_html( $message ?: 'This link is invalid or has expired. Please contact the event organiser if you need help.' ); ?></p><a class="hherm-manage-button" href="<?php echo esc_url( $events_url ); ?>">View upcoming events</a></section>
		<?php elseif ( ! $active ) : ?><section class="hherm-manage-card hherm-manage-result"><span class="hherm-manage-eyebrow">Attendance unavailable</span><h1>This attendance is no longer active</h1><p>This registration is not currently approved for the event. Please contact the event organiser if you think this is unexpected.</p><a class="hherm-manage-outline" href="<?php echo esc_url( $events_url ); ?>">View upcoming events</a></section>
		<?php else : ?>
			<div class="hherm-manage-eyebrow">Approved attendance</div><h1><?php echo esc_html( $event_name ); ?></h1><p class="hherm-manage-intro"><?php echo nl2br( esc_html( $this->theme->copy( 'manage_intro', array( '{event_name}' => $event_name, '{contact_email}' => $contact ) ) ) ); ?></p>
			<section class="hherm-manage-card hherm-manage-event"><div><strong><?php echo esc_html( $event['date_line'] ); ?></strong><span><?php echo esc_html( $event['time_line'] ); ?><?php echo $event['venue'] ? ' · ' . esc_html( $event['venue'] ) : ''; ?></span></div><span class="hherm-manage-pill">Approved</span></section>
			<?php if ( $message ) : ?><div class="hherm-manage-message" role="alert"><?php echo esc_html( $message ); ?></div><?php endif; ?>
			<?php if ( ! $message && 'unchanged' === $notice ) : ?><div class="hherm-manage-info" role="status">No changes were made, so your attendance remains approved.</div><?php endif; ?>
			<section class="hherm-manage-card"><div class="hherm-manage-card-heading"><div><h2>Update your attendance</h2><p>Changes are sent back to staff for review.</p></div></div><form method="post" class="hherm-manage-form"><div class="hherm-manage-form-grid"><label><span>First name</span><input type="text" name="first_name" autocomplete="given-name" value="<?php echo esc_attr( $item['first_name'] ?? '' ); ?>" required></label><label><span>Last name</span><input type="text" name="last_name" autocomplete="family-name" value="<?php echo esc_attr( $item['last_name'] ?? '' ); ?>" required></label><label><span>Email</span><input type="email" name="email" autocomplete="email" value="<?php echo esc_attr( $item['email'] ?? '' ); ?>" required></label><label><span>Phone</span><input type="tel" name="phone" autocomplete="tel" value="<?php echo esc_attr( $item['phone'] ?? '' ); ?>"></label><label><span>Organisation</span><input type="text" name="organisation" autocomplete="organization" value="<?php echo esc_attr( $item['organisation'] ?? '' ); ?>"></label><div class="hherm-manage-field"><span id="hherm-manage-party-label">Number attending</span><div class="hherm-manage-party" data-party-control data-max="1000" role="group" aria-labelledby="hherm-manage-party-label"><button type="button" data-party-minus aria-label="Decrease number attending">−</button><output data-party-value aria-live="polite"><?php echo esc_html( $party ); ?></output><button type="button" data-party-plus aria-label="Increase number attending">+</button><input type="hidden" name="number_of_attendees" value="<?php echo esc_attr( $party ); ?>" data-party-input></div></div></div><label><span>Reason for attending</span><textarea name="reason_for_attending" rows="4"><?php echo esc_textarea( $item['reason_for_attending'] ?? '' ); ?></textarea></label><p class="hherm-manage-help">Changing any of these details, including the number attending, sends your registration back for staff review.</p><?php wp_nonce_field( $this->nonce_action( absint( $item['_ID'] ), $this->token_from_request() ), 'hherm_manage_nonce' ); ?><input type="hidden" name="hherm_manage_action" value="update"><button class="hherm-manage-button" type="submit">Submit changes for review</button></form></section>
			<section class="hherm-manage-card hherm-manage-withdraw"><div><h2>Withdraw attendance</h2><p>Withdraw your place if you can no longer attend. Your reserved places will be released.</p></div><form method="post"><?php wp_nonce_field( $this->nonce_action( absint( $item['_ID'] ), $this->token_from_request() ), 'hherm_manage_nonce' ); ?><input type="hidden" name="hherm_manage_action" value="withdraw"><button class="hherm-manage-outline hherm-manage-danger" type="submit" onclick="return confirm('Withdraw your attendance from this event?');">Withdraw attendance</button></form></section>
		<?php endif; ?>
		</main><?php echo $this->theme->footer( 'hherm-manage-footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><script>document.addEventListener('click',function(e){var b=e.target.closest('[data-party-minus],[data-party-plus]');if(!b)return;var c=b.closest('[data-party-control]'),i=c.querySelector('[data-party-input]'),v=c.querySelector('[data-party-value]'),n=parseInt(i.value||1,10),max=parseInt(c.dataset.max||1000,10);n=b.hasAttribute('data-party-plus')?Math.min(max,n+1):Math.max(1,n-1);i.value=n;v.textContent=n;});</script></body></html>
		<?php
		exit;
	}

	private function redirect_with_notice( int $id, string $token, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'hherm_manage_notice', sanitize_key( $notice ), $this->public_url( $id, $token ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reuses the bearer token already checked by resolve() to build the form nonce; this accessor changes no data.
	private function token_from_request(): string {
		return isset( $_GET['hherm_manage_token'] ) ? sanitize_text_field( wp_unslash( $_GET['hherm_manage_token'] ) ) : '';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function public_url( int $id, string $token ): string {
		return add_query_arg( array( 'hherm_manage_registration' => $id, 'hherm_manage_token' => rawurlencode( $token ) ), home_url( '/' ) );
	}

	private function event_details( int $event_id ): array {
		$start = $this->parse_datetime( get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ) );
		$end   = $this->parse_datetime( get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true ) );
		return array(
			'id'        => $event_id,
			'title'     => $this->event_label( $event_id ),
			'date_line' => $start ? wp_date( 'l j F Y', $start->getTimestamp(), wp_timezone() ) : 'Event date to be confirmed',
			'time_line' => $start ? wp_date( 'g:i a', $start->getTimestamp(), wp_timezone() ) . ( $end ? ' – ' . wp_date( 'g:i a', $end->getTimestamp(), wp_timezone() ) : '' ) : '',
			'venue'     => (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true ),
		);
	}

	private function action_expiry( int $event_id ): int {
		$deadline = $this->parse_datetime( get_post_meta( $event_id, 'cancellation_deadline', true ) );
		$start    = $this->parse_datetime( get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ) );
		$expiry   = $deadline ?: $start;
		return $expiry ? $expiry->getTimestamp() : time() + self::FALLBACK_EXPIRY;
	}

	private function within_action_window( int $event_id ): bool {
		return time() < $this->action_expiry( $event_id );
	}

	private function parse_datetime( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = absint( $value );
			return $timestamp ? ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() ) : false;
		}
		$value = trim( (string) $value );
		if ( ! $value ) {
			return false;
		}
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			if ( $date ) return $date;
		}
		return false;
	}

	private function valid_event( int $event_id ): bool {
		$post = get_post( $event_id );
		return $post && 'trash' !== $post->post_status && $this->settings->get( 'events_cpt', 'events' ) === $post->post_type;
	}

	private function event_cancelled( int $event_id ): bool {
		return in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private function event_label( int $event_id ): string {
		$name = (string) get_post_meta( $event_id, 'event_name', true );
		return $name ?: get_the_title( $event_id ) ?: 'Untitled event';
	}
}
