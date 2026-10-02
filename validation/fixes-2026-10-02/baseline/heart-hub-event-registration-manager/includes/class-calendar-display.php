<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Native public calendar rendered by [hherm_events_calendar] and, limited to
 * fundraisers and raffles, by [hherm_fundraisers_calendar].
 */
final class Calendar_Display {
	private const MAX_SPAN_DAYS = 31;
	private const FILTERS = array( 'all', 'fundraisers' );
	private const FUNDRAISER_MONTHS_BEHIND = 36;
	private const MAX_MONTHS_BEHIND = 120;

	private $settings;
	private $display;
	private $status_cache = array();
	private $series_ids = null;
	private $fundraiser_terms = null;

	public function __construct( Settings $settings, ?Event_Public_Display $display = null ) {
		$this->settings = $settings;
		$this->display = $display ?: new Event_Public_Display( $settings );
	}

	public function register(): void {
		add_shortcode( 'hherm_events_calendar', array( $this, 'shortcode' ) );
		add_shortcode( 'hherm_fundraisers_calendar', array( $this, 'fundraisers_shortcode' ) );
		add_action( 'wp_ajax_hherm_calendar_month', array( $this, 'ajax_month' ) );
		add_action( 'wp_ajax_nopriv_hherm_calendar_month', array( $this, 'ajax_month' ) );
	}

	public function shortcode( $attributes = array() ): string {
		return $this->render_shortcode( (array) $attributes, 'all', 'hherm_events_calendar' );
	}

	/** Fundraisers and raffles only, reaching further back so past fundraisers stay on record. */
	public function fundraisers_shortcode( $attributes = array() ): string {
		return $this->render_shortcode( (array) $attributes, 'fundraisers', 'hherm_fundraisers_calendar' );
	}

	private function render_shortcode( array $attributes, string $filter, string $tag ): string {
		$attributes = shortcode_atts( array( 'title' => '', 'show_title' => '', 'months_behind' => '' ), $attributes, $tag );
		$options = Calendar_Settings::get();
		$default_behind = 'fundraisers' === $filter ? max( (int) $options['months_behind'], self::FUNDRAISER_MONTHS_BEHIND ) : (int) $options['months_behind'];
		$options['months_behind'] = '' === (string) $attributes['months_behind'] ? $default_behind : $this->months_behind( $attributes['months_behind'] );
		$timezone = wp_timezone();
		$current_month = new \DateTimeImmutable( 'first day of this month 00:00:00', $timezone );
		$month = $this->requested_month( $current_month, $options );
		$default_heading = 'fundraisers' === $filter ? true : (bool) $options['show_heading'];
		$show_heading = '' === (string) $attributes['show_title'] ? $default_heading : 'true' === strtolower( (string) $attributes['show_title'] );
		$default_title = 'fundraisers' === $filter ? 'Fundraisers & Raffles' : (string) $options['heading'];
		$title = sanitize_text_field( (string) $attributes['title'] ) ?: sanitize_text_field( $default_title );
		$base_url = remove_query_arg( array( 'hherm_calendar_month' ) );

		wp_enqueue_style( 'hherm-calendar-display', HHERM_URL . 'assets/calendar-display.css', array( 'hherm-event-public' ), HHERM_VERSION );
		wp_enqueue_script( 'hherm-calendar-display', HHERM_URL . 'assets/calendar-display.js', array(), HHERM_VERSION, true );
		return $this->render_calendar( $month, $options, $show_heading, $title, $base_url, $filter );
	}

	private function months_behind( $value ): int {
		return is_scalar( $value ) ? min( self::MAX_MONTHS_BEHIND, absint( $value ) ) : 0;
	}

