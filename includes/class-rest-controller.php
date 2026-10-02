<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';

final class REST_Controller {
	private const DIETARY_FIELD = 'dietaryrequirements';
	private const DIETARY_DETAILS_FIELD = 'please_let_us_know';
	private $repository; private $email; private $audit; private $settings; private $capacity;
	public function __construct( CCT_Repository $repository, Email_Automation $email, Audit_Log $audit, Settings $settings, Capacity_Manager $capacity ) { $this->repository = $repository; $this->email = $email; $this->audit = $audit; $this->settings = $settings; $this->capacity = $capacity; }
	public function register(): void { add_action( 'rest_api_init', array( $this, 'routes' ) ); add_filter( 'rest_post_dispatch', array( $this, 'no_store' ), 10, 3 ); }

	public function routes(): void {
		$permission = array( $this, 'permission' );
		register_rest_route( 'heart-hub/v1', '/applications', array( 'methods' => 'GET', 'callback' => array( $this, 'index' ), 'permission_callback' => $permission ) );
		register_rest_route( 'heart-hub/v1', '/applications/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'show' ), 'permission_callback' => $permission ) );
		register_rest_route( 'heart-hub/v1', '/applications/(?P<id>\d+)/review', array( 'methods' => 'POST', 'callback' => array( $this, 'review' ), 'permission_callback' => $permission ) );
		register_rest_route( 'heart-hub/v1', '/applications/(?P<id>\d+)/details', array( 'methods' => 'POST', 'callback' => array( $this, 'edit' ), 'permission_callback' => $permission ) );
		register_rest_route( 'heart-hub/v1', '/applications/bulk', array( 'methods' => 'POST', 'callback' => array( $this, 'bulk' ), 'permission_callback' => $permission ) );
		register_rest_route( 'heart-hub/v1', '/check-ins', array( 'methods' => 'GET', 'callback' => array( $this, 'checkins' ), 'permission_callback' => $permission ) );
	}

	public function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( Plugin::CAPABILITY ) ) { return new \WP_Error( 'hherm_forbidden', 'You do not have access to event applications.', array( 'status' => 403 ) ); }
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) { return new \WP_Error( 'hherm_bad_nonce', 'Security check failed. Refresh the page and try again.', array( 'status' => 403 ) ); }
		return true;
	}

	public function index( \WP_REST_Request $request ) {
		$date_from = sanitize_text_field( (string) $request['date_from'] );
		$date_to = sanitize_text_field( (string) $request['date_to'] );
		$filters = array( 'page' => max( 1, absint( $request['page'] ) ), 'per_page' => min( 50, max( 5, absint( $request['per_page'] ?: 20 ) ) ), 'status' => sanitize_key( $request['status'] ), 'event_id' => absint( $request['event_id'] ), 'search' => sanitize_text_field( (string) $request['search'] ), 'order' => 'asc' === $request['order'] ? 'asc' : 'desc', 'date_from' => Event_Datetime::date_storage( $date_from ), 'date_to' => Event_Datetime::date_storage( $date_to ) );
		$include_stats = ! in_array( $request->get_param( 'include_stats' ), array( false, 0, '0', 'false' ), true );
		$result = $include_stats ? $this->repository->dashboard( $filters ) : $this->repository->list( $filters );
		if ( isset( $result['error'] ) ) { return $result['error']; }
		$result['items'] = array_map( array( $this, 'summary' ), $result['items'] );
		$result['events'] = $this->events();
		$result['pages'] = (int) ceil( $result['total'] / $filters['per_page'] );
		return rest_ensure_response( $result );
	}

	public function show( \WP_REST_Request $request ) {
		$item = $this->repository->get( absint( $request['id'] ) );
		if ( is_wp_error( $item ) ) { return $item; }
		return rest_ensure_response( $this->application_details( $item ) );
	}

	public function review( \WP_REST_Request $request ) {
		$id = absint( $request['id'] ); $status = sanitize_key( $request['status'] );
		if ( ! in_array( $status, array( 'approved', 'declined' ), true ) ) { return new \WP_Error( 'hherm_invalid_status', 'Status must be approved or declined.', array( 'status' => 400 ) ); }
		$notes = sanitize_textarea_field( 'approved' === $status ? $request['approval_notes'] : $request['decline_reason'] );
		$result = $this->process_review( $id, $status, $notes );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function bulk( \WP_REST_Request $request ) {
		$status = sanitize_key( $request['status'] );
		if ( ! in_array( $status, array( 'approved', 'declined' ), true ) ) { return new \WP_Error( 'hherm_invalid_status', 'Bulk status must be approved or declined.', array( 'status' => 400 ) ); }
		$raw_ids = $request->get_param( 'ids' );
		if ( ! is_array( $raw_ids ) || ! $raw_ids || count( $raw_ids ) > 100 ) return new \WP_Error( 'hherm_invalid_ids', 'Select between one and one hundred applications.', array( 'status' => 400 ) );
		$ids = array();
		foreach ( $raw_ids as $raw_id ) {
			// Never turn arrays, booleans, negative/fractional values or overflowing
			// strings into a different valid registration ID through integer coercion.
			if ( ( ! is_int( $raw_id ) && ! is_string( $raw_id ) ) || ! preg_match( '/^[0-9]+$/', (string) $raw_id ) ) return new \WP_Error( 'hherm_invalid_ids', 'Application IDs must be positive whole numbers.', array( 'status' => 400 ) );
			$id = absint( $raw_id );
			if ( ! $id || (string) $id !== ltrim( (string) $raw_id, '0' ) ) return new \WP_Error( 'hherm_invalid_ids', 'Application IDs must be positive whole numbers.', array( 'status' => 400 ) );
			$ids[] = $id;
		}
		$ids = array_values( array_unique( $ids ) );
		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$results = array( 'succeeded' => array(), 'failed' => array() );
		foreach ( $ids as $id ) {
			$result = $this->process_review( $id, $status, $notes );
			if ( is_wp_error( $result ) ) { $results['failed'][] = array( 'id' => $id, 'message' => $result->get_error_message() ); }
			else { $results['succeeded'][] = $id; }
		}
		return rest_ensure_response( $results );
	}

	public function edit( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$lock = 'hherm_review_lock_' . $id;
		$token = Mutation_Lock::acquire( $lock );
		if ( is_wp_error( $token ) ) return $token;
		try {
			$item = $this->repository->get( $id );
			if ( is_wp_error( $item ) ) return $item;
			return $this->capacity->with_event_lock( absint( $item['event_id'] ?? 0 ), function () use ( $request, $id, $item, $lock, $token ) {
				$fields = array();
				foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'organisation', 'reason_for_attending' ) as $key ) {
					if ( ! $request->has_param( $key ) ) continue;
					if ( ! is_scalar( $request[ $key ] ) ) return new \WP_Error( 'hherm_invalid_details', 'Applicant details must be text.', array( 'status' => 422 ) );
					$fields[ $key ] = 'reason_for_attending' === $key ? sanitize_textarea_field( $request[ $key ] ) : sanitize_text_field( $request[ $key ] );
				}
				if ( $request->has_param( self::DIETARY_FIELD ) ) {
					if ( ! is_scalar( $request[ self::DIETARY_FIELD ] ) ) return new \WP_Error( 'hherm_invalid_details', 'Dietary requirements must be text.', array( 'status' => 422 ) );
					$choice = sanitize_key( (string) $request[ self::DIETARY_FIELD ] );
					if ( ! in_array( $choice, array( '', 'yes', 'no' ), true ) ) return new \WP_Error( 'hherm_invalid_dietary_requirements', 'Choose Yes or No for dietary requirements.', array( 'status' => 422 ) );
					$fields[ self::DIETARY_FIELD ] = $choice;
				}
				if ( $request->has_param( self::DIETARY_DETAILS_FIELD ) ) {
					if ( ! is_scalar( $request[ self::DIETARY_DETAILS_FIELD ] ) ) return new \WP_Error( 'hherm_invalid_details', 'Dietary requirement details must be text.', array( 'status' => 422 ) );
					$details = sanitize_textarea_field( (string) $request[ self::DIETARY_DETAILS_FIELD ] );
					if ( strlen( $details ) > 1000 ) return new \WP_Error( 'hherm_invalid_dietary_details', 'Dietary requirement details must be 1,000 characters or fewer.', array( 'status' => 422 ) );
					$fields[ self::DIETARY_DETAILS_FIELD ] = $details;
				}
				$final_dietary_choice = $fields[ self::DIETARY_FIELD ] ?? sanitize_key( (string) ( $item[ self::DIETARY_FIELD ] ?? '' ) );
				if ( 'yes' !== $final_dietary_choice && ( $request->has_param( self::DIETARY_FIELD ) || $request->has_param( self::DIETARY_DETAILS_FIELD ) ) ) {
					$fields[ self::DIETARY_DETAILS_FIELD ] = '';
				}
				if ( isset( $fields['email'] ) && '' !== $fields['email'] && ! is_email( $fields['email'] ) ) return new \WP_Error( 'hherm_invalid_email', 'Enter a valid email address or leave it blank.', array( 'status' => 422 ) );
				$key = $this->capacity->attendee_key() ?: 'number_of_attendees';
				$party_item = $item;
				if ( $request->has_param( 'number_of_attendees' ) ) {
					if ( ! is_scalar( $request['number_of_attendees'] ) ) return new \WP_Error( 'hherm_invalid_attendees', 'Enter a whole number for party size.', array( 'status' => 422 ) );
					$party_item[ $key ] = $request['number_of_attendees'];
				}
				$new_party = $this->capacity->attendees_from( $party_item );
				if ( is_wp_error( $new_party ) ) return $new_party;
				$old_party = max( 1, (int) ( $item[ $key ] ?? 1 ) );
				if ( $new_party !== $old_party && ! empty( $item['checked_in_at'] ) ) return new \WP_Error( 'hherm_already_checked_in', 'Party size cannot be changed after check-in. Contact details can still be edited.', array( 'status' => 409 ) );
				if ( $new_party !== $old_party && $this->capacity->partially_configured() ) return new \WP_Error( 'hherm_capacity_not_configured', 'Complete the capacity field settings before changing party size.', array( 'status' => 422 ) );
				$fields[ $key ] = $new_party;
				$event_id = absint( $item['event_id'] ?? 0 );
				$before = $this->capacity->reservation_state( $id );
				if ( is_wp_error( $before ) ) return $before;
				$adjusted = false;
				$created_reservation = false;
				if ( ! Mutation_Lock::owns( $lock, $token ) ) return $this->lost_registration_lock( $id, $event_id );
				if ( $before['reserved'] && $before['attendees'] !== $new_party ) {
					$result = $this->capacity->adjust_reserved( $id, $event_id, $before['attendees'], $new_party );
					if ( is_wp_error( $result ) ) return $result;
					$adjusted = true;
				}
				if ( $this->capacity->enabled() && ! $before['reserved'] && $new_party !== $old_party && in_array( $item['registration_status'] ?? '', array( 'pending', 'approved' ), true ) ) {
					$result = $this->capacity->ensure_reserved( $id, $event_id, $new_party );
					if ( is_wp_error( $result ) ) return $result;
					$created_reservation = true;
				}
				if ( ! Mutation_Lock::owns( $lock, $token ) ) return $this->lost_registration_lock( $id, $event_id, $adjusted || $created_reservation );
				$updated = $this->repository->update_registration( $id, $fields, static function () use ( $lock, $token ): bool { return Mutation_Lock::owns( $lock, $token ); } );
				if ( ! Mutation_Lock::owns( $lock, $token ) ) return $this->lost_registration_lock( $id, $event_id, $adjusted || $created_reservation );
				if ( is_wp_error( $updated ) ) {
					if ( 'hherm_update_rollback_failed' === $updated->get_error_code() ) {
						$this->reconcile_failed_write( $id, $event_id, $before, $item, $lock, $token );
						return $updated;
					}
					if ( $adjusted || $created_reservation ) {
						$rollback = $created_reservation ? $this->capacity->release( $id, $event_id, $new_party ) : $this->capacity->adjust_reserved( $id, $event_id, $new_party, $before['attendees'] );
						if ( is_wp_error( $rollback ) ) {
							$this->audit->write( $id, 'capacity_error', 'edit_rollback_failed', array( 'event_id' => $event_id ) );
							return new \WP_Error( 'hherm_edit_rollback_failed', 'The edit failed and capacity could not be restored. Please check the event capacity before retrying.', array( 'status' => 500 ) );
						}
					}
					return $updated;
				}
				$this->audit->write( $id, 'registration_edit', 'updated', array( 'event_id' => $event_id, 'fields' => array_keys( $fields ), 'previous_party' => $old_party, 'new_party' => $new_party, 'edited_by' => get_current_user_id() ) );
				return rest_ensure_response( $this->application_details( $updated ) );
			} );
		} finally {
			Mutation_Lock::release( $lock, $token );
		}
	}

	public function checkins( \WP_REST_Request $request ) {
		$event_id = absint( $request['event_id'] );
		$post = get_post( $event_id );
		if ( ! $post || 'trash' === $post->post_status || $this->settings->get( 'events_cpt', 'events' ) !== $post->post_type ) { return new \WP_Error( 'hherm_event_missing', 'The selected event could not be loaded.', array( 'status' => 404 ) ); }
		$items = $this->repository->attendance_list( $event_id );
		if ( is_wp_error( $items ) ) { return $items; }
		$expected = 0; $checked_in = 0; $feed = array(); $attendee_key = $this->capacity->attendee_key();
		foreach ( $items as $item ) {
			$party = max( 1, absint( $attendee_key ? ( $item[ $attendee_key ] ?? 1 ) : 1 ) );
			$expected += $party;
			$checked_at = sanitize_text_field( $item['checked_in_at'] ?? '' );
			if ( $checked_at ) {
				$actual_party = isset( $item['checked_in_party_size'] ) ? max( 1, min( $party, absint( $item['checked_in_party_size'] ) ) ) : $party;
				$checked_in += $actual_party;
				$feed[] = array( 'name' => trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ), 'party' => $actual_party, 'time' => $checked_at );
			}
		}
		usort( $feed, static function ( $a, $b ) { return strcmp( $b['time'], $a['time'] ); } );
		$end = (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true );
		$end_timestamp = Event_Datetime::timestamp( $end );
		$expires = $end_timestamp ? $end_timestamp + HOUR_IN_SECONDS : 0;
		return rest_ensure_response( array( 'event_id' => $event_id, 'checked_in' => $checked_in, 'expected' => $expected, 'expires_at' => $expires, 'feed' => array_slice( $feed, 0, 12 ) ) );
	}

	private function process_review( int $id, string $status, string $notes ) {
		if ( 'declined' === $status && '' === trim( $notes ) ) {
			$notes = 'We’re sorry, we’re unable to approve your registration for this event.';
		}
		$lock = 'hherm_review_lock_' . $id;
		$token = Mutation_Lock::acquire( $lock );
		if ( is_wp_error( $token ) ) return $token;
		try {
			$item = $this->repository->get( $id );
			if ( is_wp_error( $item ) ) { return $item; }
			$result = $this->capacity->with_event_lock( absint( $item['event_id'] ?? 0 ), function () use ( $id, $status, $notes, $item, $lock, $token ) {
				$current_status = sanitize_key( $item['registration_status'] ?? '' );
				if ( ! in_array( $current_status, array( 'pending', 'waitlist' ), true ) ) { return new \WP_Error( 'hherm_already_reviewed', 'This application has already been reviewed.', array( 'status' => 409 ) ); }
				$event = $this->event( absint( $item['event_id'] ?? 0 ) );
				if ( empty( $event['id'] ) ) { return new \WP_Error( 'hherm_event_missing', 'The related event could not be loaded.', array( 'status' => 422 ) ); }
				if ( 'approved' === $status && in_array( strtolower( trim( (string) get_post_meta( $event['id'], 'event_cancelled', true ) ) ), array( '1', 'true', 'yes', 'on' ), true ) ) return new \WP_Error( 'hherm_event_cancelled', 'This event has been cancelled. Its registrations cannot be approved.', array( 'status' => 409 ) );
				if ( $this->capacity->partially_configured() ) { return new \WP_Error( 'hherm_capacity_not_configured', 'Event capacity is not fully configured. Enter both capacity field keys in plugin settings.', array( 'status' => 422 ) ); }
				if ( ! Mutation_Lock::owns( $lock, $token ) ) return $this->lost_registration_lock( $id, $event['id'] );
				if ( $this->capacity->enabled() ) {
					$attendees = $this->capacity->attendees_from( $item );
					if ( is_wp_error( $attendees ) ) { return $attendees; }
					$reservation_before = $this->capacity->reservation_state( $id );
					if ( is_wp_error( $reservation_before ) ) return $reservation_before;
					$capacity_result = 'approved' === $status ? $this->capacity->ensure_reserved( $id, $event['id'], $attendees ) : $this->capacity->release( $id, $event['id'], $attendees );
					if ( is_wp_error( $capacity_result ) ) {
						if ( 'hherm_event_at_capacity' === $capacity_result->get_error_code() ) { return new \WP_Error( 'hherm_event_at_capacity', 'Sorry, the event is at capacity. You will have to decline or remove an attendee before approving this application.', array( 'status' => 409 ) ); }
						return $capacity_result;
					}
				}
				if ( ! Mutation_Lock::owns( $lock, $token ) ) return $this->lost_registration_lock( $id, $event['id'], isset( $reservation_before ) );
				$updated = $this->repository->update_review( $id, $status, $notes, static function () use ( $lock, $token ): bool { return Mutation_Lock::owns( $lock, $token ); } );
				if ( ! Mutation_Lock::owns( $lock, $token ) ) return $this->lost_registration_lock( $id, $event['id'], isset( $reservation_before ) );
				if ( is_wp_error( $updated ) ) {
					if ( 'hherm_update_rollback_failed' === $updated->get_error_code() && isset( $reservation_before ) ) {
						$this->reconcile_failed_write( $id, $event['id'], $reservation_before, $item, $lock, $token );
						return $updated;
					}
					$reservation_after = $this->capacity->enabled() && isset( $attendees, $reservation_before ) ? $this->capacity->reservation_state( $id ) : null;
					if ( is_wp_error( $reservation_after ) ) {
						$this->audit->write( $id, 'capacity_error', 'review_rollback_failed', array( 'event_id' => $event['id'], 'decision' => $status, 'reason' => 'reservation_read_failed' ) );
						return new \WP_Error( 'hherm_review_rollback_failed', 'The review could not be saved and its reservation state could not be verified. Please check this event before retrying.', array( 'status' => 500 ) );
					}
					if ( is_array( $reservation_after ) && (bool) $reservation_before['reserved'] !== (bool) $reservation_after['reserved'] ) {
						$rollback = $reservation_before['reserved'] ? $this->capacity->ensure_reserved( $id, $event['id'], absint( $reservation_before['attendees'] ) ) : $this->capacity->release( $id, $event['id'], $attendees );
						if ( is_wp_error( $rollback ) ) {
							$this->audit->write( $id, 'capacity_error', 'review_rollback_failed', array( 'event_id' => $event['id'], 'decision' => $status, 'reason' => $rollback->get_error_code() ) );
							return new \WP_Error( 'hherm_review_rollback_failed', 'The review could not be saved and its capacity change could not be fully restored. Please check this event before retrying.', array( 'status' => 500 ) );
						}
					}
					return $updated;
				}
				$this->audit->write( $id, 'decision', $status, array( 'notes' => $notes, 'event_id' => $event['id'], 'previous_status' => $current_status ) );
				return array( 'application' => $updated, 'event' => $event );
			} );
			if ( is_wp_error( $result ) ) return $result;
			// Capacity is settled; slow mail transport must not block other applicants.
			$email = $this->email->send_decision( $result['application'], $result['event'], $status );
			return array( 'application' => $this->safe_item( $result['application'] ), 'email' => $email );
		} finally { Mutation_Lock::release( $lock, $token ); }
	}

	private function lost_registration_lock( int $id, int $event_id, bool $capacity_may_have_changed = false ) {
		if ( $capacity_may_have_changed ) $this->audit->write( $id, 'capacity_error', 'registration_lock_lost', array( 'event_id' => $event_id ) );
		// A replacement owner may already have changed the record. Blind compensation
		// would risk undoing their work, so stop and require a fresh review.
		return new \WP_Error( 'hherm_registration_lock_lost', 'This registration changed while your request was being processed. Please check its details and reserved places before retrying.', array( 'status' => 409 ) );
	}

	/** Keep capacity consistent with verified stored data when storage compensation failed. */
	private function reconcile_failed_write( int $id, int $event_id, array $before, array $original, string $lock, string $token ): void {
		if ( ! $this->capacity->enabled() ) return;
		$saved = $this->repository->get( $id );
		if ( ! Mutation_Lock::owns( $lock, $token ) ) {
			$this->lost_registration_lock( $id, $event_id, true );
			return;
		}
		$result = $saved;
		if ( ! is_wp_error( $saved ) ) {
			$party = $this->capacity->attendees_from( $saved );
			$result = $party;
			if ( ! is_wp_error( $party ) ) {
				$state = $this->capacity->reservation_state( $id );
				if ( is_wp_error( $state ) ) {
					$this->audit->write( $id, 'capacity_error', 'storage_reconciliation_failed', array( 'event_id' => $event_id, 'reason' => 'reservation_read_failed' ) );
					return;
				}
				$key = $this->capacity->attendee_key() ?: 'number_of_attendees';
				$business_unchanged = ( $saved['registration_status'] ?? '' ) === ( $original['registration_status'] ?? '' ) && (string) ( $saved[ $key ] ?? 1 ) === (string) ( $original[ $key ] ?? 1 );
				$needs_reservation = in_array( $saved['registration_status'] ?? '', array( 'pending', 'approved' ), true ) && ( $before['reserved'] || ! $business_unchanged );
				$result = $needs_reservation ? ( $state['reserved'] ? $this->capacity->adjust_reserved( $id, $event_id, $state['attendees'], $party ) : $this->capacity->ensure_reserved( $id, $event_id, $party ) ) : $this->capacity->release( $id, $event_id, $party );
			}
		}
		$this->audit->write( $id, 'capacity_error', is_wp_error( $result ) ? 'storage_reconciliation_failed' : 'storage_reconciled', array( 'event_id' => $event_id ) );
	}

	private function summary( array $item ): array { $attendee_key = $this->capacity->attendee_key() ?: 'number_of_attendees'; $status = sanitize_key( (string) ( $item['registration_status'] ?? '' ) ); return array( 'id' => absint( $item['_ID'] ?? 0 ), 'name' => trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ), 'email' => sanitize_email( $item['email'] ?? '' ), 'attendees' => max( 1, (int) ( $item[ $attendee_key ] ?? 1 ) ), 'event' => $this->event( absint( $item['event_id'] ?? 0 ) ), 'registration_date' => Event_Datetime::display( $item['registration_date'] ?? '', Event_Datetime::DATETIME_FORMAT ), 'status' => $status ?: 'pending' ); }
	private function safe_item( array $item ): array {
		$keys = array( '_ID','event_id','first_name','last_name','email','phone','attended_before','impacted_by_trauma','reason_for_attending','organisation','dietaryrequirements','please_let_us_know','registration_date','registration_status','registration_type','form_id','reviewed_by','reviewed_date','approval_notes','decline_reason','attendance_status','checked_in_at','checked_in_party_size' );
		$attendee_key = $this->capacity->attendee_key() ?: 'number_of_attendees';
		$payment_keys = array(
			'payment_amount' => sanitize_key( $this->settings->get( 'payment_amount_field', 'amount_paid' ) ),
			'payment_status' => sanitize_key( $this->settings->get( 'payment_status_field', 'payment_status' ) ),
			'payment_method' => sanitize_key( $this->settings->get( 'payment_method_field', 'payment_method' ) ),
		);
		$keys[] = $attendee_key;
		foreach ( array_filter( $payment_keys ) as $key ) { $keys[] = $key; }
		$safe = array_intersect_key( $item, array_flip( $keys ) );
		foreach ( array( 'registration_date', 'reviewed_date' ) as $date_key ) {
			if ( array_key_exists( $date_key, $safe ) ) $safe[ $date_key ] = Event_Datetime::display( $safe[ $date_key ], Event_Datetime::DATETIME_FORMAT );
		}
		$safe['number_of_attendees'] = max( 1, (int) ( $item[ $attendee_key ] ?? 1 ) );
		foreach ( $payment_keys as $output_key => $field_key ) {
			$value = $field_key ? ( $item[ $field_key ] ?? '' ) : '';
			$safe[ $output_key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		if ( ! empty( $safe['reviewed_by'] ) ) {
			$user = get_userdata( absint( $safe['reviewed_by'] ) );
			$safe['reviewer_name'] = $user ? $user->display_name : '';
		}
		return $safe;
	}

	private function application_details( array $item ): array {
		$history = $this->customer_history( $item );
		$result = array( 'application' => $this->safe_item( $item ), 'event' => $this->event( absint( $item['event_id'] ?? 0 ) ), 'history' => is_wp_error( $history ) ? array() : $history );
		if ( is_wp_error( $history ) ) $result['history_error'] = 'Customer history is temporarily unavailable. Reopen this application to try again.';
		return $result;
	}

	private function customer_history( array $current ) {
		$email = sanitize_email( $current['email'] ?? '' );
		if ( ! is_email( $email ) || ! method_exists( $this->repository, 'customer_registrations' ) ) {
			return array();
		}
		$items = $this->repository->customer_registrations( $email );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$attendee_key = $this->capacity->attendee_key() ?: 'number_of_attendees';
		$amount_key = sanitize_key( $this->settings->get( 'payment_amount_field', 'amount_paid' ) );
		$status_key = sanitize_key( $this->settings->get( 'payment_status_field', 'payment_status' ) );
		$method_key = sanitize_key( $this->settings->get( 'payment_method_field', 'payment_method' ) );
		$history = array();
		foreach ( $items as $item ) {
			$event_id = absint( $item['event_id'] ?? 0 );
			$event = $this->event( $event_id );
			$event_start_key = sanitize_key( $this->settings->get( 'event_start', 'start_date' ) );
			$event_timestamp = $event_id && $event_start_key ? Event_Datetime::timestamp( get_post_meta( $event_id, $event_start_key, true ) ) : 0;
			$registration_timestamp = Event_Datetime::timestamp( $item['registration_date'] ?? '' );
			$attendance = sanitize_key( (string) ( $item['attendance_status'] ?? '' ) );
			if ( ! $attendance && ! empty( $item['checked_in_at'] ) ) $attendance = 'attended';
			$registration_status = sanitize_key( (string) ( $item['registration_status'] ?? '' ) ) ?: 'pending';
			$payment_amount = $amount_key ? ( $item[ $amount_key ] ?? '' ) : '';
			$payment_status = $status_key ? ( $item[ $status_key ] ?? '' ) : '';
			$payment_method = $method_key ? ( $item[ $method_key ] ?? '' ) : '';
			$history[] = array(
				'id'                  => absint( $item['_ID'] ?? 0 ),
				'event'               => $event,
				'registration_date'   => Event_Datetime::display( $item['registration_date'] ?? '', Event_Datetime::DATETIME_FORMAT ),
				'registration_status' => $registration_status,
				'attendance_status'   => $attendance,
				'checked_in_at'       => Event_Datetime::display( $item['checked_in_at'] ?? '', Event_Datetime::DATETIME_FORMAT ),
				'party_size'          => max( 1, (int) ( $item[ $attendee_key ] ?? 1 ) ),
				'payment_amount'      => is_scalar( $payment_amount ) ? sanitize_text_field( (string) $payment_amount ) : '',
				'payment_status'      => is_scalar( $payment_status ) ? sanitize_text_field( (string) $payment_status ) : '',
				'payment_method'      => is_scalar( $payment_method ) ? sanitize_text_field( (string) $payment_method ) : '',
				'sort_timestamp'      => $event_timestamp ?: $registration_timestamp,
			);
		}
		usort( $history, static function ( array $left, array $right ): int { return ( $left['sort_timestamp'] <=> $right['sort_timestamp'] ) ?: ( $left['id'] <=> $right['id'] ); } );
		foreach ( $history as &$entry ) unset( $entry['sort_timestamp'] );
		unset( $entry );
		return $history;
	}
	private function events(): array { $ids = get_posts( array( 'post_type' => $this->settings->get( 'events_cpt', 'events' ), 'post_status' => array( 'publish', 'private', 'draft', 'future' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => false ) ); return array_map( static function ( $id ) { return array( 'id' => absint( $id ), 'title' => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); }, $ids ); }
	private function event( int $id ): array {
		$post = get_post( $id );
		$cpt = $this->settings->get( 'events_cpt' );
		if ( ! $post || 'trash' === $post->post_status || ( $cpt && $cpt !== $post->post_type ) ) {
			return array();
		}
		$key = function ( $name ) { return $this->settings->get( $name ); };
		$meta = function ( $name ) use ( $id, $key ) {
			$meta_key = $key( $name );
			$value = $meta_key ? get_post_meta( $id, $meta_key, true ) : '';
			return is_scalar( $value ) ? (string) $value : '';
		};
		$start_raw = $meta( 'event_start' );
		$end_raw = $meta( 'event_end' );
		$date = $meta( 'event_date' );
		if ( ! $date && $start_raw ) {
			$date = $this->format_event_datetime( $start_raw, Event_Datetime::DATE_FORMAT );
		}
		if ( 'tbc' === Event_Public_Display::normalise_date_status( get_post_meta( $id, 'event_date_status', true ), get_post_meta( $id, 'event_schedule_status', true ) ) ) $date = 'Date TBC';
		$remaining = $this->capacity->enabled() ? $this->capacity->remaining( $id ) : null;
		$external_fee = in_array( strtolower( (string) get_post_meta( $id, 'external_fee_enabled', true ) ), array( '1', 'true', 'yes', 'on' ), true );
		$fee = $external_fee ? get_post_meta( $id, 'external_fee_amount', true ) : get_post_meta( $id, 'registration_fee', true );
		$fee = is_scalar( $fee ) ? sanitize_text_field( (string) $fee ) : '';
		if ( $external_fee && '' !== $fee && is_numeric( $fee ) ) $fee = '$' . number_format( (float) $fee, 2 );
		return array(
			'id'        => $id,
			'title'     => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'date'      => $date,
			'start'     => $this->format_event_datetime( $start_raw, Event_Datetime::TIME_FORMAT ),
			'end'       => $this->format_event_datetime( $end_raw, Event_Datetime::TIME_FORMAT ),
			'venue'     => $meta( 'event_venue' ),
			'organiser' => $meta( 'event_organiser' ),
			'fee'       => $fee,
			'info'      => $meta( 'event_info' ) ?: $post->post_excerpt ?: $post->post_content,
			'capacity'  => absint( get_post_meta( $id, 'event_capacity', true ) ),
			'remaining' => is_wp_error( $remaining ) || null === $remaining ? null : $remaining,
		);
	}

	private function format_event_datetime( string $value, string $format ): string {
		return Event_Datetime::display( $value, $format );
	}
	public function no_store( $response, $server, $request ) { if ( 0 === strpos( $request->get_route(), '/heart-hub/v1/' ) ) { $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' ); $response->header( 'Pragma', 'no-cache' ); } return $response; }
}
