<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether the site is configured for event-type and editor fields.
 *
 * This class deliberately only reads JetEngine configuration. Event/category
 * creation remains an explicit administrator action.
 */
final class Event_Type_Readiness {
	private $settings;

	/**
	 * JetEngine fields that must exist on the Events post type.
	 *
	 * Switchers use the same meta keys as the plugin editor, so their true/false
	 * values are also available to JetEngine Dynamic Visibility and Elementor.
	 */
	public static function required_fields(): array {
		return array(
			'amount_raised'               => 'number',
			'registration_fee'            => 'text',
			'sponsorship_cost'            => 'text',
			'raffle'                      => 'switcher',
			'co_hosted_event'             => 'switcher',
			'show_what_to_expect'         => 'switcher',
			'registration_enabled'        => 'switcher',
			'show_remaining_spots'        => 'switcher',
			'allow_waitlist'              => 'switcher',
			'show_interest_contact_button' => 'switcher',
		);
	}

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Keep the rules independent from WordPress so release checks can cover them.
	 *
	 * @param array $terms  Event type IDs keyed by workshop and fundraising.
	 * @param array $fields JetEngine field types keyed by field name.
	 */
	public static function evaluate( array $terms, array $fields ): array {
		$issues = array();
		$definitions = array(
			'workshop'    => array( 'label' => 'Workshop', 'slug' => Event_Types::WORKSHOP_TERM_SLUG ),
			'fundraising' => array( 'label' => 'Fundraising Event', 'slug' => Event_Types::FUNDRAISING_TERM_SLUG ),
		);
		foreach ( $definitions as $key => $definition ) {
			if ( empty( $terms[ $key ] ) ) {
				$issues[] = array(
					'code'    => 'missing_' . $key . '_term',
					'area'    => 'categories',
					'message' => sprintf( 'Create the %s category with the slug %s.', $definition['label'], $definition['slug'] ),
				);
			}
		}

		$normalised = array();
		foreach ( $fields as $name => $type ) {
			$normalised[ sanitize_key( (string) $name ) ] = sanitize_key( (string) $type );
		}
		foreach ( self::required_fields() as $name => $expected ) {
			if ( empty( $normalised[ $name ] ) ) {
				$issues[] = array(
					'code'    => 'missing_' . $name,
					'area'    => 'metadata',
					'message' => sprintf( 'Add the %s JetEngine field to the Events post type as %s.', $name, ucfirst( $expected ) ),
				);
			} elseif ( $expected !== $normalised[ $name ] ) {
				$issues[] = array(
					'code'    => 'invalid_' . $name . '_type',
					'area'    => 'metadata',
					'message' => sprintf( 'Change the %s JetEngine field from %s to %s.', $name, ucfirst( $normalised[ $name ] ), ucfirst( $expected ) ),
				);
			}
		}
		return $issues;
	}

	public function issues(): array {
		$post_type = sanitize_key( (string) $this->settings->get( 'events_cpt', 'events' ) ) ?: 'events';
		$fields = $this->configured_fields( $post_type );
		/**
		 * Allows sites with a custom JetEngine configuration provider to expose the
		 * same field-name => field-type map without replacing the readiness rules.
		 */
		$fields = apply_filters( 'hherm/event_type_meta_fields', $fields, $post_type );
		return self::evaluate( Event_Types::terms(), is_array( $fields ) ? $fields : array() );
	}

	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_screen() ) {
			return;
		}
		$issues = $this->issues();
		if ( ! $issues ) {
			return;
		}
		$category_url = admin_url( 'admin.php?page=hherm&tab=event-setup#hherm-event-categories' );
		$metadata_url = admin_url( 'admin.php?page=jet-engine-cpt' );
		?>
		<div class="notice notice-error hherm-readiness-notice">
			<p><strong><?php esc_html_e( 'Heart Hub event types are not ready:', 'heart-hub-event-registration-manager' ); ?></strong></p>
			<ul>
			<?php foreach ( $issues as $issue ) : ?>
				<li><?php echo esc_html( $issue['message'] ); ?> <a href="<?php echo esc_url( 'categories' === $issue['area'] ? $category_url : $metadata_url ); ?>"><?php echo esc_html( 'categories' === $issue['area'] ? 'Manage event categories' : 'Open JetEngine Post Types' ); ?></a></li>
			<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin route detection for displaying setup guidance; no data is changed.
	private function is_plugin_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( Admin_Page::MENU_SLUG, Event_Manager::LIST_PAGE_SLUG, Event_Manager::FUNDRAISER_LIST_PAGE_SLUG, Event_Manager::PAGE_SLUG, Event_Manager::CATEGORY_PAGE_SLUG, Attendance_Manager::PAGE_SLUG, Checkin_Manager::CHECKIN_PAGE_SLUG, Checkin_Manager::PAGE_SLUG, 'hherm', 'hherm-email-templates' ), true );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function configured_fields( string $post_type ): array {
		if ( ! function_exists( 'jet_engine' ) ) return array();
		try {
			$engine = jet_engine();
			if ( ! isset( $engine->meta_boxes ) || ! method_exists( $engine->meta_boxes, 'get_fields_for_context' ) ) return array();
			$configured = $engine->meta_boxes->get_fields_for_context( 'post_type', $post_type );
			return is_array( $configured ) ? $this->field_map( $configured ) : array();
		} catch ( \Throwable $exception ) {
			// A partial JetEngine boot should produce useful missing-field errors.
			return array();
		}
	}

	private function field_map( array $meta_fields ): array {
		$fields = array();
		foreach ( $meta_fields as $field_name => $field ) {
			if ( is_object( $field ) ) $field = get_object_vars( $field );
			if ( ! is_array( $field ) ) continue;
			$name = sanitize_key( (string) ( $field['name'] ?? $field['key'] ?? $field['id'] ?? ( is_string( $field_name ) ? $field_name : '' ) ) );
			$type = sanitize_key( (string) ( $field['type'] ?? $field['field_type'] ?? '' ) );
			if ( $name && $type ) $fields[ $name ] = $type;
		}
		return $fields;
	}
}
