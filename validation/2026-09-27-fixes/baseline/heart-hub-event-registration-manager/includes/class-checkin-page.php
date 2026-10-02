<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Checkin_Page {
	private $settings;
	private $checkin;

	public function __construct( Settings $settings, Checkin_Manager $checkin ) {
		$this->settings = $settings;
		$this->checkin  = $checkin;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-protected, read-only event selection; QR changes and previews have their own nonce checks.
	public function render(): void {
		$this->require_access();
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$events = get_posts( array( 'post_type' => $this->settings->get( 'events_cpt', 'events' ), 'post_status' => array( 'publish', 'private', 'draft', 'future' ), 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => false, 'meta_query' => array( array( 'key' => Calendar_Schedule::TYPE_META, 'compare' => 'NOT EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Excludes calendar schedule entries, which are not bookable events.
		$expiry = $event_id ? $this->checkin->preview_expiry( $event_id ) : 0;
		?>
		<div class="wrap hherm-checkin-page-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1><?php esc_html_e( 'Check-in page', 'heart-hub-event-registration-manager' ); ?></h1><p><?php esc_html_e( 'Preview the attendee page at any time. The live QR link still opens only during its event window.', 'heart-hub-event-registration-manager' ); ?></p></div><div class="hherm-page-actions"><label class="hherm-inline-field"><span><?php esc_html_e( 'Viewing check-in page for', 'heart-hub-event-registration-manager' ); ?></span><select name="event_id" data-checkin-event form="hherm-checkin-page-selector"><option value="">Select an event</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>><?php echo esc_html( $this->checkin->event_label( $event->ID ) ); ?></option><?php endforeach; ?></select></label><?php if ( $expiry ) : ?><span class="hherm-live-pill">Live until <?php echo esc_html( wp_date( 'j M, g:i a', $expiry, wp_timezone() ) ); ?></span><?php endif; ?></div></header><hr class="wp-header-end">
			<form id="hherm-checkin-page-selector" class="hherm-visually-hidden" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( Checkin_Manager::CHECKIN_PAGE_SLUG ); ?>"><input type="hidden" name="event_id" data-checkin-event-submit value="<?php echo esc_attr( $event_id ); ?>"></form>
			<?php if ( ! $event_id ) : ?><div class="hherm-empty-state"><h2><?php esc_html_e( 'Choose an event to begin', 'heart-hub-event-registration-manager' ); ?></h2><p><?php esc_html_e( 'Select an event above to see the attendee-facing check-in page and live register.', 'heart-hub-event-registration-manager' ); ?></p></div>
			<?php else : ?>
			<?php $this->render_event_content( $event_id ); ?>
			<?php endif; ?>
		</div>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function render_event_content( int $event_id ): void {
		$this->require_access();
		$preview_url = $event_id ? $this->checkin->admin_preview_url( $event_id ) : '';
		?>
		<div class="hherm-checkin-page-grid" data-checkin-live data-event-id="<?php echo esc_attr( $event_id ); ?>">
			<section class="hherm-public-preview-card"><div class="hherm-card-heading"><h2><?php esc_html_e( 'Attendee page preview', 'heart-hub-event-registration-manager' ); ?></h2><?php if ( $preview_url ) : ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $preview_url ); ?>">Open in new tab</a><?php endif; ?></div><?php if ( $preview_url ) : ?><iframe sandbox="allow-same-origin" title="Attendee check-in page preview" referrerpolicy="no-referrer" src="<?php echo esc_url( $preview_url ); ?>"></iframe><?php else : ?><div class="hherm-preview-placeholder"><p><?php esc_html_e( 'Choose an event to preview its temporary attendee page.', 'heart-hub-event-registration-manager' ); ?></p></div><?php endif; ?></section>
			<div class="hherm-checkin-page-side"><section class="hherm-list-card hherm-behaviour-card"><div class="hherm-card-heading"><h2><?php esc_html_e( 'How the temporary page behaves', 'heart-hub-event-registration-manager' ); ?></h2></div><ol><li><?php esc_html_e( 'The signed link is valid for this event only and expires one hour after it ends.', 'heart-hub-event-registration-manager' ); ?></li><li><?php esc_html_e( 'Only approved registrations can check in; unmatched email addresses are directed to a volunteer.', 'heart-hub-event-registration-manager' ); ?></li><li><?php esc_html_e( 'Each check-in records attendance and a timestamp in the audit log.', 'heart-hub-event-registration-manager' ); ?></li><li><?php esc_html_e( 'A smaller party size returns unused places to the event capacity.', 'heart-hub-event-registration-manager' ); ?></li></ol></section><section class="hherm-list-card hherm-live-checkins"><div class="hherm-card-heading"><h2><?php esc_html_e( 'Live check-ins', 'heart-hub-event-registration-manager' ); ?></h2><span data-checkin-total>Loading…</span></div><div class="hherm-live-progress"><span data-checkin-progress></span></div><div class="hherm-live-feed" data-checkin-feed><p class="hherm-muted">Loading live check-ins…</p></div></section></div>
		</div>
		<?php
	}

	private function require_access(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage event check-in.', 'heart-hub-event-registration-manager' ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) );
		}
	}
}
