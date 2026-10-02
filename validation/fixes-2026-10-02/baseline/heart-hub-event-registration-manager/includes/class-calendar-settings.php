<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Calendar display settings shared by the public shortcode and schedule manager.
 */
final class Calendar_Settings {
	public const PAGE_SLUG = 'hherm-calendar-settings';
	public const OPTION = 'hherm_calendar_display';
	private const DESIGN_VERSION_OPTION = 'hherm_calendar_design_version';
	private const DESIGN_VERSION = 2;

	/** 1.30.0 defaults replaced these; saved values still equal to them follow the new design. */
	private const PREVIOUS_DEFAULTS = array(
		'font_family'            => 'inherit',
		'calendar_ink'           => '#233744',
		'calendar_muted'         => '#60727d',
		'calendar_accent'        => '#4f87ad',
		'calendar_border'        => '#dce5e9',
		'weekday_background'     => '#f4f7f8',
		'event_background'       => '#edf5f8',
		'event_text'             => '#244454',
		'opening_hours_accent'   => '#4a9568',
		'community_visit_accent' => '#7463b6',
		'speech_accent'          => '#c88b37',
	);

	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 21 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_design' ) );
	}

	public static function maybe_migrate_design(): void {
		if ( (int) get_option( self::DESIGN_VERSION_OPTION, 0 ) >= self::DESIGN_VERSION ) return;
		$saved = get_option( self::OPTION, null );
		if ( is_array( $saved ) ) {
			$defaults = self::defaults();
			foreach ( self::PREVIOUS_DEFAULTS as $key => $previous ) {
				if ( isset( $saved[ $key ] ) && is_scalar( $saved[ $key ] ) && strtolower( (string) $saved[ $key ] ) === $previous ) $saved[ $key ] = $defaults[ $key ];
			}
			update_option( self::OPTION, $saved );
		}
		update_option( self::DESIGN_VERSION_OPTION, self::DESIGN_VERSION, true );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) return;
		wp_enqueue_style( 'hherm-admin-base', HHERM_URL . 'assets/admin-base.css', array(), HHERM_VERSION );
		wp_enqueue_style( 'hherm-events', HHERM_URL . 'assets/events.css', array( 'hherm-admin-base' ), HHERM_VERSION );
		wp_enqueue_style( 'hherm-calendar-schedule', HHERM_URL . 'assets/calendar-schedule.css', array( 'hherm-admin-base' ), HHERM_VERSION );
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			Admin_Page::MENU_SLUG,
			'Calendar Settings',
			'Calendar Settings',
			Plugin::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting( 'hherm_calendar_display', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
		add_filter( 'option_page_capability_hherm_calendar_display', static function (): string { return Plugin::CAPABILITY; } );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$heading = $input['heading'] ?? $defaults['heading'];
		$week_start = $input['week_start'] ?? '1';
		$settings = array(
			'show_heading'  => empty( $input['show_heading'] ) ? 0 : 1,
			'heading'       => substr( sanitize_text_field( is_scalar( $heading ) ? (string) $heading : $defaults['heading'] ), 0, 120 ),
			'week_start'    => is_scalar( $week_start ) && '0' === (string) $week_start ? 0 : 1,
			'months_behind' => $this->bounded_month_setting( $input['months_behind'] ?? $defaults['months_behind'], 12, true ),
			'months_ahead'  => $this->bounded_month_setting( $input['months_ahead'] ?? $defaults['months_ahead'], 36, false ),
			'show_popups'   => empty( $input['show_popups'] ) ? 0 : 1,
			'show_times'    => empty( $input['show_times'] ) ? 0 : 1,
		);
		foreach ( self::color_defaults() as $key => $default ) {
			$color = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : false;
			$settings[ $key ] = $color ?: $default;
		}
		$font_family = isset( $input['font_family'] ) && is_scalar( $input['font_family'] ) ? (string) $input['font_family'] : $defaults['font_family'];
		$font_size = isset( $input['font_size'] ) && is_scalar( $input['font_size'] ) ? (string) $input['font_size'] : $defaults['font_size'];
		$font_weight = isset( $input['font_weight'] ) && is_scalar( $input['font_weight'] ) ? (string) $input['font_weight'] : $defaults['font_weight'];
		$font_style = isset( $input['font_style'] ) && is_scalar( $input['font_style'] ) ? (string) $input['font_style'] : $defaults['font_style'];
		$line_height = isset( $input['line_height'] ) && is_scalar( $input['line_height'] ) ? (string) $input['line_height'] : $defaults['line_height'];
		$letter_spacing = isset( $input['letter_spacing'] ) && is_scalar( $input['letter_spacing'] ) ? (string) $input['letter_spacing'] : $defaults['letter_spacing'];
		$settings['font_family'] = array_key_exists( $font_family, self::font_families() ) ? $font_family : $defaults['font_family'];
		$settings['font_size'] = in_array( $font_size, array( '12', '14', '16', '18', '20', '22', '24' ), true ) ? $font_size : $defaults['font_size'];
		$settings['font_weight'] = in_array( $font_weight, array( '300', '400', '500', '600', '700' ), true ) ? $font_weight : $defaults['font_weight'];
		$settings['font_style'] = in_array( $font_style, array( 'normal', 'italic' ), true ) ? $font_style : $defaults['font_style'];
		$settings['line_height'] = in_array( $line_height, array( '1.2', '1.35', '1.5', '1.65', '1.8' ), true ) ? $line_height : $defaults['line_height'];
		$settings['letter_spacing'] = in_array( $letter_spacing, array( 'normal', 'slight', 'medium', 'wide' ), true ) ? $letter_spacing : $defaults['letter_spacing'];
		$max_width = isset( $input['max_width'] ) && is_scalar( $input['max_width'] ) ? (string) $input['max_width'] : $defaults['max_width'];
		$density = isset( $input['density'] ) && is_scalar( $input['density'] ) ? (string) $input['density'] : $defaults['density'];
		$settings['max_width'] = in_array( $max_width, array( 'full', '720', '960', '1200', '1440' ), true ) ? $max_width : $defaults['max_width'];
		$settings['density'] = in_array( $density, array( 'compact', 'comfortable', 'spacious' ), true ) ? $density : $defaults['density'];
		return $settings;
	}

	public static function defaults(): array {
		return array(
			'show_heading'  => 0,
			'heading'       => 'Events Calendar',
			'week_start'    => 1,
			'months_behind' => 12,
			'months_ahead'  => 36,
			'show_popups'   => 1,
			'show_times'    => 1,
			'max_width'     => 'full',
			'density'       => 'comfortable',
			'font_family'   => 'heart_hub',
			'font_size'     => '16',
			'font_weight'   => '400',
			'font_style'    => 'normal',
			'line_height'   => '1.5',
			'letter_spacing'=> 'normal',
		) + self::color_defaults();
	}

	private static function font_families(): array {
		return array(
			'heart_hub'  => 'Heart Hub (Instrument Sans with Literata headings)',
			'inherit'    => 'Theme default',
			'system'     => 'System UI',
			'arial'      => 'Arial',
			'verdana'    => 'Verdana',
			'tahoma'     => 'Tahoma',
			'georgia'    => 'Georgia',
			'times'      => 'Times New Roman',
			'trebuchet'  => 'Trebuchet MS',
		);
	}

	private static function color_defaults(): array {
		return array(
			'calendar_ink'           => '#173a4c',
			'calendar_muted'         => '#5b7482',
			'calendar_accent'        => '#22799c',
			'calendar_border'        => '#e1ecf1',
			'calendar_surface'       => '#ffffff',
			'weekday_background'     => '#f7fbfc',
			'event_background'       => '#e8f3f8',
			'event_text'             => '#164e66',
			'fundraiser_accent'      => '#c0602a',
			'opening_hours_accent'   => '#3e8a5e',
			'community_visit_accent' => '#6d5bb0',
			'speech_accent'          => '#b7791f',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage calendar settings.', 'heart-hub-event-registration-manager' ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) );
		}
		$settings = self::get();
		?>
		<div class="wrap hherm-events-wrap hherm-calendar-schedule-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Calendar Settings</h1><p>Control how the plugin calendar appears on the public Events page.</p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Calendar_Schedule::PAGE_SLUG ) ); ?>">Manage schedules</a></header><hr class="wp-header-end">
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Calendar settings saved.</p></div><?php endif; ?>
			<div class="notice notice-info inline"><p>Use the shortcode <code>[hherm_events_calendar]</code>, or <code>[hherm_fundraisers_calendar]</code> for fundraisers and raffles only, in an Elementor Shortcode widget or the WordPress editor. Recurring schedules are calculated for the month being viewed, so they do not create thousands of event posts.</p></div>
			<form method="post" action="options.php" class="hherm-calendar-settings-form">
				<?php settings_fields( 'hherm_calendar_display' ); ?>
				<section class="hherm-calendar-settings-section">
					<h2>Calendar behavior</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Heading</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_heading]" value="1" <?php checked( $settings['show_heading'], 1 ); ?>> Show a small label above the month name</label><p><input class="regular-text" type="text" aria-label="Heading text" name="<?php echo esc_attr( self::OPTION ); ?>[heading]" value="<?php echo esc_attr( $settings['heading'] ); ?>" maxlength="120"></p></td></tr>
					<tr><th scope="row">Week starts on</th><td><select name="<?php echo esc_attr( self::OPTION ); ?>[week_start]"><option value="1" <?php selected( (int) $settings['week_start'], 1 ); ?>>Monday</option><option value="0" <?php selected( (int) $settings['week_start'], 0 ); ?>>Sunday</option></select></td></tr>
					<tr><th scope="row">Months available in the past</th><td><select name="<?php echo esc_attr( self::OPTION ); ?>[months_behind]"><?php $this->month_options( (int) $settings['months_behind'], true ); ?></select><p class="description">This controls how far the calendar can navigate before the current month.</p></td></tr>
					<tr><th scope="row">Months available ahead</th><td><select name="<?php echo esc_attr( self::OPTION ); ?>[months_ahead]"><?php $this->month_options( (int) $settings['months_ahead'], false ); ?></select><p class="description">The default is three years. Recurring dates are calculated as visitors move through the calendar.</p></td></tr>
					<tr><th scope="row">Event details</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_popups]" value="1" <?php checked( $settings['show_popups'], 1 ); ?>> Show event details on hover, keyboard focus, or tap</label></td></tr>
					<tr><th scope="row">Event times</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_times]" value="1" <?php checked( $settings['show_times'], 1 ); ?>> Show event times on calendar entries</label></td></tr>
				</table>
				</section>

				<section class="hherm-calendar-settings-section">
					<h2>Typography</h2>
					<p class="description">Set the calendar font and primary text color. Font sizing also adjusts event labels and detail popups.</p>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="hherm-calendar-font-family">Font family</label></th><td><select id="hherm-calendar-font-family" name="<?php echo esc_attr( self::OPTION ); ?>[font_family]"><?php foreach ( self::font_families() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['font_family'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th scope="row"><label for="hherm-calendar-font-size">Font size</label></th><td><select id="hherm-calendar-font-size" name="<?php echo esc_attr( self::OPTION ); ?>[font_size]"><?php foreach ( array( '12', '14', '16', '18', '20', '22', '24' ) as $size ) : ?><option value="<?php echo esc_attr( $size ); ?>" <?php selected( $settings['font_size'], $size ); ?>><?php echo esc_html( $size . ' px' ); ?></option><?php endforeach; ?></select><p class="description">Sets the base size. Calendar labels scale with it.</p></td></tr>
						<tr><th scope="row"><label for="hherm-calendar-font-weight">Font weight</label></th><td><select id="hherm-calendar-font-weight" name="<?php echo esc_attr( self::OPTION ); ?>[font_weight]"><?php foreach ( array( '300' => 'Light', '400' => 'Regular', '500' => 'Medium', '600' => 'Semi-bold', '700' => 'Bold' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['font_weight'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th scope="row"><label for="hherm-calendar-font-style">Font style</label></th><td><select id="hherm-calendar-font-style" name="<?php echo esc_attr( self::OPTION ); ?>[font_style]"><option value="normal" <?php selected( $settings['font_style'], 'normal' ); ?>>Normal</option><option value="italic" <?php selected( $settings['font_style'], 'italic' ); ?>>Italic</option></select></td></tr>
						<tr><th scope="row"><label for="hherm-calendar-line-height">Line height</label></th><td><select id="hherm-calendar-line-height" name="<?php echo esc_attr( self::OPTION ); ?>[line_height]"><?php foreach ( array( '1.2', '1.35', '1.5', '1.65', '1.8' ) as $height ) : ?><option value="<?php echo esc_attr( $height ); ?>" <?php selected( $settings['line_height'], $height ); ?>><?php echo esc_html( $height ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th scope="row"><label for="hherm-calendar-letter-spacing">Letter spacing</label></th><td><select id="hherm-calendar-letter-spacing" name="<?php echo esc_attr( self::OPTION ); ?>[letter_spacing]"><option value="normal" <?php selected( $settings['letter_spacing'], 'normal' ); ?>>Normal</option><option value="slight" <?php selected( $settings['letter_spacing'], 'slight' ); ?>>Slightly wider</option><option value="medium" <?php selected( $settings['letter_spacing'], 'medium' ); ?>>Medium</option><option value="wide" <?php selected( $settings['letter_spacing'], 'wide' ); ?>>Wide</option></select></td></tr>
						<?php $this->color_row( $settings, 'calendar_ink', 'Primary text color', 'Month heading, day numbers, and main calendar text.' ); ?>
					</table>
				</section>

				<section class="hherm-calendar-settings-section">
					<h2>Layout</h2>
					<p class="description">These settings apply to every calendar shortcode on the site.</p>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="hherm-calendar-max-width">Maximum width</label></th><td><select id="hherm-calendar-max-width" name="<?php echo esc_attr( self::OPTION ); ?>[max_width]"><option value="full" <?php selected( $settings['max_width'], 'full' ); ?>>Use available width</option><option value="720" <?php selected( $settings['max_width'], '720' ); ?>>720 px</option><option value="960" <?php selected( $settings['max_width'], '960' ); ?>>960 px</option><option value="1200" <?php selected( $settings['max_width'], '1200' ); ?>>1200 px</option><option value="1440" <?php selected( $settings['max_width'], '1440' ); ?>>1440 px</option></select><p class="description">Narrower calendars are centered in their page-builder column.</p></td></tr>
						<tr><th scope="row"><label for="hherm-calendar-density">Calendar spacing</label></th><td><select id="hherm-calendar-density" name="<?php echo esc_attr( self::OPTION ); ?>[density]"><option value="compact" <?php selected( $settings['density'], 'compact' ); ?>>Compact</option><option value="comfortable" <?php selected( $settings['density'], 'comfortable' ); ?>>Comfortable</option><option value="spacious" <?php selected( $settings['density'], 'spacious' ); ?>>Spacious</option></select><p class="description">Adjusts day-cell height and spacing on desktop and mobile.</p></td></tr>
					</table>
				</section>

				<section class="hherm-calendar-settings-section">
					<h2>Colors</h2>
					<p class="description">Choose the calendar palette. Each category keeps a soft background; its colored dot is configurable below.</p>
					<table class="form-table" role="presentation">
						<?php
						$this->color_row( $settings, 'calendar_muted', 'Secondary text', 'Weekday labels, event details, and supporting text.' );
						$this->color_row( $settings, 'calendar_accent', 'Accent', 'Today marker, navigation hover, and general event marker.' );
						$this->color_row( $settings, 'calendar_border', 'Grid lines', 'Calendar and navigation borders.' );
						$this->color_row( $settings, 'calendar_surface', 'Day and popup background', 'Day cells, navigation buttons, and event detail popups.' );
						$this->color_row( $settings, 'weekday_background', 'Weekday header background', 'Background behind weekday names.' );
						$this->color_row( $settings, 'event_background', 'Event background', 'Background for workshops and other events.' );
						$this->color_row( $settings, 'event_text', 'Event text', 'Text color for workshops and other events.' );
						$this->color_row( $settings, 'fundraiser_accent', 'Fundraiser marker', 'Dot color for fundraisers and raffles.' );
						$this->color_row( $settings, 'opening_hours_accent', 'Opening hours marker', 'Dot color for opening-hours entries.' );
						$this->color_row( $settings, 'community_visit_accent', 'Community visit marker', 'Dot color for community-visit entries.' );
						$this->color_row( $settings, 'speech_accent', 'Speech marker', 'Dot color for speech entries.' );
						?>
					</table>
				</section>
				<?php submit_button( 'Save calendar settings' ); ?>
			</form>
		</div>
		<?php
	}

	private function month_options( int $selected, bool $allow_zero ): void {
		$options = $allow_zero ? array( 0, 3, 6, 12, 24, 36 ) : array( 3, 6, 12, 24, 36 );
		foreach ( $options as $months ) {
			$label = 0 === $months ? 'Current month only' : sprintf( _n( '%d month', '%d months', $months, 'heart-hub-event-registration-manager' ), $months );
			echo '<option value="' . esc_attr( (string) $months ) . '" ' . selected( $selected, $months, false ) . '>' . esc_html( $label ) . '</option>';
		}
	}

	private function bounded_month_setting( $value, int $default, bool $allow_zero ): int {
		$value = is_scalar( $value ) ? absint( $value ) : $default;
		$allowed = $allow_zero ? array( 0, 3, 6, 12, 24, 36 ) : array( 3, 6, 12, 24, 36 );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function color_row( array $settings, string $key, string $label, string $help ): void {
		$id = 'hherm-calendar-color-' . $key;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="hherm-calendar-color-control" id="<?php echo esc_attr( $id ); ?>" type="color" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings[ $key ] ); ?>"><p class="description"><?php echo esc_html( $help ); ?></p></td>
		</tr>
		<?php
	}
}
