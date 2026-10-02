<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class CCT_Repository {
	private $settings;
	private $creating_registration = false;
	private $created_registration_id = 0;

	public function __construct( Settings $settings ) { $this->settings = $settings; }

	private function type() {
		if ( ! class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
			return new \WP_Error( 'hherm_jetengine_missing', 'JetEngine Custom Content Types is unavailable.' );
		}
		$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();
		if ( empty( $module->manager ) || ! method_exists( $module->manager, 'get_content_types' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine CCT manager is incompatible.' );
		}
		$type = $module->manager->get_content_types( $this->settings->get( 'cct_slug', 'event_registrations' ) );
		return $type ?: new \WP_Error( 'hherm_cct_missing', 'The Event Registrations CCT was not found.' );
	}

	public function get( int $id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$type->db->set_format_flag( ARRAY_A );
		try {
			$item = $type->db->get_item( $id );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'hherm_read_failed', 'JetEngine could not read the registration.', array( 'status' => 500 ) );
		}
		if ( is_wp_error( $item ) ) return $item;
		return is_array( $item ) ? $this->normalise_item( $item ) : new \WP_Error( 'hherm_not_found', 'Application not found.', array( 'status' => 404 ) );
	}

	public function create_registration( array $fields ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) return $type;
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine item handler cannot create an application.' );
		}
		// JetEngine's CCT handler inserts when update_item receives no _ID.
		unset( $fields['_ID'] );
		if ( array_key_exists( 'registration_status', $fields ) ) $fields['registration_status'] = $this->normalise_item( $fields )['registration_status'];
		$this->creating_registration = true;
		$this->created_registration_id = 0;
		try {
			$result = $this->write_item( $handler, $fields );
		} finally {
			$this->creating_registration = false;
		}
		// The lifecycle hook identifies the row even when a later hook throws or
		// a handler returns a boolean instead of the newly inserted numeric ID.
		$item_id = $this->created_registration_id ?: ( is_wp_error( $result ) || ! $result ? 0 : absint( $result ) );
		$this->created_registration_id = 0;
		if ( ! $item_id ) return is_wp_error( $result ) ? $result : new \WP_Error( 'hherm_create_failed', 'JetEngine could not save the expression of interest.' );
		$saved = $this->get( $item_id );
		$required = $fields;
		// user_id is optional JetForm integration metadata, rather than a field in
		// the documented registrations CCT schema. Keep writing it where configured.
		unset( $required['user_id'] );
		// Interest status identifies these records even when the optional application
		// type column has not been added to the CCT.
		if ( 'expression_of_interest' === ( $required['registration_type'] ?? '' ) ) unset( $required['registration_type'] );
		if ( $result && ! is_wp_error( $result ) && ! is_wp_error( $saved ) && $this->fields_match( $saved, $required ) ) return $item_id;
		$discarded = $this->discard_registration( $item_id );
		if ( is_wp_error( $discarded ) ) return $discarded;
		return is_wp_error( $result ) ? $result : new \WP_Error( 'hherm_create_incomplete', 'JetEngine did not save all submitted details. The incomplete application was removed. Please check the CCT field configuration before retrying.', array( 'status' => 500 ) );
	}

	/** The CCT hook runs inside update_item(), before a plugin-owned insert is verified. */
	public function defer_created_registration( int $id ): bool {
		if ( ! $this->creating_registration ) return false;
		if ( ! $this->created_registration_id ) $this->created_registration_id = $id;
		return true;
	}

	/** Remove only an incomplete new application, before any successful notification. */
	public function discard_registration( int $id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) return $type;
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'raw_delete_item' ) ) return $this->create_cleanup_error();
		try {
			// raw_delete_item is JetEngine's supported non-interactive deletion API.
			// delete_item performs an administrator permission check and may redirect.
			$handler->raw_delete_item( $id );
		} catch ( \Throwable $error ) {
			// A deletion hook can throw after the row has already been removed.
		}
		$saved = $this->get( $id );
		return is_wp_error( $saved ) && 'hherm_not_found' === $saved->get_error_code() ? true : $this->create_cleanup_error();
	}

	private function create_cleanup_error() {
		return new \WP_Error( 'hherm_create_cleanup_failed', 'The application could not be saved completely or removed. Please contact the event organiser before submitting again.', array( 'status' => 500 ) );
	}

	public function list( array $filters ): array {
		return $this->scan( $filters, false );
	}

	/** One bounded scan supplies both the filtered page and global dashboard totals. */
	public function dashboard( array $filters ): array {
		return $this->scan( $filters, true );
	}

	public function statistics(): array {
		$result = $this->scan( array( 'per_page' => 1 ), true );
		return isset( $result['error'] ) ? array( 'error' => $result['error'] ) : $result['stats'];
	}

	private function scan( array $filters, bool $with_statistics ): array {
		$filters = wp_parse_args( $filters, array( 'page' => 1, 'per_page' => 20, 'status' => '', 'event_id' => 0, 'search' => '', 'order' => 'desc', 'date_from' => '', 'date_to' => '' ) );
		$per_page = max( 1, min( 500, (int) $filters['per_page'] ) );
		$offset = ( max( 1, (int) $filters['page'] ) - 1 ) * $per_page;
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return array( 'error' => $type ); }
		$type->db->set_format_flag( ARRAY_A );
		$args = array();
		if ( ! $with_statistics && $filters['event_id'] ) $args[] = array( 'field' => 'event_id', 'operator' => '=', 'value' => absint( $filters['event_id'] ) );
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$direction = 'asc' === $filters['order'] ? 'ASC' : 'DESC';
		$order = array( array( 'orderby' => 'registration_date', 'order' => $direction, 'type' => 'CHAR' ), array( 'orderby' => '_ID', 'order' => $direction, 'type' => 'INTEGER' ) );
		$counts = array( 'interest' => 0, 'pending' => 0, 'waitlist' => 0, 'approved' => 0, 'declined' => 0, 'pending_places' => 0, 'waitlist_places' => 0, 'approved_week' => 0, 'capacity_percent' => 0, 'open_events' => 0 );
		$attendee_key = $this->settings->get( 'attendee_count_field', 'number_of_attendees' ) ?: 'number_of_attendees';
		$week_start = $with_statistics ? ( new \DateTimeImmutable( 'monday this week', wp_timezone() ) )->setTime( 0, 0 )->getTimestamp() : 0;
		$from = $filters['date_from'] ? $this->site_datetime_timestamp( $filters['date_from'] . ' 00:00:00' ) : 0;
		$to = $filters['date_to'] ? $this->site_datetime_timestamp( $filters['date_to'] . ' 23:59:59' ) : PHP_INT_MAX;
		$needle = strtolower( (string) $filters['search'] );
		$phone_needle = preg_match( '/^[+\d\s().-]+$/', $needle ) ? preg_replace( '/\D/', '', $needle ) : '';
		$items = array();
		$total = 0;
		$previous_batch = '';
		// Legacy statuses and formatted phone searches need PHP filtering. Retain only
		// one 500-row batch and the requested page; exact totals still require O(n) reads.
		for ( $query_offset = 0; ; $query_offset += 500 ) {
			try {
				$batch = $type->db->query( $args, 500, $query_offset, $order, 'AND' );
			} catch ( \Throwable $error ) {
				return array( 'error' => new \WP_Error( 'hherm_query_failed', 'JetEngine could not read registrations.', array( 'status' => 500 ) ) );
			}
			if ( is_wp_error( $batch ) ) return array( 'error' => $batch );
			if ( ! is_array( $batch ) || count( $batch ) > 500 ) return array( 'error' => new \WP_Error( 'hherm_query_failed', 'JetEngine could not read a bounded page of registrations.' ) );
			if ( ! $batch ) break;
			$fingerprint = md5( serialize( $batch ) );
			if ( $fingerprint === $previous_batch ) return array( 'error' => new \WP_Error( 'hherm_query_failed', 'JetEngine did not advance the registration page.' ) );
			$previous_batch = $fingerprint;
			foreach ( $batch as $row ) {
				$item = $this->normalise_item( $row );
				$status = $item['registration_status'];
				if ( $with_statistics && in_array( $status, array( 'interest', 'pending', 'waitlist', 'approved', 'declined' ), true ) ) {
					++$counts[ $status ];
					$places = max( 1, absint( $item[ $attendee_key ] ?? 1 ) );
					if ( 'pending' === $status ) $counts['pending_places'] += $places;
					if ( 'waitlist' === $status ) $counts['waitlist_places'] += $places;
					if ( 'approved' === $status && $this->site_datetime_timestamp( $item['reviewed_date'] ?? '' ) >= $week_start ) ++$counts['approved_week'];
				}
				if ( in_array( $filters['status'], array( 'interest', 'pending', 'waitlist', 'approved', 'declined' ), true ) && $filters['status'] !== $status ) continue;
				if ( $filters['event_id'] && absint( $filters['event_id'] ) !== absint( $item['event_id'] ?? 0 ) ) continue;
				if ( $filters['date_from'] || $filters['date_to'] ) {
					$registered = $this->site_datetime_timestamp( $item['registration_date'] ?? '' );
					if ( ! $registered || $registered < $from || $registered > $to ) continue;
				}
				if ( '' !== $needle ) {
					$haystack = strtolower( implode( ' ', array( $item['first_name'] ?? '', $item['last_name'] ?? '', $item['email'] ?? '', $item['phone'] ?? '' ) ) );
					$phone = preg_replace( '/\D/', '', (string) ( $item['phone'] ?? '' ) );
					if ( false === strpos( $haystack, $needle ) && ( '' === $phone_needle || false === strpos( $phone, $phone_needle ) ) ) continue;
				}
				if ( $total >= $offset && count( $items ) < $per_page ) $items[] = $item;
				++$total;
			}
			if ( count( $batch ) < 500 ) break;
		}
		$result = array( 'items' => $items, 'total' => $total );
		if ( $with_statistics ) $result['stats'] = $this->event_statistics( $counts );
		return $result;
	}

	private function event_statistics( array $counts ): array {
		$event_ids = get_posts( array( 'post_type' => $this->settings->get( 'events_cpt', 'events' ), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => false ) );
		$total_capacity = 0;
		$remaining_capacity = 0;
		$now = time();
		$remaining_key = sanitize_key( $this->settings->get( 'event_remaining_capacity', 'current_event_capacity' ) );
		foreach ( $event_ids as $event_id ) {
			$enabled = strtolower( (string) get_post_meta( $event_id, 'registration_enabled', true ) );
			$cancelled = strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) );
			$opens = $this->site_datetime_timestamp( get_post_meta( $event_id, 'registration_open', true ) );
			$closes = $this->site_datetime_timestamp( get_post_meta( $event_id, 'registration_close', true ) );
			if ( in_array( $enabled, array( '0', 'false', 'no', 'off' ), true ) || in_array( $cancelled, array( '1', 'true', 'yes', 'on' ), true ) || ( $opens && $now < $opens ) || ( $closes && $now >= $closes ) ) continue;
			$total = absint( get_post_meta( $event_id, 'event_capacity', true ) );
			$remaining_raw = $remaining_key ? get_post_meta( $event_id, $remaining_key, true ) : '';
			$remaining = '' === (string) $remaining_raw ? $total : min( $total, absint( $remaining_raw ) );
			++$counts['open_events'];
			$total_capacity += $total;
			$remaining_capacity += $remaining;
		}
		if ( $total_capacity > 0 ) $counts['capacity_percent'] = min( 100, max( 0, (int) round( ( $total_capacity - $remaining_capacity ) / $total_capacity * 100 ) ) );
		return $counts;
	}

	private function site_datetime_timestamp( $value ): int {
		if ( is_numeric( $value ) ) return absint( $value );
		$value = trim( (string) $value );
		if ( '' === $value ) return 0;
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y h:i a', 'd/m/Y', 'j F Y, g:i a' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === $value ) return $date->getTimestamp();
		}
		return 0;
	}

	public function update_review( int $id, string $status, string $notes, ?callable $can_write = null ) {
		$data = array(
			'registration_status' => $status,
			'reviewed_by'         => get_current_user_id(),
			'reviewed_date'       => current_time( 'mysql' ),
			'approval_notes'      => 'approved' === $status ? $notes : '',
			'decline_reason'      => 'declined' === $status ? $notes : '',
		);
		$item = $this->get( $id );
		if ( is_wp_error( $item ) ) return $item;
		$party_key = sanitize_key( $this->settings->get( 'attendee_count_field', '' ) ) ?: 'number_of_attendees';
		$data[ $party_key ] = max( 1, (int) ( $item[ $party_key ] ?? 1 ) );
		return $this->update_registration( $id, $data, $can_write );
	}

	public function update_registration( int $id, array $fields, ?callable $can_write = null ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine item handler is incompatible.' );
		}
		$before = $this->get( $id );
		if ( is_wp_error( $before ) ) return $before;
		unset( $fields['_ID'] );
		if ( array_key_exists( 'registration_status', $fields ) ) $fields['registration_status'] = $this->normalise_item( $fields )['registration_status'];
		if ( $can_write && ! $can_write() ) return $this->lost_write_lock();
		$result = $this->write_item( $handler, array( '_ID' => $id ) + $fields );
		$saved = $this->get( $id );
		if ( $can_write && ! $can_write() ) return $this->lost_write_lock();
		if ( ! is_wp_error( $result ) && $result && ! is_wp_error( $saved ) && $this->fields_match( $saved, $fields ) ) return $saved;

		// CCT field mappings or storage filters can accept an update while silently
		// dropping some values. Restore all touched fields before callers undo capacity.
		$restore = array();
		foreach ( $fields as $key => $value ) $restore[ $key ] = $before[ $key ] ?? '';
		if ( is_wp_error( $saved ) || ! $this->fields_match( $saved, $restore, true ) ) {
			$this->write_item( $handler, array( '_ID' => $id ) + $restore );
			$saved = $this->get( $id );
			if ( is_wp_error( $saved ) || ! $this->fields_match( $saved, $restore, true ) ) {
				return new \WP_Error( 'hherm_update_rollback_failed', 'The registration could not be saved completely or restored. Please check its details and event capacity before retrying.', array( 'status' => 500 ) );
			}
		}
		if ( is_wp_error( $result ) ) return $result;
		return new \WP_Error( 'hherm_update_failed', 'JetEngine did not save all requested registration fields. The previous values were restored. Check the CCT field configuration before retrying.', array( 'status' => 500 ) );
	}

	private function lost_write_lock() {
		return new \WP_Error( 'hherm_registration_lock_lost', 'This registration changed while your request was being processed. Please check its details and reserved places before retrying.', array( 'status' => 409 ) );
	}

	private function write_item( $handler, array $fields ) {
		try {
			return $handler->update_item( $fields );
		} catch ( \Throwable $error ) {
			// A hook can throw after writing. The update caller still verifies/compensates.
			return new \WP_Error( 'hherm_storage_exception', 'JetEngine could not complete the registration update.', array( 'status' => 500 ) );
		}
	}

	private function fields_match( array $saved, array $expected, bool $allow_missing_empty = false ): bool {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $saved ) ) {
				if ( $allow_missing_empty && '' === $value ) continue;
				return false;
			}
			$actual = $saved[ $key ];
			// SQL number/text columns may return strings for numeric writes.
			if ( ( is_scalar( $value ) || null === $value ) && ( is_scalar( $actual ) || null === $actual ) ) {
				if ( (string) $actual !== (string) $value ) return false;
			} elseif ( $actual !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return every registration stored for one customer email address.
	 *
	 * @return array|\WP_Error
	 */
	public function customer_registrations( string $email ) {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'hherm_invalid_customer_email', 'A valid customer email is required.' );
		}
		$type = $this->type();
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$args = array( array( 'field' => 'email', 'operator' => '=', 'value' => $email, 'type' => 'CHAR' ) );
		$items = $this->query_items( $type, $args, array( array( 'orderby' => 'registration_date', 'order' => 'ASC', 'type' => 'CHAR' ) ) );
		if ( is_wp_error( $items ) ) return $items;
		return array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $email ): bool {
					return $email === strtolower( sanitize_email( $item['email'] ?? '' ) );
				}
			)
		);
	}

	public function event_registrations( int $event_id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) return $type;
		$args = array( array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ) );
		return $this->query_items( $type, $args, array( array( 'orderby' => 'last_name', 'order' => 'ASC', 'type' => 'CHAR' ) ) );
	}

	public function attendance_list( int $event_id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$args = array(
			array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ),
			array( 'field' => 'registration_status', 'operator' => '=', 'value' => 'approved', 'type' => 'CHAR' ),
		);
		return $this->query_items( $type, $args, array( array( 'orderby' => 'last_name', 'order' => 'ASC', 'type' => 'CHAR' ) ) );
	}

	public function cancellation_recipients( int $event_id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$args = array( array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ) );
		$items = $this->query_items( $type, $args );
		if ( is_wp_error( $items ) ) return $items;
		return array_values( array_filter( $items, static function ( $item ) {
			return in_array( sanitize_key( $item['registration_status'] ?? 'pending' ), array( 'pending', 'approved', 'waitlist' ), true );
		} ) );
	}

	public function interest_recipients( int $event_id ) {
		$items = $this->event_registrations( $event_id );
		if ( is_wp_error( $items ) ) return $items;
		return array_values( array_filter( $items, static function ( array $item ): bool { return 'interest' === $item['registration_status']; } ) );
	}

	/** A failed query must not masquerade as an event/customer with no registrations. */
	private function query_items( $type, array $args, array $order = array() ) {
		try {
			$type->db->set_format_flag( ARRAY_A );
			$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
			$items = $type->db->query( $args, 0, 0, $order, 'AND' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'hherm_query_failed', 'JetEngine could not read registrations.', array( 'status' => 500 ) );
		}
		if ( is_wp_error( $items ) ) return $items;
		if ( ! is_array( $items ) ) return new \WP_Error( 'hherm_query_failed', 'JetEngine could not read registrations.', array( 'status' => 500 ) );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) return new \WP_Error( 'hherm_query_failed', 'JetEngine returned an invalid registration.', array( 'status' => 500 ) );
		}
		return array_map( array( $this, 'normalise_item' ), $items );
	}

	public function update_attendance( int $id, string $status, ?callable $can_write = null ) {
		if ( ! in_array( $status, array( 'attended', 'no-show', 'partial', 'unknown' ), true ) ) {
			return new \WP_Error( 'hherm_invalid_attendance', 'The attendance status is invalid.' );
		}
		return $this->update_registration( $id, array( 'attendance_status' => $status ), $can_write );
	}

	public function find_approved_by_email( int $event_id, string $email ) {
		$items = $this->attendance_list( $event_id );
		if ( is_wp_error( $items ) ) { return $items; }
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) { return new \WP_Error( 'hherm_checkin_not_found', 'The registration could not be verified.' ); }
		foreach ( $items as $item ) {
			$item_email = strtolower( sanitize_email( $item['email'] ?? '' ) );
			if ( $item_email && hash_equals( $email, $item_email ) ) { return $item; }
		}
		return new \WP_Error( 'hherm_checkin_not_found', 'The registration could not be verified.' );
	}

	public function update_self_checkin( int $id, string $feedback, int $party_size = 0, string $status = 'attended', int $score = 0, ?callable $can_write = null ) {
		$field = sanitize_key( $this->settings->get( 'feedback_field', 'event_feedback' ) );
		$status = in_array( $status, array( 'attended', 'partial' ), true ) ? $status : 'attended';
		$data = array( 'attendance_status' => $status, 'checked_in_at' => current_time( 'mysql' ) );
		if ( $party_size > 0 ) { $data['checked_in_party_size'] = $party_size; }
		if ( $score >= 1 && $score <= 5 ) { $data['feedback_score'] = $score; }
		if ( $field && '' !== $feedback ) {
			$data[ $field ] = $feedback;
		}
		return $this->update_registration( $id, $data, $can_write );
	}

	public function connect_relation( int $event_id, int $registration_id ): bool {
		$relation_id = absint( $this->settings->get( 'relation_id' ) );
		if ( ! $relation_id || ! function_exists( 'jet_engine' ) || empty( jet_engine()->relations ) || ! method_exists( jet_engine()->relations, 'get_active_relations' ) ) { return false; }
		$relation = jet_engine()->relations->get_active_relations( $relation_id );
		if ( ! $relation || ! method_exists( $relation, 'update' ) ) { return false; }
		if ( method_exists( $relation, 'get_children' ) && in_array( $registration_id, array_map( 'intval', (array) $relation->get_children( $event_id, 'ids' ) ), true ) ) { return true; }
		$relation->set_update_context( 'parent' );
		return (bool) $relation->update( $event_id, $registration_id );
	}

	public function update_feedback( int $id, string $feedback, int $score ) {
		$field = sanitize_key( $this->settings->get( 'feedback_field', 'event_feedback' ) );
		$fields = array( 'feedback_score' => min( 5, max( 1, $score ) ) );
		if ( $field && '' !== $feedback ) {
			$fields[ $field ] = $feedback;
		}
		return $this->update_registration( $id, $fields );
	}

	private function normalise_item( $item ): array {
		$item = is_object( $item ) ? get_object_vars( $item ) : (array) $item;
		$status = sanitize_key( (string) ( $item['registration_status'] ?? '' ) );
		if ( 'expression_of_interest' === $status || 'expression-of-interest' === $status ) $status = 'interest';
		$item['registration_status'] = $status ?: 'pending';
		return $item;
	}
}
