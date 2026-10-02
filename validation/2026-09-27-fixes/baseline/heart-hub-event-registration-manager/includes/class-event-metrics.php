<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Public impact metrics calculated from event posts and attendance records.
 */
final class Event_Metrics {
	private const TAXONOMY = 'event-category';
	private const AMOUNT_KEY = 'amount_raised';

	private $settings;
	private $repository;
	private $event_ids = array();
	private $attendance = array();

	public function __construct( Settings $settings, CCT_Repository $repository ) {
		$this->settings = $settings;
		$this->repository = $repository;
	}

	public function register(): void {
		add_shortcode( 'hherm_total_funds_raised', array( $this, 'total_funds_shortcode' ) );
		add_shortcode( 'hherm_total_attendees', array( $this, 'total_attendees_shortcode' ) );
		add_shortcode( 'hherm_events_run', array( $this, 'events_run_shortcode' ) );
		add_shortcode( 'hherm_event_funds_raised', array( $this, 'event_funds_shortcode' ) );
		add_shortcode( 'hherm_event_attendees', array( $this, 'event_attendees_shortcode' ) );
	}

	public function total_funds_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'months' => 12, 'decimals' => 0 ), (array) $attributes, 'hherm_total_funds_raised' );
		return $this->money_markup( $this->total_funds( $this->months( $attributes['months'] ) ), $attributes, 'hherm-total-funds-raised' );
	}

	public function total_attendees_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'months' => 12 ), (array) $attributes, 'hherm_total_attendees' );
		return $this->number_markup( $this->total_attendees( $this->months( $attributes['months'] ) ), 'hherm-total-attendees' );
	}

	public function events_run_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'months' => 12 ), (array) $attributes, 'hherm_events_run' );
		return $this->number_markup( count( $this->event_ids( $this->months( $attributes['months'] ) ) ), 'hherm-events-run' );
	}

	public function event_funds_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'event_id' => 0, 'decimals' => 0 ), (array) $attributes, 'hherm_event_funds_raised' );
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id ) return '';
		return $this->money_markup( $this->event_funds( $event_id ), $attributes, 'hherm-event-funds-raised' );
	}

	public function event_attendees_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'event_id' => 0 ), (array) $attributes, 'hherm_event_attendees' );
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id ) return '';
		return $this->number_markup( $this->event_attendees( $event_id ), 'hherm-event-attendees' );
	}

	public function total_funds( int $months = 12 ): float {
		$total = 0.0;
		$fundraising_term = Event_Types::terms()['fundraising'] ?? 0;
		if ( ! $fundraising_term ) return $total;
		foreach ( $this->event_ids( $months ) as $event_id ) {
			if ( has_term( (int) $fundraising_term, self::TAXONOMY, $event_id ) ) $total += $this->event_funds( $event_id );
		}
		return $total;
	}

	public function total_attendees( int $months = 12 ): int {
		$total = 0;
		foreach ( $this->event_ids( $months ) as $event_id ) $total += $this->event_attendees( $event_id );
		return $total;
	}

	public function event_funds( int $event_id ): float {
		$value = trim( str_replace( ',', '', (string) get_post_meta( $event_id, self::AMOUNT_KEY, true ) ) );
		return is_numeric( $value ) ? max( 0, (float) $value ) : 0.0;
	}

	public function event_attendees( int $event_id ): int {
		if ( isset( $this->attendance[ $event_id ] ) ) return $this->attendance[ $event_id ];
		$items = $this->repository->attendance_list( $event_id );
		if ( is_wp_error( $items ) || ! is_array( $items ) ) return $this->attendance[ $event_id ] = 0;
		$party_key = sanitize_key( (string) $this->settings->get( 'attendee_count_field', 'number_of_attendees' ) ) ?: 'number_of_attendees';
		return $this->attendance[ $event_id ] = self::attendance_people( $items, $party_key );
	}

	public static function attendance_people( array $items, string $party_key = 'number_of_attendees' ): int {
		$total = 0;
		foreach ( $items as $item ) {
			$status = sanitize_key( (string) ( $item['attendance_status'] ?? 'unknown' ) );
			$party = max( 1, absint( $item[ $party_key ] ?? 1 ) );
			$checked_in = absint( $item['checked_in_party_size'] ?? 0 );
			if ( 'attended' === $status ) {
				$total += $checked_in ? min( $party, $checked_in ) : $party;
			} elseif ( 'partial' === $status && $checked_in ) {
				$total += min( $party, $checked_in );
			}
		}
		return $total;
	}

	public function event_ids( int $months = 12 ): array {
		$months = $this->months( $months );
		if ( isset( $this->event_ids[ $months ] ) ) return $this->event_ids[ $months ];
		$post_type = sanitize_key( (string) $this->settings->get( 'events_cpt', 'events' ) ) ?: 'events';
		$posts = get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => array( 'publish', 'private' ),
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		) );
		$now = (int) apply_filters( 'hherm/metrics_now', current_datetime()->getTimestamp() );
		$from = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->modify( '-' . $months . ' months' )->getTimestamp();
		$start_key = sanitize_key( (string) $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$ids = array();
		foreach ( $posts as $post ) {
			$event_id = is_object( $post ) ? absint( $post->ID ?? 0 ) : absint( $post );
			if ( ! $event_id || metadata_exists( 'post', $event_id, Calendar_Schedule::TYPE_META ) || $this->is_cancelled( $event_id ) ) continue;
			$timestamp = $this->datetime_timestamp( (string) get_post_meta( $event_id, $start_key, true ) );
			if ( $timestamp >= $from && $timestamp <= $now ) $ids[] = $event_id;
		}
		return $this->event_ids[ $months ] = $ids;
	}

	private function event_id( $value ): int {
		$event_id = absint( $value ) ?: absint( get_the_ID() );
		if ( ! $event_id ) return 0;
		$post = get_post( $event_id );
		$post_type = sanitize_key( (string) $this->settings->get( 'events_cpt', 'events' ) ) ?: 'events';
		return $post && 'trash' !== $post->post_status && $post_type === $post->post_type ? $event_id : 0;
	}

	private function is_cancelled( int $event_id ): bool {
		return in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private function datetime_timestamp( string $value ): int {
		if ( is_numeric( $value ) ) return max( 0, (int) $value );
		$value = trim( $value );
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			if ( $date && $date->format( $format ) === $value ) return $date->getTimestamp();
		}
		return 0;
	}

	private function months( $value ): int {
		return max( 1, min( 120, absint( $value ) ?: 12 ) );
	}

	private function money_markup( float $value, array $attributes, string $class ): string {
		$decimals = max( 0, min( 2, absint( $attributes['decimals'] ?? 0 ) ) );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( number_format_i18n( $value, $decimals ) ) . '</span>';
	}

	private function number_markup( int $value, string $class ): string {
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( number_format_i18n( $value ) ) . '</span>';
	}
}
