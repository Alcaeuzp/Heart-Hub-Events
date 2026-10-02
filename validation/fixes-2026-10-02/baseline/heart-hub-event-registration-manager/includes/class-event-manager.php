<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Event_Manager {
	public const PAGE_SLUG = 'hherm-create-event';
	public const LIST_PAGE_SLUG = 'hherm-events';
	public const FUNDRAISER_LIST_PAGE_SLUG = 'hherm-fundraisers';
	public const CATEGORY_PAGE_SLUG = 'hherm-event-categories';
	public const ACTION = 'hherm_save_event';
	public const CATEGORY_ACTION = 'hherm_save_event_category';
	public const DELETE_CATEGORY_ACTION = 'hherm_delete_event_category';
	public const CANCEL_ACTION = 'hherm_cancel_event';
	private const TAXONOMY = 'event-category';

	private $settings;
	private $audit;
	private $capacity;
	private $repository;
	private $email;
	private $metrics;
	private $fundraiser_screen = false;

	public function __construct( Settings $settings, Audit_Log $audit, Capacity_Manager $capacity, CCT_Repository $repository, Email_Automation $email, ?Event_Metrics $metrics = null ) {
		$this->settings = $settings;
		$this->audit = $audit;
		$this->capacity = $capacity;
		$this->repository = $repository;
		$this->email = $email;
		$this->metrics = $metrics ?: new Event_Metrics( $settings, $repository );
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::CATEGORY_ACTION, array( $this, 'handle_category' ) );
		add_action( 'admin_post_' . self::DELETE_CATEGORY_ACTION, array( $this, 'handle_delete_category' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( $this, 'handle_cancel_event' ) );
	}

	public function map_api_config(): array {
		$api_key = '';
		if ( class_exists( '\Jet_Engine\Modules\Maps_Listings\Module' ) ) {
			try {
				$module = \Jet_Engine\Modules\Maps_Listings\Module::instance();
				if ( isset( $module->settings ) && method_exists( $module->settings, 'get' ) ) {
					$api_key = sanitize_text_field( (string) $module->settings->get( 'api_key' ) );
				}
			} catch ( \Throwable $exception ) {
				$api_key = '';
			}
		}
		if ( ! $api_key ) {
			$map_settings = (array) get_option( 'jet-engine-maps-settings', array() );
			$api_key = sanitize_text_field( (string) ( $map_settings['api_key'] ?? '' ) );
		}
		if ( ! $api_key ) {
			return array();
		}
		return array(
			'script_url' => add_query_arg(
				array(
					'key'      => $api_key,
					'libraries' => 'places',
					'loading'  => 'async',
				),
				'https://maps.googleapis.com/maps/api/js'
			),
		);
	}

	public function render_fundraisers(): void {
		$this->fundraiser_screen = true;
		$this->render_list();
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-protected, read-only event filters and notices; all changes use separate nonce-verified handlers.
	public function render_list(): void {
		$is_fundraiser_screen = $this->fundraiser_screen;
		$list_page = $is_fundraiser_screen ? self::FUNDRAISER_LIST_PAGE_SLUG : self::LIST_PAGE_SLUG;
		$this->require_event_access( $is_fundraiser_screen ? 'manage fundraisers' : 'manage events' );
		$view_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		if ( $view_id ) { ( new Event_Overview( $this->settings, $this->repository, $list_page ) )->render( $view_id ); return; }
		$post_type = $this->post_type();
		$search = isset( $_GET['event_search'] ) ? sanitize_text_field( wp_unslash( $_GET['event_search'] ) ) : '';
		$status = isset( $_GET['event_status'] ) ? sanitize_key( wp_unslash( $_GET['event_status'] ) ) : '';
		$category = isset( $_GET['event_category'] ) ? absint( wp_unslash( $_GET['event_category'] ) ) : 0;
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		$allowed_statuses = array( 'publish', 'draft', 'future', 'private', 'pending' );
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => in_array( $status, $allowed_statuses, true ) ? $status : $allowed_statuses,
			'posts_per_page' => 20,
			'paged'          => $paged,
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$event_types = Event_Types::terms();
		$fundraising_term = absint( $event_types['fundraising'] ?? 0 );
		$calendar_term = get_term_by( 'slug', 'calendar-schedule', self::TAXONOMY );
		if ( ! $calendar_term ) $calendar_term = get_term_by( 'name', 'Calendar Schedule', self::TAXONOMY );
		$calendar_term_id = $calendar_term && ! is_wp_error( $calendar_term ) ? (int) $calendar_term->term_id : 0;
		$tax_query = array();
		if ( $fundraising_term && taxonomy_exists( self::TAXONOMY ) ) {
			$tax_query[] = array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => $fundraising_term, 'operator' => $is_fundraiser_screen ? 'IN' : 'NOT IN' );
		}
		if ( ! $is_fundraiser_screen && $calendar_term_id && taxonomy_exists( self::TAXONOMY ) ) {
			$tax_query[] = array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => $calendar_term_id, 'operator' => 'NOT IN' );
		}
		if ( $category && taxonomy_exists( self::TAXONOMY ) && ( ! $is_fundraiser_screen || $category !== $fundraising_term ) ) {
			$tax_query[] = array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => $category );
		}
		if ( $tax_query ) {
			if ( count( $tax_query ) > 1 ) $tax_query = array_merge( array( 'relation' => 'AND' ), $tax_query );
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required to separate events/fundraisers and filter existing categories; results are paginated to 20 posts.
			$args['tax_query'] = $tax_query;
		}
		$query = post_type_exists( $post_type ) ? new \WP_Query( $args ) : null;
		$terms = taxonomy_exists( self::TAXONOMY ) ? get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) ) : array();
		$event_start_key = sanitize_key( $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$status_labels = array( 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending review', 'private' => 'Private', 'future' => 'Scheduled' );
		$event_venue_key = sanitize_key( $this->settings->get( 'event_venue', 'venue' ) ) ?: 'venue';
		$screen_title = $is_fundraiser_screen ? 'Manage Fundraisers' : 'Manage Events';
		$screen_description = $is_fundraiser_screen ? 'Record funds raised and attendance for fundraising events.' : 'Create, edit and cancel workshops and community events, and see who is coming.';
		$create_url = $is_fundraiser_screen ? add_query_arg( 'context', 'fundraising', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) : admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$impact_event_ids = $is_fundraiser_screen ? $this->metrics->event_ids( 12 ) : array();
		?>
		<div class="wrap hherm-events-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1><?php echo esc_html( $screen_title ); ?></h1><p><?php echo esc_html( $screen_description ); ?></p></div><a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>"><?php echo esc_html( $is_fundraiser_screen ? 'Create fundraiser' : 'Create event' ); ?></a></header><hr class="wp-header-end">
			<?php if ( $is_fundraiser_screen ) : ?><section class="hherm-fundraiser-summary" aria-label="Last 12 months impact summary"><div><strong><?php echo esc_html( '$' . number_format_i18n( $this->metrics->total_funds( 12 ), 0 ) ); ?></strong><span>Raised by fundraisers</span></div><div><strong><?php echo esc_html( number_format_i18n( $this->metrics->total_attendees( 12 ) ) ); ?></strong><span>People attended all events</span></div><div><strong><?php echo esc_html( number_format_i18n( count( $impact_event_ids ) ) ); ?></strong><span>Events run</span></div><small>Rolling 12 months · cancelled and future events excluded</small></section><?php endif; ?>
			<?php if ( isset( $_GET['event_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $is_fundraiser_screen ? 'Fundraiser saved successfully.' : 'Event saved successfully.' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['cancel_result'] ) ) : $cancel_result = array(); foreach ( array( 'sent', 'scheduled', 'logged', 'skipped', 'failed' ) as $cancel_key ) $cancel_result[ $cancel_key ] = isset( $_GET[ 'cancel_' . $cancel_key ] ) ? absint( wp_unslash( $_GET[ 'cancel_' . $cancel_key ] ) ) : 0; ?><div class="notice <?php echo empty( $cancel_result['failed'] ) ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo esc_html( sprintf( 'Event cancelled. Emails: %d sent, %d scheduled, %d logged, %d skipped, %d failed.', $cancel_result['sent'] ?? 0, $cancel_result['scheduled'] ?? 0, $cancel_result['logged'] ?? 0, $cancel_result['skipped'] ?? 0, $cancel_result['failed'] ?? 0 ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['cancel_error'] ) ) : $cancel_errors = array( 'invalid' => 'The selected event could not be cancelled.', 'recipients' => 'The registrations for this event could not be loaded, so it was not cancelled. Please try again.', 'finished' => 'This event has already finished, so it was not cancelled and no emails were sent.' ); ?><div class="notice notice-error"><p><?php echo esc_html( $cancel_errors[ sanitize_key( wp_unslash( $_GET['cancel_error'] ) ) ] ?? $cancel_errors['invalid'] ); ?></p></div><?php endif; ?>
			<?php if ( ! post_type_exists( $post_type ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( 'The “%s” post type is not registered. Confirm JetEngine is active and the Events CPT slug is correct.', $post_type ) ); ?></p></div>
			<?php else : ?>
				<form class="hherm-list-filters" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( $list_page ); ?>"><label><span><?php echo esc_html( $is_fundraiser_screen ? 'Search fundraisers' : 'Search events' ); ?></span><input type="search" name="event_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Event name"></label><label><span>Status</span><select name="event_status"><option value="">All statuses</option><?php foreach ( $allowed_statuses as $item ) : ?><option value="<?php echo esc_attr( $item ); ?>" <?php selected( $status, $item ); ?>><?php echo esc_html( ucfirst( $item ) ); ?></option><?php endforeach; ?></select></label><label><span>Additional category</span><select name="event_category"><option value="0">All categories</option><?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $term ) : if ( (int) $term->term_id === $fundraising_term || in_array( (int) $term->term_id, Event_Types::lifecycle_terms(), true ) ) continue; ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $category, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; endif; ?></select></label><div class="hherm-filter-actions"><button class="button button-primary">Filter</button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $list_page ) ); ?>">Clear</a></div></form>
				<section class="hherm-list-card"><div class="hherm-table-wrap"><table class="widefat fixed striped"><thead><tr><th><?php echo esc_html( $is_fundraiser_screen ? 'Fundraiser' : 'Event' ); ?></th><th>Start</th><th>Venue</th><?php if ( $is_fundraiser_screen ) : ?><th>Amount raised</th><th>Attendees</th><?php else : ?><th>Capacity</th><th>Categories</th><?php endif; ?><th class="hherm-status-col">Status</th><th class="hherm-actions-col">Actions</th></tr></thead><tbody>
				<?php if ( $query && $query->have_posts() ) : while ( $query->have_posts() ) : $query->the_post(); $post_id = get_the_ID(); $event_terms = get_the_terms( $post_id, self::TAXONOMY ); ?>
					<?php
					$cancelled = $this->is_cancelled( $post_id );
					$capacity_total = absint( get_post_meta( $post_id, 'event_capacity', true ) );
					$capacity_remaining = $this->capacity->enabled() ? $this->capacity->remaining( $post_id ) : $capacity_total;
					$capacity_remaining = is_wp_error( $capacity_remaining ) ? null : max( 0, (int) $capacity_remaining );
					$capacity_used = null === $capacity_remaining ? 0 : max( 0, $capacity_total - $capacity_remaining );
					$capacity_percent = $capacity_total ? min( 100, (int) round( $capacity_used / $capacity_total * 100 ) ) : 0;
					$amount_raw = trim( (string) get_post_meta( $post_id, 'amount_raised', true ) );
					$post_status = (string) get_post_status( $post_id );
					// A finished event has nobody left to warn, so cancelling it would only send confusing emails.
					$can_cancel = $cancelled || ! $this->has_finished( $post_id );
					$edit_args = array( 'event_id' => $post_id );
					if ( $is_fundraiser_screen ) $edit_args['context'] = 'fundraising';
					?>
					<tr data-event-url="<?php echo esc_url( add_query_arg( 'event_id', $post_id, admin_url( 'admin.php?page=' . $list_page ) ) ); ?>" tabindex="0" aria-label="<?php echo esc_attr( 'View event: ' . $this->event_name( $post_id ) ); ?>">
						<td><strong><?php echo esc_html( $this->event_name( $post_id ) ); ?></strong></td>
						<td><?php echo esc_html( $this->display_datetime( get_post_meta( $post_id, $event_start_key, true ) ) ); ?></td>
						<td><?php echo esc_html( (string) get_post_meta( $post_id, $event_venue_key, true ) ?: '—' ); ?></td>
						<?php if ( $is_fundraiser_screen ) : ?>
							<td><strong><?php echo esc_html( is_numeric( $amount_raw ) ? '$' . number_format_i18n( (float) $amount_raw, 2 ) : 'Not recorded' ); ?></strong></td>
							<td><?php echo esc_html( $this->has_finished( $post_id ) ? number_format_i18n( $this->metrics->event_attendees( $post_id ) ) : '—' ); ?></td>
						<?php else : ?>
							<td class="hherm-capacity-cell"><?php if ( null === $capacity_remaining ) : ?>Invalid<?php else : ?><div><strong><?php echo esc_html( $capacity_used . ' / ' . $capacity_total ); ?></strong><span><?php echo esc_html( $capacity_remaining ? $capacity_remaining . ' places left' : ( $this->is_waitlist_enabled( $post_id ) ? 'Full · waitlist open' : 'Full' ) ); ?></span></div><div class="hherm-capacity-meter"><span class="<?php echo esc_attr( $capacity_percent >= 100 ? 'is-full' : ( $capacity_percent >= 85 ? 'is-nearly-full' : '' ) ); ?>" style="width:<?php echo esc_attr( $capacity_percent ); ?>%"></span></div><?php endif; ?></td>
							<td><?php echo esc_html( $this->term_names( $event_terms ) ); ?></td>
						<?php endif; ?>
						<td><span class="hherm-post-status hherm-post-status--<?php echo esc_attr( $cancelled ? 'cancelled' : $post_status ); ?>"><?php echo esc_html( $cancelled ? 'Cancelled' : ( $status_labels[ $post_status ] ?? ucfirst( $post_status ) ) ); ?></span></td>
						<td class="hherm-row-actions"><div class="hherm-row-action-buttons"><a class="button button-small" href="<?php echo esc_url( add_query_arg( 'event_id', $post_id, admin_url( 'admin.php?page=' . $list_page ) ) ); ?>">Overview</a><a class="button button-small" href="<?php echo esc_url( add_query_arg( $edit_args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>">Edit</a><?php if ( 'publish' === $post_status ) : ?><a class="button button-small" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener">View page<span class="screen-reader-text"> (opens in a new tab)</span></a><?php endif; ?><?php if ( $can_cancel ) : $noun = $is_fundraiser_screen ? 'fundraiser' : 'event'; ?><a class="button button-small <?php echo $cancelled ? '' : 'hherm-delete-link'; ?>" data-hherm-confirm="<?php echo esc_attr( $cancelled ? 'Retry failed cancellation emails? Successfully sent emails will be skipped.' : 'Cancel this ' . $noun . ' and email everyone registered (except declined registrations)?' ); ?>" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::CANCEL_ACTION, 'event_id' => $post_id, 'return_page' => $list_page ), admin_url( 'admin-post.php' ) ), self::CANCEL_ACTION . '_' . $post_id ) ); ?>"><?php echo esc_html( $cancelled ? 'Retry notices' : 'Cancel ' . $noun ); ?></a><?php endif; ?></div></td>
					</tr>
				<?php endwhile; wp_reset_postdata(); else : ?><tr><td colspan="7" class="hherm-empty-cell"><?php echo esc_html( $is_fundraiser_screen ? 'No fundraisers found.' : 'No events found.' ); ?></td></tr><?php endif; ?>
				</tbody></table></div></section>
				<?php if ( $query && $query->max_num_pages > 1 ) : ?><nav class="hherm-list-pagination" aria-label="Events pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', remove_query_arg( array( 'event_saved', 'cancel_result', 'cancel_sent', 'cancel_scheduled', 'cancel_logged', 'cancel_skipped', 'cancel_failed', 'cancel_error' ) ) ), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages, 'type' => 'list' ) ) ); ?></nav><?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-protected editor display and notices; saving uses the nonce-verified handle() action.
	public function render(): void {
		$this->require_event_access( 'create or edit events' );
		$post_type = $this->post_type();
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$event = $event_id ? get_post( $event_id ) : null;
		if ( $event && $post_type !== $event->post_type ) {
			$event = null;
			$event_id = 0;
		}
		$values = $this->event_values( $event );
		$retry_request = $this->event_retry_request( $event_id );
		if ( $retry_request ) $values = $this->submitted_event_values( $values, $retry_request );
		$selected_terms = $event_id && taxonomy_exists( self::TAXONOMY ) ? wp_get_object_terms( $event_id, self::TAXONOMY, array( 'fields' => 'ids' ) ) : array();
		$selected_terms = is_wp_error( $selected_terms ) ? array() : array_map( 'intval', $selected_terms );
		if ( $retry_request ) $selected_terms = array_map( 'absint', (array) ( $retry_request['event_categories'] ?? array() ) );
		$event_types = Event_Types::terms();
		$event_type = $retry_request ? sanitize_key( $retry_request['event_type'] ?? 'keep' ) : Event_Types::selection( $selected_terms, $event_types, ! $event_id );
		$requested_context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';
		if ( ! $event_id && 'fundraising' === $requested_context ) $event_type = 'fundraising';
		$is_fundraising = 'fundraising' === $event_type || ( 'keep' === $event_type && $event_types['fundraising'] && in_array( $event_types['fundraising'], $selected_terms, true ) );
		$fundraiser_context = $is_fundraising || 'fundraising' === $requested_context;
		$back_page = $fundraiser_context ? self::FUNDRAISER_LIST_PAGE_SLUG : self::LIST_PAGE_SLUG;
		$hidden_category_ids = Event_Types::lifecycle_terms();
		$terms = taxonomy_exists( self::TAXONOMY ) ? get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) ) : array();
		$saved_id = isset( $_GET['event_saved'] ) ? absint( wp_unslash( $_GET['event_saved'] ) ) : 0;
		$error_code = isset( $_GET['event_error'] ) ? sanitize_key( wp_unslash( $_GET['event_error'] ) ) : '';
		$error_field = $this->error_field( $error_code, $retry_request );
		?>
		<div class="wrap hherm-create-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1><?php echo esc_html( $event_id ? ( $fundraiser_context ? 'Edit fundraiser' : 'Edit event' ) : ( $fundraiser_context ? 'Create fundraiser' : 'Create event' ) ); ?></h1><p><?php echo esc_html( ( $fundraiser_context ? 'Fill in the fundraiser details below. The public event page updates when you save.' : 'Fill in the event details below. The public event page updates when you save.' ) ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $back_page ) ); ?>"><?php echo esc_html( $fundraiser_context ? 'Back to fundraisers' : 'Back to events' ); ?></a></header><hr class="wp-header-end">
			<?php if ( $saved_id ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Event saved successfully.', 'heart-hub-event-registration-manager' ); ?></p></div><?php elseif ( $error_code ) : ?><div id="hherm-event-error" class="notice notice-error" role="alert" data-event-error data-error-field="<?php echo esc_attr( $error_field ); ?>"><p><?php echo esc_html( $this->error_message( $error_code ) ); ?></p></div><?php endif; ?>
			<?php if ( $event_id && $this->is_cancelled( $event_id ) ) : ?><div class="notice notice-warning"><p>This event is cancelled. Registrations remain disabled; use Manage Events to retry any failed cancellation emails.</p></div><?php endif; ?>
			<?php if ( ! post_type_exists( $post_type ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sprintf( 'The “%s” event post type is not registered. Confirm JetEngine is active and the Events CPT slug is correct.', $post_type ) ); ?></p></div><?php else : ?>
			<form class="hherm-event-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><?php wp_nonce_field( self::ACTION, 'hherm_event_nonce' ); ?>
				<section class="hherm-form-card"><h2>Event type</h2><?php if ( ! $event_id && $fundraiser_context ) : ?><input type="hidden" name="event_type" value="fundraising"><p><strong>Fundraising</strong> — this event will appear in the Fundraisers tab.</p><?php else : ?><label class="hherm-event-type-field"><span>Type</span><select name="event_type" data-event-type data-keep-fundraising="<?php echo $is_fundraising ? '1' : '0'; ?>"><?php if ( $event_id ) : ?><option value="keep" <?php selected( $event_type, 'keep' ); ?>>Keep existing type/categories</option><?php endif; ?><option value="workshop" <?php selected( $event_type, 'workshop' ); ?>>Workshop — standard / free</option><option value="fundraising" <?php selected( $event_type, 'fundraising' ); ?>>Fundraising</option></select></label><?php endif; ?><?php if ( ! $event_types['workshop'] || ! $event_types['fundraising'] ) : ?><p>Add the Workshop and Fundraising event categories before selecting a missing type.</p><?php endif; ?>
				<div data-fundraising-fields <?php if ( ! $is_fundraising ) echo 'hidden'; ?>><fieldset <?php disabled( ! $is_fundraising ); ?>><p class="hherm-fundraising-help">Add the event costs now. The final amount raised is recorded after the fundraiser has been created.</p><div class="hherm-switch-grid"><?php $this->switch_field( 'external_fee_enabled', 'Use external fee details', 'Show one external fee and optional payment link instead of the registration and sponsorship costs.', $values, 'data-external-fee-toggle' ); ?></div><div class="hherm-form-grid" data-internal-fee-fields><label><span>Sponsorship cost</span><input type="text" name="sponsorship_cost" value="<?php echo esc_attr( $values['sponsorship_cost'] ); ?>"></label><label><span>Registration fee</span><input type="text" name="registration_fee" value="<?php echo esc_attr( $values['registration_fee'] ); ?>"></label></div><div class="hherm-form-grid" data-external-fee-fields><label><span>External fee amount</span><span class="hherm-money-input"><span aria-hidden="true">$</span><input type="number" name="external_fee_amount" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $values['external_fee_amount'] ); ?>"></span><small>Optional. Leave blank when the external site supplies the fee.</small></label><label><span>External fee link</span><input type="url" name="external_fee_url" value="<?php echo esc_attr( $values['external_fee_url'] ); ?>" placeholder="https://"><small>Optional link to the external payment or fee page.</small></label></div><div class="hherm-raffle-settings"><?php $this->switch_field( 'raffle', 'Raffle', 'Turn this on when raffle entry is available for this event.', $values ); ?><label class="hherm-raffle-cost" data-raffle-cost-field <?php if ( 'true' !== $values['raffle'] ) echo 'hidden'; ?>><span>Raffle entry cost</span><span class="hherm-money-input"><span aria-hidden="true">$</span><input type="number" name="raffle_cost" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $values['raffle_cost'] ); ?>"></span><small>Optional. Leave blank to show that a raffle is available without displaying a price.</small></label></div><?php if ( $event_id ) : ?><div class="hherm-fundraiser-result"><label><span>Amount raised</span><span class="hherm-money-input"><span aria-hidden="true">$</span><input type="number" name="amount_raised" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $values['amount_raised'] ); ?>"></span><small>Record the final amount once it is known.</small></label></div><?php endif; ?></fieldset></div></section>
				<section class="hherm-form-card"><div class="hherm-form-card__heading"><span class="dashicons dashicons-calendar-alt"></span><div><h2>Event details</h2><p>Core information shown on the event page.</p></div></div><div class="hherm-form-grid">
					<label class="hherm-field--wide"><span>Event name <strong>*</strong></span><input type="text" name="event_name" required maxlength="200" value="<?php echo esc_attr( $values['event_name'] ); ?>"></label>
					<label><span>Event Date Status <strong>*</strong></span><select name="event_date_status" data-date-status><option value="confirmed" <?php selected( $values['event_date_status'], 'confirmed' ); ?>>Date Confirmed</option><option value="tbc" <?php selected( $values['event_date_status'], 'tbc' ); ?>>Date TBC</option></select><small>Date TBC events can be saved and published without a date.</small></label>
					<label data-confirmed-date-field><span>Start date &amp; time</span><input type="text" placeholder="DD/MM/YYYY hh:mm am/pm" name="start_date" lang="en-AU" value="<?php echo esc_attr( $values['start_date'] ); ?>"><small>Australian date and time, e.g. 21/11/2026 08:00 am.</small></label><label data-confirmed-date-field><span>End date &amp; time</span><input type="text" placeholder="DD/MM/YYYY hh:mm am/pm" name="end_date__time" lang="en-AU" value="<?php echo esc_attr( $values['end_date__time'] ); ?>"><small>Australian date and time, e.g. 21/11/2026 08:00 am.</small></label>
					<label><span>Venue</span><input type="text" name="venue" maxlength="200" value="<?php echo esc_attr( $values['venue'] ); ?>"></label><label><span>Organiser</span><input type="text" name="organiser" maxlength="200" value="<?php echo esc_attr( $values['organiser'] ); ?>"></label><div class="hherm-field--wide"><?php $this->switch_field( 'co_hosted_event', 'Co-hosted event', 'Co-hosted by Heart Hub and the venue or organiser listed above.', $values ); ?></div><label class="hherm-field--wide"><span>Presenters</span><textarea name="presenters" rows="4"><?php echo esc_textarea( $values['presenters'] ); ?></textarea><small>Enter one or several presenters, using separate lines as needed.</small></label><label class="hherm-field--wide"><span>Address</span><input type="text" name="address" data-hherm-address-autocomplete autocomplete="off" maxlength="500" value="<?php echo esc_attr( $values['address'] ); ?>"><small data-address-status>Start typing to choose a validated address from Google.</small></label><label class="hherm-field--wide"><span>Parking &amp; access instructions</span><textarea name="parking_access" rows="3" maxlength="3000"><?php echo esc_textarea( $values['parking_access'] ); ?></textarea><small>Included in the approval email and shown on the event page.</small></label>
				</div><div class="hherm-featured-image-field"><span>Featured image</span><input type="hidden" name="featured_image_id" value="<?php echo esc_attr( $values['featured_image_id'] ); ?>"><div class="hherm-featured-image-preview" data-featured-image-preview><?php if ( $values['featured_image_id'] ) echo wp_kses_post( wp_get_attachment_image( absint( $values['featured_image_id'] ), 'medium' ) ); ?></div><p><button type="button" class="button" data-select-featured-image>Select featured image</button> <button type="button" class="button-link-delete" data-remove-featured-image <?php if ( ! $values['featured_image_id'] ) echo 'hidden'; ?>>Remove image</button></p><small>Uses the native WordPress featured image for this event.</small></div><div class="hherm-editor-field"><label for="hherm_event_description"><span>Description</span></label><?php wp_editor( $values['_description'], 'hherm_event_description', array( 'textarea_name' => '_description', 'textarea_rows' => 6, 'media_buttons' => false, 'quicktags' => false, 'tinymce' => array( 'toolbar1' => 'bold,italic,link,bullist', 'toolbar2' => '' ) ) ); ?></div></section>
				<section class="hherm-form-card"><div class="hherm-form-card__heading hherm-form-card__heading--split"><span class="dashicons dashicons-list-view"></span><div><h2>What to Expect</h2><p>Retain the content here even when it is hidden publicly.</p></div><span class="hherm-expectation-counter" data-expectation-count><?php echo esc_html( count( $values['what_to_expect'] ) ); ?> of 6</span></div><div class="hherm-switch-grid"><?php $this->switch_field( 'show_what_to_expect', 'Show What to Expect', 'Display this section on the public event page.', $values ); ?></div><div data-what-to-expect-fields><div class="hherm-expectations" data-expectations data-max="6"><?php foreach ( array_slice( $values['what_to_expect'], 0, 6 ) as $index => $entry ) { $this->render_expectation_row( $entry, $index ); } ?></div><div class="hherm-repeater-controls"><button type="button" class="button" data-add-expectation>+ Add what-to-expect item</button></div></div><template data-expectation-template><?php $this->render_expectation_row( array( 'title' => '', 'content' => '' ), '__INDEX__' ); ?></template></section>
				<?php if ( taxonomy_exists( self::TAXONOMY ) && ! is_wp_error( $terms ) ) : ?><section class="hherm-form-card hherm-form-card--categories" data-event-categories data-new-event="<?php echo $event_id ? '0' : '1'; ?>" data-fundraising-selected="<?php echo $is_fundraising ? '1' : '0'; ?>" <?php if ( ! $event_id && $is_fundraising ) echo 'hidden'; ?>><div class="hherm-form-card__heading"><span class="dashicons dashicons-category"></span><div><h2>Categories</h2><p>Choose one or more categories for this event.</p></div></div><div class="hherm-term-picker"><?php $selectable_terms = 0; if ( $terms ) : foreach ( $terms as $term ) : if ( in_array( (int) $term->term_id, array_merge( array_values( $event_types ), $hidden_category_ids ), true ) ) continue; ++$selectable_terms; ?><label><input type="checkbox" name="event_categories[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $selected_terms, true ) ); ?>> <span><?php echo esc_html( $term->name ); ?></span></label><?php endforeach; endif; if ( ! $selectable_terms ) : ?><p>No selectable categories are available.</p><?php endif; ?></div></section><?php endif; ?>
				<section class="hherm-form-card"><div class="hherm-form-card__heading"><span class="dashicons dashicons-tickets-alt"></span><div><h2>Registration settings</h2><p>Choose how visitors act on this event.</p></div></div><div class="hherm-form-grid"><label><span>Registration Type</span><select name="registration_type" data-registration-type><option value="website_registration" <?php selected( $values['registration_type'], 'website_registration' ); ?>>Website Registration</option><option value="external_registration" <?php selected( $values['registration_type'], 'external_registration' ); ?>>External Registration</option><option value="no_registration" <?php selected( $values['registration_type'], 'no_registration' ); ?>>No Registration</option></select><small>Website Registration is used when no type is supplied.</small></label><label data-external-registration-field><span>External Registration URL</span><input type="url" name="external_registration_url" value="<?php echo esc_attr( $values['external_registration_url'] ); ?>" placeholder="https://"><small>Opens in a new tab from the public event page.</small></label><label><span>Total capacity</span><input type="number" name="event_capacity" min="0" step="1" value="<?php echo esc_attr( $values['event_capacity'] ); ?>"><small>Capacity remains available internally even when spots are hidden.</small></label></div><div class="hherm-switch-grid" data-registration-controls><?php $this->switch_field( 'registration_enabled', 'Registration enabled', 'Allow visitors to register through this website when the event date is confirmed.', $values ); $this->switch_field( 'show_remaining_spots', 'Show Remaining Spots', 'Display remaining capacity publicly for website registration.', $values ); $this->switch_field( 'allow_waitlist', 'Allow waiting list', 'Permit a waiting list when capacity is reached.', $values ); ?></div><div class="hherm-registration-lock-message" data-registration-lock-message hidden><p>As this event has no confirmed date, registration is locked.</p></div><div class="hherm-switch-grid" data-interest-contact-switch><?php $this->switch_field( 'show_interest_contact_button', 'Show Contact Us button for interest', 'Display a Contact Us button while this website-registration event is Date TBC.', $values ); ?></div><div data-website-registration-fields><div class="hherm-form-grid"><label><span>Registration opens</span><input type="text" placeholder="DD/MM/YYYY hh:mm am/pm" name="registration_open" lang="en-AU" value="<?php echo esc_attr( $values['registration_open'] ); ?>"></label><label><span>Registration closes</span><input type="text" placeholder="DD/MM/YYYY hh:mm am/pm" name="registration_close" lang="en-AU" value="<?php echo esc_attr( $values['registration_close'] ); ?>"><small>May remain open after the event starts.</small></label><label><span>Cancellation deadline</span><input type="text" placeholder="DD/MM/YYYY hh:mm am/pm" name="cancellation_deadline" lang="en-AU" value="<?php echo esc_attr( $values['cancellation_deadline'] ); ?>"><small>Must fall before the event start.</small></label></div></div></section>
				<div class="hherm-form-submit"><p>Draft events are saved without being publicly visible.</p><div><button type="submit" class="button button-secondary button-large" name="post_status" value="draft">Save draft</button> <button type="submit" class="button button-primary button-large" name="post_status" value="publish"><?php echo esc_html( $event_id ? 'Update & publish' : 'Publish event' ); ?></button></div></div>
			</form><?php endif; ?>
		</div><?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function handle(): void {
		$this->require_event_access( 'save events' );
		check_admin_referer( self::ACTION, 'hherm_event_nonce' );
		$request = wp_unslash( $_POST );
		$post_type = $this->post_type();
		$event_id = absint( $request['event_id'] ?? 0 );
		$is_new_event = 0 === $event_id;
		$previous_schedule_status = $event_id ? sanitize_key( (string) get_post_meta( $event_id, 'event_schedule_status', true ) ) : '';
		$previous_start = $event_id ? (string) get_post_meta( $event_id, sanitize_key( $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date', true ) : '';
		if ( ! $previous_schedule_status ) $previous_schedule_status = $previous_start ? 'scheduled' : 'tba';
		if ( ! post_type_exists( $post_type ) ) $this->redirect_error( 'post_type_missing', $event_id );
		$post_type_object = get_post_type_object( $post_type );
		$capability = $event_id ? 'edit_post' : ( $post_type_object && ! empty( $post_type_object->cap->create_posts ) ? $post_type_object->cap->create_posts : 'edit_posts' );
		if ( ! current_user_can( $capability, $event_id ) ) $this->redirect_error( 'post_type_capability', $event_id );
		if ( $event_id && ( ! get_post( $event_id ) || $post_type !== get_post_type( $event_id ) ) ) $this->redirect_error( 'event_missing' );
		$event_types = Event_Types::terms();
		$existing_terms = $event_id ? wp_get_object_terms( $event_id, self::TAXONOMY, array( 'fields' => 'ids' ) ) : array();
		if ( is_wp_error( $existing_terms ) ) $this->redirect_error( 'save_failed', $event_id );
		$existing_terms = array_map( 'intval', $existing_terms );
		$event_type = sanitize_key( $request['event_type'] ?? 'keep' );
		$submitted_terms = array_map( 'absint', (array) ( $request['event_categories'] ?? array() ) );
		if ( $is_new_event && 'fundraising' === $event_type ) $submitted_terms = array();
		$save_terms = Event_Types::categories( $event_type, $submitted_terms, $existing_terms, $event_types, Event_Types::lifecycle_terms() );
		if ( is_wp_error( $save_terms ) ) $this->redirect_error( $save_terms->get_error_code(), $event_id );
		$is_fundraising = $event_types['fundraising'] && in_array( $event_types['fundraising'], $save_terms, true );
		$featured_image_id = absint( $request['featured_image_id'] ?? 0 );
		if ( $featured_image_id && ( ! wp_attachment_is_image( $featured_image_id ) || ! current_user_can( 'read_post', $featured_image_id ) ) ) $this->redirect_error( 'invalid_featured_image', $event_id );
		$amount_raised = $event_id ? trim( (string) get_post_meta( $event_id, 'amount_raised', true ) ) : '';
		if ( $is_fundraising && ! $is_new_event ) {
			$amount_raw = trim( (string) ( $request['amount_raised'] ?? '' ) );
			if ( '' !== $amount_raw && ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $amount_raw ) ) $this->redirect_error( 'invalid_amount_raised', $event_id );
			$amount_raised = '' === $amount_raw ? '' : number_format( (float) $amount_raw, 2, '.', '' );
		}
		$external_fee_enabled = $is_fundraising && isset( $request['external_fee_enabled'] );
		$external_fee_amount = '';
		$external_fee_url = '';
		if ( $external_fee_enabled ) {
			$external_fee_amount_raw = trim( (string) ( $request['external_fee_amount'] ?? '' ) );
			if ( '' !== $external_fee_amount_raw && ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $external_fee_amount_raw ) ) $this->redirect_error( 'invalid_external_fee_amount', $event_id );
			$external_fee_amount = '' === $external_fee_amount_raw ? '' : number_format( (float) $external_fee_amount_raw, 2, '.', '' );
			$external_fee_url_raw = trim( (string) ( $request['external_fee_url'] ?? '' ) );
			$external_fee_url = esc_url_raw( $external_fee_url_raw, array( 'http', 'https' ) );
			if ( $external_fee_url_raw && ! $external_fee_url ) $this->redirect_error( 'invalid_external_fee_url', $event_id );
		}
		$raffle_enabled = $is_fundraising && in_array( strtolower( (string) ( $request['raffle'] ?? '' ) ), array( '1', 'true', 'yes', 'on' ), true );
		$raffle_cost = '';
		if ( $raffle_enabled ) {
			$raffle_cost_raw = trim( (string) ( $request['raffle_cost'] ?? '' ) );
			if ( '' !== $raffle_cost_raw && ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $raffle_cost_raw ) ) $this->redirect_error( 'invalid_raffle_cost', $event_id );
			$raffle_cost = '' === $raffle_cost_raw ? '' : number_format( (float) $raffle_cost_raw, 2, '.', '' );
		}
		$name = sanitize_text_field( $request['event_name'] ?? '' );
		if ( '' === $name ) $this->redirect_error( 'name_required', $event_id );
		$address = $this->normalise_address_value( $request['address'] ?? '' );
		if ( strlen( $address ) > 500 ) $this->redirect_error( 'invalid_address', $event_id );
		$previous_address = $event_id ? $this->normalise_address_value( get_post_meta( $event_id, 'address', true ) ) : '';
		if ( $address && $address !== $previous_address ) {
			$validated_address = $this->validate_address( $address );
			if ( is_wp_error( $validated_address ) ) $this->redirect_error( 'invalid_address', $event_id );
			$address = (string) $validated_address;
		}
		$date_status = sanitize_key( $request['event_date_status'] ?? 'confirmed' );
		if ( ! in_array( $date_status, array( 'confirmed', 'tbc' ), true ) ) $this->redirect_error( 'invalid_date_status', $event_id );
		$schedule_status = 'tbc' === $date_status ? 'tba' : 'scheduled';
		$expected_month = 'tbc' === $date_status && $event_id ? sanitize_text_field( (string) get_post_meta( $event_id, 'expected_month', true ) ) : '';
		if ( $expected_month && ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $expected_month ) ) $this->redirect_error( 'invalid_expected_month', $event_id );
		$registration_type_raw = sanitize_key( $request['registration_type'] ?? '' );
		$registration_type = Event_Public_Display::normalise_registration_type( $registration_type_raw );
		if ( $registration_type_raw && $registration_type_raw !== $registration_type ) $this->redirect_error( 'invalid_registration_type', $event_id );
		$external_url_raw = 'external_registration' === $registration_type ? trim( (string) ( $request['external_registration_url'] ?? '' ) ) : ( $event_id ? (string) get_post_meta( $event_id, 'external_registration_url', true ) : '' );
		$external_url = esc_url_raw( $external_url_raw, array( 'http', 'https' ) );
		if ( 'external_registration' === $registration_type && $external_url_raw && ! $external_url ) $this->redirect_error( 'invalid_external_url', $event_id );
		$no_registration_cta = 'none';
		$start = $this->datetime( $request['start_date'] ?? '', false ); $end = $this->datetime( $request['end_date__time'] ?? '', false );
		if ( 'website_registration' === $registration_type ) {
			$open = $this->datetime( $request['registration_open'] ?? '', false ); $close = $this->datetime( $request['registration_close'] ?? '', false ); $cancel = $this->datetime( $request['cancellation_deadline'] ?? '', false );
		} else {
			$open = $event_id ? (string) get_post_meta( $event_id, 'registration_open', true ) : '';
			$close = $event_id ? (string) get_post_meta( $event_id, 'registration_close', true ) : '';
			$cancel = $event_id ? (string) get_post_meta( $event_id, 'cancellation_deadline', true ) : '';
		}
		if ( is_wp_error( $start ) || is_wp_error( $end ) || is_wp_error( $open ) || is_wp_error( $close ) || is_wp_error( $cancel ) ) $this->redirect_error( 'invalid_date', $event_id );
		if ( 'tba' === $schedule_status ) { $start = ''; $end = ''; }
		if ( $start && $end && strtotime( $end ) <= strtotime( $start ) ) $this->redirect_error( 'end_before_start', $event_id );
		if ( 'website_registration' === $registration_type ) {
			if ( $open && $close && strtotime( $close ) <= strtotime( $open ) ) $this->redirect_error( 'registration_dates', $event_id );
			if ( $start && $cancel && strtotime( $cancel ) > strtotime( $start ) ) $this->redirect_error( 'cancellation_after_start', $event_id );
		}
		$capacity_raw = '' === (string) ( $request['event_capacity'] ?? '' ) ? '0' : (string) $request['event_capacity'];
		if ( ! preg_match( '/^\d+$/', $capacity_raw ) ) $this->redirect_error( 'invalid_capacity', $event_id );
		$capacity_preflight = $this->capacity->validate_total( $event_id, absint( $capacity_raw ), empty( $request['event_id'] ) );
		if ( is_wp_error( $capacity_preflight ) ) $this->redirect_error( $capacity_preflight->get_error_code(), $event_id );
		$status = in_array( $request['post_status'] ?? '', array( 'draft', 'publish' ), true ) ? $request['post_status'] : 'draft';
		if ( 'publish' === $status && 'external_registration' === $registration_type && ! $external_url ) $this->redirect_error( 'external_url_required', $event_id );
		$description = wp_kses_post( $request['_description'] ?? '' );
		$parking_access = sanitize_textarea_field( $request['parking_access'] ?? '' );
		if ( strlen( $parking_access ) > 3000 ) $this->redirect_error( 'parking_access_length', $event_id );
		$expectations = $this->sanitize_expectations( $request['what_to_expect'] ?? array() );
		if ( is_wp_error( $expectations ) ) $this->redirect_error( $expectations->get_error_code(), $event_id );
		$previous_post = $event_id ? get_post( $event_id ) : null;
		$post_data = array( 'post_type' => $post_type, 'post_status' => $status, 'post_title' => $name, 'post_content' => $description );
		if ( $event_id ) { $post_data['ID'] = $event_id; $result = wp_update_post( $post_data, true ); } else { $post_data['post_author'] = get_current_user_id(); $result = wp_insert_post( $post_data, true ); }
		if ( is_wp_error( $result ) ) $this->redirect_error( 'save_failed', $event_id );
		$event_id = (int) $result;
		$capacity_result = $this->capacity->save_total( $event_id, absint( $capacity_raw ), $is_new_event );
		if ( is_wp_error( $capacity_result ) ) {
			if ( $is_new_event ) {
				wp_delete_post( $event_id, true );
				$event_id = 0;
			} elseif ( $previous_post ) {
				$rollback = wp_update_post( array( 'ID' => $event_id, 'post_title' => $previous_post->post_title, 'post_content' => $previous_post->post_content, 'post_status' => $previous_post->post_status ), true );
				if ( is_wp_error( $rollback ) ) {
					$this->audit->write( $event_id, 'event_update', 'rollback_failed', array( 'reason' => $rollback->get_error_code(), 'capacity_error' => $capacity_result->get_error_code() ) );
					$this->redirect_error( 'save_failed', $event_id );
				}
			}
			$this->redirect_error( $capacity_result->get_error_code(), $event_id );
		}
		$start_key = sanitize_key( $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$end_key = sanitize_key( $this->settings->get( 'event_end', 'end_date__time' ) ) ?: 'end_date__time';
		$venue_key = sanitize_key( $this->settings->get( 'event_venue', 'venue' ) ) ?: 'venue';
		$organiser_key = sanitize_key( $this->settings->get( 'event_organiser', 'organiser' ) ) ?: 'organiser';
		$info_key = sanitize_key( $this->settings->get( 'event_info', '_description' ) ) ?: '_description';
		$website_registration_available = Event_Public_Display::website_registration_available( $registration_type, $date_status ) && ! $this->is_cancelled( $event_id );
		$interest_contact_available = Event_Public_Display::interest_contact_available( $registration_type, $date_status );
		$registration_enabled = $website_registration_available && isset( $request['registration_enabled'] ) ? 'true' : 'false';
		$show_remaining_spots = $website_registration_available && isset( $request['show_remaining_spots'] ) ? 'true' : 'false';
		$allow_waitlist = $website_registration_available && isset( $request['allow_waitlist'] ) ? 'true' : 'false';
		$show_interest_contact_button = $interest_contact_available && isset( $request['show_interest_contact_button'] ) ? 'true' : 'false';
		$meta = array( 'event_name' => $name, $info_key => $description, 'event_date_status' => $date_status, 'event_schedule_status' => $schedule_status, 'expected_month' => 'tba' === $schedule_status ? $expected_month : '', $start_key => Event_Datetime::storage( $start ), $end_key => Event_Datetime::storage( $end ), $venue_key => sanitize_text_field( $request['venue'] ?? '' ), 'address' => $address, $organiser_key => sanitize_text_field( $request['organiser'] ?? '' ), 'co_hosted_event' => in_array( strtolower( (string) ( $request['co_hosted_event'] ?? '' ) ), array( '1', 'true', 'yes', 'on' ), true ) ? 'true' : 'false', 'presenters' => sanitize_textarea_field( $request['presenters'] ?? '' ), 'parking_access' => $parking_access, 'what_to_expect' => $expectations, 'show_what_to_expect' => isset( $request['show_what_to_expect'] ) ? 'true' : 'false', 'registration_type' => $registration_type, 'external_registration_url' => $external_url, 'no_registration_cta' => $no_registration_cta, 'show_register_now_button' => 'false', 'show_remaining_spots' => $show_remaining_spots, 'registration_enabled' => $registration_enabled, 'show_interest_contact_button' => $show_interest_contact_button, 'registration_open' => $open, 'registration_close' => $close, 'allow_waitlist' => $allow_waitlist, 'cancellation_deadline' => $cancel );
		if ( $is_fundraising ) {
			$meta['amount_raised'] = $amount_raised;
			$meta['registration_fee'] = sanitize_text_field( $request['registration_fee'] ?? get_post_meta( $event_id, 'registration_fee', true ) );
			$meta['sponsorship_cost'] = sanitize_text_field( $request['sponsorship_cost'] ?? get_post_meta( $event_id, 'sponsorship_cost', true ) );
			$meta['external_fee_enabled'] = $external_fee_enabled ? 'true' : 'false';
			$meta['external_fee_amount'] = $external_fee_amount;
			$meta['external_fee_url'] = $external_fee_url;
			$meta['raffle'] = $raffle_enabled ? 'true' : 'false';
			$meta['raffle_cost'] = $raffle_cost;
		}
		foreach ( $meta as $key => $value ) update_post_meta( $event_id, $key, $value );
		if ( array_key_exists( 'featured_image_id', $request ) ) {
			if ( $featured_image_id ) {
				set_post_thumbnail( $event_id, $featured_image_id );
			} else {
				delete_post_thumbnail( $event_id );
			}
		}
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			$term_ids = $save_terms;
			$term_ids = array_values( array_filter( $term_ids, function ( $term_id ) { return term_exists( $term_id, self::TAXONOMY ); } ) );
			$saved_terms = wp_set_object_terms( $event_id, $term_ids, self::TAXONOMY, false );
			if ( is_wp_error( $saved_terms ) ) $this->redirect_error( 'save_failed', $event_id );
		}
		$action = empty( $request['event_id'] ) ? 'event_created' : 'event_updated';
		$this->audit->write( $event_id, $action, $status, array( 'post_type' => $post_type, 'event_name' => $name ) );
		$notification_dispatched = (string) get_post_meta( $event_id, 'schedule_confirmation_dispatched', true );
		if ( 'publish' === $status && 'scheduled' === $schedule_status && $start && ( ! $notification_dispatched || 'tba' === $previous_schedule_status || ! $previous_start ) ) {
			$this->email->notify_event_scheduled( $event_id );
			update_post_meta( $event_id, 'schedule_confirmation_dispatched', current_time( 'mysql' ) );
		}
		do_action( 'hherm/event-saved', $event_id, $meta, $action );
		$redirect_page = $is_fundraising ? self::FUNDRAISER_LIST_PAGE_SLUG : self::PAGE_SLUG;
		$redirect_args = $is_fundraising ? array( 'event_saved' => $event_id ) : array( 'event_id' => $event_id, 'event_saved' => $event_id );
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php?page=' . $redirect_page ) ) ); exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-protected category display and notices; category changes use separate nonce-verified handlers.
	public function render_categories( bool $embedded = false ): void {
		$this->require_taxonomy_admin();
		$taxonomy_exists = taxonomy_exists( self::TAXONOMY );
		$edit_id = isset( $_GET['edit_term'] ) ? absint( wp_unslash( $_GET['edit_term'] ) ) : 0;
		$edit_term = $taxonomy_exists && $edit_id ? get_term( $edit_id, self::TAXONOMY ) : null;
		if ( is_wp_error( $edit_term ) ) $edit_term = null;
		$locked_edit_name = '';
		if ( $edit_term && Event_Types::is_protected_category( $edit_term ) ) {
			$locked_edit_name = (string) $edit_term->name;
			$edit_term = null;
		}
		$terms = $taxonomy_exists ? get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name' ) ) : array();
		$return_page = $embedded ? 'hherm' : self::CATEGORY_PAGE_SLUG;
		$category_url = $this->category_admin_url( $return_page );
		?>
		<div id="hherm-event-categories" class="<?php echo $embedded ? 'hherm-settings-categories hherm-categories-wrap hherm-events-wrap' : 'wrap hherm-categories-wrap hherm-events-wrap'; ?>">
		<?php if ( $embedded ) : ?>
		<section class="hherm-settings-section"><div class="hherm-settings-heading"><div><span class="hherm-eyebrow">Structure</span><h2>Event categories</h2><p>Manage the categories you can assign to events.</p></div></div>
		<?php else : ?>
		<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Event Categories</h1><p>Manage the categories you can assign to events.</p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::LIST_PAGE_SLUG ) ); ?>">Back to events</a></header><hr class="wp-header-end">
		<?php endif; ?>
		<?php if ( isset( $_GET['category_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Category saved successfully.</p></div><?php elseif ( isset( $_GET['category_deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Category deleted.</p></div><?php elseif ( isset( $_GET['category_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( $this->category_error_message( sanitize_key( wp_unslash( $_GET['category_error'] ) ) ) ); ?></p></div><?php endif; ?>
		<?php if ( ! $taxonomy_exists ) : ?>
			<div class="notice notice-error"><p>The “event-category” taxonomy is not registered. Confirm JetEngine is active and the taxonomy slug is correct.</p></div>
		<?php else : ?>
			<div class="hherm-category-layout">
				<section class="hherm-form-card hherm-category-form"><div class="hherm-form-card__heading"><span class="dashicons dashicons-category"></span><div><h2><?php echo esc_html( $edit_term ? 'Edit category' : 'Add category' ); ?></h2><p>Changes are saved directly to the event-category taxonomy.</p></div></div>
				<?php if ( $locked_edit_name ) : ?><div class="notice notice-info inline"><p><strong><?php echo esc_html( $locked_edit_name ); ?></strong> is a locked event category because site templates and workflows depend on it. It cannot be edited or deleted here.</p></div><?php endif; ?>
				<?php if ( ! $edit_term ) : ?><div class="notice notice-warning inline hherm-category-warning"><p><strong>Important:</strong> Events use dynamic category configuration. Adding a new event category may not function as expected in every event layout or workflow. If it does not behave as expected, please contact Paul.</p></div><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::CATEGORY_ACTION ); ?>"><input type="hidden" name="term_id" value="<?php echo esc_attr( $edit_term ? $edit_term->term_id : 0 ); ?>"><input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>"><?php wp_nonce_field( self::CATEGORY_ACTION, 'hherm_category_nonce' ); ?><label><span>Name <strong>*</strong></span><input type="text" name="name" required value="<?php echo esc_attr( $edit_term ? $edit_term->name : '' ); ?>"></label><label><span>Slug</span><input type="text" name="slug" value="<?php echo esc_attr( $edit_term ? $edit_term->slug : '' ); ?>"><small>Leave blank to generate it from the name.</small></label><label><span>Description</span><textarea name="description" rows="5"><?php echo esc_textarea( $edit_term ? $edit_term->description : '' ); ?></textarea></label><p><button class="button button-primary"><?php echo esc_html( $edit_term ? 'Update category' : 'Add category' ); ?></button><?php if ( $edit_term ) : ?> <a class="button" href="<?php echo esc_url( $category_url ); ?>">Cancel</a><?php endif; ?></p></form></section>
				<section class="hherm-list-card"><div class="hherm-card-heading"><h2>Existing categories</h2><span><?php echo esc_html( is_wp_error( $terms ) ? '0 categories' : sprintf( '%d categories', count( $terms ) ) ); ?></span></div><div class="hherm-table-wrap"><table class="widefat fixed striped"><thead><tr><th>Name</th><th>Slug</th><th>Events</th><th>Actions</th></tr></thead><tbody><?php if ( ! is_wp_error( $terms ) && $terms ) : foreach ( $terms as $term ) : $objects = get_objects_in_term( $term->term_id, self::TAXONOMY ); $in_use = is_wp_error( $objects ) || ! empty( $objects ); $locked = Event_Types::is_protected_category( $term ); $usage_count = is_wp_error( $objects ) ? (int) $term->count : count( array_unique( array_map( 'intval', $objects ) ) ); ?><tr><td><strong><?php echo esc_html( $term->name ); ?></strong><?php if ( $term->description ) : ?><small class="hherm-term-description"><?php echo esc_html( $term->description ); ?></small><?php endif; ?></td><td><code><?php echo esc_html( $term->slug ); ?></code></td><td><?php echo esc_html( $usage_count ); ?></td><td class="hherm-row-actions"><div class="hherm-row-action-buttons"><?php if ( $locked ) : ?><span class="hherm-in-use">Locked</span><?php else : ?><a class="button button-small" href="<?php echo esc_url( $this->category_admin_url( $return_page, array( 'edit_term' => $term->term_id ) ) ); ?>">Edit</a><?php if ( ! $in_use ) : ?><a class="button button-small hherm-delete-link" data-hherm-confirm="Delete this category?" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::DELETE_CATEGORY_ACTION, 'term_id' => $term->term_id, 'return_page' => $return_page ), admin_url( 'admin-post.php' ) ), self::DELETE_CATEGORY_ACTION . '_' . $term->term_id ) ); ?>">Delete</a><?php else : ?><span class="hherm-in-use">In use</span><?php endif; ?><?php endif; ?></div></td></tr><?php endforeach; else : ?><tr><td colspan="4" class="hherm-empty-cell">No event categories have been created.</td></tr><?php endif; ?></tbody></table></div></section>
			</div>
		<?php endif; ?>
		<?php if ( $embedded ) : ?></section><?php endif; ?>
		</div><?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function handle_cancel_event(): void {
		$this->require_event_access( 'cancel events' );
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
		$return_page = isset( $_GET['return_page'] ) ? sanitize_key( wp_unslash( $_GET['return_page'] ) ) : self::LIST_PAGE_SLUG;
		if ( ! in_array( $return_page, array( self::LIST_PAGE_SLUG, self::FUNDRAISER_LIST_PAGE_SLUG ), true ) ) $return_page = self::LIST_PAGE_SLUG;
		check_admin_referer( self::CANCEL_ACTION . '_' . $event_id );
		if ( ! $event_id || $this->post_type() !== get_post_type( $event_id ) || ! current_user_can( 'edit_post', $event_id ) ) {
			$this->redirect_cancel_error( 'invalid', $return_page );
		}
		if ( ! $this->is_cancelled( $event_id ) && $this->has_finished( $event_id ) ) {
			$this->redirect_cancel_error( 'finished', $return_page );
		}
		$recipients = $this->repository->cancellation_recipients( $event_id );
		if ( is_wp_error( $recipients ) ) {
			$this->redirect_cancel_error( 'recipients', $return_page );
		}
		update_post_meta( $event_id, 'event_cancelled', 'true' );
		update_post_meta( $event_id, 'event_cancelled_date', current_time( 'mysql' ) );
		update_post_meta( $event_id, 'registration_enabled', 'false' );
		$event = $this->email_event( $event_id );
		$counts = array( 'sent' => 0, 'scheduled' => 0, 'logged' => 0, 'skipped' => 0, 'failed' => 0 );
		foreach ( $recipients as $recipient ) {
			$result = $this->email->send_cancellation( $recipient, $event );
			$email_status = sanitize_key( $result['status'] ?? 'failed' );
			if ( isset( $counts[ $email_status ] ) ) { ++$counts[ $email_status ]; } else { ++$counts['failed']; }
		}
		$this->audit->write( $event_id, 'event_cancelled', 'cancelled', array( 'event_id' => $event_id, 'recipients' => count( $recipients ), 'email_results' => $counts ) );
		do_action( 'hherm/event-cancelled', $event_id, $recipients, $counts );
		// Plain integer arguments: JSON does not survive wp_safe_redirect()'s URL sanitising.
		$result_args = array( 'cancel_result' => 1 );
		foreach ( $counts as $key => $count ) $result_args[ 'cancel_' . $key ] = (int) $count;
		wp_safe_redirect( add_query_arg( $result_args, admin_url( 'admin.php?page=' . $return_page ) ) );
		exit;
	}

	public function handle_category(): void {
		$this->require_taxonomy_admin(); check_admin_referer( self::CATEGORY_ACTION, 'hherm_category_nonce' );
		$request = wp_unslash( $_POST );
		$return_page = $this->category_return_page( $request['return_page'] ?? '' );
		$term_id = absint( $request['term_id'] ?? 0 ); $name = sanitize_text_field( $request['name'] ?? '' );
		$existing_term = $term_id ? get_term( $term_id, self::TAXONOMY ) : null;
		if ( $existing_term && ! is_wp_error( $existing_term ) && Event_Types::is_protected_category( $existing_term ) ) $this->redirect_category_error( 'category_locked', 0, $return_page );
		if ( '' === $name ) $this->redirect_category_error( 'name_required', $term_id, $return_page );
		$args = array( 'description' => sanitize_textarea_field( $request['description'] ?? '' ) ); $slug = sanitize_title( $request['slug'] ?? '' ); if ( $slug ) $args['slug'] = $slug;
		$result = $term_id ? wp_update_term( $term_id, self::TAXONOMY, array_merge( $args, array( 'name' => $name ) ) ) : wp_insert_term( $name, self::TAXONOMY, $args );
		if ( is_wp_error( $result ) ) $this->redirect_category_error( 'save_failed', $term_id, $return_page );
		wp_safe_redirect( $this->category_admin_url( $return_page, array( 'category_saved' => 1 ) ) ); exit;
	}

	public function handle_delete_category(): void {
		$this->require_taxonomy_admin();
		$return_page = $this->category_return_page( isset( $_GET['return_page'] ) ? sanitize_key( wp_unslash( $_GET['return_page'] ) ) : '' );
		$term_id = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0; check_admin_referer( self::DELETE_CATEGORY_ACTION . '_' . $term_id );
		$term = get_term( $term_id, self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) && Event_Types::is_protected_category( $term ) ) $this->redirect_category_error( 'category_locked', 0, $return_page );
		$objects = get_objects_in_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) || is_wp_error( $objects ) || ! empty( $objects ) ) $this->redirect_category_error( 'category_in_use', 0, $return_page );
		$result = wp_delete_term( $term_id, self::TAXONOMY ); if ( ! $result || is_wp_error( $result ) ) $this->redirect_category_error( 'delete_failed', 0, $return_page );
		wp_safe_redirect( $this->category_admin_url( $return_page, array( 'category_deleted' => 1 ) ) ); exit;
	}

	private function post_type(): string { return sanitize_key( $this->settings->get( 'events_cpt', 'events' ) ); }
	private function is_cancelled( int $event_id ): bool { return in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true ); }
	private function email_event( int $event_id ): array { $post = get_post( $event_id ); $start = (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ); $end = (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true ); return array( 'id' => $event_id, 'title' => $this->event_name( $event_id ), 'date' => $this->format_email_datetime( $start, Event_Datetime::DATE_FORMAT ), 'start' => $this->format_email_datetime( $start, Event_Datetime::TIME_FORMAT ), 'end' => $this->format_email_datetime( $end, Event_Datetime::TIME_FORMAT ), 'venue' => (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true ), 'organiser' => (string) get_post_meta( $event_id, $this->settings->get( 'event_organiser', 'organiser' ), true ), 'info' => (string) get_post_meta( $event_id, $this->settings->get( 'event_info', '_description' ), true ) ?: ( $post ? $post->post_content : '' ) ); }
	private function format_email_datetime( string $value, string $format ): string { return Event_Datetime::display( $value, $format ); }
	private function redirect_cancel_error( string $code, string $page = self::LIST_PAGE_SLUG ): void { wp_safe_redirect( add_query_arg( 'cancel_error', sanitize_key( $code ), admin_url( 'admin.php?page=' . $page ) ) ); exit; }

	/** True once the event's end (or start, when no end is recorded) has passed. */
	private function has_finished( int $post_id ): bool {
		$start_key = sanitize_key( $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date';
		$end_key = sanitize_key( $this->settings->get( 'event_end', 'end_date__time' ) ) ?: 'end_date__time';
		$finished_at = Event_Datetime::timestamp( get_post_meta( $post_id, $end_key, true ) ) ?: Event_Datetime::timestamp( get_post_meta( $post_id, $start_key, true ) );
		return $finished_at && $finished_at <= time();
	}
	private function require_event_access( string $task ): void { if ( ! current_user_can( Plugin::CAPABILITY ) ) wp_die( esc_html( sprintf( 'You do not have permission to %s.', $task ) ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) ); }
	private function require_admin( string $task ): void { if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html( sprintf( 'You do not have permission to %s.', $task ) ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) ); }
	private function require_taxonomy_admin(): void { $this->require_admin( 'manage event categories' ); if ( taxonomy_exists( self::TAXONOMY ) ) { $taxonomy = get_taxonomy( self::TAXONOMY ); if ( $taxonomy && ! current_user_can( $taxonomy->cap->manage_terms ) ) wp_die( esc_html__( 'You do not have permission to manage event categories.', 'heart-hub-event-registration-manager' ), '', array( 'response' => 403 ) ); } }
	private function event_name( int $post_id ): string { $name = (string) get_post_meta( $post_id, 'event_name', true ); return $name ?: ( get_the_title( $post_id ) ?: '(Untitled)' ); }
	private function is_waitlist_enabled( int $post_id ): bool { return in_array( strtolower( (string) get_post_meta( $post_id, 'allow_waitlist', true ) ), array( '1', 'true', 'yes', 'on' ), true ); }
	private function capacity_label( int $post_id ): string { $total = absint( get_post_meta( $post_id, 'event_capacity', true ) ); if ( ! $this->capacity->enabled() ) return (string) $total; $remaining = $this->capacity->remaining( $post_id ); return is_wp_error( $remaining ) ? 'Invalid' : sprintf( '%d remaining / %d', $remaining, $total ); }
	private function display_datetime( $value ): string { if ( ! $value ) { $post_id = get_the_ID(); return $post_id && 'tbc' === Event_Public_Display::normalise_date_status( get_post_meta( $post_id, 'event_date_status', true ), get_post_meta( $post_id, 'event_schedule_status', true ) ) ? 'Date TBC' : '—'; } return Event_Datetime::display( $value, Event_Datetime::DATETIME_FORMAT ); }
	private function term_names( $terms ): string { if ( ! $terms || is_wp_error( $terms ) ) return '—'; return implode( ', ', wp_list_pluck( $terms, 'name' ) ); }
	private function normalise_address_value( $value ): string { if ( is_array( $value ) ) { foreach ( array( 'address', 'formatted_address', 'location', 'value' ) as $key ) { if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) return sanitize_text_field( (string) $value[ $key ] ); } return ''; } return sanitize_text_field( (string) $value ); }
	private function event_values( $event ): array {
		$defaults = array( 'event_name' => '', '_description' => '', 'featured_image_id' => '0', 'event_date_status' => 'confirmed', 'event_schedule_status' => 'scheduled', 'expected_month' => '', 'start_date' => '', 'end_date__time' => '', 'venue' => '', 'address' => '', 'organiser' => '', 'co_hosted_event' => 'false', 'presenters' => '', 'amount_raised' => '', 'registration_fee' => '', 'sponsorship_cost' => '', 'external_fee_enabled' => 'false', 'external_fee_amount' => '', 'external_fee_url' => '', 'raffle' => 'false', 'raffle_cost' => '', 'parking_access' => '', 'what_to_expect' => array(), 'show_what_to_expect' => 'false', 'registration_type' => 'website_registration', 'external_registration_url' => '', 'no_registration_cta' => 'none', 'show_register_now_button' => 'false', 'show_remaining_spots' => 'false', 'registration_enabled' => 'true', 'show_interest_contact_button' => 'false', 'event_capacity' => '0', 'registration_open' => '', 'registration_close' => '', 'allow_waitlist' => 'false', 'cancellation_deadline' => '' );
		if ( ! $event ) return $defaults;
		$meta_keys = array(
			'event_name'    => 'event_name',
			'_description'  => sanitize_key( $this->settings->get( 'event_info', '_description' ) ) ?: '_description',
			'start_date'    => sanitize_key( $this->settings->get( 'event_start', 'start_date' ) ) ?: 'start_date',
			'end_date__time'=> sanitize_key( $this->settings->get( 'event_end', 'end_date__time' ) ) ?: 'end_date__time',
			'venue'         => sanitize_key( $this->settings->get( 'event_venue', 'venue' ) ) ?: 'venue',
			'organiser'     => sanitize_key( $this->settings->get( 'event_organiser', 'organiser' ) ) ?: 'organiser',
		);
		foreach ( $defaults as $key => $default ) {
			$value = get_post_meta( $event->ID, $meta_keys[ $key ] ?? $key, true );
			if ( 'what_to_expect' === $key ) {
				if ( is_string( $value ) && '' !== $value ) { $decoded = json_decode( $value, true ); $value = is_array( $decoded ) ? $decoded : array(); }
				$defaults[ $key ] = is_array( $value ) ? $this->normalise_expectations( $value ) : array();
			} else {
				$defaults[ $key ] = '' !== (string) $value ? $value : $default;
			}
		}
		$defaults['featured_image_id'] = (string) get_post_thumbnail_id( $event->ID );
		foreach ( array( 'start_date', 'end_date__time', 'registration_open', 'registration_close', 'cancellation_deadline' ) as $date_key ) {
			$defaults[ $date_key ] = Event_Datetime::editor_input( $defaults[ $date_key ] );
		}
		$defaults['raffle'] = in_array( strtolower( (string) $defaults['raffle'] ), array( '1', 'true', 'yes', 'on' ), true ) ? 'true' : 'false';
		$defaults['external_fee_enabled'] = in_array( strtolower( (string) $defaults['external_fee_enabled'] ), array( '1', 'true', 'yes', 'on' ), true ) ? 'true' : 'false';
		$defaults['co_hosted_event'] = in_array( strtolower( (string) $defaults['co_hosted_event'] ), array( '1', 'true', 'yes', 'on' ), true ) ? 'true' : 'false';
		$stored_schedule_status = sanitize_key( (string) get_post_meta( $event->ID, 'event_schedule_status', true ) );
		$defaults['event_date_status'] = Event_Public_Display::normalise_date_status( get_post_meta( $event->ID, 'event_date_status', true ), $stored_schedule_status );
		$defaults['event_schedule_status'] = 'tbc' === $defaults['event_date_status'] ? 'tba' : 'scheduled';
		$defaults['registration_type'] = Event_Public_Display::normalise_registration_type( $defaults['registration_type'] );
		foreach ( array( 'show_what_to_expect', 'show_register_now_button', 'show_remaining_spots', 'registration_enabled', 'show_interest_contact_button', 'allow_waitlist' ) as $flag ) {
			$defaults[ $flag ] = Event_Public_Display::normalise_flag( $defaults[ $flag ], 'allow_waitlist' !== $flag ) ? 'true' : 'false';
		}
		$defaults['event_name'] = $defaults['event_name'] ?: $event->post_title;
		$defaults['_description'] = $defaults['_description'] ?: $event->post_content;
		return $defaults;
	}
	private function submitted_event_values( array $values, array $request ): array {
		$text_fields = array( 'event_name', 'featured_image_id', 'event_date_status', 'start_date', 'end_date__time', 'venue', 'organiser', 'amount_raised', 'registration_fee', 'sponsorship_cost', 'external_fee_amount', 'external_fee_url', 'raffle_cost', 'registration_type', 'external_registration_url', 'event_capacity', 'registration_open', 'registration_close', 'cancellation_deadline' );
		foreach ( $text_fields as $field ) {
			if ( array_key_exists( $field, $request ) ) $values[ $field ] = is_scalar( $request[ $field ] ) ? sanitize_text_field( (string) $request[ $field ] ) : '';
		}
		foreach ( array( 'presenters', 'parking_access' ) as $field ) {
			if ( array_key_exists( $field, $request ) ) $values[ $field ] = is_scalar( $request[ $field ] ) ? sanitize_textarea_field( (string) $request[ $field ] ) : '';
		}
		if ( array_key_exists( 'address', $request ) ) $values['address'] = $this->normalise_address_value( $request['address'] );
		if ( array_key_exists( '_description', $request ) ) $values['_description'] = is_scalar( $request['_description'] ) ? wp_kses_post( (string) $request['_description'] ) : '';
		$values['what_to_expect'] = isset( $request['what_to_expect'] ) && is_array( $request['what_to_expect'] ) ? $this->normalise_expectations( $request['what_to_expect'] ) : array();
		foreach ( array( 'co_hosted_event', 'raffle', 'external_fee_enabled', 'show_what_to_expect', 'registration_enabled', 'show_remaining_spots', 'allow_waitlist', 'show_interest_contact_button' ) as $flag ) {
			$values[ $flag ] = isset( $request[ $flag ] ) ? 'true' : 'false';
		}
		$values['event_schedule_status'] = 'tbc' === ( $values['event_date_status'] ?? '' ) ? 'tba' : 'scheduled';
		return $values;
	}
	private function event_retry_request( int $event_id ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The random token only retrieves the current user's short-lived draft after the nonce-verified save handler redirects.
		$token = isset( $_GET['event_retry'] ) ? sanitize_key( wp_unslash( $_GET['event_retry'] ) ) : '';
		if ( ! $token || ! preg_match( '/^[a-z0-9]{20}$/', $token ) ) return array();
		$retry = get_transient( $this->event_retry_key( $token ) );
		if ( ! is_array( $retry ) || absint( $retry['event_id'] ?? 0 ) !== $event_id || ! isset( $retry['request'] ) || ! is_array( $retry['request'] ) ) return array();
		return $retry['request'];
	}
	private function store_event_retry( int $event_id ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called only from handle() after its event-save nonce has been verified.
		$request = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		if ( ! is_array( $request ) || ! $request ) return '';
		// Preserve the permitted formatting and line breaks that scalar text sanitization intentionally removes.
		if ( isset( $_POST['_description'] ) && is_scalar( $_POST['_description'] ) ) $request['_description'] = wp_kses_post( wp_unslash( $_POST['_description'] ) );
		foreach ( array( 'presenters', 'parking_access' ) as $field ) {
			if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) $request[ $field ] = sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) );
		}
		if ( isset( $_POST['what_to_expect'] ) && is_array( $_POST['what_to_expect'] ) ) $request['what_to_expect'] = $this->normalise_expectations( wp_unslash( $_POST['what_to_expect'] ) );
		unset( $request['action'], $request['hherm_event_nonce'], $request['_wp_http_referer'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$token = strtolower( wp_generate_password( 20, false, false ) );
		return set_transient( $this->event_retry_key( $token ), array( 'event_id' => $event_id, 'request' => $request ), HOUR_IN_SECONDS ) ? $token : '';
	}
	private function event_retry_key( string $token ): string { return 'hherm_event_retry_' . get_current_user_id() . '_' . $token; }
	private function error_field( string $code, array $request = array() ): string {
		if ( 'invalid_date' === $code ) {
			$date_fields = array( 'start_date', 'end_date__time' );
			if ( 'website_registration' === Event_Public_Display::normalise_registration_type( $request['registration_type'] ?? '' ) ) $date_fields = array_merge( $date_fields, array( 'registration_open', 'registration_close', 'cancellation_deadline' ) );
			foreach ( $date_fields as $field ) {
				if ( isset( $request[ $field ] ) && is_wp_error( $this->datetime( $request[ $field ], false ) ) ) return $field;
			}
			return 'start_date';
		}
		$fields = array(
			'invalid_event_type'          => 'event_type',
			'event_type_missing'          => 'event_type',
			'name_required'               => 'event_name',
			'invalid_date_status'         => 'event_date_status',
			'end_before_start'            => 'end_date__time',
			'registration_dates'          => 'registration_close',
			'cancellation_after_start'    => 'cancellation_deadline',
			'invalid_registration_type'   => 'registration_type',
			'invalid_external_url'        => 'external_registration_url',
			'external_url_required'       => 'external_registration_url',
			'invalid_amount_raised'       => 'amount_raised',
			'invalid_raffle_cost'         => 'raffle_cost',
			'invalid_external_fee_amount' => 'external_fee_amount',
			'invalid_external_fee_url'    => 'external_fee_url',
			'invalid_capacity'            => 'event_capacity',
			'hherm_capacity_below_reserved' => 'event_capacity',
			'hherm_capacity_locked'       => 'event_capacity',
			'hherm_capacity_update_failed'=> 'event_capacity',
			'invalid_address'             => 'address',
			'parking_access_length'       => 'parking_access',
			'invalid_expectations'        => 'what_to_expect',
			'expectations_limit'          => 'what_to_expect',
			'expectations_incomplete'     => 'what_to_expect',
			'expectations_length'         => 'what_to_expect',
		);
		return $fields[ $code ] ?? '';
	}
	private function validate_address( string $address ) {
		if ( ! class_exists( '\Jet_Engine\Modules\Maps_Listings\Module' ) ) return $address;
		try {
			$module = \Jet_Engine\Modules\Maps_Listings\Module::instance();
			$provider_id = isset( $module->settings ) && method_exists( $module->settings, 'get' ) ? sanitize_key( (string) $module->settings->get( 'geocode_provider' ) ) : '';
			$provider = $provider_id && isset( $module->providers ) && method_exists( $module->providers, 'get_providers' ) ? $module->providers->get_providers( 'geocode', $provider_id ) : false;
			if ( ! $provider || ! method_exists( $provider, 'get_location_data' ) ) return $address;
			$location = $provider->get_location_data( $address );
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'invalid_address' );
		}
		if ( is_wp_error( $location ) || ! is_array( $location ) || ! isset( $location['lat'], $location['lng'] ) || ! is_numeric( $location['lat'] ) || ! is_numeric( $location['lng'] ) ) return new \WP_Error( 'invalid_address' );
		return $address;
	}
	private function render_expectation_row( array $entry, $index ): void { ?><div class="hherm-expectation-row" data-expectation-row><span class="hherm-expectation-index" data-row-number><?php echo is_numeric( $index ) ? esc_html( (int) $index + 1 ) : ''; ?></span><label class="hherm-expectation-field"><span>Title</span><input type="text" name="what_to_expect[<?php echo esc_attr( $index ); ?>][title]" required maxlength="200" value="<?php echo esc_attr( $entry['title'] ?? '' ); ?>"></label><label class="hherm-expectation-field"><span>Content</span><textarea name="what_to_expect[<?php echo esc_attr( $index ); ?>][content]" required maxlength="5000" rows="2"><?php echo esc_textarea( $entry['content'] ?? '' ); ?></textarea></label><button type="button" class="hherm-expectation-remove" data-remove-expectation aria-label="Remove what to expect item">&minus;</button></div><?php }
	private function sanitize_expectations( $raw ) { if ( ! is_array( $raw ) ) return new \WP_Error( 'invalid_expectations' ); if ( count( $raw ) > 6 ) return new \WP_Error( 'expectations_limit' ); $items = array(); foreach ( $raw as $entry ) { if ( ! is_array( $entry ) ) return new \WP_Error( 'invalid_expectations' ); $title = sanitize_text_field( $entry['title'] ?? '' ); $content = sanitize_textarea_field( $entry['content'] ?? '' ); if ( '' === $title && '' === $content ) continue; if ( '' === $title || '' === $content ) return new \WP_Error( 'expectations_incomplete' ); if ( strlen( $title ) > 200 || strlen( $content ) > 5000 ) return new \WP_Error( 'expectations_length' ); $items[] = array( 'title' => $title, 'content' => $content ); } return $items; }
	private function normalise_expectations( array $items ): array { $normalised = array(); foreach ( array_slice( $items, 0, 6 ) as $entry ) { if ( ! is_array( $entry ) ) continue; $title = sanitize_text_field( $entry['title'] ?? '' ); $content = sanitize_textarea_field( $entry['content'] ?? '' ); if ( '' !== $title || '' !== $content ) $normalised[] = array( 'title' => $title, 'content' => $content ); } return $normalised; }
	private function switch_field( string $name, string $label, string $help, array $values, string $attributes = '' ): void { ?><label class="hherm-switch" <?php echo $attributes ? esc_attr( $attributes ) : ''; ?>><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="true" <?php checked( 'true', (string) $values[ $name ] ); ?>><span class="hherm-switch-control" aria-hidden="true"></span><span class="hherm-switch-copy"><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $help ); ?></small></span></label><?php }
	private function datetime( $value, bool $required ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) return $required ? new \WP_Error( 'required_datetime' ) : '';
		$iso = Event_Datetime::editor_storage( $value );
		return null === $iso ? new \WP_Error( 'invalid_datetime' ) : $iso;
	}
	private function redirect_error( string $code, int $event_id = 0 ): void {
		$args = array( 'event_error' => sanitize_key( $code ) );
		if ( $event_id ) $args['event_id'] = $event_id;
		$retry = $this->store_event_retry( $event_id );
		if ( $retry ) $args['event_retry'] = $retry;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from handle() after its event-save nonce has been verified.
		if ( ! $event_id && 'fundraising' === sanitize_key( wp_unslash( $_POST['event_type'] ?? '' ) ) ) $args['context'] = 'fundraising';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
	// Categories are managed in Settings › Event Setup; the old standalone screen redirects there.
	private function category_return_page( $page ): string { return 'hherm'; }
	private function category_admin_url( string $page, array $args = array() ): string { $page = $this->category_return_page( $page ); if ( 'hherm' === $page ) $args = array_merge( array( 'tab' => 'event-setup' ), $args ); $url = admin_url( 'admin.php?page=' . $page ); if ( $args ) $url = add_query_arg( $args, $url ); return 'hherm' === $page ? $url . '#hherm-event-categories' : $url; }
	private function redirect_category_error( string $code, int $term_id = 0, string $page = self::CATEGORY_PAGE_SLUG ): void { $args = array( 'category_error' => sanitize_key( $code ) ); if ( $term_id ) $args['edit_term'] = $term_id; wp_safe_redirect( $this->category_admin_url( $page, $args ) ); exit; }
	private function error_message( string $code ): string { $messages = array( 'invalid_event_type' => 'Choose a valid event type.', 'event_type_missing' => 'Create the selected Workshop or Fundraising event category first.', 'post_type_missing' => 'The Events post type is not available.', 'post_type_capability' => 'Your account cannot save posts in the Events post type.', 'event_missing' => 'That event could not be found.', 'name_required' => 'Enter an event name.', 'invalid_date_status' => 'Choose Date Confirmed or Date TBC.', 'invalid_featured_image' => 'Choose a valid image from the media library.', 'invalid_external_fee_amount' => 'External fee must be zero or more, with no more than two decimal places.', 'invalid_external_fee_url' => 'Enter an external fee URL beginning with https:// or http://.', 'invalid_date' => 'Enter dates as DD/MM/YYYY hh:mm am/pm, for example 21/11/2026 08:00 am.', 'end_before_start' => 'The event end must be later than its start.', 'registration_dates' => 'Registration closing must be later than registration opening.', 'cancellation_after_start' => 'The cancellation deadline cannot be after the event starts.', 'invalid_registration_type' => 'Choose Website Registration, External Registration or No Registration.', 'invalid_external_url' => 'Enter a complete External Registration URL beginning with http:// or https://.', 'external_url_required' => 'Enter an External Registration URL before publishing an External Registration event.', 'invalid_amount_raised' => 'Amount raised must be zero or more, with no more than two decimal places.', 'invalid_raffle_cost' => 'Raffle entry cost must be zero or more, with no more than two decimal places.', 'invalid_capacity' => 'Event capacity must be a whole number of zero or more.', 'invalid_address' => 'Google could not validate that address. Choose a suggested address or enter a complete postal address and try again.', 'parking_access_length' => 'Parking and access instructions must be 3,000 characters or fewer.', 'invalid_expectations' => 'The What to Expect entries are invalid.', 'expectations_limit' => 'Add no more than six What to Expect entries.', 'expectations_incomplete' => 'Every What to Expect entry needs both a title and content.', 'expectations_length' => 'What to Expect titles must be 200 characters or fewer and content 5,000 characters or fewer.', 'hherm_capacity_below_reserved' => 'Capacity cannot be reduced below the number of attendee places already reserved.', 'hherm_capacity_locked' => 'This event capacity is being updated. Please try again.', 'hherm_capacity_update_failed' => 'WordPress could not update the event capacity.', 'save_failed' => 'WordPress could not save the event. Please try again.' ); return $messages[ $code ] ?? 'The event could not be saved. Please check the form and try again.'; }
	private function category_error_message( string $code ): string { $messages = array( 'name_required' => 'Enter a category name.', 'save_failed' => 'WordPress could not save the category. The name or slug may already exist.', 'category_locked' => 'That event category is locked because site templates and workflows depend on it.', 'category_in_use' => 'Only categories that are not assigned to events can be deleted.', 'delete_failed' => 'WordPress could not delete the category.' ); return $messages[ $code ] ?? 'The category could not be changed.'; }
}
