<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	function wp_timezone() { return new DateTimeZone( 'Australia/Perth' ); }
	function wp_date( $format, $timestamp, $timezone = null ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ); }
	function absint( $value ) { return abs( (int) $value ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_url( $value ) { return esc_html( $value ); }
	function esc_url_raw( $value, $protocols = null ) { return preg_match( '#^https?://#', (string) $value ) ? (string) $value : ''; }
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }
	function apply_filters( $hook, $value ) { return $value; }
	function get_permalink( $id ) { return 'https://example.test/events/' . $id . '/'; }
	function get_the_title( $id ) { return $GLOBALS['posts'][ $id ]['title'] ?? ''; }
	function get_post_field( $field, $id ) { return ''; }
	function get_post_status( $id ) { return $GLOBALS['posts'][ $id ]['status'] ?? 'publish'; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['posts'][ $id ]['meta'][ $key ] ?? ''; }
	function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['posts'][ $id ]['meta'][ $key ] ); }
	function matches_meta_query( array $meta, array $query ): bool {
		if ( isset( $query['key'] ) ) {
			$key = $query['key'];
			if ( 'EXISTS' === ( $query['compare'] ?? '=' ) ) return array_key_exists( $key, $meta );
			if ( ! array_key_exists( $key, $meta ) ) return false;
			// WordPress casts NUMERIC clauses as SIGNED; CHAR clauses compare their stored strings.
			$comparison = 'NUMERIC' === ( $query['type'] ?? 'CHAR' )
				? (int) $meta[ $key ] <=> (int) $query['value']
				: strcmp( (string) $meta[ $key ], (string) $query['value'] );
			switch ( $query['compare'] ?? '=' ) {
				case '>=': return $comparison >= 0;
				case '<': return $comparison < 0;
				case '=': return 0 === $comparison;
			}
			throw new Exception( 'Unsupported fixture meta comparison' );
		}
		$results = array();
		foreach ( $query as $key => $clause ) if ( 'relation' !== $key ) $results[] = matches_meta_query( $meta, $clause );
		return 'OR' === ( $query['relation'] ?? 'AND' ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}
	function get_posts( $args ) {
		$ids = array();
		foreach ( $GLOBALS['posts'] as $id => $post ) {
			$meta = $post['meta'];
			if ( ! in_array( $post['status'], (array) $args['post_status'], true ) ) continue;
			if ( isset( $args['meta_key'] ) && ! array_key_exists( $args['meta_key'], $meta ) ) continue;
			if ( ! matches_meta_query( $meta, $args['meta_query'] ) ) continue;
			$ids[] = $id;
		}
		return $ids;
	}
	function sanitize_hex_color( $value ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $value ) ? $value : null; }
	function get_option( $key, $default = false ) { return $default; }
	function get_term_by( $field, $value, $taxonomy ) { return 'fundraising-event' === $value ? (object) array( 'term_id' => 50 ) : false; }
	function is_wp_error( $value ) { return false; }
	function has_term( $terms, $taxonomy, $id ) { return (bool) array_intersect( (array) $terms, $GLOBALS['posts'][ $id ]['terms'] ?? array() ); }
	function check( $value, $message ) { if ( ! $value ) throw new Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	class Settings { public $values = array(); public function get( $key, $default = '' ) { return $this->values[ $key ] ?? $default; } }
	class Calendar_Schedule {
		public const TYPE_META = 'hherm_calendar_entry_type';
		public const MASTER_META = 'hherm_calendar_series_master';
		public const RULE_META = 'hherm_calendar_recurrence_rule';
		public const SCHEDULE_STATUS_META = 'hherm_calendar_schedule_status';
	}
	require dirname( __DIR__ ) . '/includes/class-event-datetime.php';
	require dirname( __DIR__ ) . '/includes/class-event-types.php';
	require dirname( __DIR__ ) . '/includes/class-event-public-display.php';
	require dirname( __DIR__ ) . '/includes/class-calendar-settings.php';
	require dirname( __DIR__ ) . '/includes/class-calendar-display.php';

	$tz = wp_timezone();
	// A month two months ahead keeps every event in the future regardless of when the test runs.
	$month = ( new \DateTimeImmutable( 'first day of this month 00:00', $tz ) )->modify( '+2 months' );
	$at = static function ( int $day, string $time ) use ( $month ): int { return $month->modify( '+' . ( $day - 1 ) . ' days' )->modify( $time )->getTimestamp(); };
	$last = (int) $month->format( 't' );
	$event = static function ( string $title, int $start, int $end, array $meta = array() ): array {
		return array( 'title' => $title, 'status' => 'publish', 'meta' => $meta + array( 'start_date' => $start, 'end_date__time' => $end, 'venue' => 'Hall', 'registration_type' => 'website_registration', 'event_date_status' => 'confirmed', 'event_capacity' => 20 ) );
	};
	$GLOBALS['posts'] = array(
		1 => $event( 'Pottery with Sara Angelique Pottery', $at( 3, '10:30' ), $at( 3, '12:30' ) ),
		2 => $event( 'Two day retreat', $at( $last - 1, '09:00' ), $at( $last + 1, '15:00' ) ),
		3 => $event( 'Cancelled walk', $at( 10, '09:00' ), $at( 10, '10:00' ), array( 'event_cancelled' => 'true' ) ),
		4 => $event( 'Early event', $at( 5, '09:00' ), $at( 5, '10:00' ), array( 'registration_open' => $at( 4, '09:00' ) ) ),
		5 => $event( 'Full workshop', $at( 6, '09:00' ), $at( 6, '10:00' ), array( 'event_capacity' => 10, 'current_event_capacity' => 0, 'allow_waitlist' => 'true' ) ),
		6 => $event( 'Sold out talk', $at( 7, '09:00' ), $at( 7, '10:00' ), array( 'event_capacity' => 10, 'current_event_capacity' => 0 ) ),
		7 => $event( 'Closed session', $at( 8, '09:00' ), $at( 8, '10:00' ), array( 'registration_enabled' => 'false' ) ),
		8 => $event( 'Golf day', $at( 9, '09:00' ), $at( 9, '15:00' ), array( 'registration_type' => 'external_registration', 'external_registration_url' => 'https://golf.example/book' ) ),
		9 => $event( 'Open day', $at( 11, '09:00' ), $at( 11, '10:00' ), array( 'registration_type' => 'no_registration' ) ),
		10 => array( 'title' => 'Opening hours', 'status' => 'draft', 'meta' => array( 'hherm_calendar_entry_type' => 'opening_hours', 'hherm_calendar_series_master' => '1', 'hherm_calendar_schedule_status' => 'publish', 'venue' => 'Hub', 'hherm_calendar_recurrence_rule' => array( 'frequency' => 'dates', 'dates' => array( $month->modify( '+13 days' )->format( 'Y-m-d' ) ), 'slots' => array( array( 'start' => '10:00', 'end' => '15:00' ) ) ) ) ),
		11 => $event( 'Next year event', $at( 1, '09:00' ) + 100 * DAY_IN_SECONDS, $at( 1, '10:00' ) + 100 * DAY_IN_SECONDS ),
	);
	// Event 4: registration opens in the future (relative to now) so it is still in its interest phase.
	$GLOBALS['posts'][4]['meta']['registration_open'] = time() + 5 * DAY_IN_SECONDS;
	$settings = new Settings();
	$settings->values = array( 'attendee_count_field' => 'number_of_attendees', 'event_remaining_capacity' => 'current_event_capacity' );
	$display = new Calendar_Display( $settings );
	$render = new \ReflectionMethod( $display, 'render_calendar' );
	$render->setAccessible( true );
	$options = array( 'week_start' => 1, 'months_behind' => 12, 'months_ahead' => 36, 'show_popups' => 1, 'show_times' => 1, 'density' => 'comfortable' );
	$html = $render->invoke( $display, $month, $options, false, '', 'https://example.test/events/' );
	$dom = new \DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	$xpath = new \DOMXPath( $dom );
	$count = static function ( string $query ) use ( $xpath ): int { return $xpath->query( $query )->length; };
	$text = static function ( string $query ) use ( $xpath ): string { $node = $xpath->query( $query )->item( 0 ); return $node ? trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) : ''; };

	$offset = ( (int) $month->format( 'w' ) + 6 ) % 7;
	\check( (int) ceil( ( $offset + $last ) / 7 ) === $count( '//tbody/tr' ), 'grid renders only the weeks the month needs' );
	\check( 1 === $count( "//td[contains(@class,'has-events')][not(contains(@class,'is-outside-month'))]//span[contains(@class,'hherm-calendar__day-number')][.='3']" ), 'event day is marked as having events' );
	\check( false !== strpos( $html, 'Pottery with Sara Angelique Pottery' ) && 'Registrations open' === $text( "//li[contains(@class,'hherm-calendar__agenda-event')][.//*[contains(.,'Pottery')]]//span[contains(@class,'hherm-calendar__status-label')]" ), 'open website registration is labelled in the agenda' );
	\check( 1 <= $count( "//a[contains(@class,'hherm-calendar__cta')][contains(@href,'/events/1/#event-registration')][.='Register']" ), 'open registration links to the event registration form' );

	$retreat = $xpath->query( "//li[contains(@class,'is-multi-day')]" );
	$segments = array();
	foreach ( $retreat as $node ) if ( false === strpos( $node->getAttribute( 'class' ), 'agenda' ) ) $segments[] = preg_match( '/is-(start|middle|end)\b/', $node->getAttribute( 'class' ), $m ) ? $m[1] : '';
	\check( array( 'start', 'middle', 'end' ) === $segments, 'multi-day event appears on each day it runs, including the next month\'s leading day' );
	\check( false !== strpos( $html, 'From 9:00 am' ) && false !== strpos( $html, 'Continues all day' ) && false !== strpos( $html, 'Until 3:00 pm' ), 'multi-day segments describe their part of the event' );
	\check( 0 === $count( "//li[contains(@class,'hherm-calendar__agenda-day')][contains(@id,'" . $month->modify( 'first day of next month' )->format( 'Y-m-d' ) . "')]" ), 'agenda lists only days inside the month' );

	\check( 1 === $count( "//li[contains(@class,'hherm-calendar__event')][contains(@class,'is-cancelled')][not(contains(@class,'agenda'))]" ) && 'Cancelled' === $text( "//li[contains(@class,'is-cancelled')]//span[contains(@class,'hherm-calendar__event-time')]" ), 'cancelled event is marked in the grid' );
	\check( 0 === $count( "//li[contains(@class,'is-cancelled')]//a[contains(@class,'hherm-calendar__cta')]" ), 'cancelled event offers no registration button' );
	\check( false !== strpos( $html, 'Registrations open ' . wp_date( 'j F', $GLOBALS['posts'][4]['meta']['registration_open'], $tz ) ) && 1 <= $count( "//a[contains(@href,'/events/4/#event-expression-interest')][.='Express interest']" ), 'future registration shows its opening date and interest link' );
	\check( false !== strpos( $html, 'Fully booked – waitlist open' ) && 1 <= $count( "//a[contains(@href,'/events/5/#event-registration')][.='Join the waitlist']" ), 'full event with waitlist offers the waitlist' );
	\check( 1 <= $count( "//li[.//*[contains(.,'Sold out talk')]]//span[contains(@class,'is-full')]" ) && 0 === $count( "//a[contains(@href,'/events/6/')][contains(@class,'hherm-calendar__cta')]" ), 'full event without waitlist has no registration button' );
	\check( 1 <= $count( "//li[.//*[contains(.,'Closed session')]]//span[contains(@class,'is-closed')]" ), 'disabled registration is shown as closed' );
	\check( 1 <= $count( "//a[@href='https://golf.example/book'][@target='_blank'][contains(@rel,'noopener')]" ), 'external registration opens the organiser site in a new tab' );
	\check( 0 === $count( "//li[.//*[contains(.,'Open day')]]//span[contains(@class,'hherm-calendar__status-label')]" ), 'no-registration event shows no registration state' );
	\check( 1 <= $count( "//li[contains(@class,'hherm-calendar__event--opening_hours')]" ) && 0 === $count( "//li[contains(@class,'hherm-calendar__event--opening_hours')]//span[contains(@class,'hherm-calendar__status-label')]" ), 'recurring schedule entries render without registration state' );

	$links = $xpath->query( "//a[contains(@class,'hherm-calendar__day-link')]" );
	$targets_ok = $links->length > 0;
	foreach ( $links as $link ) {
		$id = substr( $link->getAttribute( 'href' ), 1 );
		if ( 1 !== $count( "//li[@id='{$id}'][contains(@class,'hherm-calendar__agenda-day')]" ) ) $targets_ok = false;
	}
	\check( $targets_ok, 'every mobile day link targets its agenda day' );
	\check( 0 === $count( "//td[contains(@class,'is-outside-month')]//a[contains(@class,'hherm-calendar__day-link')]" ) && 1 <= $count( "//td[contains(@class,'is-outside-month')]//span[contains(@class,'hherm-calendar__day-link')]" ), 'outside-month days show dots without a dead agenda link' );
	\check( 0 === $count( "//p[contains(@class,'hherm-calendar__notice')]" ), 'a month with events shows no empty notice' );

	// The following month only holds the last day of the retreat, so it is not empty.
	$html = $render->invoke( $display, $month->modify( '+1 month' ), $options, false, '', 'https://example.test/events/' );
	\check( false === strpos( $html, 'No events in' ) && false !== strpos( $html, 'Until 3:00 pm' ), 'a month holding only the end of a multi-day event is not reported empty' );
	// Empty month: points to the next event, which is further ahead.
	$next_month = ( new \DateTimeImmutable( '@' . $GLOBALS['posts'][11]['meta']['start_date'] ) )->setTimezone( $tz );
	$far = $next_month->modify( 'first day of this month 00:00' )->modify( '-1 month' );
	$html = $render->invoke( $display, $far, $options, false, '', 'https://example.test/events/' );
	\check( false !== strpos( $html, 'No events in ' . wp_date( 'F Y', $far->getTimestamp(), $tz ) . '.' ), 'empty month states that it has no events' );
	\check( false !== strpos( $html, 'Next event: Next year event' ) && false !== strpos( $html, 'hherm_calendar_month=' . $next_month->format( 'Y-m' ) ) && false === strpos( $html, 'hherm-calendar__agenda"' ), 'empty month links to the month of the next event and omits the agenda' );
	$GLOBALS['posts'][11]['meta']['event_cancelled'] = 'true';
	$html = $render->invoke( $display, $far, $options, false, '', 'https://example.test/events/' );
	\check( false === strpos( $html, 'Next event:' ), 'a cancelled event is never offered as the next event' );
	$past = ( new \DateTimeImmutable( 'first day of this month 00:00', $tz ) )->modify( '-3 months' );
	$html = $render->invoke( $display, $past, $options, false, '', 'https://example.test/events/' );
	\check( false !== strpos( $html, 'No events in' ) && false === strpos( $html, 'Next event:' ), 'past empty months do not suggest a next event' );

	// Popups disabled: grid falls back to links and labels.
	$html = $render->invoke( $display, $month, array( 'show_popups' => 0 ) + $options, false, '', 'https://example.test/events/' );
	\check( false === strpos( $html, 'data-hherm-event-trigger' ) && false !== strpos( $html, 'hherm-calendar__event-link' ), 'popup-free mode still renders event links' );
	\check( false !== strpos( $html, '>Cancelled: 9:00 am – 10:00 am Cancelled walk<' ), 'popup-free mode labels cancelled events in text' );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	$xpath = new \DOMXPath( $dom );
	\check( 1 === $xpath->query( "//li[contains(@class,'hherm-calendar__agenda-event')][contains(@class,'is-start')]//a[contains(@class,'hherm-calendar__cta')]" )->length && 0 === $xpath->query( "//li[contains(@class,'hherm-calendar__agenda-event')][contains(@class,'is-middle') or contains(@class,'is-end')]//a[contains(@class,'hherm-calendar__cta')]" )->length, 'multi-day agenda entries show the registration button on the first day only' );

	// Past events are shown as a completed, static record.
	$past_month = ( new \DateTimeImmutable( 'first day of this month 00:00', $tz ) )->modify( '-2 months' );
	$past_at = static function ( int $day, string $time ) use ( $past_month ): int { return $past_month->modify( '+' . ( $day - 1 ) . ' days' )->modify( $time )->getTimestamp(); };
	$GLOBALS['posts'] = array(
		20 => $event( 'Past quiz night', $past_at( 4, '18:00' ), $past_at( 4, '21:00' ) ) + array( 'terms' => array( 50 ) ),
		21 => $event( 'Past workshop', $past_at( 6, '10:00' ), $past_at( 6, '12:00' ) ),
		22 => $event( 'Past raffle draw', $past_at( 8, '12:00' ), $past_at( 8, '13:00' ), array( 'raffle' => 'true' ) ),
		23 => $event( 'Past cancelled', $past_at( 9, '12:00' ), $past_at( 9, '13:00' ), array( 'event_cancelled' => 'true' ) ),
		24 => $event( 'Future gala', $at( 12, '18:00' ), $at( 12, '22:00' ) ) + array( 'terms' => array( 50 ) ),
		10 => array( 'title' => 'Opening hours', 'status' => 'draft', 'meta' => array( 'hherm_calendar_entry_type' => 'opening_hours', 'hherm_calendar_series_master' => '1', 'hherm_calendar_schedule_status' => 'publish', 'venue' => 'Hub', 'hherm_calendar_recurrence_rule' => array( 'frequency' => 'dates', 'dates' => array( $past_month->modify( '+13 days' )->format( 'Y-m-d' ) ), 'slots' => array( array( 'start' => '10:00', 'end' => '15:00' ) ) ) ) ),
	);
	$display = new Calendar_Display( $settings );
	$html = $render->invoke( $display, $past_month, $options, false, '', 'https://example.test/events/' );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	$xpath = new \DOMXPath( $dom );
	$count = static function ( string $query ) use ( $xpath ): int { return $xpath->query( $query )->length; };
	$text = static function ( string $query ) use ( $xpath ): string { $node = $xpath->query( $query )->item( 0 ); return $node ? trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) : ''; };
	$completed = "//li[contains(@class,'hherm-calendar__event')][contains(@class,'is-completed')][not(contains(@class,'agenda'))]";
	\check( 4 === $count( $completed ), 'standard calendar shows past events as completed' );
	\check( 0 === $count( $completed . "//*[@data-hherm-event-trigger or @data-hherm-event-card] | " . $completed . "//a" ), 'completed events have no popup, hover card or link' );
	\check( 'Completed ' . wp_date( 'j M', $past_at( 4, '21:00' ), $tz ) . ' Past quiz night' === $text( $completed . "[.//*[contains(.,'Past quiz night')]]" ), 'completed event shows the completion date and name' );
	\check( 'Completed ' . wp_date( 'j F Y', $past_at( 6, '12:00' ), $tz ) === $text( "//li[contains(@class,'hherm-calendar__agenda-event')][contains(@class,'is-completed')][.//*[contains(.,'Past workshop')]]//span[contains(@class,'hherm-calendar__agenda-time')]" ) && 0 === $count( "//li[contains(@class,'hherm-calendar__agenda-event')][contains(@class,'is-completed')]//a" ), 'agenda lists completed events without links, venue or registration' );
	\check( 1 === $count( "//li[contains(@class,'is-cancelled')][not(contains(@class,'is-completed'))][not(contains(@class,'agenda'))]" ), 'past cancelled events stay marked as cancelled' );
	\check( 1 <= $count( "//span[contains(@class,'hherm-calendar__dot')][contains(@class,'is-completed')]" ), 'mobile dots are greyed for completed events' );

	// Fundraisers calendar: only fundraising-category and raffle events, past and future.
	$display = new Calendar_Display( $settings );
	$html = $render->invoke( $display, $past_month, $options, false, '', 'https://example.test/events/', 'fundraisers' );
	\check( false !== strpos( $html, 'Past quiz night' ) && false !== strpos( $html, 'Past raffle draw' ), 'fundraisers calendar shows past fundraisers and raffles' );
	\check( false === strpos( $html, 'Past workshop' ) && false === strpos( $html, 'Past cancelled' ) && false === strpos( $html, 'Opening hours' ), 'fundraisers calendar excludes other events and schedule entries' );
	\check( false !== strpos( $html, 'data-filter="fundraisers"' ) && false !== strpos( $html, 'Fundraisers and raffles in' ), 'fundraisers calendar carries its filter for month navigation' );
	$html = $render->invoke( $display, $month, $options, false, '', 'https://example.test/events/', 'fundraisers' );
	\check( false !== strpos( $html, 'Future gala' ) && false === strpos( $html, 'is-completed' ) && false !== strpos( $html, 'data-hherm-event-trigger' ), 'upcoming fundraisers keep their popup' );
	$empty = $month->modify( '-1 month' );
	$html = $render->invoke( $display, $empty, $options, false, '', 'https://example.test/events/', 'fundraisers' );
	\check( false !== strpos( $html, 'No fundraisers or raffles in' ) && 1 === preg_match( '/hherm-calendar__banner.*Next fundraiser.*Future gala.*\/events\/24\//s', $html ), 'empty fundraiser month shows the next-fundraiser banner linking to the event' );
	$html = $render->invoke( $display, $past_month, $options, true, 'Fundraisers & Raffles', 'https://example.test/events/', 'fundraisers' );
	\check( false !== strpos( $html, 'hherm-calendar__banner' ) && false !== strpos( $html, 'hherm-calendar__eyebrow' ), 'fundraiser months with events still show the banner and label' );
	\check( false === strpos( $html, 'hherm-calendar__legend' ), 'a month showing a single category needs no legend' );
	$GLOBALS['posts'][25] = $event( 'Upcoming raffle', $at( 20, '12:00' ), $at( 20, '13:00' ), array( 'raffle' => 'true' ) );
	$GLOBALS['posts'][26] = $event( 'Upcoming workshop', $at( 21, '10:00' ), $at( 21, '12:00' ) );
	$html = $render->invoke( new Calendar_Display( $settings ), $month, $options, false, '', 'https://example.test/events/' );
	\check( false !== strpos( $html, 'is-event">Workshops &amp; events' ) && false !== strpos( $html, 'is-fundraiser">Fundraisers' ) && false !== strpos( $html, 'is-raffle">Raffle available' ), 'legend lists the categories shown this month' );
	\check( false !== strpos( $html, 'hherm-calendar__event--fundraiser' ) && false !== strpos( $html, 'hherm-calendar__tag">Raffle' ), 'raffles use the fundraiser colour and carry a Raffle tag' );

	// A registration window may stay open after the event starts.
	$now = time();
	$in_progress = $event( 'In progress workshop', $now - 3600, $now + 3600, array( 'registration_close' => $now + 1800 ) );
	$GLOBALS['posts'] = array( 40 => $in_progress );
	$status = new \ReflectionMethod( Calendar_Display::class, 'registration_status' );
	$status->setAccessible( true );
	$state = $status->invoke( new Calendar_Display( $settings ), 40, $now - 3600, false );
	\check( 'open' === $state['state'] && ! empty( $state['cta_url'] ), 'calendar preserves registration when closing is after an event has started' );
	$GLOBALS['posts'][40]['meta']['registration_close'] = $now - 60;
	$state = $status->invoke( new Calendar_Display( $settings ), 40, $now - 3600, false );
	\check( 'closed' === $state['state'] && empty( $state['cta_url'] ), 'calendar stops registration at its explicit close even during an event' );

	$GLOBALS['posts'] = array( 41 => $event( 'Zero capacity', $at( 5, '09:00' ), $at( 5, '10:00' ), array( 'event_capacity' => 0, 'current_event_capacity' => 0 ) ) );
	$state = $status->invoke( new Calendar_Display( $settings ), 41, $at( 5, '09:00' ), false );
	\check( 'full' === $state['state'] && empty( $state['cta_url'] ), 'configured zero capacity is full and offers no registration link' );
	unset( $GLOBALS['posts'][41]['meta']['current_event_capacity'] );
	$state = $status->invoke( new Calendar_Display( $settings ), 41, $at( 5, '09:00' ), false );
	\check( 'full' === $state['state'], 'missing remaining capacity falls back to a zero total consistently with the capacity manager' );
	$GLOBALS['posts'][41]['meta']['allow_waitlist'] = 'true';
	$state = $status->invoke( new Calendar_Display( $settings ), 41, $at( 5, '09:00' ), false );
	\check( 'waitlist' === $state['state'] && 'Join the waitlist' === $state['cta_label'], 'zero-capacity events can still offer their configured waitlist' );
	$untracked = new Settings();
	$untracked->values = array( 'attendee_count_field' => '', 'event_remaining_capacity' => '' );
	$state = $status->invoke( new Calendar_Display( $untracked ), 41, $at( 5, '09:00' ), false );
	\check( 'open' === $state['state'], 'zero does not close registration when capacity automation is disabled' );

	// Every visible occurrence and calendar render owns a distinct popup target.
	$GLOBALS['posts'] = array( 42 => $event( 'Five day fundraiser', $at( 3, '09:00' ), $at( 7, '17:00' ) ) + array( 'terms' => array( 50 ) ) );
	$display = new Calendar_Display( $settings );
	$first_html = $render->invoke( $display, $month, $options, false, '', 'https://example.test/events/' );
	$second_html = $render->invoke( $display, $month, $options, false, '', 'https://example.test/events/', 'fundraisers' );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $first_html . $second_html );
	$xpath = new \DOMXPath( $dom );
	$popups = $xpath->query( "//div[contains(@class,'hherm-calendar__popup')][@id]" );
	$popup_ids = array();
	foreach ( $popups as $popup ) $popup_ids[] = $popup->getAttribute( 'id' );
	\check( 10 === count( $popup_ids ) && 10 === count( array_unique( $popup_ids ) ), 'five-day events have unique popup IDs across middle days and two calendars' );
	$targets_match = true;
	foreach ( $xpath->query( '//button[@data-hherm-event-trigger]' ) as $button ) {
		$target = $button->getAttribute( 'aria-controls' );
		if ( 1 !== $xpath->query( "./div[@id='{$target}']", $button->parentNode )->length ) $targets_match = false;
	}
	\check( $targets_match, 'each calendar trigger controls its own local popup' );

	// Exercise the actual nested NUMERIC/CHAR query predicates for imported local strings.
	$formats = array( 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' );
	$GLOBALS['posts'] = array( 50 => $event( 'Numeric date event', $at( 5, '10:00' ), $at( 5, '11:00' ) ) );
	foreach ( $formats as $index => $format ) {
		$GLOBALS['posts'][51 + $index] = $event( 'Imported date ' . $index, $at( 5, '10:00' ), 0, array( 'start_date' => wp_date( $format, $at( 5, '10:00' ), $tz ) ) );
	}
	$GLOBALS['posts'][60] = $event( 'Before lookback', $at( 1, '00:00' ) - 32 * DAY_IN_SECONDS, 0, array( 'start_date' => $month->modify( '-32 days' )->format( 'Y-m-d\TH:i' ) ) );
	$GLOBALS['posts'][61] = $event( 'Next month boundary', $at( 1, '00:00' ), 0, array( 'start_date' => $month->modify( '+1 month' )->format( 'Y-m-d\TH:i' ) ) );
	$GLOBALS['posts'][62] = $event( 'Draft import', $at( 5, '10:00' ), 0, array( 'start_date' => wp_date( 'Y-m-d\TH:i', $at( 5, '10:00' ), $tz ) ) );
	$GLOBALS['posts'][62]['status'] = 'draft';
	$range = new \ReflectionMethod( Calendar_Display::class, 'events_for_range' );
	$range->setAccessible( true );
	$occurrences = $range->invoke( new Calendar_Display( $settings ), $month, $month->modify( '+1 month' ) );
	$found_ids = array();
	foreach ( $occurrences as $day_events ) foreach ( $day_events as $item ) $found_ids[] = $item['id'];
	sort( $found_ids );
	\check( array( 50, 51, 52, 53, 54, 55 ) === $found_ids, 'bounded calendar query includes every supported imported local format and excludes drafts and dates outside the range' );
}
