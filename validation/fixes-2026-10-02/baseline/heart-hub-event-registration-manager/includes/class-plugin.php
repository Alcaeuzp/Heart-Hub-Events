<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public const CAPABILITY = 'manage_heart_hub_event_registrations';
	private const DATA_VERSION_OPTION = 'hherm_data_version';
	private static $instance;
	private $settings;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$settings   = new Settings();
		$this->settings = $settings;
		$audit      = new Audit_Log();
		$repository = new CCT_Repository( $settings );
		$capacity   = new Capacity_Manager( $settings, $audit );
		$public_pages = new Public_Page_Theme( $settings );
		$event_display = new Event_Public_Display( $settings );
		$self_service = new Registration_Self_Service( $settings, $repository, $audit, $capacity, $public_pages );
		$email      = new Email_Service( $settings, $audit, $self_service );
		$support_groups = new Support_Groups( $settings, $email );
		$email_automation = new Email_Automation( $settings, $repository, $audit, $email );
		$feedback   = new Post_Event_Feedback( $settings, $repository, $audit, $email, $public_pages );
		$metrics    = new Event_Metrics( $settings, $repository );
		$events     = new Event_Manager( $settings, $audit, $capacity, $repository, $email_automation, $metrics );
		$checkin    = new Checkin_Manager( $settings, $repository, $audit, $capacity, $public_pages );
		$checkin_page = new Checkin_Page( $settings, $checkin );
		$attendance = new Attendance_Manager( $settings, $repository, $audit, $checkin, $checkin_page );
		$registrations_page = new Registrations_Page();
		$email_page         = new Email_Page( $settings, $email );
		$analytics_page     = new Analytics_Page( $settings, $repository );
		$calendar_schedule  = new Calendar_Schedule( $settings, $audit );
		$calendar_settings  = new Calendar_Settings();
		$calendar_display   = new Calendar_Display( $settings, $event_display );

		$settings->register();
		$calendar_settings->register();
		$calendar_schedule->register();
		$calendar_display->register();
		$email_page->register();
		$event_display->register();
		$support_groups->register();
		$metrics->register();
		( new Event_Type_Readiness( $settings ) )->register();
		$email_automation->register();
		$self_service->register();
		$events->register();
		( new Past_Events( $settings ) )->register();
		$checkin->register();
		$attendance->register();
		$feedback->register();
		( new Admin_Page( $settings, $registrations_page, $email_page, $events, $attendance, $checkin_page, $analytics_page, $support_groups ) )->register();
		( new REST_Controller( $repository, $email_automation, $audit, $settings, $capacity ) )->register();
		( new JetForm_Integration( $repository, $settings, $audit, $capacity, $email_automation ) )->register();

		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_action( 'init', array( $this, 'sync_role_capabilities' ), 20 );
		add_action( 'init', array( $this, 'maybe_upgrade_event_dates' ), 40 );
	}

	public static function activate(): void {
		Audit_Log::install();
		Support_Groups::install();
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAPABILITY );
		}
		Post_Event_Feedback::schedule();
	}

	public static function deactivate(): void {
		Past_Events::unschedule();
		Post_Event_Feedback::unschedule();
		Email_Automation::unschedule();
		delete_transient( 'hherm_feedback_cron_lock' );
	}

	public function sync_role_capabilities(): void {
		$roles = (array) get_option( Settings::OPTION_OWNER_ROLES, array() );
		$roles[] = 'administrator';
		foreach ( array_unique( array_map( 'sanitize_key', $roles ) ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * Convert legacy local event-date strings to the timestamps expected by JetEngine.
	 */
	public function maybe_upgrade_event_dates(): void {
		$installed = (string) get_option( self::DATA_VERSION_OPTION, '0' );
		if ( version_compare( $installed, '1.24.3', '>=' ) ) {
			return;
		}

		$post_type = sanitize_key( $this->settings->get( 'events_cpt', 'events' ) );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return;
		}

		$keys = array_filter(
			array_unique(
				array(
					sanitize_key( $this->settings->get( 'event_start', 'start_date' ) ),
					sanitize_key( $this->settings->get( 'event_end', 'end_date__time' ) ),
				)
			)
		);
		$event_ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		foreach ( $event_ids as $event_id ) {
			foreach ( $keys as $key ) {
				$value = get_post_meta( $event_id, $key, true );
				if ( '' === trim( (string) $value ) || is_numeric( $value ) ) {
					continue;
				}
				$timestamp = Event_Datetime::timestamp( $value );
				if ( $timestamp > 0 ) {
					update_post_meta( $event_id, $key, $timestamp );
				}
			}
		}

		update_option( self::DATA_VERSION_OPTION, HHERM_VERSION, false );
	}

	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$missing = array();
		if ( ! class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' ) ) {
			$missing[] = 'JetEngine Custom Content Types';
		}
		if ( ! function_exists( 'jet_form_builder' ) && ! defined( 'JET_FORM_BUILDER_VERSION' ) ) {
			$missing[] = 'JetFormBuilder';
		}
		if ( $missing ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( sprintf( 'Heart Hub Event Registration Manager requires: %s.', implode( ', ', $missing ) ) )
			);
		}
	}
}
