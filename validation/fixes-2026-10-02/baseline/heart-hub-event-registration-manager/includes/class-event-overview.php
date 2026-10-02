<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Event_Overview {
	private const REGISTRATION_LABELS = array( 'interest' => 'Expression of interest', 'pending' => 'Pending review', 'waitlist' => 'Waitlist', 'approved' => 'Approved', 'declined' => 'Declined' );
	private const ATTENDANCE_LABELS = array( 'attended' => 'Attended', 'partial' => 'Partially attended', 'no-show' => 'No-show', 'unknown' => 'Unrecorded' );

	private $settings;
	private $repository;
	private $list_page;

	public function __construct( Settings $settings, CCT_Repository $repository, string $list_page = '' ) {
		$this->settings = $settings;
		$this->repository = $repository;
		$this->list_page = sanitize_key( $list_page ) ?: Event_Manager::LIST_PAGE_SLUG;
	}

	public function render( int $event_id ): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) wp_die( 'Access denied.' );
		$event = get_post( $event_id );
		if ( ! $event || 'trash' === $event->post_status || $event->post_type !== $this->settings->get( 'events_cpt', 'events' ) ) {
			$this->render_problem( 'Event unavailable', 'This event could not be found. It may have been moved to the trash.' );
			return;
		}
		$items = $this->repository->event_registrations( $event_id );
		if ( is_wp_error( $items ) ) {
			$this->render_problem( 'Registrations unavailable', $items->get_error_message() );
			return;
		}
		$party_key = $this->settings->get( 'attendee_count_field', 'number_of_attendees' ) ?: 'number_of_attendees';
		$feedback_key = $this->settings->get( 'feedback_field', 'event_feedback' ) ?: 'event_feedback';
		$counts = array( 'interest' => 0, 'pending' => 0, 'waitlist' => 0, 'approved' => 0, 'declined' => 0, 'attended' => 0, 'partial' => 0, 'no-show' => 0, 'unknown' => 0 );
		$feedback = array();
		$scores = array();
		$places = 0;
		$present = 0;
		foreach ( $items as $item ) {
			$status = $item['registration_status'] ?? 'pending';
			if ( in_array( $status, array( 'interest', 'pending', 'waitlist', 'approved', 'declined' ), true ) ) ++$counts[ $status ];
			if ( 'approved' === $status ) {
				$attendance = $this->attendance( $item );
				++$counts[ $attendance ];
				$party = max( 1, (int) ( $item[ $party_key ] ?? 1 ) );
				$places += $party;
				if ( 'attended' === $attendance ) $present += $party;
				elseif ( 'partial' === $attendance && ! empty( $item['checked_in_party_size'] ) ) $present += min( $party, max( 1, (int) $item['checked_in_party_size'] ) );
			}
			$score = (int) ( $item['feedback_score'] ?? 0 );
			$comment = trim( (string) ( $item[ $feedback_key ] ?? '' ) );
			if ( $score >= 1 && $score <= 5 ) $scores[] = $score;
			if ( $comment || ( $score >= 1 && $score <= 5 ) ) $feedback[] = array( 'item' => $item, 'score' => $score, 'comment' => $comment );
		}
		$name = get_post_meta( $event_id, 'event_name', true ) ?: $event->post_title;
		$start = get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true );
		$end = get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true );
		$description = get_post_meta( $event_id, $this->settings->get( 'event_info', '_description' ), true ) ?: $event->post_content;
		$date_status = Event_Public_Display::normalise_date_status( get_post_meta( $event_id, 'event_date_status', true ), get_post_meta( $event_id, 'event_schedule_status', true ) );
		$registration_type = Event_Public_Display::normalise_registration_type( get_post_meta( $event_id, 'registration_type', true ) );
		$registration_labels = array( 'website_registration' => 'Website Registration', 'external_registration' => 'External Registration', 'no_registration' => 'No Registration' );
		$details = array(
			'Status' => in_array( (string) get_post_meta( $event_id, 'event_cancelled', true ), array( 'true', '1' ), true ) ? 'Cancelled' : ( array( 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending review', 'private' => 'Private', 'future' => 'Scheduled' )[ $event->post_status ] ?? ucfirst( $event->post_status ) ),
			'Event date status' => 'tbc' === $date_status ? 'Date TBC' : 'Date Confirmed',
			'Start' => $this->date( $start ), 'End' => $this->date( $end ),
			'Venue' => get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true ),
			'Organiser' => get_post_meta( $event_id, $this->settings->get( 'event_organiser', 'organiser' ), true ),
			'Address' => get_post_meta( $event_id, 'address', true ),
			'Registration opens' => $this->date( get_post_meta( $event_id, 'registration_open', true ) ),
			'Registration closes' => $this->date( get_post_meta( $event_id, 'registration_close', true ) ),
			'Co-hosted event' => in_array( strtolower( (string) get_post_meta( $event_id, 'co_hosted_event', true ) ), array( '1', 'true', 'yes', 'on' ), true ) ? 'Yes' : 'No',
			'Presenters' => get_post_meta( $event_id, 'presenters', true ),
			'Capacity' => get_post_meta( $event_id, 'event_capacity', true ),
			'Registration type' => $registration_labels[ $registration_type ],
			'External registration URL' => 'external_registration' === $registration_type ? get_post_meta( $event_id, 'external_registration_url', true ) : '',
			'Registration enabled' => Event_Public_Display::website_registration_available( $registration_type, $date_status ) && Event_Public_Display::normalise_flag( get_post_meta( $event_id, 'registration_enabled', true ), true ) ? 'Yes' : 'No',
			'Show Contact Us for interest' => Event_Public_Display::interest_contact_available( $registration_type, $date_status ) && Event_Public_Display::normalise_flag( get_post_meta( $event_id, 'show_interest_contact_button', true ), false ) ? 'Yes' : 'No',
			'Show remaining spots' => Event_Public_Display::website_registration_available( $registration_type, $date_status ) && Event_Public_Display::normalise_flag( get_post_meta( $event_id, 'show_remaining_spots', true ), true ) ? 'Yes' : 'No',
			'Show What to Expect' => Event_Public_Display::normalise_flag( get_post_meta( $event_id, 'show_what_to_expect', true ), true ) ? 'Yes' : 'No',
			'Parking & access' => get_post_meta( $event_id, 'parking_access', true ),
		);
		$terms = get_the_terms( $event_id, 'event-category' );
		$types = Event_Types::terms();
		$is_fundraising = $types['fundraising'] && has_term( $types['fundraising'], 'event-category', $event_id );
		if ( $is_fundraising ) {
			$amount_raised = trim( (string) get_post_meta( $event_id, 'amount_raised', true ) );
			$details['Amount raised'] = is_numeric( $amount_raised ) ? '$' . number_format_i18n( (float) $amount_raised, 2 ) : 'Not recorded';
			if ( Event_Public_Display::normalise_flag( get_post_meta( $event_id, 'external_fee_enabled', true ), false ) ) {
				$fee = get_post_meta( $event_id, 'external_fee_amount', true );
				$details['External entry fee'] = is_numeric( $fee ) ? '$' . number_format_i18n( (float) $fee, 2 ) . ' (paid to the venue, not Heart Hub)' : 'Paid to the venue';
				$details['External fee information'] = get_post_meta( $event_id, 'external_fee_url', true );
			} else {
				$details['Registration fee'] = get_post_meta( $event_id, 'registration_fee', true );
				$details['Sponsorship cost'] = get_post_meta( $event_id, 'sponsorship_cost', true );
			}
			$raffle_enabled = in_array( strtolower( (string) get_post_meta( $event_id, 'raffle', true ) ), array( '1', 'true', 'yes', 'on' ), true );
			$raffle_cost = trim( (string) get_post_meta( $event_id, 'raffle_cost', true ) );
			$details['Raffle'] = $raffle_enabled ? ( is_numeric( $raffle_cost ) ? 'Available — $' . number_format_i18n( (float) $raffle_cost, 2 ) : 'Available' ) : 'No';
		}
		$details['Categories'] = $terms && ! is_wp_error( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
		?>
		<div id="hherm-app" class="wrap hherm-events-wrap hherm-app"><div data-event-content>
		<header class="hherm-page-header"><div><span class="hherm-eyebrow">Event overview</span><h1><?php echo esc_html( $name ); ?></h1><p>Event details, registrations, attendance, and guest feedback.</p></div><div class="hherm-event-links">
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->list_page ) ); ?>"><?php echo esc_html( $is_fundraising ? 'Back to fundraisers' : 'Back to events' ); ?></a>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'event_id' => $event_id, 'context' => $is_fundraising ? 'fundraising' : 'event' ), admin_url( 'admin.php?page=' . Event_Manager::PAGE_SLUG ) ) ); ?>"><?php echo esc_html( $is_fundraising ? 'Edit fundraiser' : 'Edit event' ); ?></a>
		<?php if ( 'publish' === $event->post_status ) : ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( get_permalink( $event_id ) ); ?>">View Page</a><?php endif; ?>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'event_id', $event_id, admin_url( 'admin.php?page=' . Attendance_Manager::PAGE_SLUG ) ) ); ?>">Manage attendance &amp; QR code</a>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'event_id' => $event_id, 'period' => 'all' ), admin_url( 'admin.php?page=' . Checkin_Manager::PAGE_SLUG ) ) ); ?>">Event analytics</a>
		</div></header><hr class="wp-header-end">
		<section class="hherm-list-card hherm-event-section"><h2>Event details</h2><dl class="hherm-event-details"><?php foreach ( $details as $label => $value ) : ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( is_scalar( $value ) && '' !== (string) $value ? $value : '—' ); ?></dd></div><?php endforeach; ?></dl><div><?php echo wp_kses_post( $description ); ?></div></section>
		<section class="hherm-list-card hherm-event-section"><h2>Performance</h2><p><?php echo esc_html( sprintf( '%d registrations · %d approved places · %d confirmed people attending · %d feedback responses', count( $items ), $places, $present, count( $feedback ) ) ); ?></p><p><?php echo esc_html( sprintf( 'Expressions of interest: %d · Pending: %d · Waitlist: %d · Approved: %d · Declined: %d', $counts['interest'], $counts['pending'], $counts['waitlist'], $counts['approved'], $counts['declined'] ) ); ?></p><p><?php echo esc_html( sprintf( 'Attendance by registration — All attended: %d · Partially attended: %d · No-show: %d · Unrecorded: %d', $counts['attended'], $counts['partial'], $counts['no-show'], $counts['unknown'] ) ); ?></p><p>Average rating: <strong><?php echo esc_html( $scores ? round( array_sum( $scores ) / count( $scores ), 1 ) . ' / 5' : 'No ratings yet' ); ?></strong></p><p>Unrecorded attendance is shown separately from confirmed no-shows. Partial registrations without a recorded headcount are excluded from confirmed people attending.</p></section>
		<section class="hherm-list-card hherm-event-section"><h2>Registrations &amp; attendance</h2><div class="hherm-table-wrap"><table class="widefat striped"><thead><tr><th>Applicant</th><th>Contact</th><th>Party</th><th>Registration</th><th>Attendance</th><th>Checked in</th><th>Action</th></tr></thead><tbody>
		<?php foreach ( $items as $item ) : $id = absint( $item['_ID'] ?? 0 ); ?><tr><td><?php echo esc_html( trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ) ); ?></td><td><?php echo esc_html( $item['email'] ?? '' ); ?><br><?php echo esc_html( $item['phone'] ?? '' ); ?></td><td><?php echo esc_html( max( 1, (int) ( $item[ $party_key ] ?? 1 ) ) ); ?></td><td><?php $registration_status = sanitize_key( $item['registration_status'] ?? 'pending' ); ?><span class="<?php echo esc_attr( 'hherm-status hherm-status--' . $registration_status ); ?>"><?php echo esc_html( self::REGISTRATION_LABELS[ $registration_status ] ?? ucfirst( $registration_status ) ); ?></span></td><td><?php echo esc_html( 'approved' === ( $item['registration_status'] ?? '' ) ? self::ATTENDANCE_LABELS[ $this->attendance( $item ) ] : '—' ); ?></td><td><?php echo esc_html( ! empty( $item['checked_in_at'] ) ? ( $item['checked_in_party_size'] ?? '—' ) . ' people · ' . Event_Datetime::display( $item['checked_in_at'], Event_Datetime::DATETIME_FORMAT ) : '—' ); ?></td><td><button type="button" class="button button-small hherm-review" data-id="<?php echo esc_attr( $id ); ?>">Open registration</button></td></tr><?php endforeach; ?>
		<?php if ( ! $items ) : ?><tr><td colspan="7">No registrations yet.</td></tr><?php endif; ?></tbody></table></div></section>
		<section class="hherm-list-card hherm-event-section"><h2>Guest feedback &amp; suggestions</h2><?php foreach ( $feedback as $response ) : ?><article class="hherm-event-feedback"><strong><?php echo esc_html( trim( ( $response['item']['first_name'] ?? '' ) . ' ' . ( $response['item']['last_name'] ?? '' ) ) ?: 'Attendee' ); ?></strong><?php if ( $response['score'] >= 1 && $response['score'] <= 5 ) : ?> <span><?php echo esc_html( $response['score'] ); ?>/5</span><?php endif; ?><p><?php echo nl2br( esc_html( $response['comment'] ?: 'Rating only; no comments provided.' ) ); ?></p></article><?php endforeach; ?><?php if ( ! $feedback ) : ?><p>No feedback submitted yet.</p><?php endif; ?></section>
		</div><div class="hherm-modal" hidden><div class="hherm-backdrop" data-close></div><section role="dialog" aria-modal="true" aria-labelledby="hherm-modal-title" tabindex="-1"><button class="hherm-close" type="button" data-close aria-label="Close">×</button><div class="hherm-modal-content"></div></section></div></div>
		<?php
	}

	private function render_problem( string $title, string $message ): void {
		?>
		<div class="wrap hherm-events-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Event overview</span><h1><?php echo esc_html( $title ); ?></h1></div><div class="hherm-page-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->list_page ) ); ?>"><?php echo esc_html( Event_Manager::FUNDRAISER_LIST_PAGE_SLUG === $this->list_page ? 'Back to fundraisers' : 'Back to events' ); ?></a></div></header><hr class="wp-header-end">
			<div class="notice notice-error inline"><p><?php echo esc_html( $message ); ?></p></div>
		</div>
		<?php
	}

	private function attendance( array $item ): string {
		$status = $item['attendance_status'] ?? 'unknown';
		return in_array( $status, array( 'attended', 'partial', 'no-show' ), true ) ? $status : 'unknown';
	}

	private function date( $value ): string {
		return Event_Datetime::display( $value, Event_Datetime::DATETIME_FORMAT );
	}
}
