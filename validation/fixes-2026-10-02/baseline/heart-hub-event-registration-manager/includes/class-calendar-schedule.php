<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen for dated entries displayed by the JetEngine Events calendar.
 */
final class Calendar_Schedule {
	public const PAGE_SLUG = 'hherm-calendar-schedule';
	public const SAVE_ACTION = 'hherm_save_calendar_entry';
	public const TRASH_ACTION = 'hherm_trash_calendar_entry';
	public const TYPE_META = 'hherm_calendar_entry_type';
	public const MASTER_META = 'hherm_calendar_series_master';
	public const RULE_META = 'hherm_calendar_recurrence_rule';
	public const SCHEDULE_STATUS_META = 'hherm_calendar_schedule_status';
	private const TAXONOMY = 'event-category';
	private const TERM_SLUG = 'calendar-schedule';
	private const RETRY_PREFIX = 'hherm_calendar_retry_';

	private $settings;
	private $audit;
	private $hook_suffix = '';

	public function __construct( Settings $settings, Audit_Log $audit ) {
		$this->settings = $settings;
		$this->audit = $audit;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'ensure_category' ), 99 );
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::TRASH_ACTION, array( $this, 'handle_trash' ) );
	}

	public function ensure_category(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			$this->calendar_term_id();
		}
		$this->ensure_schedule_statuses();
	}

	private function ensure_schedule_statuses(): void {
		// This backfill runs on admin_init; limit it to an hourly check rather than every admin, AJAX and heartbeat request.
		if ( wp_doing_ajax() || get_transient( 'hherm_schedule_status_check' ) ) return;
		set_transient( 'hherm_schedule_status_check', 1, HOUR_IN_SECONDS );
		$post_type = $this->post_type();
		if ( ! post_type_exists( $post_type ) ) return;
		$entries = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'future', 'private', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => self::TYPE_META, 'compare' => 'EXISTS' ),
					array( 'key' => self::SCHEDULE_STATUS_META, 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		foreach ( $entries as $post_id ) update_post_meta( $post_id, self::SCHEDULE_STATUS_META, get_post_status( $post_id ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			Admin_Page::MENU_SLUG,
			'Calendar Schedule',
			'Calendar Schedule',
			Plugin::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'hherm-admin-base', HHERM_URL . 'assets/admin-base.css', array(), HHERM_VERSION );
		wp_enqueue_style( 'hherm-events', HHERM_URL . 'assets/events.css', array( 'hherm-admin-base' ), HHERM_VERSION );
		wp_enqueue_style( 'hherm-calendar-schedule', HHERM_URL . 'assets/calendar-schedule.css', array( 'hherm-admin-base' ), HHERM_VERSION );
		wp_enqueue_script( 'hherm-calendar-schedule', HHERM_URL . 'assets/calendar-schedule.js', array(), HHERM_VERSION, true );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters and editor selection on a capability-protected screen; all changes use separate nonce-verified handlers.
	public function render(): void {
		$this->require_access();
		$post_type = $this->post_type();
		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		$is_new = isset( $_GET['new'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['new'] ) );
		$editing = $entry_id || $is_new;
		$error = isset( $_GET['calendar_error'] ) ? sanitize_key( wp_unslash( $_GET['calendar_error'] ) ) : '';
		$retry_token = isset( $_GET['retry'] ) ? sanitize_key( wp_unslash( $_GET['retry'] ) ) : '';
		$retry_values = $retry_token ? get_transient( $this->retry_key( $retry_token ) ) : false;
		if ( $retry_token ) {
			delete_transient( $this->retry_key( $retry_token ) );
		}

		$entry = $entry_id ? get_post( $entry_id ) : null;
		if ( $entry_id && ( ! $entry || $entry->post_type !== $post_type || ! metadata_exists( 'post', $entry_id, self::TYPE_META ) ) ) {
			$entry = null;
			$entry_id = 0;
			$error = $error ?: 'entry_missing';
		}
		$values = $this->entry_values( $entry );
		if ( is_array( $retry_values ) ) {
			$values = array_merge( $values, $retry_values );
		}

		if ( $editing ) {
			$this->render_editor( $post_type, $entry_id, $values, $error );
			return;
		}

		$search = isset( $_GET['calendar_search'] ) ? sanitize_text_field( wp_unslash( $_GET['calendar_search'] ) ) : '';
		$status = isset( $_GET['calendar_status'] ) ? sanitize_key( wp_unslash( $_GET['calendar_status'] ) ) : '';
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		$allowed_statuses = array( 'publish', 'draft' );
		$start_key = $this->meta_key( 'event_start', 'start_date' );
		$query = post_type_exists( $post_type ) ? new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $allowed_statuses,
				'posts_per_page' => 20,
				'paged'          => $paged,
				's'              => $search,
				'meta_key'       => $start_key,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => $this->list_meta_query( $status ),
			)
		) : null;
		?>
		<div class="wrap hherm-events-wrap hherm-calendar-schedule-wrap">
			<header class="hherm-page-header">
				<div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Calendar Schedule</h1><p>Manage one-off entries and repeating opening hours, community visits, speeches, and other dates shown on the plugin calendar.</p></div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&new=1' ) ); ?>">Add schedule</a>
			</header><hr class="wp-header-end">
			<?php if ( isset( $_GET['calendar_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Calendar schedule saved. Published dates are available to the Events calendar.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['calendar_trashed'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Calendar entry moved to the trash.</p></div><?php endif; ?>
			<?php if ( ! post_type_exists( $post_type ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( 'The “%s” post type is not registered. Confirm JetEngine is active and the Events CPT slug is correct.', $post_type ) ); ?></p></div>
			<?php elseif ( ! taxonomy_exists( self::TAXONOMY ) ) : ?>
				<div class="notice notice-error"><p>The event-category taxonomy is not registered. Calendar entries need it to receive the Calendar Schedule category.</p></div>
			<?php else : ?>
				<form class="hherm-calendar-filters" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><label><span>Search entries</span><input type="search" name="calendar_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Entry name or description"></label><label><span>Status</span><select name="calendar_status"><option value="">All statuses</option><?php foreach ( $allowed_statuses as $item ) : ?><option value="<?php echo esc_attr( $item ); ?>" <?php selected( $status, $item ); ?>><?php echo esc_html( ucfirst( $item ) ); ?></option><?php endforeach; ?></select></label><div class="hherm-filter-actions"><button class="button button-primary">Filter</button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Clear</a></div></form>
				<section class="hherm-list-card"><div class="hherm-table-wrap"><table class="widefat fixed striped"><thead><tr><th>Entry</th><th>Type</th><th>Schedule</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead><tbody>
				<?php if ( $query && $query->have_posts() ) : while ( $query->have_posts() ) : $query->the_post(); $post_id = get_the_ID(); $item_type = sanitize_key( (string) get_post_meta( $post_id, self::TYPE_META, true ) ); $is_series = '1' === get_post_meta( $post_id, self::MASTER_META, true ); $effective_status = sanitize_key( (string) get_post_meta( $post_id, self::SCHEDULE_STATUS_META, true ) ) ?: get_post_status( $post_id ); ?>
					<tr>
						<td><strong><?php echo esc_html( get_the_title( $post_id ) ); ?></strong></td>
						<td><?php echo esc_html( $this->type_label( $item_type ) ); ?></td>
						<td><?php echo esc_html( $is_series ? $this->recurrence_label( get_post_meta( $post_id, self::RULE_META, true ) ) : $this->display_range( $post_id ) ); ?></td>
						<td><?php echo esc_html( (string) get_post_meta( $post_id, $this->meta_key( 'event_venue', 'venue' ), true ) ?: '—' ); ?></td>
						<td><span class="hherm-post-status hherm-post-status--<?php echo esc_attr( $effective_status ); ?>"><?php echo esc_html( ucfirst( $effective_status ) ); ?></span></td>
						<td class="hherm-row-actions"><div class="hherm-row-action-buttons"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&entry_id=' . $post_id ) ); ?>">Edit</a><?php if ( ! $is_series && 'publish' === $effective_status ) : ?><a class="button button-small" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener">View Page</a><?php endif; ?><a class="button button-small hherm-delete-link" data-hherm-confirm="Move this calendar entry to the trash?" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::TRASH_ACTION, 'entry_id' => $post_id ), admin_url( 'admin-post.php' ) ), self::TRASH_ACTION . '_' . $post_id ) ); ?>">Trash</a></div></td>
					</tr>
				<?php endwhile; wp_reset_postdata(); else : ?><tr><td colspan="6" class="hherm-empty-cell">No calendar entries found.</td></tr><?php endif; ?>
				</tbody></table></div></section>
				<?php if ( $query && $query->max_num_pages > 1 ) : ?><nav class="hherm-list-pagination" aria-label="Calendar schedule pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', remove_query_arg( array( 'calendar_saved', 'calendar_trashed', 'calendar_error' ) ) ), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages, 'type' => 'list' ) ) ); ?></nav><?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function handle_save(): void {
		$this->require_access();
		check_admin_referer( self::SAVE_ACTION, 'hherm_calendar_nonce' );
		$request = wp_unslash( $_POST );
		$post_type = $this->post_type();
		$entry_id = absint( $request['entry_id'] ?? 0 );
		$is_new = ! $entry_id;
		if ( ! post_type_exists( $post_type ) ) {
			$this->redirect_error( 'post_type_missing', $entry_id, $request );
		}
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			$this->redirect_error( 'taxonomy_missing', $entry_id, $request );
		}
		$existing_post = $entry_id ? get_post( $entry_id ) : null;
		if ( $entry_id && ( ! $existing_post || $existing_post->post_type !== $post_type || ! metadata_exists( 'post', $entry_id, self::TYPE_META ) || ! current_user_can( 'edit_post', $entry_id ) ) ) {
			$this->redirect_error( 'entry_missing', 0, $request );
		}

		$type = sanitize_key( $this->request_text( $request, 'calendar_entry_type' ) );
		$title = $this->request_text( $request, 'entry_title' );
		$frequency = sanitize_key( $this->request_text( $request, 'recurrence_frequency' ) ) ?: 'none';
		$start_input = $this->request_text( $request, 'start_date' );
		$end_input = $this->request_text( $request, 'end_date__time' );
		$venue = $this->request_text( $request, 'venue' );
		$description = $this->request_text( $request, 'description', true );
		$status_raw = $this->request_text( $request, 'post_status' );
		$status = in_array( $status_raw, array( 'draft', 'publish' ), true ) ? $status_raw : 'draft';
		if ( ! array_key_exists( $type, $this->types() ) ) {
			$this->redirect_error( 'invalid_type', $entry_id, $request );
		}
		if ( ! array_key_exists( $frequency, $this->frequencies() ) ) {
			$this->redirect_error( 'invalid_frequency', $entry_id, $request );
		}
		if ( '' === $title ) {
			$this->redirect_error( 'title_required', $entry_id, $request );
		}
		if ( strlen( $title ) > 200 ) {
			$this->redirect_error( 'title_length', $entry_id, $request );
		}
		$rule = array();
		if ( 'none' === $frequency ) {
			$start_local = Event_Datetime::editor_storage( $start_input );
			$end_local = Event_Datetime::editor_storage( $end_input );
			if ( null === $start_local || '' === $start_local ) $this->redirect_error( 'start_required', $entry_id, $request );
			if ( null === $end_local ) $this->redirect_error( 'invalid_datetime', $entry_id, $request );
			if ( $end_local && Event_Datetime::timestamp( $end_local ) <= Event_Datetime::timestamp( $start_local ) ) $this->redirect_error( 'end_before_start', $entry_id, $request );
		} else {
			$rule = $this->recurrence_rule_from_request( $request, $frequency );
			if ( is_wp_error( $rule ) ) $this->redirect_error( $rule->get_error_code(), $entry_id, $request );
			$anchor = 'dates' === $frequency ? $rule['dates'][0] : $rule['start_date'];
			$start_local = $anchor . 'T' . $rule['slots'][0]['start'];
			$end_local = $rule['slots'][0]['end'] ? $anchor . 'T' . $rule['slots'][0]['end'] : '';
		}
		if ( '' === $venue ) {
			$this->redirect_error( 'venue_required', $entry_id, $request );
		}
		if ( strlen( $venue ) > 200 ) {
			$this->redirect_error( 'venue_length', $entry_id, $request );
		}
		if ( strlen( $description ) > 5000 ) {
			$this->redirect_error( 'description_length', $entry_id, $request );
		}

		$term_id = $this->calendar_term_id();
		if ( is_wp_error( $term_id ) ) {
			$this->redirect_error( 'category_failed', $entry_id, $request );
		}
		$post_data = array(
			'post_type'    => $post_type,
			'post_status'  => 'none' === $frequency ? $status : 'draft',
			'post_title'   => $title,
			'post_content' => $description,
		);
		if ( $entry_id ) {
			$post_data['ID'] = $entry_id;
			$result = wp_update_post( $post_data, true );
		} else {
			$post_data['post_author'] = get_current_user_id();
			$result = wp_insert_post( $post_data, true );
		}
		if ( is_wp_error( $result ) || ! $result ) {
			$this->redirect_error( 'save_failed', $entry_id, $request );
		}
		$entry_id = (int) $result;

		$start_key = $this->meta_key( 'event_start', 'start_date' );
		$end_key = $this->meta_key( 'event_end', 'end_date__time' );
		$venue_key = $this->meta_key( 'event_venue', 'venue' );
		$info_key = $this->meta_key( 'event_info', '_description' );
		$meta = array(
			'event_name'                => $title,
			self::TYPE_META             => $type,
			self::SCHEDULE_STATUS_META  => $status,
			$start_key                  => Event_Datetime::storage( $start_local ),
			$end_key                    => Event_Datetime::storage( $end_local ),
			$venue_key                  => $venue,
			$info_key                   => $description,
			'event_date_status'         => 'confirmed',
			'event_schedule_status'     => 'scheduled',
			'expected_month'            => '',
			'registration_type'         => 'no_registration',
			'registration_enabled'      => 'false',
			'show_remaining_spots'      => 'false',
			'allow_waitlist'            => 'false',
			'show_what_to_expect'       => 'false',
			'show_interest_contact_button' => 'false',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $entry_id, $key, $value );
		}
		if ( 'none' === $frequency ) {
			delete_post_meta( $entry_id, self::MASTER_META );
			delete_post_meta( $entry_id, self::RULE_META );
		} else {
			update_post_meta( $entry_id, self::MASTER_META, '1' );
			update_post_meta( $entry_id, self::RULE_META, $rule );
		}

		$existing_terms = wp_get_object_terms( $entry_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $existing_terms ) ) {
			$this->redirect_error( 'category_failed', $entry_id, $request );
		}
		$term_ids = array_values( array_unique( array_merge( array_map( 'absint', $existing_terms ), array( $term_id ) ) ) );
		$assigned = wp_set_object_terms( $entry_id, $term_ids, self::TAXONOMY, false );
		if ( is_wp_error( $assigned ) ) {
			$this->redirect_error( 'category_failed', $entry_id, $request );
		}
		$this->audit->write( $entry_id, $is_new ? 'calendar_entry_created' : 'calendar_entry_updated', $status, array( 'post_type' => $post_type, 'entry_type' => $type, 'entry_title' => $title, 'recurrence' => $frequency ) );

		wp_safe_redirect( add_query_arg( array( 'calendar_saved' => 1 ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	public function handle_trash(): void {
		$this->require_access();
		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		check_admin_referer( self::TRASH_ACTION . '_' . $entry_id );
		$post = $entry_id ? get_post( $entry_id ) : null;
		if ( ! $post || $post->post_type !== $this->post_type() || ! metadata_exists( 'post', $entry_id, self::TYPE_META ) || ! current_user_can( 'edit_post', $entry_id ) ) {
			wp_die( esc_html__( 'That calendar entry could not be moved to the trash.', 'heart-hub-event-registration-manager' ), esc_html__( 'Calendar entry unavailable', 'heart-hub-event-registration-manager' ), array( 'response' => 404 ) );
		}
		$result = wp_trash_post( $entry_id );
		if ( ! $result ) {
			wp_die( esc_html__( 'WordPress could not move the calendar entry to the trash.', 'heart-hub-event-registration-manager' ), esc_html__( 'Calendar entry update failed', 'heart-hub-event-registration-manager' ), array( 'response' => 500 ) );
		}
		$this->audit->write( $entry_id, 'calendar_entry_trashed', 'trashed', array( 'post_type' => $post->post_type, 'entry_title' => get_the_title( $entry_id ) ) );
		wp_safe_redirect( add_query_arg( 'calendar_trashed', 1, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	private function render_editor( string $post_type, int $entry_id, array $values, string $error ): void {
		?>
		<div class="wrap hherm-events-wrap hherm-calendar-schedule-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1><?php echo esc_html( $entry_id ? 'Edit calendar entry' : 'Add calendar entry' ); ?></h1><p>Set a one-off date or a repeating schedule for the Events calendar.</p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Back to schedule</a></header><hr class="wp-header-end">
			<?php if ( $error ) : ?><div class="notice notice-error" role="alert"><p><?php echo esc_html( $this->error_message( $error ) ); ?></p></div><?php endif; ?>
			<?php if ( ! post_type_exists( $post_type ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sprintf( 'The “%s” post type is not registered. Confirm JetEngine is active and the Events CPT slug is correct.', $post_type ) ); ?></p></div>
			<?php elseif ( ! taxonomy_exists( self::TAXONOMY ) ) : ?><div class="notice notice-error"><p>The event-category taxonomy is not registered. Calendar entries need it to receive the Calendar Schedule category.</p></div>
			<?php else : ?>
				<form class="hherm-calendar-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>"><input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>"><?php wp_nonce_field( self::SAVE_ACTION, 'hherm_calendar_nonce' ); ?>
					<section class="hherm-form-card"><div class="hherm-calendar-form-grid">
						<label><span>Entry name <strong>*</strong></span><input type="text" name="entry_title" maxlength="200" required value="<?php echo esc_attr( $values['entry_title'] ); ?>" placeholder="For example, Open at Heart Hub"></label>
						<label><span>Type <strong>*</strong></span><select name="calendar_entry_type" required><?php foreach ( $this->types() as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $values['calendar_entry_type'], $type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
						<label><span>Repeat</span><select name="recurrence_frequency" data-hherm-recurrence><?php foreach ( $this->frequencies() as $frequency => $label ) : ?><option value="<?php echo esc_attr( $frequency ); ?>" <?php selected( $values['recurrence_frequency'], $frequency ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><small>Repeating schedules continue until you change their visibility or move them to Trash.</small></label>
						<div class="hherm-calendar-one-off-fields hherm-calendar-field--wide" data-hherm-one-off>
							<label><span>Start date &amp; time <strong>*</strong></span><input type="datetime-local" name="start_date" value="<?php echo esc_attr( $values['start_date'] ); ?>"><small>Uses the WordPress site timezone.</small></label>
							<label><span>End date &amp; time</span><input type="datetime-local" name="end_date__time" value="<?php echo esc_attr( $values['end_date__time'] ); ?>"><small>Optional. Leave blank for a single-time entry.</small></label>
						</div>
						<div class="hherm-calendar-recurrence-fields hherm-calendar-field--wide" data-hherm-repeat-fields hidden>
							<label data-hherm-repeat-start><span>Start repeating from <strong>*</strong></span><input type="date" name="repeat_start_date" value="<?php echo esc_attr( $values['repeat_start_date'] ); ?>"><small>Daily, weekly, and monthly schedules begin on or after this date.</small></label>
							<fieldset class="hherm-calendar-weekdays" data-hherm-weekdays><legend>Repeat on <strong>*</strong></legend><div class="hherm-calendar-weekday-list"><?php foreach ( $this->weekday_labels() as $weekday => $weekday_label ) : ?><label><input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( (string) $weekday ); ?>" <?php checked( in_array( $weekday, $values['weekdays'], true ) ); ?>> <?php echo esc_html( $weekday_label ); ?></label><?php endforeach; ?></div></fieldset>
							<label data-hherm-month-day><span>Repeat on day of month <strong>*</strong></span><select name="month_day"><?php for ( $day = 1; $day <= 31; $day++ ) : ?><option value="<?php echo esc_attr( (string) $day ); ?>" <?php selected( $values['month_day'], $day ); ?>><?php echo esc_html( $this->ordinal( $day ) ); ?></option><?php endfor; ?></select><small>Months without the selected day are skipped.</small></label>
							<div class="hherm-calendar-custom-dates" data-hherm-custom-dates><span class="hherm-calendar-field-label">Choose dates <strong>*</strong></span><div data-hherm-date-list><?php foreach ( $values['custom_dates'] as $date ) : ?><div class="hherm-calendar-date-row"><input type="date" name="custom_dates[]" aria-label="Date" value="<?php echo esc_attr( $date ); ?>"><button class="button-link-delete" type="button" data-hherm-remove-date>Remove</button></div><?php endforeach; ?></div><button class="button" type="button" data-hherm-add-date>Add another date</button></div>
							<div class="hherm-calendar-time-slots"><span class="hherm-calendar-field-label">Time slots <strong>*</strong></span><p class="description">Each time slot is added on every selected day. End time is optional.</p><div data-hherm-slot-list><?php foreach ( $values['time_slots'] as $index => $slot ) : ?><div class="hherm-calendar-time-row"><label><span>Starts</span><input type="time" name="time_slots[<?php echo esc_attr( (string) $index ); ?>][start]" value="<?php echo esc_attr( $slot['start'] ); ?>"></label><label><span>Ends</span><input type="time" name="time_slots[<?php echo esc_attr( (string) $index ); ?>][end]" value="<?php echo esc_attr( $slot['end'] ); ?>"></label><button class="button-link-delete" type="button" data-hherm-remove-slot>Remove</button></div><?php endforeach; ?></div><button class="button" type="button" data-hherm-add-slot>Add another time</button></div>
						</div>
						<label class="hherm-calendar-field--wide"><span>Location <strong>*</strong></span><input type="text" name="venue" maxlength="200" required value="<?php echo esc_attr( $values['venue'] ); ?>" placeholder="Venue, school, club, or address"></label>
						<label class="hherm-calendar-field--wide"><span>Description</span><textarea name="description" rows="5" maxlength="5000"><?php echo esc_textarea( $values['description'] ); ?></textarea></label>
						<label><span>Visibility</span><select name="post_status"><option value="publish" <?php selected( $values['post_status'], 'publish' ); ?>>Published — show on calendar</option><option value="draft" <?php selected( $values['post_status'], 'draft' ); ?>>Draft — keep off calendar</option></select></label>
					</div></section>
					<div class="hherm-calendar-form-actions"><button class="button button-primary" type="submit"><?php echo esc_html( $entry_id ? 'Save changes' : 'Add to calendar' ); ?></button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Cancel</a></div>
				</form>
				<p class="hherm-calendar-note">Entries are saved to the existing <code><?php echo esc_html( $post_type ); ?></code> post type, assigned the <strong>Calendar Schedule</strong> category, and marked as having no registration. Repeating dates are calculated as each month is viewed; a three-year schedule does not create extra event posts.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function entry_values( $entry ): array {
		$rule = $entry ? get_post_meta( $entry->ID, self::RULE_META, true ) : array();
		$rule = is_array( $rule ) ? $rule : array();
		$start = $entry ? Event_Datetime::input( get_post_meta( $entry->ID, $this->meta_key( 'event_start', 'start_date' ), true ) ) : '';
		$end = $entry ? Event_Datetime::input( get_post_meta( $entry->ID, $this->meta_key( 'event_end', 'end_date__time' ), true ) ) : '';
		$slots = (array) ( $rule['slots'] ?? array() );
		if ( ! $slots ) {
			$slots[] = array(
				'start' => $start ? substr( $start, 11, 5 ) : '',
				'end'   => $end ? substr( $end, 11, 5 ) : '',
			);
		}
		foreach ( $slots as &$slot ) {
			$slot = array(
				'start' => sanitize_text_field( (string) ( $slot['start'] ?? '' ) ),
				'end'   => sanitize_text_field( (string) ( $slot['end'] ?? '' ) ),
			);
		}
		unset( $slot );
		$start_date = sanitize_text_field( (string) ( $rule['start_date'] ?? ( $start ? substr( $start, 0, 10 ) : '' ) ) );
		$weekdays = array_map( 'absint', (array) ( $rule['weekdays'] ?? array() ) );
		if ( ! $weekdays && $start_date ) {
			$start_day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
			if ( $start_day ) $weekdays[] = (int) $start_day->format( 'w' );
		}
		$values = array(
			'entry_title'          => $entry ? get_the_title( $entry ) : '',
			'calendar_entry_type'  => $entry ? sanitize_key( (string) get_post_meta( $entry->ID, self::TYPE_META, true ) ) : 'opening_hours',
			'start_date'           => $start,
			'end_date__time'       => $end,
			'recurrence_frequency' => sanitize_key( (string) ( $rule['frequency'] ?? 'none' ) ),
			'repeat_start_date'    => $start_date,
			'weekdays'             => $weekdays,
			'month_day'            => max( 1, min( 31, absint( $rule['month_day'] ?? ( $start_date ? substr( $start_date, 8, 2 ) : 1 ) ) ) ),
			'custom_dates'         => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $rule['dates'] ?? array() ) ) ) ),
			'time_slots'           => $slots,
			'venue'                => $entry ? (string) get_post_meta( $entry->ID, $this->meta_key( 'event_venue', 'venue' ), true ) : '',
			'description'          => $entry ? (string) $entry->post_content : '',
			'post_status'          => ! $entry ? 'publish' : ( sanitize_key( (string) get_post_meta( $entry->ID, self::SCHEDULE_STATUS_META, true ) ) ?: ( 'publish' === $entry->post_status ? 'publish' : 'draft' ) ),
		);
		foreach ( array( 'entry_title', 'calendar_entry_type', 'start_date', 'end_date__time', 'recurrence_frequency', 'repeat_start_date', 'venue', 'post_status' ) as $key ) $values[ $key ] = sanitize_text_field( $values[ $key ] );
		$values['description'] = sanitize_textarea_field( $values['description'] );
		return $values;
	}

	private function display_range( int $entry_id ): string {
		$start = get_post_meta( $entry_id, $this->meta_key( 'event_start', 'start_date' ), true );
		$end = get_post_meta( $entry_id, $this->meta_key( 'event_end', 'end_date__time' ), true );
		$start_text = Event_Datetime::display( $start, Event_Datetime::DATETIME_FORMAT );
		$end_format = Event_Datetime::display( $start, 'Y-m-d' ) === Event_Datetime::display( $end, 'Y-m-d' ) ? Event_Datetime::TIME_FORMAT : Event_Datetime::DATETIME_FORMAT;
		$end_text = Event_Datetime::display( $end, $end_format );
		return $end_text ? $start_text . ' – ' . $end_text : ( $start_text ?: '—' );
	}

	private function list_meta_query( string $status ): array {
		$query = array(
			'relation' => 'AND',
			array( 'key' => self::TYPE_META, 'compare' => 'EXISTS' ),
		);
		if ( in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$query[] = array( 'key' => self::SCHEDULE_STATUS_META, 'value' => $status, 'compare' => '=' );
		}
		return $query;
	}

	private function recurrence_rule_from_request( array $request, string $frequency ) {
		$slots = array();
		foreach ( $this->request_slot_array( $request ) as $slot ) {
			$start = trim( $slot['start'] );
			$end = trim( $slot['end'] );
			if ( '' === $start && '' === $end ) continue;
			if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start ) || ( '' !== $end && ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end ) ) ) {
				return new \WP_Error( 'invalid_time_slot' );
			}
			if ( '' !== $end && $end <= $start ) return new \WP_Error( 'end_before_start' );
			$slots[] = array( 'start' => $start, 'end' => $end );
		}
		$slots = array_values( array_unique( $slots, SORT_REGULAR ) );
		if ( ! $slots ) return new \WP_Error( 'slot_required' );
		if ( count( $slots ) > 12 ) return new \WP_Error( 'too_many_slots' );
		usort( $slots, static function ( array $left, array $right ): int { return strcmp( $left['start'], $right['start'] ); } );

		$rule = array(
			'frequency' => $frequency,
			'start_date' => '',
			'weekdays' => array(),
			'month_day' => 0,
			'dates' => array(),
			'slots' => $slots,
		);
		if ( 'dates' === $frequency ) {
			$dates = array_values( array_unique( array_filter( $this->request_text_array( $request, 'custom_dates' ) ) ) );
			foreach ( $dates as $date ) {
				if ( ! $this->valid_date( $date ) ) return new \WP_Error( 'invalid_custom_date' );
			}
			if ( ! $dates ) return new \WP_Error( 'custom_dates_required' );
			if ( count( $dates ) > 100 ) return new \WP_Error( 'too_many_dates' );
			sort( $dates, SORT_STRING );
			$rule['dates'] = $dates;
			return $rule;
		}

		$start_date = Event_Datetime::date_storage( $this->request_text( $request, 'repeat_start_date' ) );
		if ( ! $start_date ) return new \WP_Error( 'repeat_start_required' );
		$rule['start_date'] = $start_date;
		if ( 'weekly' === $frequency ) {
			$weekdays = array_values( array_unique( array_filter( $this->request_int_array( $request, 'weekdays' ), static function ( int $day ): bool { return $day >= 0 && $day <= 6; } ) ) );
			if ( ! $weekdays ) return new \WP_Error( 'weekdays_required' );
			sort( $weekdays, SORT_NUMERIC );
			$rule['weekdays'] = $weekdays;
		}
		if ( 'monthly' === $frequency ) {
			$day = absint( $this->request_text( $request, 'month_day' ) );
			if ( $day < 1 || $day > 31 ) return new \WP_Error( 'month_day_required' );
			$rule['month_day'] = $day;
		}
		return $rule;
	}

	private function recurrence_label( $rule ): string {
		if ( ! is_array( $rule ) ) return 'Repeating schedule';
		$frequency = sanitize_key( (string) ( $rule['frequency'] ?? '' ) );
		$labels = array(
			'daily' => 'Every day',
			'weekly' => 'Every week',
			'monthly' => 'Every month on the ' . $this->ordinal( absint( $rule['month_day'] ?? 0 ) ),
			'dates' => 'Selected dates (' . count( (array) ( $rule['dates'] ?? array() ) ) . ')',
		);
		$label = $labels[ $frequency ] ?? 'Repeating schedule';
		if ( 'weekly' === $frequency ) {
			$days = array();
			foreach ( (array) ( $rule['weekdays'] ?? array() ) as $weekday ) {
				$days[] = $this->weekday_labels()[ absint( $weekday ) ] ?? '';
			}
			$label .= ': ' . implode( ', ', array_filter( $days ) );
		}
		$times = array();
		foreach ( (array) ( $rule['slots'] ?? array() ) as $slot ) {
			if ( empty( $slot['start'] ) ) continue;
			$time = $this->format_clock( (string) $slot['start'] );
			if ( ! empty( $slot['end'] ) ) $time .= '–' . $this->format_clock( (string) $slot['end'] );
			$times[] = $time;
		}
		return $label . ( $times ? ' · ' . implode( ', ', $times ) : '' );
	}

	private function ordinal( int $number ): string {
		if ( $number < 1 || $number > 31 ) return 'day';
		$suffix = 'th';
		if ( ! in_array( $number % 100, array( 11, 12, 13 ), true ) ) $suffix = array( 1 => 'st', 2 => 'nd', 3 => 'rd' )[ $number % 10 ] ?? 'th';
		return $number . $suffix;
	}

	private function format_clock( string $time ): string {
		$parsed = \DateTimeImmutable::createFromFormat( '!H:i', $time, wp_timezone() );
		return $parsed ? $parsed->format( 'g:i a' ) : $time;
	}

	private function valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return false;
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		return $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $parsed->format( 'Y-m-d' ) === $date;
	}

	private function frequencies(): array {
		return array( 'none' => 'Does not repeat', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month', 'dates' => 'Selected dates' );
	}

	private function weekday_labels(): array {
		return array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
	}

	private function request_int_array( array $request, string $key ): array {
		$values = $request[ $key ] ?? array();
		if ( ! is_array( $values ) ) return array();
		$clean = array();
		foreach ( $values as $value ) if ( is_scalar( $value ) ) $clean[] = absint( $value );
		return $clean;
	}

	private function request_text_array( array $request, string $key ): array {
		$values = $request[ $key ] ?? array();
		if ( ! is_array( $values ) ) return array();
		$clean = array();
		foreach ( $values as $value ) if ( is_scalar( $value ) ) $clean[] = sanitize_text_field( (string) $value );
		return $clean;
	}

	private function request_slot_array( array $request ): array {
		$values = $request['time_slots'] ?? array();
		if ( ! is_array( $values ) ) return array();
		$slots = array();
		foreach ( $values as $slot ) {
			if ( ! is_array( $slot ) ) continue;
			$start = $slot['start'] ?? '';
			$end = $slot['end'] ?? '';
			$slots[] = array(
				'start' => is_scalar( $start ) ? sanitize_text_field( (string) $start ) : '',
				'end' => is_scalar( $end ) ? sanitize_text_field( (string) $end ) : '',
			);
		}
		return $slots;
	}

	private function calendar_term_id() {
		$term = get_term_by( 'slug', self::TERM_SLUG, self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$term = get_term_by( 'name', 'Calendar Schedule', self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( 'Calendar Schedule', self::TAXONOMY, array( 'slug' => self::TERM_SLUG, 'description' => 'Dated entries managed in Heart Hub Events > Calendar Schedule.' ) );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() ) {
				$existing_id = $created->get_error_data();
				if ( is_array( $existing_id ) ) {
					$existing_id = $existing_id['term_id'] ?? reset( $existing_id );
				}
				return absint( $existing_id );
			}
			return $created;
		}
		return absint( $created['term_id'] ?? 0 );
	}

	private function retry_key( string $token ): string {
		return self::RETRY_PREFIX . get_current_user_id() . '_' . sanitize_key( $token );
	}

	private function redirect_error( string $code, int $entry_id = 0, array $request = array() ): void {
		$args = array( 'calendar_error' => sanitize_key( $code ) );
		if ( $entry_id ) {
			$args['entry_id'] = $entry_id;
		}
		$token = '';
		if ( $request ) {
			$token = wp_generate_password( 12, false, false );
			$retry_values = array(
			'entry_title'         => $this->request_text( $request, 'entry_title' ),
			'calendar_entry_type' => sanitize_key( $this->request_text( $request, 'calendar_entry_type' ) ) ?: 'opening_hours',
			'recurrence_frequency' => sanitize_key( $this->request_text( $request, 'recurrence_frequency' ) ) ?: 'none',
			'start_date'          => $this->request_text( $request, 'start_date' ),
			'end_date__time'      => $this->request_text( $request, 'end_date__time' ),
			'repeat_start_date'   => $this->request_text( $request, 'repeat_start_date' ),
			'weekdays'            => $this->request_int_array( $request, 'weekdays' ),
			'month_day'           => min( 31, max( 1, absint( $this->request_text( $request, 'month_day' ) ) ) ),
			'custom_dates'        => $this->request_text_array( $request, 'custom_dates' ),
			'time_slots'          => $this->request_slot_array( $request ),
			'venue'               => $this->request_text( $request, 'venue' ),
			'description'         => $this->request_text( $request, 'description', true ),
			'post_status'         => in_array( $this->request_text( $request, 'post_status' ), array( 'draft', 'publish' ), true ) ? $this->request_text( $request, 'post_status' ) : 'draft',
			);
			set_transient( $this->retry_key( $token ), $retry_values, 10 * MINUTE_IN_SECONDS );
			$args['retry'] = $token;
			if ( ! $entry_id ) {
				$args['new'] = 1;
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	private function error_message( string $code ): string {
		$messages = array(
			'post_type_missing' => 'The Events post type is not available.',
			'taxonomy_missing'  => 'The event-category taxonomy is not available.',
			'entry_missing'     => 'That calendar entry could not be found or edited.',
			'invalid_type'      => 'Choose a valid calendar entry type.',
			'invalid_frequency' => 'Choose a valid repeat schedule.',
			'title_required'    => 'Enter an entry name.',
			'title_length'      => 'Entry names must be 200 characters or fewer.',
			'start_required'    => 'Enter a valid start date and time.',
			'repeat_start_required' => 'Choose the date this schedule should start.',
			'weekdays_required' => 'Choose at least one day of the week.',
			'month_day_required' => 'Choose a day from 1 to 31 for the monthly schedule.',
			'custom_dates_required' => 'Choose at least one date for this schedule.',
			'invalid_custom_date' => 'One or more selected dates are invalid.',
			'too_many_dates' => 'Choose no more than 100 dates at a time.',
			'slot_required' => 'Add at least one time slot with a start time.',
			'invalid_time_slot' => 'Enter valid start and end times for each time slot.',
			'too_many_slots' => 'Use no more than 12 time slots in one schedule.',
			'invalid_datetime'  => 'Enter a valid start or end date and time.',
			'end_before_start'  => 'The end date and time must be later than the start.',
			'venue_required'    => 'Enter a location.',
			'venue_length'      => 'Locations must be 200 characters or fewer.',
			'description_length'=> 'Descriptions must be 5,000 characters or fewer.',
			'category_failed'   => 'The Calendar Schedule category could not be assigned. Confirm the event-category taxonomy is available.',
			'save_failed'       => 'WordPress could not save the calendar entry. Please try again.',
		);
		return $messages[ $code ] ?? 'The calendar entry could not be saved. Please check the form and try again.';
	}

	private function types(): array {
		return array(
			'opening_hours'  => 'Opening hours',
			'community_visit' => 'Community visit',
			'speech'         => 'Speech / presentation',
			'other'          => 'Other',
		);
	}

	private function type_label( string $type ): string {
		$types = $this->types();
		return $types[ $type ] ?? 'Other';
	}

	private function meta_key( string $setting, string $default ): string {
		return sanitize_key( (string) $this->settings->get( $setting, $default ) ) ?: $default;
	}

	private function request_text( array $request, string $key, bool $textarea = false ): string {
		$value = $request[ $key ] ?? '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return $textarea ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
	}

	private function post_type(): string {
		return sanitize_key( (string) $this->settings->get( 'events_cpt', 'events' ) );
	}

	private function require_access(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the calendar schedule.', 'heart-hub-event-registration-manager' ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) );
		}
	}
}
