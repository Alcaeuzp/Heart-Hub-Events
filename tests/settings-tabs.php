<?php
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'YEAR_IN_SECONDS', 31536000 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	$GLOBALS['options'] = array( 'admin_email' => 'admin@example.test' );
	function current_user_can( $capability ) { return true; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default; }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
	function wp_kses_post( $value ) { return (string) $value; }
	function sanitize_hex_color( $value ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $value ) ? strtolower( (string) $value ) : ''; }
	function wp_roles() { return (object) array( 'roles' => array( 'administrator' => array( 'name' => 'Administrator' ), 'editor' => array( 'name' => 'Editor' ) ) ); }
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $value ) { return esc_attr( $value ); }
	function esc_textarea( $value ) { return esc_attr( $value ); }
	function selected( $value, $current = true, $display = true ) { $result = (string) $value === (string) $current ? ' selected' : ''; if ( $display ) echo $result; return $result; }
	function checked( $value, $current = true, $display = true ) { $result = (string) $value === (string) $current ? ' checked' : ''; if ( $display ) echo $result; return $result; }
	function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
	function submit_button( $text, ...$args ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
	function check( $value, $message ) { if ( ! $value ) throw new \Exception( $message ); echo "PASS $message\n"; }
}

namespace HeartHub\EventRegistrations {
	require dirname( __DIR__ ) . '/includes/class-settings.php';
	$settings = new Settings();
	$tabs = array( 'emails', 'appearance', 'contact-access', 'event-setup', 'shortcodes' );
	foreach ( $tabs as $tab ) {
		ob_start();
		$settings->render( $tab );
		$html = ob_get_clean();
		\check( 1 === substr_count( $html, 'hherm-settings-tab-panel is-active' ), "$tab activates exactly one panel" );
		\check( false !== strpos( $html, 'data-settings-panel="' . $tab . '"' ), "$tab panel is rendered" );
		\check( false !== strpos( $html, 'hherm_settings[email_mode]' ) && false !== strpos( $html, 'hherm_settings[notification_email]' ) && false !== strpos( $html, 'hherm_settings[contact_email]' ) && false !== strpos( $html, 'hherm_settings[cct_slug]' ), "$tab retains every core settings group in the form" );
		\check( false !== strpos( $html, 'hherm_email_automation[approval][enabled]' ) && false !== strpos( $html, 'hherm_public_page_settings[header_logo_url]' ) && false !== strpos( $html, 'hherm_owner_roles[]' ), "$tab retains automation, appearance, and role values" );
	}
	\check( false === strpos( $html, '<button type="submit">' ), 'shortcodes tab has no misleading settings save button' );
	\check( 17 === substr_count( $html, '<div class="hherm-shortcode-card">' ), 'shortcodes tab lists all seventeen registered shortcodes' );
	$GLOBALS['options'][ Settings::OPTION_EMAILS ] = array( 'approval_subject' => 'Keep this approval', 'decline_subject' => 'Old decline', 'decline_body' => '<p>Old decline body</p>' );
	$partial = $settings->sanitize_emails( array( '_scope' => 'decline', 'decline_subject' => 'New decline', 'decline_body' => '<p>New decline body</p>' ) );
	\check( 'Keep this approval' === $partial['approval_subject'], 'saving one email template preserves another template' );
	\check( 'New decline' === $partial['decline_subject'] && '<p>New decline body</p>' === $partial['decline_body'], 'a scoped template save updates its own subject and body' );
	\check( isset( $partial['support_confirmed_body'], $partial['support_pending_body'], $partial['support_interest_body'], $partial['internal_notification_body'] ), 'support group and internal notification templates are available through existing settings' );
}
