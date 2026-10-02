<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class CCT_Repository {
	private $settings;

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
		$item = $type->db->get_item( $id );
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
		$result = $handler->update_item( $fields );
		if ( is_wp_error( $result ) ) return $result;
		$item_id = absint( $result );
		return $item_id ?: new \WP_Error( 'hherm_create_failed', 'JetEngine could not save the expression of interest.' );
	}

	public function list( array $filters ): array {
		$filters = wp_parse_args( $filters, array( 'page' => 1, 'per_page' => 20, 'status' => '', 'event_id' => 0, 'search' => '', 'order' => 'desc', 'date_from' => '', 'date_to' => '' ) );
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return array( 'error' => $type ); }
		$type->db->set_format_flag( ARRAY_A );
		$args = array();
		if ( $filters['event_id'] ) $args[] = array( 'field' => 'event_id', 'operator' => '=', 'value' => absint( $filters['event_id'] ) );
		// Apply these in PHP after normalisation. Older CCT rows can use an empty
		// status or an expression_of_interest value, and JetEngine's field query
		// would otherwise hide those applications from the admin list.
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$order = array( array( 'orderby' => 'registration_date', 'order' => 'asc' === $filters['order'] ? 'ASC' : 'DESC', 'type' => 'CHAR' ) );
		// Search spans multiple fields; query through JetEngine, then filter and paginate in PHP to avoid direct CCT SQL.
		$all = $type->db->query( $args, 0, 0, $order, 'AND' );
		$all = is_array( $all ) ? array_map( array( $this, 'normalise_item' ), $all ) : array();
		if ( in_array( $filters['status'], array( 'interest', 'pending', 'waitlist', 'approved', 'declined' ), true ) ) {
			$wanted_status = $filters['status'];
			$all = array_values( array_filter( $all, static function ( $item ) use ( $wanted_status ) {
				$status = sanitize_key( (string) ( $item['registration_status'] ?? 'pending' ) );
				return $wanted_status === ( '' === $status ? 'pending' : $status );
			} ) );
		}
		if ( $filters['event_id'] ) {
			$event_id = absint( $filters['event_id'] );
			$all = array_values( array_filter( $all, static function ( $item ) use ( $event_id ) { return $event_id === absint( $item['event_id'] ?? 0 ); } ) );
		}
		if ( $filters['date_from'] || $filters['date_to'] ) {
			$from = $filters['date_from'] ? strtotime( $filters['date_from'] . ' 00:00:00' ) : 0;
			$to = $filters['date_to'] ? strtotime( $filters['date_to'] . ' 23:59:59' ) : PHP_INT_MAX;
			$all = array_values( array_filter( $all, static function ( $item ) use ( $from, $to ) {
				$registered = strtotime( (string) ( $item['registration_date'] ?? '' ) );
				return false !== $registered && $registered >= $from && $registered <= $to;
			} ) );
		}
		if ( $filters['search'] ) {
			$needle = strtolower( $filters['search'] );
			// Ignore common phone formatting without turning name/email searches into digit searches.
			$phone_needle = preg_match( '/^[+\d\s().-]+$/', $needle ) ? preg_replace( '/\D/', '', $needle ) : '';
			$all = array_values( array_filter( $all, static function ( $item ) use ( $needle, $phone_needle ) {
				$haystack = strtolower( implode( ' ', array( $item['first_name'] ?? '', $item['last_name'] ?? '', $item['email'] ?? '', $item['phone'] ?? '' ) ) );
				$phone = preg_replace( '/\D/', '', (string) ( $item['phone'] ?? '' ) );
				return false !== strpos( $haystack, $needle ) || ( '' !== $phone_needle && false !== strpos( $phone, $phone_needle ) );
			} ) );
		}
		$total = count( $all );
		$offset = ( $filters['page'] - 1 ) * $filters['per_page'];
		return array( 'items' => array_slice( $all, $offset, $filters['per_page'] ), 'total' => $total );
	}

	public function statistics(): array {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return array( 'error' => $type ); }
		$type->db->set_format_flag( ARRAY_A );
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( array() ) : array();
		$items = $type->db->query( $args, 0, 0, array(), 'AND' );
		$items = is_array( $items ) ? array_map( array( $this, 'normalise_item' ), $items ) : array();
		$counts = array( 'pending' => 0, 'waitlist' => 0, 'approved' => 0, 'declined' => 0, 'pending_places' => 0, 'waitlist_places' => 0, 'approved_week' => 0, 'capacity_percent' => 0, 'open_events' => 0 );
		$attendee_key = $this->settings->get( 'attendee_count_field', 'number_of_attendees' );
		$week_start = ( new \DateTimeImmutable( 'monday this week', wp_timezone() ) )->setTime( 0, 0 )->getTimestamp();
		foreach ( $items as $item ) {
			$status = sanitize_key( $item['registration_status'] ?? 'pending' );
			if ( ! isset( $counts[ $status ] ) ) { continue; }
			++$counts[ $status ];
			$places = max( 1, absint( $item[ $attendee_key ] ?? 1 ) );
			if ( 'pending' === $status ) { $counts['pending_places'] += $places; }
			if ( 'waitlist' === $status ) { $counts['waitlist_places'] += $places; }
			if ( 'approved' === $status && $week_start && $this->site_datetime_timestamp( $item['reviewed_date'] ?? '' ) >= $week_start ) { ++$counts['approved_week']; }
		}
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
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			if ( $date ) return $date->getTimestamp();
		}
		return 0;
	}

	public function update_review( int $id, string $status, string $notes ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine item handler is incompatible.' );
		}
		$data = array(
			'_ID'                 => $id,
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
		$result = $handler->update_item( $data );
		return $result ? $this->get( $id ) : new \WP_Error( 'hherm_update_failed', 'JetEngine could not update the application.' );
	}

	public function update_registration( int $id, array $fields ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine item handler is incompatible.' );
		}
		$data   = array( '_ID' => $id ) + $fields;
		$result = $handler->update_item( $data );
		return $result ? $this->get( $id ) : new \WP_Error( 'hherm_update_failed', 'JetEngine could not update the application.' );
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
		$type->db->set_format_flag( ARRAY_A );
		$args = array( array( 'field' => 'email', 'operator' => '=', 'value' => $email, 'type' => 'CHAR' ) );
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$items = $type->db->query( $args, 0, 0, array( array( 'orderby' => 'registration_date', 'order' => 'ASC', 'type' => 'CHAR' ) ), 'AND' );
		$items = is_array( $items ) ? array_map( array( $this, 'normalise_item' ), $items ) : array();
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
		$type->db->set_format_flag( ARRAY_A );
		$args = array( array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ) );
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$items = $type->db->query( $args, 0, 0, array( array( 'orderby' => 'last_name', 'order' => 'ASC', 'type' => 'CHAR' ) ), 'AND' );
		return is_array( $items ) ? array_map( array( $this, 'normalise_item' ), $items ) : array();
	}

	public function attendance_list( int $event_id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$type->db->set_format_flag( ARRAY_A );
		$args = array(
			array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ),
			array( 'field' => 'registration_status', 'operator' => '=', 'value' => 'approved', 'type' => 'CHAR' ),
		);
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$items = $type->db->query( $args, 0, 0, array( array( 'orderby' => 'last_name', 'order' => 'ASC', 'type' => 'CHAR' ) ), 'AND' );
		return is_array( $items ) ? array_map( array( $this, 'normalise_item' ), $items ) : array();
	}

	public function cancellation_recipients( int $event_id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$type->db->set_format_flag( ARRAY_A );
		$args = array( array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ) );
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$items = $type->db->query( $args, 0, 0, array(), 'AND' );
		$items = is_array( $items ) ? array_map( array( $this, 'normalise_item' ), $items ) : array();
		return array_values( array_filter( $items, static function ( $item ) {
			return in_array( sanitize_key( $item['registration_status'] ?? 'pending' ), array( 'pending', 'approved', 'waitlist' ), true );
		} ) );
	}

	public function interest_recipients( int $event_id ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) return $type;
		$type->db->set_format_flag( ARRAY_A );
		$args = array(
			array( 'field' => 'event_id', 'operator' => '=', 'value' => $event_id, 'type' => 'INTEGER' ),
			array( 'field' => 'registration_status', 'operator' => '=', 'value' => 'interest', 'type' => 'CHAR' ),
		);
		$args = method_exists( $type, 'prepare_query_args' ) ? $type->prepare_query_args( $args ) : $args;
		$items = $type->db->query( $args, 0, 0, array(), 'AND' );
		return is_array( $items ) ? array_map( array( $this, 'normalise_item' ), $items ) : array();
	}

	public function update_attendance( int $id, string $status ) {
		if ( ! in_array( $status, array( 'attended', 'no-show', 'partial', 'unknown' ), true ) ) {
			return new \WP_Error( 'hherm_invalid_attendance', 'The attendance status is invalid.' );
		}
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine item handler is incompatible.' );
		}
		$result = $handler->update_item( array( '_ID' => $id, 'attendance_status' => $status ) );
		return $result ? $this->get( $id ) : new \WP_Error( 'hherm_attendance_update_failed', 'JetEngine could not update the attendance status.' );
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

	public function update_self_checkin( int $id, string $feedback, int $party_size = 0, string $status = 'attended', int $score = 0 ) {
		$type = $this->type();
		if ( is_wp_error( $type ) ) { return $type; }
		$handler = method_exists( $type, 'get_item_handler' ) ? $type->get_item_handler() : null;
		if ( ! $handler || ! method_exists( $handler, 'update_item' ) ) {
			return new \WP_Error( 'hherm_jetengine_incompatible', 'The installed JetEngine item handler is incompatible.' );
		}
		$field = sanitize_key( $this->settings->get( 'feedback_field', 'event_feedback' ) );
		$status = in_array( $status, array( 'attended', 'partial' ), true ) ? $status : 'attended';
		$data = array( '_ID' => $id, 'attendance_status' => $status, 'checked_in_at' => current_time( 'mysql' ) );
		if ( $party_size > 0 ) { $data['checked_in_party_size'] = $party_size; }
		if ( $score >= 1 && $score <= 5 ) { $data['feedback_score'] = $score; }
		if ( $field && '' !== $feedback ) {
			$data[ $field ] = $feedback;
		}
		$result = $handler->update_item( $data );
		return $result ? $this->get( $id ) : new \WP_Error( 'hherm_checkin_update_failed', 'JetEngine could not save the check-in.' );
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
		if ( 'expression_of_interest' === $status || 'expression-of-interest' === $status ) $item['registration_status'] = 'interest';
		return $item;
	}
}
