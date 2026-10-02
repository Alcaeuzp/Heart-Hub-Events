<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Event_Types {
	public const WORKSHOP_TERM_SLUG = 'workshop';
	public const FUNDRAISING_TERM_SLUG = 'fundraising-event';
	private const PROTECTED_CATEGORY_SLUGS = array(
		'workshop',
		'fundraising-event',
		'community-outreach',
		'community-outreach-event',
		'community-outreach-events',
		'mindfulness-workshop',
		'mindfulness-workshop-event',
		'mindfulness-workshop-events',
		'mindfulness-workshops',
		'calendar-schedule',
	);
	private const PROTECTED_CATEGORY_NAMES = array(
		'workshop',
		'fundraising event',
		'fundraising',
		'community outreach',
		'community outreach event',
		'community outreach events',
		'mindfulness workshop',
		'mindfulness workshop event',
		'mindfulness workshop events',
		'mindfulness workshops',
		'calendar schedule',
	);

	public static function terms(): array {
		$ids = array();
		$definitions = array(
			'workshop'    => array( 'slug' => self::WORKSHOP_TERM_SLUG, 'names' => array( 'Workshop' ) ),
			'fundraising' => array( 'slug' => self::FUNDRAISING_TERM_SLUG, 'names' => array( 'Fundraising Event', 'Fundraising' ) ),
		);
		foreach ( $definitions as $key => $definition ) {
			$term = get_term_by( 'slug', $definition['slug'], 'event-category' );
			if ( ! $term ) {
				foreach ( $definition['names'] as $name ) {
					$term = get_term_by( 'name', $name, 'event-category' );
					if ( $term ) break;
				}
			}
			$ids[ $key ] = $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
		}
		return $ids;
	}

	/**
	 * Categories maintained by a dedicated workflow rather than the event form.
	 *
	 * They remain assigned when an event is edited, but are deliberately omitted
	 * from the standard category picker so an administrator cannot add them to a
	 * current event by mistake.
	 */
	public static function lifecycle_terms(): array {
		$ids = array();
		foreach ( array( 'past-events' => 'Past Events', 'past-fundraisers' => 'Past Fundraisers', 'calendar-schedule' => 'Calendar Schedule' ) as $slug => $name ) {
			$term = get_term_by( 'slug', $slug, 'event-category' );
			if ( ! $term ) $term = get_term_by( 'name', $name, 'event-category' );
			if ( $term && ! is_wp_error( $term ) ) $ids[] = (int) $term->term_id;
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Core event categories are referenced by site templates and workflows.
	 * Match both the expected slugs and names so an older site configuration is
	 * still protected when its slug differs from the current convention.
	 */
	public static function is_protected_category( $term ): bool {
		if ( is_array( $term ) ) {
			$slug = $term['slug'] ?? '';
			$name = $term['name'] ?? '';
		} elseif ( is_object( $term ) ) {
			$slug = $term->slug ?? '';
			$name = $term->name ?? '';
		} else {
			return false;
		}
		$slug = strtolower( trim( (string) $slug ) );
		$name = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
		return in_array( $slug, self::PROTECTED_CATEGORY_SLUGS, true ) || in_array( $name, self::PROTECTED_CATEGORY_NAMES, true );
	}

	public static function selection( array $assigned, array $types, bool $new ): string {
		if ( $new ) return 'workshop';
		$matched = array_keys( array_filter( $types, static function ( $id ) use ( $assigned ) { return $id && in_array( $id, $assigned, true ); } ) );
		return 1 === count( $matched ) ? $matched[0] : 'keep';
	}

	public static function categories( string $selection, array $submitted, array $existing, array $types, array $preserved = array() ) {
		if ( ! in_array( $selection, array( 'keep', 'workshop', 'fundraising' ), true ) ) return new \WP_Error( 'invalid_event_type' );
		$type_ids = array_filter( array_values( $types ) );
		$preserved = array_values( array_unique( array_filter( array_map( 'intval', $preserved ) ) ) );
		$protected_existing = array_intersect( $existing, $preserved );
		$other = array_diff( $submitted, $type_ids, $preserved );
		if ( 'keep' === $selection ) return array_values( array_unique( array_merge( $other, array_intersect( $existing, $type_ids ), $protected_existing ) ) );
		if ( empty( $types[ $selection ] ) ) return new \WP_Error( 'event_type_missing' );
		return array_values( array_unique( array_merge( $other, array( $types[ $selection ] ), $protected_existing ) ) );
	}
}