	public function ajax_month(): void {
		$options = Calendar_Settings::get();
		$filter = isset( $_POST['filter'] ) && is_scalar( $_POST['filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter'] ) ) : 'all';
		if ( ! in_array( $filter, self::FILTERS, true ) ) $filter = 'all';
		if ( isset( $_POST['months_behind'] ) && is_scalar( $_POST['months_behind'] ) && '' !== (string) $_POST['months_behind'] ) {
			$options['months_behind'] = $this->months_behind( wp_unslash( (string) $_POST['months_behind'] ) );
		}
		$timezone = wp_timezone();
		$current_month = new \DateTimeImmutable( 'first day of this month 00:00:00', $timezone );
		$raw_month = isset( $_POST['month'] ) && is_scalar( $_POST['month'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['month'] ) ) : '';
		if ( '' !== $raw_month && ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $raw_month ) ) {
			wp_send_json_error( array( 'message' => 'Invalid calendar month.' ), 400 );
		}
		$month = $this->requested_month( $current_month, $options, $raw_month );
		$show_heading = isset( $_POST['show_heading'] ) && is_scalar( $_POST['show_heading'] ) && '1' === (string) wp_unslash( $_POST['show_heading'] );
		$title = isset( $_POST['title'] ) && is_scalar( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		if ( '' === $title ) $title = sanitize_text_field( (string) $options['heading'] );
		$requested_base_url = isset( $_POST['base_url'] ) && is_scalar( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['base_url'] ) ) : '';
		$base_url = wp_validate_redirect( $requested_base_url, home_url( '/' ) );
		$base_url = remove_query_arg( array( 'hherm_calendar_month' ), $base_url );
		$html = $this->render_calendar( $month, $options, $show_heading, $title, $base_url, $filter );
		wp_send_json_success( array( 'month' => $month->format( 'Y-m' ), 'html' => $html ) );
	}

	private function render_calendar( \DateTimeImmutable $month, array $options, bool $show_heading, string $title, string $base_url, string $filter = 'all' ): string {
		$timezone = wp_timezone();
		$today = new \DateTimeImmutable( 'today', $timezone );
		$first_weekday = (int) $options['week_start'];
		$offset = ( (int) $month->format( 'w' ) - $first_weekday + 7 ) % 7;
		$grid_start = $month->modify( '-' . $offset . ' days' );
		// Only the weeks this month occupies (four to six rows).
		$weeks = (int) ceil( ( $offset + (int) $month->format( 't' ) ) / 7 );
		$grid_end = $grid_start->modify( '+' . ( $weeks * 7 ) . ' days' );
		$weekdays = $this->weekdays( $first_weekday );
		$events = $this->events_for_range( $grid_start, $grid_end, $filter );
		$month_key = $month->format( 'Y-m' );
		$month_events = array_filter(
			$events,
			static function ( $date_key ) use ( $month_key ): bool {
				return 0 === strpos( (string) $date_key, $month_key );
			},
			ARRAY_FILTER_USE_KEY
		);
		ksort( $month_events );
		$current_month = new \DateTimeImmutable( 'first day of this month 00:00:00', $timezone );
		$previous = $month->modify( '-1 month' );
		$next = $month->modify( '+1 month' );
		$current_index = (int) $current_month->format( 'Y' ) * 12 + (int) $current_month->format( 'n' );
		$month_index = (int) $month->format( 'Y' ) * 12 + (int) $month->format( 'n' );
		$max_index = $current_index + (int) $options['months_ahead'];
		$can_previous = $month_index > $current_index - (int) $options['months_behind'];
		$can_next = $month_index < $max_index;
		$month_label = wp_date( 'F Y', $month->getTimestamp(), $timezone );
		// Anchors link mobile day cells to their agenda entries; unique per render.
		$anchor_prefix = 'hherm-calendar-' . bin2hex( random_bytes( 4 ) );
		$is_fundraisers = 'fundraisers' === $filter;
		// Fundraisers always point to the next one; the events calendar only when a month is empty.
		$upcoming = $is_fundraisers ? $this->next_event_after( $current_month, $max_index, $filter ) : array();
		$next_event = ! $upcoming && ! $month_events && $month_index >= $current_index ? $this->next_event_after( $next, $max_index, $filter ) : array();
		$legend = $this->legend( $month_events );
		ob_start();
		?>
		<div class="hherm-calendar" data-hherm-calendar="1" data-popups="<?php echo esc_attr( (string) (int) $options['show_popups'] ); ?>" data-density="<?php echo esc_attr( $this->density( $options ) ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-base-url="<?php echo esc_url( $base_url ); ?>" data-heading="<?php echo esc_attr( $title ); ?>" data-show-heading="<?php echo esc_attr( $show_heading ? '1' : '0' ); ?>" data-month="<?php echo esc_attr( $month_key ); ?>" data-filter="<?php echo esc_attr( $filter ); ?>" data-months-behind="<?php echo esc_attr( (string) (int) $options['months_behind'] ); ?>" style="<?php echo esc_attr( $this->calendar_style( $options ) ); ?>">
			<span class="hherm-calendar__status hherm-visually-hidden" role="status" aria-live="polite"></span>
			<div class="hherm-calendar__toolbar">
				<div class="hherm-calendar__heading">
					<?php if ( $show_heading && $title ) : ?><p class="hherm-calendar__eyebrow"><?php echo esc_html( $title ); ?></p><?php endif; ?>
					<h2 class="hherm-calendar__month" aria-live="polite" tabindex="-1"><?php echo esc_html( $month_label ); ?></h2>
				</div>
				<nav class="hherm-calendar__navigation" aria-label="Calendar month navigation">
					<?php if ( $can_previous ) : ?><a class="hherm-calendar__nav-button hherm-calendar__nav-button--icon" href="<?php echo esc_url( add_query_arg( 'hherm_calendar_month', $previous->format( 'Y-m' ), $base_url ) ); ?>" rel="prev" data-hherm-calendar-nav aria-label="Previous month"><?php $this->icon( 'previous' ); ?></a><?php else : ?><span class="hherm-calendar__nav-button hherm-calendar__nav-button--icon is-disabled" aria-disabled="true" aria-label="Previous month"><?php $this->icon( 'previous' ); ?></span><?php endif; ?>
					<a class="hherm-calendar__nav-button hherm-calendar__nav-button--today" href="<?php echo esc_url( $base_url ); ?>" data-hherm-calendar-nav>Today</a>
					<?php if ( $can_next ) : ?><a class="hherm-calendar__nav-button hherm-calendar__nav-button--icon" href="<?php echo esc_url( add_query_arg( 'hherm_calendar_month', $next->format( 'Y-m' ), $base_url ) ); ?>" rel="next" data-hherm-calendar-nav aria-label="Next month"><?php $this->icon( 'next' ); ?></a><?php else : ?><span class="hherm-calendar__nav-button hherm-calendar__nav-button--icon is-disabled" aria-disabled="true" aria-label="Next month"><?php $this->icon( 'next' ); ?></span><?php endif; ?>
				</nav>
			</div>
			<?php if ( $upcoming ) : ?>
				<div class="hherm-calendar__banner">
					<span class="hherm-calendar__banner-icon" aria-hidden="true"><?php $this->icon( 'heart' ); ?></span>
					<p class="hherm-calendar__banner-text"><span class="hherm-calendar__banner-label">Next fundraiser</span> <span><strong><?php echo esc_html( $upcoming['title'] ); ?></strong> · <?php echo esc_html( wp_date( 'l j F', $upcoming['start'], $timezone ) ); ?><?php if ( $upcoming['venue'] ) : ?> · <?php echo esc_html( $upcoming['venue'] ); ?><?php endif; ?></span></p>
					<?php if ( $upcoming['url'] ) : ?><a class="hherm-calendar__button" href="<?php echo esc_url( $upcoming['url'] ); ?>">View event</a><?php elseif ( $upcoming['month'] !== $month_key ) : ?><a class="hherm-calendar__button" href="<?php echo esc_url( add_query_arg( 'hherm_calendar_month', $upcoming['month'], $base_url ) ); ?>" data-hherm-calendar-nav>Show month</a><?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( ! $month_events ) : ?>
				<p class="hherm-calendar__notice"><?php echo esc_html( sprintf( 'No %s in %s.', $is_fundraisers ? 'fundraisers or raffles' : 'events', $month_label ) ); ?>
				<?php if ( $next_event ) : ?> <a class="hherm-calendar__notice-link" href="<?php echo esc_url( add_query_arg( 'hherm_calendar_month', $next_event['month'], $base_url ) ); ?>" data-hherm-calendar-nav><?php echo esc_html( sprintf( 'Next event: %s, %s', $next_event['title'], wp_date( 'l j F', $next_event['start'], $timezone ) ) ); ?> ›</a><?php endif; ?></p>
			<?php endif; ?>
			<?php if ( $legend ) : ?>
				<ul class="hherm-calendar__legend" aria-label="Key">
					<?php foreach ( $legend as $class => $label ) : ?><li class="<?php echo esc_attr( 'hherm-calendar__legend-item ' . $class ); ?>"><?php echo esc_html( $label ); ?></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="hherm-calendar__card">
			<table class="hherm-calendar__table">
				<thead><tr><?php foreach ( $weekdays as $weekday ) : ?><th scope="col"><?php echo esc_html( $weekday ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php for ( $week = 0; $week < $weeks; $week++ ) : ?><tr>
					<?php for ( $day = 0; $day < 7; $day++ ) :
						$date = $grid_start->modify( '+' . ( $week * 7 + $day ) . ' days' );
						$date_key = $date->format( 'Y-m-d' );
						$in_month = $date->format( 'Y-m' ) === $month_key;
						$classes = array( 'hherm-calendar__day' );
						if ( ! $in_month ) $classes[] = 'is-outside-month';
						if ( $date_key === $today->format( 'Y-m-d' ) ) $classes[] = 'is-today';
						$day_events = $events[ $date_key ] ?? array();
						if ( $day_events ) $classes[] = 'has-events';
						$day_label = wp_date( 'l, j F Y', $date->getTimestamp(), $timezone );
						?>
						<td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" aria-label="<?php echo esc_attr( $day_label ); ?>">
							<span class="hherm-calendar__day-number"><?php echo esc_html( $date->format( 'j' ) ); ?></span>
							<?php if ( $day_events ) : ?>
								<?php if ( $in_month ) : ?><a class="hherm-calendar__day-link" href="#<?php echo esc_attr( $anchor_prefix . '-' . $date_key ); ?>" aria-label="<?php echo esc_attr( sprintf( '%s: %d %s. Show details', $day_label, count( $day_events ), 1 === count( $day_events ) ? 'event' : 'events' ) ); ?>"><?php $this->render_dots( $day_events ); ?></a><?php else : ?><span class="hherm-calendar__day-link is-static" aria-hidden="true"><?php $this->render_dots( $day_events ); ?></span><?php endif; ?>
								<ul class="hherm-calendar__events">
									<?php foreach ( $day_events as $event ) : ?>
										<li class="<?php echo esc_attr( $this->event_classes( $event ) ); ?><?php echo $day >= 5 ? ' hherm-calendar__event--align-right' : ''; ?>">
											<?php $this->render_event( $event, $options ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					<?php endfor; ?>
				</tr><?php endfor; ?>
				</tbody>
			</table>
			</div>
			<?php if ( $month_events ) : ?>
				<section class="hherm-calendar__agenda" aria-label="<?php echo esc_attr( sprintf( '%s in %s', $is_fundraisers ? 'Fundraisers and raffles' : 'Events', $month_label ) ); ?>">
					<h3 class="hherm-calendar__agenda-title"><?php echo esc_html( sprintf( '%s in %s', $is_fundraisers ? 'Fundraisers and raffles' : 'Events', $month_label ) ); ?></h3>
					<ol class="hherm-calendar__agenda-days">
						<?php foreach ( $month_events as $date_key => $day_events ) : $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date_key, $timezone ); $quiet = ! array_filter( $day_events, array( $this, 'is_prominent' ) ); ?>
							<li class="hherm-calendar__agenda-day<?php echo $quiet ? ' is-quiet' : ''; ?>" id="<?php echo esc_attr( $anchor_prefix . '-' . $date_key ); ?>" tabindex="-1">
								<span class="hherm-calendar__agenda-tile" aria-hidden="true"><span><?php echo esc_html( wp_date( 'D', $date->getTimestamp(), $timezone ) ); ?></span><strong><?php echo esc_html( $date->format( 'j' ) ); ?></strong></span>
								<div class="hherm-calendar__agenda-body">
								<h4 class="hherm-calendar__agenda-date"><time datetime="<?php echo esc_attr( (string) $date_key ); ?>"><?php echo esc_html( wp_date( 'l j F', $date->getTimestamp(), $timezone ) ); ?></time></h4>
								<ul class="hherm-calendar__agenda-events">
									<?php foreach ( $day_events as $event ) : ?>
										<li class="<?php echo esc_attr( $this->event_classes( $event ) . ' hherm-calendar__agenda-event' . ( $this->is_prominent( $event ) ? '' : ' is-slim' ) ); ?>">
											<?php if ( ! $this->is_prominent( $event ) ) : ?>
											<span class="hherm-calendar__agenda-time"><span class="hherm-calendar__dot-inline" aria-hidden="true"></span><?php echo esc_html( $event['title'] . ' · ' . $this->time_label( $event ) ); ?></span>
											<?php elseif ( $event['completed'] ) : ?>
											<span class="hherm-calendar__agenda-time"><?php $this->icon( 'check' ); ?><?php echo esc_html( $this->completed_label( $event, 'j F Y' ) ); ?></span>
											<strong class="hherm-calendar__agenda-name"><?php echo esc_html( $event['title'] ); ?></strong>
											<?php else : ?>
											<span class="hherm-calendar__agenda-time"><span class="hherm-calendar__dot-inline" aria-hidden="true"></span><?php echo esc_html( $this->category_label( $event ) . ' · ' . $this->time_label( $event ) ); ?></span>
											<?php if ( $event['url'] ) : ?><a class="hherm-calendar__agenda-name" href="<?php echo esc_url( $event['url'] ); ?>"><?php echo esc_html( $event['title'] ); ?></a><?php else : ?><strong class="hherm-calendar__agenda-name"><?php echo esc_html( $event['title'] ); ?></strong><?php endif; ?>
											<?php if ( $event['venue'] ) : ?><span class="hherm-calendar__agenda-venue"><?php echo esc_html( $event['venue'] ); ?></span><?php endif; ?>
											<?php if ( $event['raffle'] ) : ?><span class="hherm-calendar__tag">Raffle</span><?php endif; ?>
											<?php if ( in_array( $event['segment'], array( 'single', 'start' ), true ) || $event['cancelled'] ) $this->render_status( $event ); ?>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
								</div>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function calendar_style( array $options ): string {
		$defaults = Calendar_Settings::defaults();
		$variables = array(
			'--hherm-calendar-ink'                    => 'calendar_ink',
			'--hherm-calendar-muted'                  => 'calendar_muted',
			'--hherm-calendar-accent'                 => 'calendar_accent',
			'--hherm-calendar-border'                 => 'calendar_border',
			'--hherm-calendar-surface'                => 'calendar_surface',
			'--hherm-calendar-weekday-background'     => 'weekday_background',
			'--hherm-calendar-event-background'       => 'event_background',
			'--hherm-calendar-event-text'             => 'event_text',
			'--hherm-calendar-opening-hours-accent'   => 'opening_hours_accent',
			'--hherm-calendar-community-visit-accent' => 'community_visit_accent',
			'--hherm-calendar-speech-accent'          => 'speech_accent',
			'--hherm-calendar-fundraiser-accent'      => 'fundraiser_accent',
		);
		$declarations = array();
		foreach ( $variables as $variable => $key ) {
			$color = isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ? sanitize_hex_color( (string) $options[ $key ] ) : false;
			$declarations[] = $variable . ':' . ( $color ?: $defaults[ $key ] );
		}
		$font_families = array(
			'heart_hub' => '"Instrument Sans", system-ui, -apple-system, "Segoe UI", sans-serif',
			'inherit'   => 'inherit',
			'system'    => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
			'arial'     => 'Arial, Helvetica, sans-serif',
			'verdana'   => 'Verdana, Geneva, sans-serif',
			'tahoma'    => 'Tahoma, Geneva, sans-serif',
			'georgia'   => 'Georgia, "Times New Roman", serif',
			'times'     => '"Times New Roman", Times, serif',
			'trebuchet' => '"Trebuchet MS", Helvetica, sans-serif',
		);
		$font_family = isset( $options['font_family'] ) && is_scalar( $options['font_family'] ) ? (string) $options['font_family'] : 'inherit';
		$heading_font = 'heart_hub' === $font_family ? 'Literata, Georgia, "Times New Roman", serif' : '';
		$font_family = isset( $font_families[ $font_family ] ) ? $font_families[ $font_family ] : 'inherit';
		$font_sizes = array( '12', '14', '16', '18', '20', '22', '24' );
		$font_size = isset( $options['font_size'] ) && is_scalar( $options['font_size'] ) ? (string) $options['font_size'] : '16';
		$font_size = in_array( $font_size, $font_sizes, true ) ? $font_size : '16';
		$font_weights = array( '300', '400', '500', '600', '700' );
		$font_weight = isset( $options['font_weight'] ) && is_scalar( $options['font_weight'] ) ? (string) $options['font_weight'] : '400';
		$font_weight = in_array( $font_weight, $font_weights, true ) ? $font_weight : '400';
		$emphasis_weights = array( '300' => '500', '400' => '600', '500' => '700', '600' => '800', '700' => '900' );
		$font_style = isset( $options['font_style'] ) && is_scalar( $options['font_style'] ) && 'italic' === $options['font_style'] ? 'italic' : 'normal';
		$line_heights = array( '1.2', '1.35', '1.5', '1.65', '1.8' );
		$line_height = isset( $options['line_height'] ) && is_scalar( $options['line_height'] ) ? (string) $options['line_height'] : '1.5';
		$line_height = in_array( $line_height, $line_heights, true ) ? $line_height : '1.5';
		$spacing = array( 'normal' => '0', 'slight' => '.01em', 'medium' => '.03em', 'wide' => '.06em' );
		$letter_spacing = isset( $options['letter_spacing'] ) && is_scalar( $options['letter_spacing'] ) ? (string) $options['letter_spacing'] : 'normal';
		$letter_spacing = isset( $spacing[ $letter_spacing ] ) ? $spacing[ $letter_spacing ] : '0';
		$declarations[] = '--hherm-calendar-font-family:' . $font_family;
		$declarations[] = '--hherm-calendar-heading-font:' . ( $heading_font ?: $font_family );
		$declarations[] = '--hherm-calendar-font-size:' . $font_size . 'px';
		$declarations[] = '--hherm-calendar-font-weight:' . $font_weight;
		$declarations[] = '--hherm-calendar-emphasis-weight:' . $emphasis_weights[ $font_weight ];
		$declarations[] = '--hherm-calendar-font-style:' . $font_style;
		$declarations[] = '--hherm-calendar-line-height:' . $line_height;
		$declarations[] = '--hherm-calendar-letter-spacing:' . $letter_spacing;
		$width = isset( $options['max_width'] ) && is_scalar( $options['max_width'] ) ? (string) $options['max_width'] : 'full';
		$width = in_array( $width, array( '720', '960', '1200', '1440' ), true ) ? $width . 'px' : '100%';
		$declarations[] = '--hherm-calendar-max-width:' . $width;
		return implode( ';', $declarations );
	}

	private function density( array $options ): string {
		$density = isset( $options['density'] ) && is_scalar( $options['density'] ) ? (string) $options['density'] : 'comfortable';
		return in_array( $density, array( 'compact', 'comfortable', 'spacious' ), true ) ? $density : 'comfortable';
	}

	private function requested_month( \DateTimeImmutable $current_month, array $options, ?string $requested = null ): \DateTimeImmutable {
		$raw = null === $requested && isset( $_GET['hherm_calendar_month'] ) && is_scalar( $_GET['hherm_calendar_month'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['hherm_calendar_month'] ) ) : (string) $requested;
		$month = $current_month;
		if ( preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $raw ) ) {
			$candidate = \DateTimeImmutable::createFromFormat( '!Y-m', $raw, wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $candidate && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				$month = $candidate;
			}
		}
		$current_index = (int) $current_month->format( 'Y' ) * 12 + (int) $current_month->format( 'n' );
		$month_index = (int) $month->format( 'Y' ) * 12 + (int) $month->format( 'n' );
		$minimum = $current_index - (int) $options['months_behind'];
		$maximum = $current_index + (int) $options['months_ahead'];
		if ( $month_index < $minimum ) $month = $current_month->modify( '-' . (int) $options['months_behind'] . ' months' );
		if ( $month_index > $maximum ) $month = $current_month->modify( '+' . (int) $options['months_ahead'] . ' months' );
		return $month->modify( 'first day of this month 00:00:00' );
	}

	/**
	 * Events keyed by local date (Y-m-d) for every day in [$range_start, $range_end).
	 */
	private function events_for_range( \DateTimeImmutable $range_start, \DateTimeImmutable $range_end, string $filter = 'all' ): array {
		$events = array();
		$start_key = sanitize_key( (string) $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$end_key = sanitize_key( (string) $this->settings->get( 'event_end', 'end_date__time' ) ) ?: 'end_date__time';
		$venue_key = sanitize_key( (string) $this->settings->get( 'event_venue', 'venue' ) ) ?: 'venue';
		$from = $range_start->getTimestamp();
		$to = $range_end->getTimestamp();
		$items = get_posts(
			array(
				'post_type'      => $this->post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'meta_value_num',
				'meta_key'       => $start_key,
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_query'     => array(
					// Include multi-day events that started shortly before the visible range.
					array( 'key' => $start_key, 'value' => $from - self::MAX_SPAN_DAYS * DAY_IN_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC' ),
					array( 'key' => $start_key, 'value' => $to, 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $items as $post_id ) {
			if ( 'fundraisers' === $filter && ! $this->is_fundraiser( (int) $post_id ) ) continue;
			$start = Event_Datetime::timestamp( get_post_meta( $post_id, $start_key, true ) );
			if ( ! $start ) continue;
			$this->add_occurrence( $events, (int) $post_id, $start, Event_Datetime::timestamp( get_post_meta( $post_id, $end_key, true ) ), $venue_key, $range_start, $range_end );
		}

		// Recurring series are the same for every range rendered in this request.
		// Schedule entries (opening hours, visits, speeches) are never fundraisers.
		if ( 'all' === $filter && null === $this->series_ids ) $this->series_ids = get_posts(
			array(
				'post_type'      => $this->post_type(),
				'post_status'    => array( 'draft', 'private', 'publish' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => Calendar_Schedule::TYPE_META, 'compare' => 'EXISTS' ),
					array( 'key' => Calendar_Schedule::MASTER_META, 'value' => '1', 'compare' => '=' ),
				),
			)
		);
		foreach ( 'all' === $filter ? $this->series_ids : array() as $post_id ) {
			if ( 'publish' !== get_post_meta( $post_id, Calendar_Schedule::SCHEDULE_STATUS_META, true ) ) continue;
			$rule = get_post_meta( $post_id, Calendar_Schedule::RULE_META, true );
			if ( ! is_array( $rule ) ) continue;
			foreach ( $this->recurring_dates( $rule, $range_start, $range_end ) as $date ) {
				foreach ( (array) ( $rule['slots'] ?? array() ) as $slot ) {
					$start_text = sanitize_text_field( (string) ( $slot['start'] ?? '' ) );
					if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start_text ) ) continue;
					$start = $this->local_timestamp( $date . ' ' . $start_text );
					if ( ! $start ) continue;
					$end_text = sanitize_text_field( (string) ( $slot['end'] ?? '' ) );
					$end = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end_text ) ? $this->local_timestamp( $date . ' ' . $end_text ) : 0;
					// Recurring slots never span midnight.
					$this->add_occurrence( $events, (int) $post_id, $start, $end > $start ? $end : 0, $venue_key, $range_start, $range_end );
				}
			}
		}
		foreach ( $events as &$day_events ) {
			usort(
				$day_events,
				static function ( array $left, array $right ): int {
					return array( $left['segment_order'], $left['start'] ) <=> array( $right['segment_order'], $right['start'] );
				}
			);
		}
		unset( $day_events );
		ksort( $events );
		return $events;
	}

	private function recurring_dates( array $rule, \DateTimeImmutable $range_start, \DateTimeImmutable $range_end ): array {
		$frequency = sanitize_key( (string) ( $rule['frequency'] ?? '' ) );
		$first = $range_start->format( 'Y-m-d' );
		$last = $range_end->modify( '-1 day' )->format( 'Y-m-d' );
		$dates = array();
		if ( 'dates' === $frequency ) {
			foreach ( (array) ( $rule['dates'] ?? array() ) as $date ) {
				$date = sanitize_text_field( (string) $date );
				if ( $this->valid_date( $date ) && $date >= $first && $date <= $last ) $dates[] = $date;
			}
			return array_values( array_unique( $dates ) );
		}
		$first_date = sanitize_text_field( (string) ( $rule['start_date'] ?? '' ) );
		if ( ! $this->valid_date( $first_date ) ) return array();
		$weekdays = array_map( 'absint', (array) ( $rule['weekdays'] ?? array() ) );
		$month_day = absint( $rule['month_day'] ?? 0 );
		for ( $cursor = $range_start; $cursor < $range_end; $cursor = $cursor->modify( '+1 day' ) ) {
			$date = $cursor->format( 'Y-m-d' );
			if ( $date < $first_date ) continue;
			$matches = ( 'daily' === $frequency )
				|| ( 'weekly' === $frequency && in_array( (int) $cursor->format( 'w' ), $weekdays, true ) )
				|| ( 'monthly' === $frequency && $month_day === (int) $cursor->format( 'j' ) );
			if ( $matches ) $dates[] = $date;
		}
		return $dates;
	}

	/**
	 * Place one occurrence on each visible day it covers. Multi-day events are
	 * marked start/middle/end so each day can describe its part of the event.
	 */
	private function add_occurrence( array &$events, int $post_id, int $start, int $end, string $venue_key, \DateTimeImmutable $range_start, \DateTimeImmutable $range_end ): void {
		$timezone = wp_timezone();
		$start_day = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $timezone )->setTime( 0, 0 );
		$end_day = $start_day;
		if ( $end > $start ) {
			$end_local = ( new \DateTimeImmutable( '@' . $end ) )->setTimezone( $timezone );
			$end_day = $end_local->setTime( 0, 0 );
			// An event ending exactly at midnight belongs to the previous day.
			if ( $end_day > $start_day && '00:00' === $end_local->format( 'H:i' ) ) $end_day = $end_day->modify( '-1 day' );
			$limit = $start_day->modify( '+' . self::MAX_SPAN_DAYS . ' days' );
			if ( $end_day > $limit ) $end_day = $limit;
		}
		$multi_day = $end_day > $start_day;
		$base = $this->event_details( $post_id, $start, $end, $venue_key, $multi_day );
		for ( $day = $start_day; $day <= $end_day; $day = $day->modify( '+1 day' ) ) {
			if ( $day < $range_start->setTime( 0, 0 ) || $day >= $range_end ) continue;
			$segment = ! $multi_day ? 'single' : ( $day == $start_day ? 'start' : ( $day == $end_day ? 'end' : 'middle' ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- DateTimeImmutable value comparison.
			$events[ $day->format( 'Y-m-d' ) ][] = $base + array(
				'segment'       => $segment,
				// Continuing multi-day events are listed before events starting that day.
				'segment_order' => in_array( $segment, array( 'middle', 'end' ), true ) ? 0 : 1,
			);
		}
	}

	private function event_details( int $post_id, int $start, int $end, string $venue_key, bool $multi_day ): array {
		$type = sanitize_key( (string) get_post_meta( $post_id, Calendar_Schedule::TYPE_META, true ) );
		$raffle = ! $type && Event_Public_Display::normalise_flag( get_post_meta( $post_id, 'raffle', true ), false );
		$description = (string) get_post_field( 'post_content', $post_id );
		$cancelled = Event_Public_Display::normalise_flag( get_post_meta( $post_id, 'event_cancelled', true ), false );
		// Finished when the end has passed, or the start when no end is recorded.
		$finished = $end > $start ? $end : $start;
		return array(
			'id'          => $post_id,
			'title'       => get_the_title( $post_id ),
			'start'       => $start,
			'end'         => $end,
			'multi_day'   => $multi_day,
			'venue'       => (string) get_post_meta( $post_id, $venue_key, true ),
			'description' => wp_strip_all_tags( $description ),
			'type'        => $type ?: 'event',
			'fundraiser'  => ! $type && ( $raffle || $this->is_fundraiser( $post_id ) ),
			'raffle'      => $raffle,
			'cancelled'   => $cancelled,
			'completed'   => ! $cancelled && $finished < time(),
			'finished'    => $finished,
			'url'         => 'publish' === get_post_status( $post_id ) && ! metadata_exists( 'post', $post_id, Calendar_Schedule::MASTER_META ) ? get_permalink( $post_id ) : '',
			'status'      => $type ? array() : $this->registration_status( $post_id, $start, $cancelled ),
		);
	}

	/**
	 * Visitor-facing registration state, using the same rules as the event page CTA.
	 *
	 * @return array{state?: string, label?: string, cta_label?: string, cta_url?: string, external?: bool}
	 */
	private function registration_status( int $post_id, int $start, bool $cancelled ): array {
		if ( $cancelled ) return array( 'state' => 'cancelled', 'label' => 'Cancelled' );
		if ( isset( $this->status_cache[ $post_id ] ) ) return $this->status_cache[ $post_id ];
		$status = array();
		$type = $this->display->registration_type( $post_id );
		$permalink = (string) get_permalink( $post_id );
		if ( $start < time() || 'no_registration' === $type ) {
			$status = array();
		} elseif ( 'external_registration' === $type ) {
			$url = esc_url_raw( (string) get_post_meta( $post_id, 'external_registration_url', true ), array( 'http', 'https' ) );
			if ( $url ) $status = array( 'state' => 'external', 'label' => 'Register on the organiser’s website', 'cta_label' => 'Register', 'cta_url' => $url, 'external' => true );
		} elseif ( 'confirmed' === $this->display->date_status( $post_id ) ) {
			$phase = $this->display->registration_phase( $post_id );
			$enabled = Event_Public_Display::normalise_flag( get_post_meta( $post_id, 'registration_enabled', true ), true );
			if ( ! $enabled || 'closed' === $phase ) {
				$status = array( 'state' => 'closed', 'label' => 'Registrations closed' );
			} elseif ( 'upcoming' === $phase ) {
				$opens = Event_Datetime::timestamp( get_post_meta( $post_id, 'registration_open', true ) );
				$status = array( 'state' => 'upcoming', 'label' => $opens ? 'Registrations open ' . wp_date( 'j F', $opens, wp_timezone() ) : 'Registrations open soon' );
				if ( $this->display->show_interest_contact_button( $post_id ) ) {
					$status['cta_label'] = 'Express interest';
					$status['cta_url'] = (string) apply_filters( 'hherm/expression_of_interest_url', $permalink . '#event-expression-interest', $post_id );
				}
			} elseif ( $this->is_full( $post_id ) ) {
				$waitlist = Event_Public_Display::normalise_flag( get_post_meta( $post_id, 'allow_waitlist', true ), false );
				$status = $waitlist
					? array( 'state' => 'waitlist', 'label' => 'Fully booked – waitlist open', 'cta_label' => 'Join the waitlist', 'cta_url' => $permalink . '#event-registration' )
					: array( 'state' => 'full', 'label' => 'Fully booked' );
			} else {
				$status = array( 'state' => 'open', 'label' => 'Registrations open', 'cta_label' => 'Register', 'cta_url' => $permalink . '#event-registration' );
			}
		}
		return $this->status_cache[ $post_id ] = $status;
	}

	/** Only claims "full" when capacity tracking is configured and a positive capacity has no places left. */
	private function is_full( int $post_id ): bool {
		$attendee_key = sanitize_key( (string) $this->settings->get( 'attendee_count_field', '' ) );
		$remaining_key = sanitize_key( (string) $this->settings->get( 'event_remaining_capacity', '' ) );
		if ( ! $attendee_key || ! $remaining_key ) return false;
		$total = absint( get_post_meta( $post_id, 'event_capacity', true ) );
		if ( ! $total ) return false;
		$remaining = get_post_meta( $post_id, $remaining_key, true );
		return '' !== (string) $remaining && is_numeric( $remaining ) && (int) $remaining <= 0;
	}

	/**
	 * First non-cancelled event from $from_month onward, scanning at most twelve navigable months.
	 */
	private function next_event_after( \DateTimeImmutable $from_month, int $max_index, string $filter = 'all' ): array {
		$month = $from_month->modify( 'first day of this month 00:00:00' );
		for ( $scanned = 0; $scanned < 12; $scanned++, $month = $month->modify( '+1 month' ) ) {
			if ( (int) $month->format( 'Y' ) * 12 + (int) $month->format( 'n' ) > $max_index ) break;
			foreach ( $this->events_for_range( $month, $month->modify( 'first day of next month' ), $filter ) as $day_events ) {
				foreach ( $day_events as $event ) {
					if ( ! $event['cancelled'] && ! $event['completed'] ) return array( 'title' => $event['title'], 'start' => $event['start'], 'month' => $month->format( 'Y-m' ), 'venue' => $event['venue'], 'url' => $event['url'] );
				}
			}
		}
		return array();
	}

	private function event_classes( array $event ): string {
		$classes = array( 'hherm-calendar__event', 'hherm-calendar__event--' . sanitize_html_class( $this->category( $event ) ) );
		if ( $event['cancelled'] ) $classes[] = 'is-cancelled';
		if ( $event['completed'] ) $classes[] = 'is-completed';
		if ( 'single' !== $event['segment'] ) $classes[] = 'is-multi-day is-' . $event['segment'];
		return implode( ' ', $classes );
	}

	/** Opening hours are background information; everything else gets a full entry. */
	private function is_prominent( array $event ): bool {
		return 'opening_hours' !== $event['type'] || $event['cancelled'];
	}

	private function category( array $event ): string {
		return ! empty( $event['fundraiser'] ) ? 'fundraiser' : $event['type'];
	}

	private function category_label( array $event ): string {
		$labels = array( 'fundraiser' => 'Fundraiser', 'opening_hours' => 'Opening hours', 'community_visit' => 'Community visit', 'speech' => 'Speech' );
		return $labels[ $this->category( $event ) ] ?? 'Event';
	}

	/**
	 * Key entries for the categories shown this month, in a fixed order.
	 *
	 * @return array<string, string> Legend item class => label.
	 */
	private function legend( array $month_events ): array {
		$found = array();
		foreach ( $month_events as $day_events ) {
			foreach ( $day_events as $event ) {
				$found[ $event['completed'] ? 'completed' : $this->category( $event ) ] = true;
				if ( $event['raffle'] && ! $event['completed'] ) $found['raffle'] = true;
			}
		}
		$labels = array(
			'event'           => 'Workshops & events',
			'fundraiser'      => 'Fundraisers',
			'raffle'          => 'Raffle available',
			'community_visit' => 'Community visits',
			'speech'          => 'Speeches',
			'opening_hours'   => 'Hub opening hours',
			'completed'       => 'Completed',
		);
		$legend = array();
		foreach ( $labels as $key => $label ) {
			if ( isset( $found[ $key ] ) ) $legend[ 'is-' . $key ] = $label;
		}
		return count( $legend ) > 1 ? $legend : array();
	}

	/** Decorative inline icons; the markup is fixed, so it is echoed as-is. */
	private function icon( string $name ): void {
		$paths = array(
			'previous' => '<path d="M15 18l-6-6 6-6"/>',
			'next'     => '<path d="M9 18l6-6-6-6"/>',
			'check'    => '<path d="M20 6L9 17l-5-5"/>',
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'pin'      => '<path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
			'heart'    => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) return;
		echo '<svg class="hherm-calendar__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup from the list above.
	}

	private function completed_label( array $event, string $format ): string {
		return 'Completed ' . wp_date( $format, $event['finished'], wp_timezone() );
	}

	/**
	 * Fundraising events, including those moved to Past Fundraisers, and any event offering a raffle.
	 */
	private function is_fundraiser( int $post_id ): bool {
		if ( Event_Public_Display::normalise_flag( get_post_meta( $post_id, 'raffle', true ), false ) ) return true;
		if ( null === $this->fundraiser_terms ) {
			$this->fundraiser_terms = array();
			$types = Event_Types::terms();
			if ( ! empty( $types['fundraising'] ) ) $this->fundraiser_terms[] = (int) $types['fundraising'];
			$past = get_term_by( 'slug', 'past-fundraisers', 'event-category' ) ?: get_term_by( 'name', 'Past Fundraisers', 'event-category' );
			if ( $past && ! is_wp_error( $past ) ) $this->fundraiser_terms[] = (int) $past->term_id;
		}
		return $this->fundraiser_terms && has_term( $this->fundraiser_terms, 'event-category', $post_id );
	}

	private function time_label( array $event ): string {
		$timezone = wp_timezone();
		$start = wp_date( Event_Datetime::TIME_FORMAT, $event['start'], $timezone );
		$end = $event['end'] > $event['start'] ? wp_date( Event_Datetime::TIME_FORMAT, $event['end'], $timezone ) : '';
		if ( 'start' === $event['segment'] ) return 'From ' . $start;
		if ( 'middle' === $event['segment'] ) return 'Continues all day';
		if ( 'end' === $event['segment'] ) return $end ? 'Until ' . $end : 'Final day';
		return $end ? $start . ' – ' . $end : $start;
	}

	private function render_dots( array $day_events ): void {
		echo '<span class="hherm-calendar__dots" aria-hidden="true">';
		foreach ( array_slice( $day_events, 0, 3 ) as $event ) {
			echo '<span class="' . esc_attr( 'hherm-calendar__dot hherm-calendar__event--' . sanitize_html_class( $this->category( $event ) ) . ( $event['cancelled'] ? ' is-cancelled' : '' ) . ( $event['completed'] ? ' is-completed' : '' ) ) . '"></span>';
		}
		if ( count( $day_events ) > 3 ) echo '<span class="hherm-calendar__dot-more">+' . esc_html( (string) ( count( $day_events ) - 3 ) ) . '</span>';
		echo '</span>';
	}

	private function render_status( array $event, string $details_url = '' ): void {
		$status = $event['status'];
		if ( ! empty( $status['label'] ) ) {
			echo '<span class="' . esc_attr( 'hherm-calendar__status-label is-' . sanitize_html_class( $status['state'] ) ) . '">' . esc_html( $status['label'] ) . '</span>';
		}
		$has_cta = ! empty( $status['cta_url'] ) && ! empty( $status['cta_label'] );
		if ( ! $has_cta && ! $details_url ) return;
		echo '<span class="hherm-calendar__actions">';
		if ( $has_cta ) {
			echo '<a class="hherm-calendar__cta" href="' . esc_url( $status['cta_url'] ) . '"' . ( ! empty( $status['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $status['cta_label'] ) . '</a>';
		}
		if ( $details_url ) echo '<a class="hherm-calendar__popup-link" href="' . esc_url( $details_url ) . '">Event details</a>';
		echo '</span>';
	}

	private function render_event( array $event, array $options ): void {
		$timezone = wp_timezone();
		$time = $this->time_label( $event );
		$popup_id = 'hherm-calendar-event-' . absint( $event['id'] ) . '-' . $event['start'] . '-' . $event['segment'];
		$label = trim( ( $event['cancelled'] ? 'Cancelled: ' : '' ) . ( $options['show_times'] ? $time . ' ' : '' ) . $event['title'] );
		if ( $event['completed'] && 'opening_hours' === $event['type'] ) :
			// Past opening hours stay a quiet slim line rather than a completed card on every open day.
			?>
			<span class="hherm-calendar__event-label hherm-calendar__event-completed is-slim"><span class="hherm-calendar__event-name"><span class="hherm-calendar__dot-inline" aria-hidden="true"></span><?php echo esc_html( $event['title'] ); ?></span></span>
			<?php
		elseif ( $event['completed'] ) :
			// Past events are a static record: no popup, link or registration details.
			?>
			<span class="hherm-calendar__event-label hherm-calendar__event-completed">
				<span class="hherm-calendar__event-time"><?php $this->icon( 'check' ); ?><?php echo esc_html( $this->completed_label( $event, 'j M' ) ); ?></span>
				<span class="hherm-calendar__event-name"><?php echo esc_html( $event['title'] ); ?></span>
			</span>
			<?php
		elseif ( $options['show_popups'] ) :
			if ( $event['multi_day'] ) {
				$when = wp_date( 'D j M, ' . Event_Datetime::TIME_FORMAT, $event['start'], $timezone ) . ' – ' . wp_date( 'D j M, ' . Event_Datetime::TIME_FORMAT, $event['end'], $timezone );
				$when_time = '';
			} else {
				$when = wp_date( 'l j F Y', $event['start'], $timezone );
				$when_time = $this->time_label( $event );
			}
			$slim = 'opening_hours' === $event['type'] && ! $event['cancelled'];
			?>
			<div class="hherm-calendar__event-card" data-hherm-event-card>
				<button class="hherm-calendar__event-trigger" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $popup_id ); ?>" data-hherm-event-trigger>
					<?php if ( $slim ) : ?>
					<span class="hherm-calendar__event-name"><span class="hherm-calendar__dot-inline" aria-hidden="true"></span><?php echo esc_html( $event['title'] ); ?><?php if ( $options['show_times'] ) : ?> <span class="hherm-calendar__event-slim-time"><?php echo esc_html( $time ); ?></span><?php endif; ?></span>
					<?php else : ?>
					<span class="hherm-calendar__event-time"><span class="hherm-calendar__dot-inline" aria-hidden="true"></span><?php echo esc_html( $event['cancelled'] ? 'Cancelled' : ( $options['show_times'] ? $time : $this->category_label( $event ) ) ); ?></span>
					<span class="hherm-calendar__event-name"><?php echo esc_html( $event['title'] ); ?></span>
					<?php if ( $event['raffle'] ) : ?><span class="hherm-calendar__tag">Raffle</span><?php endif; ?>
					<?php endif; ?>
				</button>
				<div class="hherm-calendar__popup" id="<?php echo esc_attr( $popup_id ); ?>" role="region" aria-label="Details for <?php echo esc_attr( $event['title'] ); ?>" hidden>
					<span class="hherm-calendar__popup-category"><?php echo esc_html( $this->category_label( $event ) ); ?><?php if ( $event['raffle'] ) : ?> · Raffle<?php endif; ?></span>
					<strong class="hherm-calendar__popup-title"><?php echo esc_html( $event['title'] ); ?></strong>
					<span class="hherm-calendar__popup-row"><?php $this->icon( 'calendar' ); ?><time datetime="<?php echo esc_attr( wp_date( 'c', $event['start'], $timezone ) ); ?>"><?php echo esc_html( $when ); ?></time></span>
					<?php if ( $when_time ) : ?><span class="hherm-calendar__popup-row hherm-calendar__popup-time"><?php $this->icon( 'clock' ); ?><?php echo esc_html( $when_time ); ?></span><?php endif; ?>
					<?php if ( $event['venue'] ) : ?><span class="hherm-calendar__popup-row hherm-calendar__popup-location"><?php $this->icon( 'pin' ); ?><?php echo esc_html( $event['venue'] ); ?></span><?php endif; ?>
					<?php if ( $event['description'] ) : ?><span class="hherm-calendar__popup-description"><?php echo esc_html( $event['description'] ); ?></span><?php endif; ?>
					<?php $this->render_status( $event, (string) $event['url'] ); ?>
				</div>
			</div>
			<?php
		else :
			if ( $event['url'] ) : ?><a class="hherm-calendar__event-link" href="<?php echo esc_url( $event['url'] ); ?>"><?php echo esc_html( $label ); ?></a><?php else : ?><span class="hherm-calendar__event-label"><?php echo esc_html( $label ); ?></span><?php endif;
		endif;
	}

	private function local_timestamp( string $value ): int {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $value, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i' ) === $value ? $date->getTimestamp() : 0;
	}

	private function valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return false;
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		return $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $parsed->format( 'Y-m-d' ) === $date;
	}

	private function weekdays( int $week_start ): array {
		$all = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
		if ( 1 === $week_start ) {
			array_shift( $all );
			$all[] = 'Sun';
		}
		return $all;
	}

	private function post_type(): string {
		return sanitize_key( (string) $this->settings->get( 'events_cpt', 'events' ) );
	}
}
