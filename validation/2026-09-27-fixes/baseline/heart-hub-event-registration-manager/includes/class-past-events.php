<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Past_Events {
	public const CRON_HOOK = 'hherm_categorize_past_events';
	private const SCHEDULE = 'hherm_every_five_minutes';
	private const TAXONOMY = 'event-category';
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'categorize' ) );
	}

	public static function schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every five minutes' );
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function categorize(): void {
		$post_type = sanitize_key( $this->settings->get( 'events_cpt', 'events' ) );
		if ( ! post_type_exists( $post_type ) || ! taxonomy_exists( self::TAXONOMY ) ) return;
		$term = get_term( 83, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) || 'past-events' !== $term->slug ) {
			$term = get_term_by( 'slug', 'past-events', self::TAXONOMY );
		}
		if ( ! $term || is_wp_error( $term ) ) return;

		$end_key = sanitize_key( $this->settings->get( 'event_end', 'end_date__time' ) ) ?: 'end_date__time';
		$now = time();
		$page = 1;
		do {
			// Page over all events so adding a category cannot shift subsequent pages.
			$events = get_posts( array(
				'post_type' => $post_type,
				'post_status' => array( 'publish', 'private' ),
				'posts_per_page' => 100,
				'paged' => $page++,
				'orderby' => 'ID',
				'order' => 'ASC',
				'fields' => 'ids',
			) );
			foreach ( $events as $event_id ) {
				$end = $this->end_timestamp( get_post_meta( $event_id, $end_key, true ) );
				if ( ! $end || $end > $now || has_term( (int) $term->term_id, self::TAXONOMY, $event_id ) ) continue;
				// Append; failed assignments remain eligible on the next run.
				wp_set_object_terms( $event_id, array( (int) $term->term_id ), self::TAXONOMY, true );
			}
		} while ( count( $events ) === 100 );
	}

	private function end_timestamp( $raw ): int {
		if ( ! is_scalar( $raw ) ) return 0;
		$raw = trim( (string) $raw );
		if ( ctype_digit( $raw ) ) return (int) $raw;
		foreach ( array( 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $raw, wp_timezone() );
			if ( $date && $date->format( $format ) === $raw ) return $date->getTimestamp();
		}
		return 0;
	}
}
