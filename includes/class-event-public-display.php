<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end presentation helpers for the existing JetEngine event templates.
 *
 * The live event layouts are owned by JetEngine/Elementor rather than this
 * plugin. These shortcodes provide one authoritative rendering path that those
 * templates can use without duplicating the event option rules.
 */
final class Event_Public_Display {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_shortcode( 'hherm_event_date', array( $this, 'date_shortcode' ) );
		add_shortcode( 'hherm_event_registration_cta', array( $this, 'registration_cta_shortcode' ) );
		add_shortcode( 'hherm_event_remaining_spots', array( $this, 'remaining_spots_shortcode' ) );
		add_shortcode( 'hherm_event_what_to_expect', array( $this, 'what_to_expect_shortcode' ) );
		add_shortcode( 'hherm_event_fee', array( $this, 'fee_shortcode' ) );
		add_filter( 'elementor/widget/render_content', array( $this, 'replace_external_fee_dynamic_fields' ), 10, 3 );
		add_filter( 'elementor/widget/render_content', array( $this, 'append_calendar_entry_details' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	public static function normalise_registration_type( $value ): string {
		$value = sanitize_key( (string) $value );
		$aliases = array(
			'website'  => 'website_registration',
			'internal' => 'website_registration',
			'external' => 'external_registration',
			'none'     => 'no_registration',
		);
		$value = $aliases[ $value ] ?? $value;
		return in_array( $value, array( 'website_registration', 'external_registration', 'no_registration' ), true ) ? $value : 'website_registration';
	}

	public static function supports_registration_display_switches( $value ): bool {
		return 'website_registration' === self::normalise_registration_type( $value );
	}

	public static function website_registration_available( $registration_type, $date_status ): bool {
		return self::supports_registration_display_switches( $registration_type ) && 'confirmed' === self::normalise_date_status( $date_status );
	}

	public static function interest_contact_available( $registration_type, $date_status ): bool {
		return self::supports_registration_display_switches( $registration_type ) && 'tbc' === self::normalise_date_status( $date_status );
	}

	public static function normalise_date_status( $value, $legacy_value = '' ): string {
		$value = sanitize_key( (string) $value );
		if ( in_array( $value, array( 'confirmed', 'tbc' ), true ) ) return $value;
		$legacy_value = sanitize_key( (string) $legacy_value );
		return 'tba' === $legacy_value ? 'tbc' : 'confirmed';
	}

	public static function normalise_flag( $value, bool $default = true ): bool {
		if ( '' === (string) $value ) return $default;
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	public function registration_type( int $event_id ): string {
		return self::normalise_registration_type( get_post_meta( $event_id, 'registration_type', true ) );
	}

	public function date_status( int $event_id ): string {
		return self::normalise_date_status(
			get_post_meta( $event_id, 'event_date_status', true ),
			get_post_meta( $event_id, 'event_schedule_status', true )
		);
	}

	public function show_register_button( int $event_id ): bool {
		return ! $this->is_cancelled( $event_id )
			&& self::website_registration_available( $this->registration_type( $event_id ), $this->date_status( $event_id ) )
			&& self::normalise_flag( get_post_meta( $event_id, 'registration_enabled', true ), true )
			&& 'open' === $this->registration_phase( $event_id );
	}

	public function show_remaining_spots( int $event_id ): bool {
		return ! $this->is_cancelled( $event_id ) && self::website_registration_available( $this->registration_type( $event_id ), $this->date_status( $event_id ) ) && self::normalise_flag( get_post_meta( $event_id, 'show_remaining_spots', true ), true ) && 'open' === $this->registration_phase( $event_id );
	}

	public function show_interest_contact_button( int $event_id ): bool {
		if ( $this->is_cancelled( $event_id ) ) return false;
		$type = $this->registration_type( $event_id );
		if ( self::interest_contact_available( $type, $this->date_status( $event_id ) ) ) {
			return self::normalise_flag( get_post_meta( $event_id, 'show_interest_contact_button', true ), false );
		}
		return self::supports_registration_display_switches( $type )
			&& self::normalise_flag( get_post_meta( $event_id, 'registration_enabled', true ), true )
			&& 'upcoming' === $this->registration_phase( $event_id );
	}

	public function registration_phase( int $event_id, ?int $now = null ): string {
		$now = null === $now ? time() : $now;
		$opens = Event_Datetime::timestamp( get_post_meta( $event_id, 'registration_open', true ) );
		$closes = Event_Datetime::timestamp( get_post_meta( $event_id, 'registration_close', true ) );
		if ( $opens && $now < $opens ) return 'upcoming';
		if ( $closes && $now >= $closes ) return 'closed';
		return 'open';
	}

	public function show_what_to_expect( int $event_id ): bool {
		return self::normalise_flag( get_post_meta( $event_id, 'show_what_to_expect', true ), true );
	}

	public function enqueue_styles(): void {
		wp_enqueue_style( 'hherm-event-public', HHERM_URL . 'assets/event-public.css', array(), HHERM_VERSION );
	}

	public function date_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'event_id' => 0, 'format' => '' ), (array) $attributes, 'hherm_event_date' );
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id ) return '';
		if ( 'tbc' === $this->date_status( $event_id ) ) return '<span class="hherm-event-date hherm-event-date--tbc">' . esc_html__( 'Date TBC', 'heart-hub-event-registration-manager' ) . '</span>';

