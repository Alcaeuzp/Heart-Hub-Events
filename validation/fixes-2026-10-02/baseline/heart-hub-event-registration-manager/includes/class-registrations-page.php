<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Registrations_Page {
	public function render(): void {
		$this->require_access();
		?>
		<div class="wrap hherm-admin-wrap">
			<header class="hherm-page-header">
				<div>
					<span class="hherm-eyebrow"><?php echo esc_html__( 'Heart Hub Events', 'heart-hub-event-registration-manager' ); ?></span>
					<h1><?php echo esc_html__( 'Registrations', 'heart-hub-event-registration-manager' ); ?></h1>
					<p><?php echo esc_html__( 'Triage pending and waitlisted applications, then make confident decisions.', 'heart-hub-event-registration-manager' ); ?></p>
				</div>
				<div class="hherm-page-actions"><button type="button" class="button" data-export-applications><?php esc_html_e( 'Export CSV', 'heart-hub-event-registration-manager' ); ?></button><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Event_Manager::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Create event', 'heart-hub-event-registration-manager' ); ?></a></div>
			</header><hr class="wp-header-end">
			<div id="hherm-app" class="hherm-app">
				<section class="hherm-stat-strip" aria-label="Registration summary"><div class="hherm-stat-card"><span class="hherm-stat-label"><i class="hherm-stat-dot hherm-stat-dot--amber"></i>Pending review</span><strong data-stat="pending">—</strong><span data-stat-note="pending">places held</span></div><div class="hherm-stat-card"><span class="hherm-stat-label"><i class="hherm-stat-dot hherm-stat-dot--blue"></i>On waitlist</span><strong data-stat="waitlist">—</strong><span data-stat-note="waitlist">places wanted</span></div><div class="hherm-stat-card"><span class="hherm-stat-label"><i class="hherm-stat-dot hherm-stat-dot--green"></i>Approved this week</span><strong data-stat="approved_week">—</strong><span data-stat-note="approved_week">new approvals</span></div><div class="hherm-stat-card"><span class="hherm-stat-label"><i class="hherm-stat-dot hherm-stat-dot--ink"></i>Capacity filled</span><strong data-stat="capacity">—</strong><span data-stat-note="capacity">across open events</span></div></section>
				<section class="hherm-filter-panel" aria-labelledby="hherm-filter-title">
					<div class="hherm-panel-heading">
						<div><h2 id="hherm-filter-title"><?php echo esc_html__( 'Filter applications', 'heart-hub-event-registration-manager' ); ?></h2><p><?php echo esc_html__( 'Narrow the list by applicant, event, status, or registration date.', 'heart-hub-event-registration-manager' ); ?></p></div>
						<button type="button" class="hherm-reset" data-reset><?php echo esc_html__( 'Clear filters', 'heart-hub-event-registration-manager' ); ?></button>
					</div>
					<div class="hherm-toolbar">
						<label class="hherm-field--search"><?php echo esc_html__( 'Search', 'heart-hub-event-registration-manager' ); ?><input type="search" data-filter="search" placeholder="<?php echo esc_attr__( 'Search name, email or phone number', 'heart-hub-event-registration-manager' ); ?>"></label>
						<label><?php echo esc_html__( 'Status', 'heart-hub-event-registration-manager' ); ?><select data-filter="status"><option value="">All statuses</option><option value="interest">Expression of interest</option><option value="pending">Pending</option><option value="waitlist">Waitlist</option><option value="approved">Approved</option><option value="declined">Declined</option></select></label>
						<label class="hherm-field--event"><?php echo esc_html__( 'Event', 'heart-hub-event-registration-manager' ); ?><select data-filter="event_id"><option value="">All events</option></select></label>
						<label><?php echo esc_html__( 'Registered from', 'heart-hub-event-registration-manager' ); ?><input type="text" placeholder="DD/MM/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" data-filter="date_from"></label><label><?php echo esc_html__( 'Registered to', 'heart-hub-event-registration-manager' ); ?><input type="text" placeholder="DD/MM/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" data-filter="date_to"></label>
						<label><?php echo esc_html__( 'Sort by date', 'heart-hub-event-registration-manager' ); ?><select data-filter="order"><option value="desc">Newest first</option><option value="asc">Oldest first</option></select></label>
					</div>
				</section>
				<section class="hherm-results-panel" aria-labelledby="hherm-results-title">
					<div class="hherm-results-heading"><div><h2 id="hherm-results-title"><?php echo esc_html__( 'Applications', 'heart-hub-event-registration-manager' ); ?></h2><span class="hherm-result-count" data-result-count aria-live="polite"></span></div><div class="hherm-quick-filters" role="group" aria-label="Quick filters"><button type="button" data-quick="pending">Needs review</button><button type="button" data-quick="waitlist">Waitlist</button><button type="button" data-quick="approved">Approved</button><button type="button" data-quick="">All</button></div></div>
					<div class="hherm-selection-bar" data-selection-bar hidden><span><strong data-selected-count>0</strong> applications selected</span><span class="hherm-muted"><strong data-selected-places>0</strong> places affected</span><div><button type="button" class="hherm-bulk-approve" data-bulk="approved">Approve selected</button><button type="button" class="hherm-bulk-decline" data-bulk="declined">Decline selected</button><button type="button" class="button-link" data-clear-selection>Clear</button></div></div>
					<div class="hherm-message" aria-live="polite"></div>
					<div class="hherm-results" aria-live="polite"></div>
					<nav class="hherm-pagination" aria-label="<?php echo esc_attr__( 'Applications pages', 'heart-hub-event-registration-manager' ); ?>" hidden></nav>
				</section>
				<div class="hherm-modal" hidden>
					<div class="hherm-backdrop" data-close></div>
					<section role="dialog" aria-modal="true" aria-labelledby="hherm-modal-title" tabindex="-1">
						<button class="hherm-close" type="button" data-close aria-label="<?php echo esc_attr__( 'Close', 'heart-hub-event-registration-manager' ); ?>">×</button>
						<div class="hherm-modal-content"></div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	private function require_access(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have access to event registrations.', 'heart-hub-event-registration-manager' ),
				esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ),
				array( 'response' => 403 )
			);
		}
	}
}
