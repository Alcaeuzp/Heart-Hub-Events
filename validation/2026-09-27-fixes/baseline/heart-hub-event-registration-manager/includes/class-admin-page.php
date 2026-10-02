<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {
	public const MENU_SLUG = 'heart-hub-event-registrations';

	private $settings;
	private $registrations;
	private $email;
	private $events;
	private $attendance;
	private $checkin_page;
	private $analytics;
	private $support_groups;
	private $hook_suffix = '';
	private $create_event_hook_suffix = '';
	private $events_hook_suffix = '';
	private $fundraisers_hook_suffix = '';
	private $attendance_hook_suffix = '';
	private $feedback_hook_suffix = '';
	private $settings_hook_suffix = '';
	private $registration_hook_suffix = '';
	private $support_groups_hook_suffix = '';
	private $support_group_editor_hook_suffix = '';

	public function __construct( Settings $settings, Registrations_Page $registrations, Email_Page $email, Event_Manager $events, Attendance_Manager $attendance, Checkin_Page $checkin_page, Analytics_Page $analytics, Support_Groups $support_groups ) {
		$this->settings     = $settings;
		$this->registrations = $registrations;
		$this->email         = $email;
		$this->events        = $events;
		$this->attendance    = $attendance;
		$this->checkin_page  = $checkin_page;
		$this->analytics     = $analytics;
		$this->support_groups = $support_groups;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		// After Calendar Schedule (20) and Calendar Settings (21), so Settings is the last item.
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 25 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_shared_assets' ), 99 );
		// Run late: other plugins that own an "events" post type menu also rewrite these on our routes.
		add_filter( 'parent_file', array( $this, 'keep_plugin_menu_open' ), 999 );
		add_filter( 'submenu_file', array( $this, 'highlight_plugin_submenu' ), 999, 2 );
		add_filter( 'admin_title', array( $this, 'hidden_page_title' ), 10, 2 );
	}

	/**
	 * WordPress cannot find a title for routes registered without a parent menu,
	 * which leaves the browser tab reading "‹ Site name".
	 *
	 * @param string $admin_title Full browser title.
	 * @param string $title       Page title WordPress found, if any.
	 * @return string
	 */
	public function hidden_page_title( $admin_title, $title ) {
		if ( '' !== trim( (string) $title ) ) {
			return $admin_title;
		}
		$titles = array(
			Checkin_Manager::CHECKIN_PAGE_SLUG => 'Check-in Page',
			Support_Groups::EDIT_PAGE_SLUG     => 'Support Programme',
			Event_Manager::CATEGORY_PAGE_SLUG  => 'Event Categories',
			'hherm-email-templates'            => 'Email Templates',
		);
		$page = $this->current_page_slug();
		if ( Event_Manager::PAGE_SLUG === $page ) {
			$editing = ! empty( $_GET['event_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only title choice.
			$fundraiser = isset( $_GET['context'] ) && 'fundraising' === sanitize_key( wp_unslash( $_GET['context'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only title choice.
			$titles[ $page ] = ( $editing ? 'Edit ' : 'Create ' ) . ( $fundraiser ? 'fundraiser' : 'event' );
		}
		if ( ! isset( $titles[ $page ] ) ) {
			return $admin_title;
		}
		return $titles[ $page ] . ' ' . $admin_title;
	}

	/**
	 * Keep the top-level menu expanded on plugin screens registered as hidden routes.
	 *
	 * @param string $parent_file Current WordPress parent menu slug.
	 * @return string
	 */
	public function keep_plugin_menu_open( $parent_file ) {
		if ( in_array( $this->current_page_slug(), $this->plugin_page_slugs(), true ) ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Highlight the visible section that owns the current hidden route.
	 *
	 * @param string|null $submenu_file Current WordPress submenu slug.
	 * @param string      $parent_file  Current WordPress parent menu slug.
	 * @return string|null
	 */
	public function highlight_plugin_submenu( $submenu_file, $parent_file ) {
		if ( self::MENU_SLUG !== $parent_file ) {
			return $submenu_file;
		}

		$page = $this->current_page_slug();
		if ( Event_Manager::PAGE_SLUG === $page ) {
			$context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';
			return 'fundraising' === $context ? Event_Manager::FUNDRAISER_LIST_PAGE_SLUG : Event_Manager::LIST_PAGE_SLUG;
		}

		$owners = array(
			Checkin_Manager::CHECKIN_PAGE_SLUG => Attendance_Manager::PAGE_SLUG,
			Event_Manager::CATEGORY_PAGE_SLUG  => Event_Manager::LIST_PAGE_SLUG,
			Calendar_Schedule::PAGE_SLUG       => Calendar_Schedule::PAGE_SLUG,
			Calendar_Settings::PAGE_SLUG       => Calendar_Settings::PAGE_SLUG,
			Support_Groups::EDIT_PAGE_SLUG     => Support_Groups::PAGE_SLUG,
			'hherm-email-templates'             => 'hherm',
		);

		return isset( $owners[ $page ] ) ? $owners[ $page ] : $submenu_file;
	}

	/**
	 * Return the current admin.php page slug.
	 */
	private function current_page_slug(): string {
		global $plugin_page;

		if ( is_string( $plugin_page ) && '' !== $plugin_page ) {
			return sanitize_key( $plugin_page );
		}

		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Return every admin route owned by this plugin.
	 *
	 * @return string[]
	 */
	private function plugin_page_slugs(): array {
		return array(
			self::MENU_SLUG,
			Attendance_Manager::PAGE_SLUG,
			Checkin_Manager::CHECKIN_PAGE_SLUG,
			Checkin_Manager::PAGE_SLUG,
			Event_Manager::LIST_PAGE_SLUG,
			Event_Manager::FUNDRAISER_LIST_PAGE_SLUG,
			Event_Manager::PAGE_SLUG,
			Event_Manager::CATEGORY_PAGE_SLUG,
			Calendar_Schedule::PAGE_SLUG,
			Calendar_Settings::PAGE_SLUG,
			Support_Groups::PAGE_SLUG,
			Support_Groups::EDIT_PAGE_SLUG,
			'hherm',
			'hherm-email-templates',
		);
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page( 'Heart Hub Events', 'Heart Hub Events', Plugin::CAPABILITY, self::MENU_SLUG, array( $this->registrations, 'render' ), 'dashicons-groups', 26 );
		$this->registration_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Registrations', 'Registrations', Plugin::CAPABILITY, self::MENU_SLUG, array( $this->registrations, 'render' ) );
		$this->attendance_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Attendance & Check-in', 'Attendance & Check-in', Plugin::CAPABILITY, Attendance_Manager::PAGE_SLUG, array( $this->attendance, 'render' ) );
		$this->feedback_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Event Feedback and Analytics', 'Feedback & Analytics', Plugin::CAPABILITY, Checkin_Manager::PAGE_SLUG, array( $this->analytics, 'render' ) );
		$this->events_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Manage Events', 'Events', Plugin::CAPABILITY, Event_Manager::LIST_PAGE_SLUG, array( $this->events, 'render_list' ) );
		$this->fundraisers_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Manage Fundraisers', 'Fundraisers', Plugin::CAPABILITY, Event_Manager::FUNDRAISER_LIST_PAGE_SLUG, array( $this->events, 'render_fundraisers' ) );
		$this->support_groups_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Manage Support Groups', 'Support Groups', Plugin::CAPABILITY, Support_Groups::PAGE_SLUG, array( $this->support_groups, 'render_list' ) );
		$this->support_group_editor_hook_suffix = (string) add_submenu_page( '', 'Create or Edit Support Programme', 'Support Programme', Plugin::CAPABILITY, Support_Groups::EDIT_PAGE_SLUG, array( $this->support_groups, 'render_editor' ) );
		$this->create_event_hook_suffix = (string) add_submenu_page( '', 'Create or Edit Heart Hub Event', 'Create Event', Plugin::CAPABILITY, Event_Manager::PAGE_SLUG, array( $this->events, 'render' ) );
	}

	public function add_settings_menu(): void {
		$this->settings_hook_suffix = (string) add_submenu_page( self::MENU_SLUG, 'Heart Hub Events Settings', 'Settings', 'manage_options', 'hherm', array( $this, 'render_settings' ) );
	}

	/**
	 * Older standalone screens now live inside Settings and Attendance.
	 * Redirect old bookmarks and links there, keeping any record being viewed.
	 */
	public function redirect_legacy_routes(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirects between capability-protected screens.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$routes = array(
			'hherm-email-templates'            => array( array( 'page' => 'hherm', 'tab' => 'emails' ), array( 'email_template' ) ),
			Event_Manager::CATEGORY_PAGE_SLUG  => array( array( 'page' => 'hherm', 'tab' => 'event-setup' ), array( 'edit_term', 'category_saved', 'category_deleted', 'category_error' ) ),
			Checkin_Manager::CHECKIN_PAGE_SLUG => array( array( 'page' => Attendance_Manager::PAGE_SLUG ), array( 'event_id' ) ),
		);
		if ( ! isset( $routes[ $page ] ) ) return;
		list( $args, $keep ) = $routes[ $page ];
		foreach ( $keep as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) $args[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Fonts and the shared confirmation prompt, for every screen that loads the plugin's admin styles
	 * (including Calendar Schedule and Calendar Settings, which enqueue their own assets).
	 */
	public function enqueue_shared_assets(): void {
		if ( ! wp_style_is( 'hherm-admin-base', 'enqueued' ) ) return;
		wp_enqueue_style( 'hherm-admin-fonts', 'https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Source+Serif+4:wght@600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts versions itself by URL.
		wp_enqueue_script( 'hherm-admin-confirm', HHERM_URL . 'assets/admin-confirm.js', array(), HHERM_VERSION, true );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only tab selection on a capability-protected admin route; saves use Settings API nonces.
	public function render_settings(): void {
		$tabs = array(
			'emails'         => 'Emails',
			'appearance'     => 'Appearance',
			'contact-access' => 'Contact & Access',
			'event-setup'    => 'Event Setup',
			'shortcodes'     => 'Shortcodes',
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'emails';
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'emails';
		?>
		<div class="wrap hherm-settings-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Settings</h1><p>Manage email delivery, appearance, access, event configuration, and front-end shortcodes.</p></div></header><hr class="wp-header-end">
			<nav class="hherm-settings-tabs" aria-label="Settings sections">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a class="<?php echo $slug === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hherm', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>" <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php $this->settings->render( $tab ); ?>
			<?php if ( 'emails' === $tab ) $this->email->render( true ); ?>
			<?php if ( 'event-setup' === $tab ) $this->events->render_categories( true ); ?>
		</div>
		<?php
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin asset selection; registration data and changes use the capability/nonce-protected REST API.
	public function enqueue_assets( string $hook_suffix ): void {
		$known_hooks = array_filter( array( $this->hook_suffix, $this->registration_hook_suffix, $this->create_event_hook_suffix, $this->events_hook_suffix, $this->fundraisers_hook_suffix, $this->support_groups_hook_suffix, $this->support_group_editor_hook_suffix, $this->attendance_hook_suffix, $this->feedback_hook_suffix, $this->settings_hook_suffix ) );
		if ( ! in_array( $hook_suffix, $known_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'hherm-admin-base', HHERM_URL . 'assets/admin-base.css', array(), HHERM_VERSION );
		$is_registration = in_array( $hook_suffix, array( $this->hook_suffix, $this->registration_hook_suffix ), true );
		if ( $is_registration ) {
			wp_enqueue_style( 'hherm-registrations', HHERM_URL . 'assets/registrations-page.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			wp_enqueue_script( 'hherm-dashboard', HHERM_URL . 'assets/dashboard.js', array(), HHERM_VERSION, true );
			wp_localize_script( 'hherm-dashboard', 'HHERM', array( 'root' => esc_url_raw( rest_url( 'heart-hub/v1/applications' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'registrationId' => isset( $_GET['registration_id'] ) ? absint( wp_unslash( $_GET['registration_id'] ) ) : 0 ) );
			return;
		}
		if ( $hook_suffix === $this->attendance_hook_suffix ) {
			wp_enqueue_style( 'hherm-attendance', HHERM_URL . 'assets/attendance.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			wp_enqueue_style( 'hherm-checkin-page', HHERM_URL . 'assets/checkin-page.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			wp_enqueue_script( 'hherm-qrcode', HHERM_URL . 'assets/vendor/qrcode.min.js', array(), '1.0.0', true );
			wp_enqueue_script( 'hherm-checkin-admin', HHERM_URL . 'assets/checkin-admin.js', array( 'hherm-qrcode' ), HHERM_VERSION, true );
			wp_enqueue_script( 'hherm-checkin-page', HHERM_URL . 'assets/checkin-page.js', array(), HHERM_VERSION, true );
			wp_localize_script( 'hherm-checkin-page', 'HHERM_CHECKIN', array( 'root' => esc_url_raw( rest_url( 'heart-hub/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return;
		}
		if ( $hook_suffix === $this->feedback_hook_suffix ) {
			wp_enqueue_style( 'hherm-analytics', HHERM_URL . 'assets/analytics.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			wp_enqueue_script( 'hherm-analytics', HHERM_URL . 'assets/analytics.js', array(), HHERM_VERSION, true );
			return;
		}
		if ( in_array( $hook_suffix, array( $this->support_groups_hook_suffix, $this->support_group_editor_hook_suffix ), true ) ) {
			wp_enqueue_style( 'hherm-support-groups-admin', HHERM_URL . 'assets/support-groups-admin.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			return;
		}
		if ( $hook_suffix === $this->create_event_hook_suffix ) {
			wp_enqueue_media();
			wp_enqueue_style( 'hherm-event-editor', HHERM_URL . 'assets/event-editor.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			$map_config = $this->events->map_api_config();
			$event_editor_dependencies = array();
			if ( ! empty( $map_config['script_url'] ) ) {
				wp_enqueue_script( 'hherm-google-maps', esc_url_raw( $map_config['script_url'] ), array(), HHERM_VERSION, true );
				$event_editor_dependencies[] = 'hherm-google-maps';
			}
			wp_enqueue_script( 'hherm-event-editor', HHERM_URL . 'assets/event-editor.js', $event_editor_dependencies, HHERM_VERSION, true );
			wp_localize_script( 'hherm-event-editor', 'HHERM_EVENT_EDITOR', array( 'addressAutocomplete' => ! empty( $map_config['script_url'] ) ) );
			return;
		}
		if ( in_array( $hook_suffix, array( $this->events_hook_suffix, $this->fundraisers_hook_suffix ), true ) ) {
			wp_enqueue_script( 'hherm-events', HHERM_URL . 'assets/events.js', array(), HHERM_VERSION, true );
			if ( in_array( $hook_suffix, array( $this->events_hook_suffix, $this->fundraisers_hook_suffix ), true ) && ! empty( $_GET['event_id'] ) ) {
				wp_enqueue_style( 'hherm-registrations', HHERM_URL . 'assets/registrations-page.css', array( 'hherm-admin-base' ), HHERM_VERSION );
				wp_enqueue_script( 'hherm-dashboard', HHERM_URL . 'assets/dashboard.js', array(), HHERM_VERSION, true );
				wp_localize_script( 'hherm-dashboard', 'HHERM', array( 'root' => esc_url_raw( rest_url( 'heart-hub/v1/applications' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
			}

			wp_enqueue_style( 'hherm-events', HHERM_URL . 'assets/events.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			return;
		}
		if ( $hook_suffix === $this->settings_hook_suffix ) {
			wp_enqueue_media();
			wp_enqueue_style( 'hherm-settings', HHERM_URL . 'assets/settings.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			wp_enqueue_script( 'hherm-email-settings', HHERM_URL . 'assets/email-settings.js', array(), HHERM_VERSION, true );
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'emails';
			if ( ! in_array( $tab, array( 'emails', 'appearance', 'contact-access', 'event-setup', 'shortcodes' ), true ) ) $tab = 'emails';
			if ( 'emails' === $tab ) {
				wp_enqueue_style( 'hherm-email-page', HHERM_URL . 'assets/email-page.css', array( 'hherm-admin-base' ), HHERM_VERSION );
			}
			if ( 'event-setup' === $tab ) {
				wp_enqueue_style( 'hherm-events', HHERM_URL . 'assets/events.css', array( 'hherm-admin-base' ), HHERM_VERSION );
				wp_enqueue_script( 'hherm-events', HHERM_URL . 'assets/events.js', array(), HHERM_VERSION, true );
			}
			wp_enqueue_style( 'hherm-settings-tabs', HHERM_URL . 'assets/settings-tabs.css', array( 'hherm-settings' ), HHERM_VERSION );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
