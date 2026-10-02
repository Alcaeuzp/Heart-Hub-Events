<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['submenus'] = array();
	$GLOBALS['removed_submenus'] = array();
	$GLOBALS['filters'] = array();
	function add_action( ...$args ) {}
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['filters'][ $hook ] = array( 'callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args ); }
	function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback, $icon = '', $position = null ) { return 'toplevel_page_' . $slug; }
	function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) { $GLOBALS['submenus'][ $slug ] = array( 'parent' => $parent, 'title' => $menu_title, 'capability' => $capability, 'callback' => $callback ); return $parent . '_page_' . $slug; }
	function remove_submenu_page( $parent, $slug ) { $GLOBALS['removed_submenus'][] = $slug; return true; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function wp_unslash( $value ) { return $value; }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
	function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function settings_errors() {}
	function check( $value, $message ) { if ( ! $value ) throw new \Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	class Plugin { public const CAPABILITY = 'manage_heart_hub_event_registrations'; }
	class Settings { public $tabs = array(); public function render( string $tab = 'emails' ): void { $this->tabs[] = $tab; echo '<div data-settings-render="' . \esc_html( $tab ) . '"></div>'; } }
	class Registrations_Page { public function render(): void {} }
	class Email_Page { public $embedded = array(); public function render( bool $embedded = false ): void { $this->embedded[] = $embedded; echo '<div data-email-render></div>'; } }
	class Event_Manager {
		public const PAGE_SLUG = 'hherm-create-event';
		public const LIST_PAGE_SLUG = 'hherm-events';
		public const FUNDRAISER_LIST_PAGE_SLUG = 'hherm-fundraisers';
		public const CATEGORY_PAGE_SLUG = 'hherm-event-categories';
		public $category_embeds = array();
		public function render(): void {}
		public function render_list(): void {}
		public function render_fundraisers(): void {}
		public function render_categories( bool $embedded = false ): void { $this->category_embeds[] = $embedded; echo '<div data-category-render></div>'; }
	}
	class Attendance_Manager { public const PAGE_SLUG = 'hherm-attendance'; public function render(): void {} }
	class Checkin_Page { public function render(): void {} }
	class Checkin_Manager { public const CHECKIN_PAGE_SLUG = 'hherm-checkin-page'; public const PAGE_SLUG = 'hherm-feedback'; }
	class Analytics_Page { public function render(): void {} }
	class Calendar_Schedule { public const PAGE_SLUG = 'hherm-calendar-schedule'; }
	class Calendar_Settings { public const PAGE_SLUG = 'hherm-calendar-settings'; }
	class Support_Groups { public const PAGE_SLUG = 'hherm-support-groups'; public const EDIT_PAGE_SLUG = 'hherm-support-group-editor'; public function render_list(): void {} public function render_editor(): void {} }
	require dirname( __DIR__ ) . '/includes/class-admin-page.php';

	$settings = new Settings();
	$email = new Email_Page();
	$events = new Event_Manager();
	$admin = new Admin_Page( $settings, new Registrations_Page(), $email, $events, new Attendance_Manager(), new Checkin_Page(), new Analytics_Page(), new Support_Groups() );
	$admin->register();
	\check( isset( $GLOBALS['filters']['parent_file'], $GLOBALS['filters']['submenu_file'] ), 'admin menu state filters are registered' );
	\check( 2 === $GLOBALS['filters']['submenu_file']['accepted_args'], 'submenu filter accepts the parent menu slug' );
	$admin->add_menu();
	\check( 'Attendance & Check-in' === $GLOBALS['submenus'][ Attendance_Manager::PAGE_SLUG ]['title'], 'combined attendance menu is registered' );
	\check( 'Support Groups' === $GLOBALS['submenus'][ Support_Groups::PAGE_SLUG ]['title'], 'support groups menu is registered' );
	\check( Plugin::CAPABILITY === $GLOBALS['submenus'][ Support_Groups::PAGE_SLUG ]['capability'], 'support groups uses the configured owner capability' );
	foreach ( array( Event_Manager::LIST_PAGE_SLUG, Event_Manager::FUNDRAISER_LIST_PAGE_SLUG, Event_Manager::PAGE_SLUG ) as $event_slug ) {
		\check( Plugin::CAPABILITY === $GLOBALS['submenus'][ $event_slug ]['capability'], "$event_slug uses the configured owner capability" );
	}
	foreach ( array( Checkin_Manager::CHECKIN_PAGE_SLUG, Event_Manager::CATEGORY_PAGE_SLUG, 'hherm-email-templates' ) as $legacy_slug ) {
		\check( ! isset( $GLOBALS['submenus'][ $legacy_slug ] ), "$legacy_slug is no longer a separate screen" );
	}
	\check( ! isset( $GLOBALS['submenus']['hherm'] ), 'Settings is registered after the calendar screens' );
	$admin->add_settings_menu();
	\check( 'manage_options' === $GLOBALS['submenus']['hherm']['capability'], 'Settings, including event categories, remains restricted to administrators' );
	foreach ( array( Event_Manager::PAGE_SLUG, Support_Groups::EDIT_PAGE_SLUG ) as $hidden_slug ) {
		\check( '' === $GLOBALS['submenus'][ $hidden_slug ]['parent'], "$hidden_slug is registered as a directly accessible hidden route" );
	}
	\check( array() === $GLOBALS['removed_submenus'], 'hidden routes are not removed after registration' );

	$_GET = array( 'page' => Event_Manager::PAGE_SLUG, 'context' => 'fundraising' );
	\check( Admin_Page::MENU_SLUG === $admin->keep_plugin_menu_open( '' ), 'create fundraiser keeps the Heart Hub Events menu expanded' );
	\check( Event_Manager::FUNDRAISER_LIST_PAGE_SLUG === $admin->highlight_plugin_submenu( null, Admin_Page::MENU_SLUG ), 'create fundraiser highlights Fundraisers' );
	$_GET = array( 'page' => Event_Manager::PAGE_SLUG );
	\check( Event_Manager::LIST_PAGE_SLUG === $admin->highlight_plugin_submenu( null, Admin_Page::MENU_SLUG ), 'create event highlights Events' );
	$_GET = array( 'page' => Support_Groups::EDIT_PAGE_SLUG );
	\check( Support_Groups::PAGE_SLUG === $admin->highlight_plugin_submenu( null, Admin_Page::MENU_SLUG ), 'support programme editor highlights Support Groups' );
	$_GET = array( 'page' => 'unrelated-plugin' );
	\check( 'tools.php' === $admin->keep_plugin_menu_open( 'tools.php' ), 'unrelated admin screens keep their existing parent menu' );

	$_GET = array( 'tab' => 'emails' );
	ob_start(); $admin->render_settings(); $html = ob_get_clean();
	\check( 'emails' === end( $settings->tabs ) && true === end( $email->embedded ), 'Emails tab embeds the email templates' );
	\check( false === strpos( $html, 'data-category-render' ), 'Emails tab does not render category management' );
	\check( 5 === substr_count( $html, '<a class=' ), 'settings header renders five tab links' );

	$_GET = array( 'tab' => 'event-setup' );
	ob_start(); $admin->render_settings(); $html = ob_get_clean();
	\check( 'event-setup' === end( $settings->tabs ) && true === end( $events->category_embeds ), 'Event Setup embeds category management' );
	\check( false === strpos( $html, 'data-email-render' ), 'Event Setup does not render email templates' );

	$_GET = array( 'tab' => 'not-a-tab' );
	ob_start(); $admin->render_settings(); ob_end_clean();
	\check( 'emails' === end( $settings->tabs ), 'invalid settings tabs safely fall back to Emails' );
}