		$start_key = sanitize_key( (string) $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$start = (string) get_post_meta( $event_id, $start_key, true );
		if ( '' === $start ) return '';
		$format = sanitize_text_field( (string) $attributes['format'] ) ?: Event_Datetime::DATE_FORMAT;
		$timestamp = $this->datetime_timestamp( $start );
		$label = $timestamp ? wp_date( $format, $timestamp, wp_timezone() ) : $start;
		return '<time class="hherm-event-date"' . ( $timestamp ? ' datetime="' . esc_attr( wp_date( 'c', $timestamp, wp_timezone() ) ) . '"' : '' ) . '>' . esc_html( $label ) . '</time>';
	}

	public function registration_cta_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'event_id'      => 0,
				'website_url'   => '#event-registration',
				'website_label' => 'Register Now',
				'external_label'=> 'Register Now',
				'contact_label' => 'Contact Us',
				'interest_label'=> 'Express Interest',
				'class'         => '',
			),
			(array) $attributes,
			'hherm_event_registration_cta'
		);
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id || $this->is_cancelled( $event_id ) ) return '';
		$type = $this->registration_type( $event_id );
		$url = '';
		$label = '';
		$external = false;
		$cta_type = $type;

		if ( 'no_registration' === $type ) {
			return '';
		} elseif ( 'external_registration' === $type ) {
			$url = esc_url_raw( (string) get_post_meta( $event_id, 'external_registration_url', true ) );
			$label = sanitize_text_field( (string) $attributes['external_label'] );
			$external = true;
		} elseif ( 'tbc' === $this->date_status( $event_id ) || 'upcoming' === $this->registration_phase( $event_id ) ) {
			if ( ! $this->show_interest_contact_button( $event_id ) ) return '';
			$url = (string) apply_filters( 'hherm/expression_of_interest_url', get_permalink( $event_id ) . '#event-expression-interest', $event_id );
			$label = sanitize_text_field( (string) $attributes['interest_label'] );
			$cta_type = 'interest';
		} else {
			if ( ! $this->show_register_button( $event_id ) ) return '';
			$url = (string) apply_filters( 'hherm/website_registration_url', $attributes['website_url'], $event_id );
			$label = sanitize_text_field( (string) $attributes['website_label'] );
		}

		if ( ! $url || ! $label ) return '';
		$classes = trim( 'hherm-event-cta hherm-event-cta--' . str_replace( '_registration', '', $cta_type ) . ' ' . sanitize_html_class( (string) $attributes['class'] ) );
		return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '"' . ( $external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}

	public function remaining_spots_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'event_id' => 0, 'singular' => '%d spot remaining', 'plural' => '%d spots remaining' ), (array) $attributes, 'hherm_event_remaining_spots' );
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id || ! $this->show_remaining_spots( $event_id ) ) return '';
		$key = sanitize_key( (string) $this->settings->get( 'event_remaining_capacity', 'current_event_capacity' ) );
		if ( ! $key ) return '';
		$value = get_post_meta( $event_id, $key, true );
		if ( '' === (string) $value ) $value = get_post_meta( $event_id, 'event_capacity', true );
		if ( '' === (string) $value || ! is_numeric( $value ) ) return '';
		$remaining = max( 0, (int) $value );
		$template = 1 === $remaining ? (string) $attributes['singular'] : (string) $attributes['plural'];
		return '<span class="hherm-event-remaining-spots">' . esc_html( sprintf( $template, $remaining ) ) . '</span>';
	}

	/**
	 * Render fundraiser costs for an event template.
	 *
	 * When external fee details are enabled, internal registration and
	 * sponsorship prices are intentionally not exposed.
	 */
	public function fee_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'event_id'           => 0,
				'registration_label' => 'Registration fee',
				'sponsorship_label'  => 'Sponsorship cost',
				'external_label'     => 'External fee',
				'external_link_label'=> 'View fee details',
				'class'              => '',
			),
			(array) $attributes,
			'hherm_event_fee'
		);
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id ) return '';

		$classes = trim( 'hherm-event-fee ' . sanitize_html_class( (string) $attributes['class'] ) );
		if ( self::normalise_flag( get_post_meta( $event_id, 'external_fee_enabled', true ), false ) ) {
			$amount = trim( (string) get_post_meta( $event_id, 'external_fee_amount', true ) );
			$url = esc_url_raw( (string) get_post_meta( $event_id, 'external_fee_url', true ), array( 'http', 'https' ) );
			if ( '' === $amount && ! $url ) return '';
			$value = is_numeric( $amount ) ? '$' . number_format_i18n( (float) $amount, 2 ) : '';
			$html = '<div class="' . esc_attr( $classes . ' hherm-event-fee--external' ) . '"><strong>' . esc_html( sanitize_text_field( (string) $attributes['external_label'] ) ) . '</strong>';
			if ( $value ) $html .= '<span>' . esc_html( $value ) . '</span>';
			if ( $url ) $html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sanitize_text_field( (string) $attributes['external_link_label'] ) ) . '</a>';
			return $html . '</div>';
		}

		$fees = array(
			sanitize_text_field( (string) $attributes['registration_label'] ) => sanitize_text_field( (string) get_post_meta( $event_id, 'registration_fee', true ) ),
			sanitize_text_field( (string) $attributes['sponsorship_label'] )  => sanitize_text_field( (string) get_post_meta( $event_id, 'sponsorship_cost', true ) ),
		);
		$html = '';
		foreach ( $fees as $label => $value ) {
			if ( '' === $value ) continue;
			$html .= '<div><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $value ) . '</span></div>';
		}
		return $html ? '<div class="' . esc_attr( $classes ) . '">' . $html . '</div>' : '';
	}

	/**
	 * Preserve existing Elementor Dynamic Field placement when an event uses
	 * external fees. JetEngine stores a custom meta-field selection in
	 * dynamic_field_post_meta_custom, so no widget ID or layout is assumed.
	 *
	 * @param string $content Rendered Elementor widget content.
	 * @param object $widget Elementor widget instance.
	 * @return string
	 */
	public function replace_external_fee_dynamic_fields( string $content, $widget ): string {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'jet-listing-dynamic-field' !== $widget->get_name() || ! method_exists( $widget, 'get_settings_for_display' ) ) return $content;
		$settings = $widget->get_settings_for_display();
		if ( ! is_array( $settings ) ) return $content;
		$field = sanitize_key( (string) ( $settings['dynamic_field_post_meta_custom'] ?? '' ) );
		if ( ! in_array( $field, array( 'registration_fee', 'sponsorship_cost' ), true ) ) return $content;

		$event_id = $this->dynamic_field_event_id( $settings );
		if ( ! $event_id || ! self::normalise_flag( get_post_meta( $event_id, 'external_fee_enabled', true ), false ) ) return $content;
		return 'registration_fee' === $field ? $this->fee_shortcode( array( 'event_id' => $event_id ) ) : '';
	}

	/**
	 * Show the details of a Calendar Schedule entry beneath its title in a
	 * JetEngine listing card. Regular Events and other Elementor headings are
	 * left untouched.
	 *
	 * @param string $content Rendered Elementor widget content.
	 * @param object $widget Elementor widget instance.
	 */
	public function append_calendar_entry_details( string $content, $widget ): string {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || ! in_array( $widget->get_name(), array( 'heading', 'jet-listing-dynamic-field' ), true ) || ! method_exists( $widget, 'get_settings_for_display' ) ) return $content;
		$settings = $widget->get_settings_for_display();
		if ( ! is_array( $settings ) ) return $content;

		$entry_id = $this->dynamic_field_event_id( $settings );
		if ( ! $entry_id || ! metadata_exists( 'post', $entry_id, Calendar_Schedule::TYPE_META ) ) return $content;

		$heading = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$heading = preg_replace( '/\s+/u', ' ', trim( str_replace( "\xc2\xa0", ' ', $heading ) ) );
		$title = preg_replace( '/\s+/u', ' ', trim( str_replace( "\xc2\xa0", ' ', get_the_title( $entry_id ) ) ) );
		if ( ! $heading || ! $title || $heading !== $title || false !== strpos( $content, 'hherm-calendar-entry-details' ) ) return $content;

		$start_key = sanitize_key( (string) $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$end_key = sanitize_key( (string) $this->settings->get( 'event_end', 'end_date__time' ) ) ?: 'end_date__time';
		$venue_key = sanitize_key( (string) $this->settings->get( 'event_venue', 'venue' ) ) ?: 'venue';
		$info_key = sanitize_key( (string) $this->settings->get( 'event_info', '_description' ) ) ?: '_description';
		$start = (string) get_post_meta( $entry_id, $start_key, true );
		$end = (string) get_post_meta( $entry_id, $end_key, true );
		$venue = sanitize_text_field( (string) get_post_meta( $entry_id, $venue_key, true ) );
		$description = (string) get_post_meta( $entry_id, $info_key, true );
		if ( '' === $description ) $description = (string) get_post_field( 'post_content', $entry_id );
		$description = sanitize_textarea_field( $description );

		$start_timestamp = Event_Datetime::timestamp( $start );
		$end_timestamp = Event_Datetime::timestamp( $end );
		if ( ! $start_timestamp ) return $content;

		$start_date = wp_date( Event_Datetime::DATE_FORMAT, $start_timestamp, wp_timezone() );
		$start_time = wp_date( Event_Datetime::TIME_FORMAT, $start_timestamp, wp_timezone() );
		$when = $start_date . ', ' . $start_time;
		if ( $end_timestamp ) {
			if ( wp_date( 'Y-m-d', $start_timestamp, wp_timezone() ) === wp_date( 'Y-m-d', $end_timestamp, wp_timezone() ) ) {
				$when .= '–' . wp_date( Event_Datetime::TIME_FORMAT, $end_timestamp, wp_timezone() );
			} else {
				$when .= ' – ' . wp_date( Event_Datetime::DATETIME_FORMAT, $end_timestamp, wp_timezone() );
			}
		}

		$details = '<div class="hherm-calendar-entry-details"><time class="hherm-calendar-entry-details__time" datetime="' . esc_attr( wp_date( 'c', $start_timestamp, wp_timezone() ) ) . '">' . esc_html( $when ) . '</time>';
		if ( $venue ) $details .= '<span class="hherm-calendar-entry-details__location">' . esc_html( $venue ) . '</span>';
		if ( $description ) $details .= '<span class="hherm-calendar-entry-details__description">' . nl2br( esc_html( $description ) ) . '</span>';
		return $content . $details . '</div>';
	}

	public function what_to_expect_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'event_id' => 0, 'heading' => 'What to Expect' ), (array) $attributes, 'hherm_event_what_to_expect' );
		$event_id = $this->event_id( $attributes['event_id'] );
		if ( ! $event_id || ! $this->show_what_to_expect( $event_id ) ) return '';
		$items = get_post_meta( $event_id, 'what_to_expect', true );
		if ( is_string( $items ) && '' !== $items ) $items = json_decode( $items, true );
		if ( ! is_array( $items ) || ! $items ) return '';
		$html = '<section class="hherm-event-expectations"><h2>' . esc_html( sanitize_text_field( (string) $attributes['heading'] ) ) . '</h2><ol>';
		$count = 0;
		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			if ( ! is_array( $item ) ) continue;
			$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$content = sanitize_textarea_field( (string) ( $item['content'] ?? '' ) );
			if ( '' === $title && '' === $content ) continue;
			$html .= '<li><strong>' . esc_html( $title ) . '</strong><span>' . nl2br( esc_html( $content ) ) . '</span></li>';
			++$count;
		}
		$html .= '</ol></section>';
		return $count ? $html : '';
	}

	private function event_id( $value ): int {
		$event_id = absint( $value );
		return $event_id ?: absint( get_the_ID() );
	}

	private function is_cancelled( int $event_id ): bool {
		return self::normalise_flag( get_post_meta( $event_id, 'event_cancelled', true ), false );
	}

	/**
	 * Resolve the current listing item with JetEngine's own object context,
	 * then fall back to the single-event post.
	 */
	private function dynamic_field_event_id( array $settings ): int {
		if ( function_exists( 'jet_engine' ) ) {
			try {
				$engine = jet_engine();
				$data = isset( $engine->listings->data ) ? $engine->listings->data : null;
				if ( $data && method_exists( $data, 'get_object_by_context' ) ) {
					$context = sanitize_key( (string) ( $settings['object_context'] ?? 'default_object' ) ) ?: 'default_object';
					$object = $data->get_object_by_context( $context );
					if ( is_object( $object ) && isset( $object->ID ) ) return absint( $object->ID );
				}
			} catch ( \Throwable $exception ) {
				// JetEngine is optional; the regular single-event context remains valid.
			}
		}
		return absint( get_the_ID() );
	}

	private function contact_url(): string {
		$page = get_page_by_path( 'contact-us' );
		$url = $page ? get_permalink( $page ) : home_url( '/contact-us/' );
		return esc_url_raw( (string) apply_filters( 'hherm/contact_url', $url ) );
	}

	private function datetime_timestamp( string $value ): int {
		if ( is_numeric( $value ) ) return (int) $value;
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}
}
