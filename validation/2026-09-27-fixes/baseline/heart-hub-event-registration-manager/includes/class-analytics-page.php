<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Analytics_Page {
	private $settings;
	private $repository;

	public function __construct( Settings $settings, CCT_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-protected, read-only analytics filters; no data is changed.
	public function render(): void {
		$this->require_access();
		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '6';
		$period = in_array( $period, array( '6', '12', 'all' ), true ) ? $period : '6';
		$focus_event = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$date_from = $this->filter_date( 'date_from' );
		$date_to = $this->filter_date( 'date_to' );
		$events = get_posts( array( 'post_type' => $this->settings->get( 'events_cpt', 'events' ), 'post_status' => array( 'publish', 'private', 'draft', 'future' ), 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false, 'meta_query' => array( array( 'key' => Calendar_Schedule::TYPE_META, 'compare' => 'NOT EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Excludes calendar schedule entries, which are not bookable events.
		$field = sanitize_key( $this->settings->get( 'feedback_field', 'event_feedback' ) );
		$since = 'all' === $period || $date_from || $date_to ? 0 : ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . absint( $period ) . ' months' )->getTimestamp();
		$totals = array( 'approved' => 0, 'attended' => 0, 'partial' => 0, 'no-show' => 0, 'unknown' => 0, 'feedback' => 0, 'scores' => array() );
		$event_metrics = array();
		$comments = array();
		foreach ( $events as $event ) {
			if ( $focus_event && $focus_event !== (int) $event->ID ) continue;
			$start_raw = (string) get_post_meta( $event->ID, $this->settings->get( 'event_start', 'start_date' ), true );
			$start = $this->event_timestamp( $start_raw );
			$event_date = $start ? wp_date( 'Y-m-d', $start, wp_timezone() ) : '';
			// Events that have not started yet have no attendance to report.
			if ( ! $focus_event && $start > time() ) continue;
			if ( $since && ( ! $start || $start < $since ) ) continue;
			if ( $date_from && ( ! $event_date || $event_date < $date_from ) ) continue;
			if ( $date_to && ( ! $event_date || $event_date > $date_to ) ) continue;
			$items = $this->repository->event_registrations( (int) $event->ID );
			if ( is_wp_error( $items ) ) continue;
			$metric = array( 'id' => (int) $event->ID, 'name' => $this->event_label( (int) $event->ID ), 'approved' => 0, 'attended' => 0, 'feedback' => 0, 'average' => 0 );
			$scores = array();
			foreach ( $items as $item ) {
				$status = sanitize_key( $item['attendance_status'] ?? 'unknown' );
				if ( ! isset( $totals[ $status ] ) ) $status = 'unknown';
				if ( 'approved' === ( $item['registration_status'] ?? '' ) ) {
					++$totals[ $status ];
					++$totals['approved'];
					++$metric['approved'];
					if ( in_array( $status, array( 'attended', 'partial' ), true ) ) ++$metric['attended'];
				}
				$feedback = $field ? trim( (string) ( $item[ $field ] ?? '' ) ) : '';
				$score = absint( $item['feedback_score'] ?? 0 );
				if ( $feedback || ( $score >= 1 && $score <= 5 ) ) {
					++$metric['feedback'];
					++$totals['feedback'];
					$comments[] = array( 'name' => trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ), 'event' => $this->event_label( (int) $event->ID ), 'comment' => $feedback, 'score' => $score );
				}
				if ( $score >= 1 && $score <= 5 ) { $scores[] = $score; $totals['scores'][] = $score; }
			}
			$metric['average'] = $scores ? round( array_sum( $scores ) / count( $scores ), 1 ) : 0;
			$event_metrics[] = $metric;
		}
		$totals['attendance_rate'] = $totals['approved'] ? (int) round( ( $totals['attended'] + $totals['partial'] ) / $totals['approved'] * 100 ) : 0;
		$totals['no_show_rate'] = $totals['approved'] ? (int) round( $totals['no-show'] / $totals['approved'] * 100 ) : 0;
		$attendee_total = $totals['attended'] + $totals['partial'];
		$totals['feedback_rate'] = $attendee_total ? (int) round( $totals['feedback'] / $attendee_total * 100 ) : 0;
		$totals['average'] = $totals['scores'] ? round( array_sum( $totals['scores'] ) / count( $totals['scores'] ), 1 ) : 0;
		$distribution = array( 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 );
		foreach ( $totals['scores'] as $score ) ++$distribution[ $score ];
		?>
		<div class="wrap hherm-attendance-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Feedback &amp; Analytics</h1><p>Review attendance and feedback trends across recent events.</p></div><div class="hherm-page-actions"><button type="button" class="button" data-export-analytics>Export CSV</button></div></header><hr class="wp-header-end"><form method="get" class="hherm-analytics-filters" aria-label="Filter event analytics"><input type="hidden" name="page" value="<?php echo esc_attr( Checkin_Manager::PAGE_SLUG ); ?>"><label>Event<select name="event_id" aria-label="Event"><option value="0">All events</option><?php foreach ( $events as $event_option ) : ?><option value="<?php echo esc_attr( $event_option->ID ); ?>" <?php selected( $focus_event, $event_option->ID ); ?>><?php echo esc_html( $this->event_label( (int) $event_option->ID ) ); ?></option><?php endforeach; ?></select></label><label>Period<select name="period" aria-label="Analytics period"><option value="6" <?php selected( $period, '6' ); ?>>Last 6 months</option><option value="12" <?php selected( $period, '12' ); ?>>Last 12 months</option><option value="all" <?php selected( $period, 'all' ); ?>>All time</option></select></label><label>Event date from <input type="text" placeholder="DD/MM/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" name="date_from" value="<?php echo esc_attr( Event_Datetime::display( $date_from, 'd/m/Y' ) ); ?>"></label><label>to <input type="text" placeholder="DD/MM/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" name="date_to" value="<?php echo esc_attr( Event_Datetime::display( $date_to, 'd/m/Y' ) ); ?>"></label><div class="hherm-analytics-filter-actions"><button class="button button-primary">Apply filters</button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Checkin_Manager::PAGE_SLUG . '&period=all' ) ); ?>">All events / all time</a></div><p class="hherm-analytics-filter-help">Custom dates use the event start date and override the selected period.</p></form>
			<div class="hherm-metric-grid"><div><strong><?php echo esc_html( $totals['attendance_rate'] ); ?>%</strong><span>Attendance rate · selected events</span></div><div><strong><?php echo esc_html( $totals['no_show_rate'] ); ?>%</strong><span>No-show rate</span></div><div><strong><?php echo esc_html( $totals['feedback'] ); ?></strong><span>Feedback responses</span></div><div><strong><?php echo esc_html( $totals['average'] ?: '—' ); ?></strong><span>Average rating · out of 5</span></div></div>
			<div class="hherm-analytics-grid"><section class="hherm-list-card"><div class="hherm-card-heading"><h2>Attendance rate by event</h2><span><?php echo esc_html( count( $event_metrics ) ); ?> events</span></div><div class="hherm-analytics-list"><?php foreach ( $event_metrics as $metric ) : $rate = $metric['approved'] ? (int) round( $metric['attended'] / $metric['approved'] * 100 ) : 0; ?><div class="hherm-analytics-row" data-event="<?php echo esc_attr( $metric['name'] ); ?>" data-rate="<?php echo esc_attr( $rate ); ?>" data-registrations="<?php echo esc_attr( $metric['approved'] ); ?>" data-average="<?php echo esc_attr( $metric['average'] ); ?>"><div><strong><a href="<?php echo esc_url( add_query_arg( 'event_id', $metric['id'], admin_url( 'admin.php?page=' . Event_Manager::LIST_PAGE_SLUG ) ) ); ?>"><?php echo esc_html( $metric['name'] ); ?></a></strong><small><?php echo esc_html( $metric['approved'] ); ?> registrations · avg <?php echo esc_html( $metric['average'] ?: '—' ); ?></small></div><b><?php echo esc_html( $rate ); ?>%</b><div class="hherm-meter"><span style="width:<?php echo esc_attr( $rate ); ?>%"></span></div></div><?php endforeach; if ( ! $event_metrics ) : ?><p class="hherm-empty-cell">No attendance data for this period.</p><?php endif; ?></div></section><section class="hherm-list-card"><div class="hherm-card-heading"><h2>Rating distribution</h2><span><?php echo esc_html( count( $totals['scores'] ) ); ?> ratings</span></div><div class="hherm-rating-list"><?php foreach ( $distribution as $stars => $count ) : ?><div><span><?php echo esc_html( $stars ); ?> stars</span><i><b style="width:<?php echo esc_attr( $totals['scores'] ? $count / count( $totals['scores'] ) * 100 : 0 ); ?>%"></b></i><strong><?php echo esc_html( $count ); ?></strong></div><?php endforeach; ?></div><p class="hherm-analytics-footnote">Feedback is linked to each event through its registration records. Attendance rates count approved registrations, including partial attendance. Unrecorded attendance is not a confirmed no-show.</p></section></div>
			<section class="hherm-list-card hherm-comments-card"><div class="hherm-card-heading"><h2>Feedback comments</h2><span><?php echo esc_html( count( $comments ) ); ?> responses</span></div><div class="hherm-comments-list"><?php foreach ( $comments as $comment ) : ?><article><div><strong><?php echo esc_html( $comment['name'] ?: 'Anonymous attendee' ); ?></strong><small><?php echo esc_html( $comment['event'] ); ?></small></div><?php if ( $comment['score'] ) : ?><b><?php echo esc_html( $comment['score'] ); ?>/5</b><?php endif; ?><p><?php echo esc_html( $comment['comment'] ?: 'Rating only; no comments provided.' ); ?></p></article><?php endforeach; if ( ! $comments ) : ?><p class="hherm-empty-cell">No comments have been submitted for this period.</p><?php endif; ?></div></section>
		</div><?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Validates read-only analytics filters; no data is changed.
	private function filter_date( string $key ): string {
		$raw = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		return Event_Datetime::date_storage( $raw );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function event_timestamp( string $raw ): int {
		if ( ctype_digit( $raw ) ) return (int) $raw;
		foreach ( array( 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $raw, wp_timezone() );
			if ( $date && $date->format( $format ) === $raw ) return $date->getTimestamp();
		}
		return 0;
	}

	private function event_label( int $event_id ): string {
		$name = (string) get_post_meta( $event_id, 'event_name', true );
		return $name ?: get_the_title( $event_id ) ?: 'Untitled event';
	}

	private function require_access(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) wp_die( esc_html__( 'You do not have permission to view event analytics.', 'heart-hub-event-registration-manager' ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) );
	}
}
