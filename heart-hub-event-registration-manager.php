<?php
/**
 * Plugin Name: Heart Hub Event Registration Manager
 * Description: Event registration management and recurring calendar schedules for Heart Hub South West.
 * Version: 1.34.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Paul Els
 * Text Domain: heart-hub-event-registration-manager
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'HHERM_VERSION', '1.34.2' );
define( 'HHERM_FILE', __FILE__ );
define( 'HHERM_PATH', plugin_dir_path( __FILE__ ) );
define( 'HHERM_URL', plugin_dir_url( __FILE__ ) );

require_once HHERM_PATH . 'includes/class-audit-log.php';
require_once HHERM_PATH . 'includes/class-settings.php';
require_once HHERM_PATH . 'includes/class-cct-repository.php';
require_once HHERM_PATH . 'includes/class-capacity-manager.php';
require_once HHERM_PATH . 'includes/class-public-page-theme.php';
require_once HHERM_PATH . 'includes/class-public-form-token.php';
require_once HHERM_PATH . 'includes/class-text-validation.php';
require_once HHERM_PATH . 'includes/class-event-datetime.php';
require_once HHERM_PATH . 'includes/class-calendar-settings.php';
require_once HHERM_PATH . 'includes/class-calendar-schedule.php';
require_once HHERM_PATH . 'includes/class-calendar-display.php';
require_once HHERM_PATH . 'includes/class-event-public-display.php';
require_once HHERM_PATH . 'includes/class-support-groups.php';
require_once HHERM_PATH . 'includes/class-registration-self-service.php';
require_once HHERM_PATH . 'includes/class-checkin-manager.php';
require_once HHERM_PATH . 'includes/class-checkin-page.php';
require_once HHERM_PATH . 'includes/class-attendance-manager.php';
require_once HHERM_PATH . 'includes/class-email-service.php';
require_once HHERM_PATH . 'includes/class-email-automation.php';
require_once HHERM_PATH . 'includes/class-post-event-feedback.php';
require_once HHERM_PATH . 'includes/class-event-types.php';
require_once HHERM_PATH . 'includes/class-event-type-readiness.php';
require_once HHERM_PATH . 'includes/class-event-metrics.php';
require_once HHERM_PATH . 'includes/class-event-manager.php';
require_once HHERM_PATH . 'includes/class-event-overview.php';
require_once HHERM_PATH . 'includes/class-past-events.php';
require_once HHERM_PATH . 'includes/class-rest-controller.php';
require_once HHERM_PATH . 'includes/class-registrations-page.php';
require_once HHERM_PATH . 'includes/class-email-page.php';
require_once HHERM_PATH . 'includes/class-analytics-page.php';
require_once HHERM_PATH . 'includes/class-admin-page.php';
require_once HHERM_PATH . 'includes/class-jetform-integration.php';
require_once HHERM_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'HeartHub\\EventRegistrations\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HeartHub\\EventRegistrations\\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'HeartHub\\EventRegistrations\\Plugin', 'instance' ) );
