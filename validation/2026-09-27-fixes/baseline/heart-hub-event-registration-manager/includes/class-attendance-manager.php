<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Attendance_Manager {
	public const PAGE_SLUG = 'hherm-attendance';
	public const ACTION = 'hherm_save_attendance';
	private const FIELD = 'attendance_status';
	private const STATUSES = array( 'attended', 'no-show', 'partial', 'unknown' );

	private $settings;
	private $repository;
	private $audit;
	private $checkin;
	private $checkin_page;

	public function __construct( Settings $settings, CCT_Repository $repository, Audit_Log $audit, Checkin_Manager $checkin, Checkin_Page $checkin_page ) {
		$this->settings = $settings;
		$this->repository = $repository;
		$this->audit = $audit;
		$this->checkin = $checkin;
		$this->checkin_page = $checkin_page;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-protected event selection and notices only; attendance writes use the nonce-verified handle() action.
	public function render(): void {
		$this->require_access();
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$events = $this->events();
		$registrations = array();
		$error = '';
		if ( $event_id ) {
			if ( ! $this->valid_event( $event_id ) ) {
				$error = 'The selected event could not be found.';
			} else {
				$result = $this->repository->attendance_list( $event_id );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$registrations = $result;
				}
			}
		}
		$attendance_counts = array( 'attended' => 0, 'partial' => 0, 'no-show' => 0, 'unknown' => 0, 'places' => 0 );
		$attendee_key = sanitize_key( $this->settings->get( 'attendee_count_field', 'number_of_attendees' ) );
		foreach ( $registrations as $registration ) { $status = sanitize_key( $registration[ self::FIELD ] ?? 'unknown' ); if ( ! isset( $attendance_counts[ $status ] ) ) { $status = 'unknown'; } ++$attendance_counts[ $status ]; $attendance_counts['places'] += max( 1, absint( $registration[ $attendee_key ] ?? 1 ) ); }
		?>
		<div class="wrap hherm-attendance-wrap hherm-checkin-page-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1><?php esc_html_e( 'Attendance & Check-in', 'heart-hub-event-registration-manager' ); ?></h1><p><?php esc_html_e( 'Generate the event-day QR link, monitor live check-ins, and record attendance from one page.', 'heart-hub-event-registration-manager' ); ?></p></div></header><hr class="wp-header-end">
			<?php if ( isset( $_GET['attendance_saved'] ) && ! isset( $_GET['attendance_error'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( '%d attendance records saved.', absint( wp_unslash( $_GET['attendance_saved'] ) ) ) ); ?></p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['attendance_conflicts'] ) ) : ?><div class="notice notice-warning is-dismissible"><p><?php echo esc_html( sprintf( '%d attendance records changed after this register was opened (for example, an attendee self check-in) and were not overwritten. Review them below and save again if needed.', absint( wp_unslash( $_GET['attendance_conflicts'] ) ) ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['checkin_notice'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php $checkin_notice = sanitize_key( wp_unslash( $_GET['checkin_notice'] ) ); echo 'revoked' === $checkin_notice ? 'The attendee check-in link was revoked.' : ( 'regenerated' === $checkin_notice ? 'The old check-in link was revoked and a new one was generated.' : 'The event-day attendee check-in link was created.' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['checkin_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['checkin_error'] ) ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['attendance_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['attendance_error'] ) ) ); ?></p></div><?php endif; ?>
			<?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>

			<form class="hherm-attendance-selector <?php echo $event_id && ! $error ? 'hherm-attendance-selector--selected' : 'hherm-attendance-selector--empty'; ?>" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><label for="hherm-attendance-event"><span>Event</span><select id="hherm-attendance-event" name="event_id" required><option value="">Select an event</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>><?php echo esc_html( $this->event_label( $event->ID ) ); ?></option><?php endforeach; ?></select></label><div class="hherm-attendance-stats"><span><small>Attended</small><strong><?php echo esc_html( $attendance_counts['attended'] ); ?></strong></span><span><small>Partial</small><strong><?php echo esc_html( $attendance_counts['partial'] ); ?></strong></span><span><small>No-show</small><strong><?php echo esc_html( $attendance_counts['no-show'] ); ?></strong></span><span><small>Unrecorded</small><strong><?php echo esc_html( $attendance_counts['unknown'] ); ?></strong></span></div><button class="button<?php echo $event_id && ! $error ? '' : ' button-primary'; ?>"><?php echo $event_id && ! $error ? 'Switch event' : 'View register'; ?></button></form>

			<?php if ( ! $event_id ) : ?><div class="hherm-empty-state"><h2>Choose an event to begin</h2><p><?php echo esc_html( $events ? 'Pick an event above to open its attendance register, event-day QR check-in link and live check-ins.' : 'There are no events yet. Create an event first, then come back here on the day to check people in.' ); ?></p></div><?php endif; ?>
			<?php if ( $event_id && ! $error ) : ?>
				<?php $this->checkin->render_event_panel( $event_id ); ?>
				<?php $this->checkin_page->render_event_content( $event_id ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><?php wp_nonce_field( self::ACTION . '_' . $event_id, 'hherm_attendance_nonce' ); ?>
					<section class="hherm-list-card"><div class="hherm-card-heading"><h2><?php echo esc_html( $this->event_label( $event_id ) ); ?></h2><span><?php echo esc_html( sprintf( '%d approved registrations · %d places reserved', count( $registrations ), $attendance_counts['places'] ) ); ?></span></div><div class="hherm-table-wrap"><table class="widefat fixed striped hherm-attendance-table"><thead><tr><th>Attendee</th><th>Party</th><th>Contact</th><th>Reason for attending</th><th>Attendance</th></tr></thead><tbody>
					<?php if ( $registrations ) : foreach ( $registrations as $registration ) : $id = absint( $registration['_ID'] ?? 0 ); $status = sanitize_key( $registration[ self::FIELD ] ?? 'unknown' ); if ( ! in_array( $status, self::STATUSES, true ) ) $status = 'unknown'; ?>
						<tr><td><strong><?php echo esc_html( trim( ( $registration['first_name'] ?? '' ) . ' ' . ( $registration['last_name'] ?? '' ) ) ?: 'Unnamed attendee' ); ?></strong></td><td><?php echo esc_html( max( 1, absint( $registration[ $attendee_key ] ?? 1 ) ) ); ?></td><td><strong><?php echo esc_html( $registration['phone'] ?? '—' ); ?></strong><?php if ( ! empty( $registration['email'] ) ) : ?><small><a href="mailto:<?php echo esc_attr( sanitize_email( $registration['email'] ) ); ?>"><?php echo esc_html( sanitize_email( $registration['email'] ) ); ?></a></small><?php endif; ?></td><td class="hherm-attendance-reason"><?php echo esc_html( $registration['reason_for_attending'] ?? '—' ); ?></td><td><input type="hidden" name="attendance_original[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $status ); ?>"><fieldset class="hherm-attendance-segments"><legend class="screen-reader-text">Attendance status</legend><?php foreach ( self::STATUSES as $option ) : ?><label class="hherm-attendance-segment hherm-attendance-segment--<?php echo esc_attr( $option ); ?>"><input type="radio" name="attendance[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $option ); ?>" <?php checked( $status, $option ); ?>><span><?php echo esc_html( $this->status_label( $option ) ); ?></span></label><?php endforeach; ?></fieldset></td></tr>
					<?php endforeach; else : ?><tr><td colspan="5" class="hherm-empty-cell">No approved registrations for this event.</td></tr><?php endif; ?>
					</tbody></table></div></section>
					<?php if ( $registrations ) : ?><div class="hherm-attendance-submit"><p>Nothing changes until you press Save attendance.</p><button class="button button-primary button-large">Save attendance</button></div><?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function handle(): void {
		$this->require_access();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $event_id, 'hherm_attendance_nonce' );
		if ( ! $this->valid_event( $event_id ) ) {
			$this->redirect( $event_id, 0, 'The selected event could not be found.' );
		}
		$submitted = isset( $_POST['attendance'] ) && is_array( $_POST['attendance'] ) ? map_deep( wp_unslash( $_POST['attendance'] ), 'sanitize_key' ) : array();
		// Statuses displayed when the register was opened; rows left untouched must not overwrite later check-ins.
		$originals = isset( $_POST['attendance_original'] ) && is_array( $_POST['attendance_original'] ) ? map_deep( wp_unslash( $_POST['attendance_original'] ), 'sanitize_key' ) : array();
		$saved = 0;
		$failed = 0;
		$conflicts = 0;
		foreach ( $submitted as $registration_id => $raw_status ) {
			if ( ! is_scalar( $raw_status ) ) {
				++$failed;
				continue;
			}
			$registration_id = absint( $registration_id );
			$status = sanitize_key( $raw_status );
			if ( ! $registration_id || ! in_array( $status, self::STATUSES, true ) ) {
				++$failed;
				continue;
			}
			$original = isset( $originals[ $registration_id ] ) && is_scalar( $originals[ $registration_id ] ) ? $this->normalise_status( $originals[ $registration_id ] ) : null;
			if ( $status === $original ) {
				continue;
			}
			$item = $this->repository->get( $registration_id );
			if ( is_wp_error( $item ) || $event_id !== absint( $item['event_id'] ?? 0 ) || 'approved' !== sanitize_key( $item['registration_status'] ?? '' ) ) {
				++$failed;
				continue;
			}
			$previous = $this->normalise_status( $item[ self::FIELD ] ?? '' );
			if ( $previous === $status ) {
				continue;
			}
			if ( null !== $original && $previous !== $original ) {
				++$conflicts;
				continue;
			}
			$result = $this->repository->update_attendance( $registration_id, $status );
			if ( is_wp_error( $result ) ) {
				++$failed;
				continue;
			}
			++$saved;
			$this->audit->write( $registration_id, 'attendance', $status, array( 'event_id' => $event_id, 'previous_status' => $previous ) );
		}
		$this->redirect( $event_id, $saved, $failed ? sprintf( '%d records saved; %d attendance records could not be saved.', $saved, $failed ) : '', $conflicts );
	}

	private function normalise_status( $status ): string {
		$status = sanitize_key( (string) $status );
		return in_array( $status, self::STATUSES, true ) ? $status : 'unknown';
	}

	private function events(): array {
		return get_posts( array( 'post_type' => $this->settings->get( 'events_cpt', 'events' ), 'post_status' => array( 'publish', 'private', 'draft', 'future' ), 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false, 'meta_query' => array( array( 'key' => Calendar_Schedule::TYPE_META, 'compare' => 'NOT EXISTS' ) ) ) );
	}

	private function valid_event( int $event_id ): bool {
		$post = get_post( $event_id );
		return $post && 'trash' !== $post->post_status && $this->settings->get( 'events_cpt', 'events' ) === $post->post_type && ! metadata_exists( 'post', $event_id, Calendar_Schedule::TYPE_META );
	}

	private function event_label( int $event_id ): string {
		$name = (string) get_post_meta( $event_id, 'event_name', true );
		$name = $name ?: get_the_title( $event_id ) ?: 'Untitled event';
		$date = (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true );
		$date = Event_Datetime::display( $date, Event_Datetime::DATETIME_FORMAT );
		return $date ? sprintf( '%s — %s', $name, $date ) : $name;
	}

	private function status_label( string $status ): string {
		return 'no-show' === $status ? 'No-show' : ucfirst( $status );
	}

	private function require_access(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage attendance.', 'heart-hub-event-registration-manager' ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) );
		}
	}

	private function redirect( int $event_id, int $saved, string $error = '', int $conflicts = 0 ): void {
		$args = array( 'event_id' => $event_id, 'attendance_saved' => $saved );
		if ( $error ) $args['attendance_error'] = $error;
		if ( $conflicts ) $args['attendance_conflicts'] = $conflicts;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
