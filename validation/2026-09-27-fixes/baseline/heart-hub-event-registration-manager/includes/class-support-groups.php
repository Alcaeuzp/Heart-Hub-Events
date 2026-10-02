<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

/**
 * Manages six-session online support-group programmes and applications.
 */
final class Support_Groups {
	public const PAGE_SLUG = 'hherm-support-groups';
	public const EDIT_PAGE_SLUG = 'hherm-support-group-editor';
	public const OPTION_PROGRAMMES = 'hherm_support_group_programmes';
	public const OPTION_SETTINGS = 'hherm_support_group_settings';
	public const SCHEMA_OPTION = 'hherm_support_group_schema';
	private const SCHEMA_VERSION = '3';
	private const FUTURE_PROGRAMME_ID = 'future-support-group';
	private const RATE_LIMIT = 5;

	private $settings;
	private $email;

	public function __construct( Settings $settings, ?Email_Service $email = null ) {
		$this->settings = $settings;
		$this->email = $email;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hherm_support_group_applications';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			programme_id varchar(64) NOT NULL,
			first_name varchar(100) NOT NULL,
			last_name varchar(100) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			attended_before tinyint(1) unsigned NOT NULL DEFAULT 0,
			reason text NULL,
			application_type varchar(20) NOT NULL DEFAULT 'registration',
			status varchar(20) NOT NULL DEFAULT 'pending',
			email_status varchar(20) NOT NULL DEFAULT '',
			internal_email_status varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY programme_id (programme_id),
			KEY created_at (created_at),
			UNIQUE KEY programme_email_type (programme_id(36), email(90), application_type(20))
		) {$charset};" );
		// dbDelta adds the new application-type-aware key but does not remove the superseded unique key on upgraded sites.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema migration on this plugin-owned table.
		$old_index = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'programme_email' ) );
		if ( $old_index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Replaces the old registration-only unique key during a schema migration.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, 'programme_email' ) );
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_install_schema' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_form_assets' ) );
		add_shortcode( 'hherm_support_groups', array( $this, 'schedule_shortcode' ) );
		add_shortcode( 'hherm_support_group_name', array( $this, 'programme_name_shortcode' ) );
		add_shortcode( 'hherm_support_group_start_date', array( $this, 'programme_start_date_shortcode' ) );
		add_shortcode( 'hherm_support_group_registration_open', array( $this, 'registration_open_shortcode' ) );
		add_shortcode( 'hherm_support_group_registration_form', array( $this, 'registration_form_shortcode' ) );
		add_action( 'admin_init', array( $this, 'redirect_future_programme' ) );
		add_action( 'admin_post_hherm_save_support_group_programme', array( $this, 'save_programme' ) );
		add_action( 'admin_post_hherm_delete_support_group_programme', array( $this, 'delete_programme' ) );
		add_action( 'admin_post_hherm_decline_support_group_applicant', array( $this, 'decline_applicant' ) );
		add_action( 'admin_post_hherm_delete_support_group_applicant', array( $this, 'delete_applicant' ) );
		add_action( 'admin_post_hherm_support_group_register', array( $this, 'submit_registration' ) );
		add_action( 'admin_post_nopriv_hherm_support_group_register', array( $this, 'submit_registration' ) );
	}

	public function maybe_install_schema(): void {
		if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			self::install();
		}
	}

	public function enqueue_public_form_assets(): void {
		wp_enqueue_style( 'hherm-support-group-form', HHERM_URL . 'assets/support-group-form.css', array(), HHERM_VERSION );
		wp_enqueue_script( 'hherm-support-group-form', HHERM_URL . 'assets/support-group-form.js', array(), HHERM_VERSION, true );
	}

	public function settings(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION_SETTINGS, array() ),
			array( 'registration_programme_id' => '' )
		);
	}

	public function programmes(): array {
		$stored = get_option( self::OPTION_PROGRAMMES, array() );
		$programmes = array();
		foreach ( is_array( $stored ) ? $stored : array() as $programme ) {
			if ( ! is_array( $programme ) ) {
				continue;
			}
			$sessions = array();
			foreach ( (array) ( $programme['sessions'] ?? array() ) as $session ) {
				$normalised = $this->normalise_session( $session );
				if ( $normalised ) {
					$sessions[] = $normalised;
				}
			}
			usort( $sessions, array( $this, 'compare_sessions' ) );
			if ( ! $sessions ) {
				continue;
			}
			$programmes[] = array(
				'id'       => sanitize_key( (string) ( $programme['id'] ?? '' ) ),
				'name'     => sanitize_text_field( (string) ( $programme['name'] ?? '' ) ),
				'sessions' => array_slice( $sessions, 0, 6 ),
			);
		}

		usort(
			$programmes,
			static function ( array $left, array $right ): int {
				return strcmp( $left['sessions'][0]['date'] . ' ' . $left['sessions'][0]['start_time'], $right['sessions'][0]['date'] . ' ' . $right['sessions'][0]['start_time'] );
			}
		);
		return $programmes;
	}

	public function selected_programme( string $requested_id = '' ) {
		$settings = $this->settings();
		$programme_id = sanitize_key( $requested_id ?: (string) $settings['registration_programme_id'] );
		foreach ( $this->programmes() as $programme ) {
			if ( $programme_id && $programme['id'] === $programme_id ) {
				return $programme;
			}
		}
		return null;
	}

	private function future_programme(): array {
		return array(
			'id'       => self::FUTURE_PROGRAMME_ID,
			'name'     => 'Future support group',
			'sessions' => array(),
		);
	}

	public function registration_is_open( array $programme ): bool {
		if ( empty( $programme['sessions'][0] ) ) {
			return false;
		}
		$first_session = $programme['sessions'][0];
		$cutoff = $this->date_time( $first_session['date'], $first_session['start_time'] );
		return $cutoff && current_datetime()->getTimestamp() < $cutoff->getTimestamp();
	}

	public function registration_open_shortcode( $attributes = array() ): string {
		$this->prevent_stale_page_cache();
		$attributes = shortcode_atts( array( 'programme_id' => '' ), (array) $attributes, 'hherm_support_group_registration_open' );
		$programme = $this->selected_programme( sanitize_key( (string) $attributes['programme_id'] ) );
		return $programme && $this->registration_is_open( $programme ) ? 'true' : 'false';
	}

	public function programme_name_shortcode( $attributes = array() ): string {
		$this->prevent_stale_page_cache();
		$attributes = shortcode_atts(
			array(
				'programme_id' => '',
				'empty_text'   => '',
			),
			(array) $attributes,
			'hherm_support_group_name'
		);
		$programme = $this->selected_programme( sanitize_key( (string) $attributes['programme_id'] ) );
		$value = $programme ? (string) $programme['name'] : sanitize_text_field( (string) $attributes['empty_text'] );
		return esc_html( $value );
	}

	public function programme_start_date_shortcode( $attributes = array() ): string {
		$this->prevent_stale_page_cache();
		$attributes = shortcode_atts(
			array(
				'programme_id' => '',
				'date_format'  => 'j F Y',
				'empty_text'   => '',
			),
			(array) $attributes,
			'hherm_support_group_start_date'
		);
		$programme = $this->selected_programme( sanitize_key( (string) $attributes['programme_id'] ) );
		$date_time = $programme && ! empty( $programme['sessions'][0] ) ? $this->date_time( $programme['sessions'][0]['date'], $programme['sessions'][0]['start_time'] ) : null;
		if ( ! $date_time ) {
			return esc_html( sanitize_text_field( (string) $attributes['empty_text'] ) );
		}
		$date_format = sanitize_text_field( (string) $attributes['date_format'] ) ?: 'j F Y';
		return esc_html( wp_date( $date_format, $date_time->getTimestamp(), wp_timezone() ) );
	}

	public function schedule_shortcode( $attributes = array() ): string {
		$this->prevent_stale_page_cache();
		$attributes = shortcode_atts(
			array(
				'programme_id'  => '',
				'date_format'   => 'j F',
				'time_format'   => 'g.i a',
				'empty_text'    => '',
				'underway_text' => 'Please register your expression of interest for the next one if you’d like to attend.',
			),
			(array) $attributes,
			'hherm_support_groups'
		);
		$programme = $this->selected_programme( sanitize_key( (string) $attributes['programme_id'] ) );
		if ( ! $programme ) {
			$empty_text = sanitize_text_field( (string) $attributes['empty_text'] );
			return $empty_text ? '<p class="hherm-support-groups-empty">' . esc_html( $empty_text ) . '</p>' : '';
		}

		wp_enqueue_style( 'hherm-support-groups', HHERM_URL . 'assets/support-groups.css', array(), HHERM_VERSION );
		if ( ! $this->registration_is_open( $programme ) ) {
			$underway_text = sanitize_text_field( (string) $attributes['underway_text'] );
			return '<div class="hherm-support-groups-underway" role="status"><strong>Current support group is underway.</strong>' . ( $underway_text ? '<p>' . esc_html( $underway_text ) . '</p>' : '' ) . '</div>';
		}

		$today = current_datetime()->format( 'Y-m-d' );
		$sessions = array_values(
			array_filter(
				$programme['sessions'],
				static function ( array $session ) use ( $today ): bool {
					return $session['date'] >= $today;
				}
			)
		);
		if ( ! $sessions ) {
			$empty_text = sanitize_text_field( (string) $attributes['empty_text'] );
			return $empty_text ? '<p class="hherm-support-groups-empty">' . esc_html( $empty_text ) . '</p>' : '';
		}

		$date_format = sanitize_text_field( (string) $attributes['date_format'] ) ?: 'j F';
		$time_format = sanitize_text_field( (string) $attributes['time_format'] ) ?: 'g.i a';
		$items = array();
		foreach ( $sessions as $session ) {
			$date_time = $this->date_time( $session['date'], $session['start_time'] );
			if ( ! $date_time ) {
				continue;
			}
			$date_label = wp_date( $date_format, $date_time->getTimestamp(), wp_timezone() );
			$start_label = wp_date( $time_format, $date_time->getTimestamp(), wp_timezone() );
			$time_html = '<time datetime="' . esc_attr( $session['date'] . 'T' . $session['start_time'] ) . '">' . esc_html( $start_label ) . '</time>';
			if ( $session['end_time'] ) {
				$end_date_time = $this->date_time( $session['date'], $session['end_time'] );
				if ( $end_date_time ) {
					$end_label = wp_date( $time_format, $end_date_time->getTimestamp(), wp_timezone() );
					$time_html .= ' <span aria-hidden="true">&ndash;</span> <time datetime="' . esc_attr( $session['date'] . 'T' . $session['end_time'] ) . '">' . esc_html( $end_label ) . '</time>';
				}
			}
			$items[] = '<li class="hherm-support-groups__item"><time class="hherm-support-groups__date" datetime="' . esc_attr( Event_Datetime::display( $session['date'], 'd/m/Y' ) ) . '">' . esc_html( $date_label ) . '</time><span class="hherm-support-groups__time">' . $time_html . '</span></li>';
		}
		return $items ? '<ul class="hherm-support-groups">' . implode( '', $items ) . '</ul>' : '';
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This method only reads the redirect result; form writes verify a dedicated nonce in submit_registration().
	public function registration_form_shortcode( $attributes = array() ): string {
		$this->prevent_stale_page_cache();
		$attributes = shortcode_atts(
			array(
				'programme_id' => '',
			),
			(array) $attributes,
			'hherm_support_group_registration_form'
		);
		$programme = $this->selected_programme( sanitize_key( (string) $attributes['programme_id'] ) );
		if ( ! $programme ) {
			$programme = $this->future_programme();
		}

		$this->enqueue_public_form_assets();
		$result = isset( $_GET['hherm_support_group_result'] ) ? sanitize_key( wp_unslash( $_GET['hherm_support_group_result'] ) ) : '';
		$result_programme = isset( $_GET['hherm_support_group_programme'] ) ? sanitize_key( wp_unslash( $_GET['hherm_support_group_programme'] ) ) : '';
		if ( $result_programme !== $programme['id'] ) {
			$result = '';
		}
		if ( in_array( $result, array( 'confirmed', 'pending', 'interest', 'confirmed-email-failed', 'pending-email-failed', 'interest-email-failed' ), true ) ) {
			$success_messages = array(
				'confirmed'              => 'Your registration is confirmed. Please check your email. Someone will contact you closer to the first meeting with information about how to connect.',
				'pending'                => 'Thank you for registering. Please check your email. Someone will be in contact to confirm your registration.',
				'interest'               => 'Thank you for registering your expression of interest for the next support programme. Please check your email. Someone will be in contact when the next programme is available.',
				'confirmed-email-failed' => 'Your registration is confirmed, but we could not send the confirmation email. Someone will contact you closer to the first meeting.',
				'pending-email-failed'   => 'Thank you for registering. We could not send the acknowledgement email, but your application was saved and someone will be in contact.',
				'interest-email-failed'  => 'Thank you for registering your expression of interest. We could not send the acknowledgement email, but your application was saved and someone will be in contact when the next programme is available.',
			);
			$message = $success_messages[ $result ];
			$heading = 0 === strpos( $result, 'interest' ) ? 'Expression of interest received' : 'Registration received';
			return '<div class="hherm-support-registration-message is-success" role="status"><h3>' . esc_html( $heading ) . '</h3><p>' . esc_html( $message ) . '</p></div>';
		}
		$is_expression_of_interest = ! $this->registration_is_open( $programme );

		$errors = array(
			'invalid'            => 'Please complete all required fields and try again.',
			'expired'            => 'This form has expired. Please refresh the page and submit your details again.',
			'duplicate'          => 'This email address is already registered for the selected support programme.',
			'duplicate-interest' => 'This email address has already submitted an expression of interest for the next support programme.',
			'rate'               => 'Too many submission attempts were received. Please wait and try again later.',
			'closed'             => 'This support programme is no longer available.',
			'failed'             => 'We could not save your application. Please try again or contact us.',
		);
		$return_url = get_permalink();
		if ( ! $return_url ) {
			$return_url = home_url( '/' );
		}
		ob_start();
		?>
		<div class="hherm-support-registration" id="hherm-support-registration-<?php echo esc_attr( $programme['id'] ); ?>">
			<?php if ( isset( $errors[ $result ] ) ) : ?><div class="hherm-support-registration__error" role="alert"><?php echo esc_html( $errors[ $result ] ); ?></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hherm-support-form>
				<input type="hidden" name="action" value="hherm_support_group_register">
				<input type="hidden" name="programme_id" value="<?php echo esc_attr( $programme['id'] ); ?>">
				<input type="hidden" name="return_url" value="<?php echo esc_url( $return_url ); ?>"><input type="hidden" name="form_mode" value="<?php echo esc_attr( $is_expression_of_interest ? 'interest' : 'registration' ); ?>">
				<?php wp_nonce_field( 'hherm_support_group_register_' . $programme['id'], 'hherm_support_group_nonce' ); ?>
				<?php echo Public_Form_Token::field( 'hherm_support_group_register_' . $programme['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped hidden input. ?>
				<div class="hherm-support-registration__name-row">
					<label><span>First name</span><input type="text" name="first_name" maxlength="100" autocomplete="given-name" required></label>
					<label><span>Last name</span><input type="text" name="last_name" maxlength="100" autocomplete="family-name" required></label>
				</div>
				<label><span>Email address</span><input type="email" name="email" maxlength="190" autocomplete="email" required></label>
				<label><span>Phone number <em>Optional</em></span><input type="tel" name="phone" maxlength="50" autocomplete="tel"></label>
				<fieldset class="hherm-support-registration__choice"><legend>Have you attended an online support group before?</legend><div><label><input type="radio" name="attended_before" value="yes" required aria-controls="hherm-support-reason-<?php echo esc_attr( $programme['id'] ); ?>"><span>Yes</span></label><label><input type="radio" name="attended_before" value="no" required aria-controls="hherm-support-reason-<?php echo esc_attr( $programme['id'] ); ?>"><span>No</span></label></div></fieldset>
				<label class="hherm-support-registration__reason" id="hherm-support-reason-<?php echo esc_attr( $programme['id'] ); ?>" hidden><span>Can you please explain why you would like to join this support group?</span><textarea name="reason" rows="5" maxlength="5000"></textarea></label>
				<div class="hherm-support-registration__honeypot" aria-hidden="true" hidden><label>Website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
				<p class="hherm-support-registration__privacy">We’ll use these details only to review your application and contact you about the support group.</p>
				<button type="submit"><?php echo esc_html( $is_expression_of_interest ? 'Register expression of interest' : 'Submit registration' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function render_list(): void {
		$this->require_capability();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only section selection on a capability-protected admin route.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'programmes';
		$view = 'interests' === $view ? 'interests' : 'programmes';
		$programmes = $this->programmes();
		$upcoming = array();
		$current_past = array();
		foreach ( $programmes as $programme ) {
			if ( $this->registration_is_open( $programme ) ) {
				$upcoming[] = $programme;
			} else {
				$current_past[] = $programme;
			}
		}
		$settings = $this->settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selection on a capability-protected admin route.
		$notice = isset( $_GET['hherm_notice'] ) ? sanitize_key( wp_unslash( $_GET['hherm_notice'] ) ) : '';
		?>
		<div class="wrap hherm-support-groups-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Support Groups</h1><p>Manage upcoming programmes and review the people who have applied.</p></div><div class="hherm-page-actions"><a class="button button-primary" href="<?php echo esc_url( $this->editor_url() ); ?>">Add new support programme</a></div></header><hr class="wp-header-end">
			<nav class="hherm-support-section-tabs" aria-label="Support group sections"><a class="<?php echo 'programmes' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( $this->page_url() ); ?>" <?php echo 'programmes' === $view ? 'aria-current="page"' : ''; ?>>Programmes</a><a class="<?php echo 'interests' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( $this->page_url( 'interests' ) ); ?>" <?php echo 'interests' === $view ? 'aria-current="page"' : ''; ?>>Expressions of interest <span><?php echo esc_html( $this->interest_count() ); ?></span></a></nav>
			<?php if ( 'deleted' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>Support group programme and its applicant records were deleted.</p></div><?php endif; ?>
			<?php if ( 'applicant-deleted' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>The expression of interest was permanently deleted.</p></div><?php endif; ?>
			<?php if ( 'interests' === $view ) : ?>
				<?php $this->render_interest_applicants(); ?>
			</div>
			<?php return; endif; ?>
			<?php $this->render_programme_table( 'Upcoming support programmes', $upcoming, $settings ); ?>
			<?php if ( $current_past ) : ?><?php $this->render_programme_table( 'Current and past programmes', $current_past, $settings ); ?><?php endif; ?>
			<section class="hherm-form-card hherm-support-groups-reference"><div class="hherm-form-card__heading"><div><h2>Front-end shortcodes</h2><p>The programme marked In use supplies the default schedule, programme details, and registration form.</p></div></div><div class="hherm-support-groups-shortcodes"><div><strong>Meeting dates</strong><code>[hherm_support_groups]</code></div><div><strong>Programme name</strong><code>[hherm_support_group_name]</code></div><div><strong>Start date</strong><code>[hherm_support_group_start_date]</code></div><div><strong>Registration form</strong><code>[hherm_support_group_registration_form]</code></div><div><strong>Registration visibility</strong><code>[hherm_support_group_registration_open]</code></div></div></section>
		</div>
		<?php
	}

	/**
	 * The "future programme" holds expressions of interest, which have their own view.
	 * Redirect before any admin output so the headers can still be sent.
	 */
	public function redirect_future_programme(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		if ( ! isset( $_GET['page'], $_GET['programme_id'] ) || self::EDIT_PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) || self::FUTURE_PROGRAMME_ID !== sanitize_key( wp_unslash( $_GET['programme_id'] ) ) || ! empty( $_GET['applicant_id'] ) ) return;
		wp_safe_redirect( $this->page_url( 'interests' ) );
		exit;
	}

	public function render_editor(): void {
		$this->require_capability();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only editor routing on a capability-protected admin page; saves and deletes use dedicated nonces.
		$id                       = isset( $_GET['programme_id'] ) ? sanitize_key( wp_unslash( $_GET['programme_id'] ) ) : '';
		$applicant_id             = isset( $_GET['applicant_id'] ) ? absint( wp_unslash( $_GET['applicant_id'] ) ) : 0;
		$confirm_delete           = ! empty( $_GET['confirm_delete'] );
		$confirm_applicant_delete = ! empty( $_GET['confirm_applicant_delete'] );
		$notice = isset( $_GET['hherm_notice'] ) ? sanitize_key( wp_unslash( $_GET['hherm_notice'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$programme = $id ? $this->selected_programme( $id ) : null;
		if ( ! $programme && self::FUTURE_PROGRAMME_ID === $id ) {
			$programme = $this->future_programme();
		}
		if ( $id && ! $programme ) {
			wp_die( esc_html( 'The requested support group programme could not be found.' ) );
		}
		if ( $confirm_delete && $programme && self::FUTURE_PROGRAMME_ID !== $id ) {
			$this->render_delete_confirmation( $programme );
			return;
		}
		if ( $applicant_id ) {
			$applicant = $programme ? $this->applicant_for( $programme['id'], $applicant_id ) : null;
			if ( ! $applicant ) {
				wp_die( esc_html( 'The requested support group applicant could not be found.' ) );
			}
			if ( $confirm_applicant_delete ) {
				$this->render_applicant_delete_confirmation( $programme, $applicant );
				return;
			}
			$this->render_applicant_detail( $programme, $applicant, $notice );
			return;
		}
		$settings = $this->settings();
		$is_selected = $programme && $programme['id'] === sanitize_key( (string) $settings['registration_programme_id'] );
		if ( ! $programme && empty( $settings['registration_programme_id'] ) ) {
			$is_selected = true;
		}
		$form_sessions = $programme ? $programme['sessions'] : array_fill( 0, 6, array( 'date' => '', 'start_time' => '', 'end_time' => '' ) );
		?>
		<div class="wrap hherm-support-groups-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1><?php echo esc_html( $programme ? 'Edit support programme' : 'Add support programme' ); ?></h1><p><?php echo esc_html( $programme ? 'Update the schedule and review this programme’s applicants.' : 'Create a six-meeting online support programme.' ); ?></p></div><div class="hherm-page-actions"><a class="button" href="<?php echo esc_url( $this->page_url() ); ?>">Back to support groups</a></div></header><hr class="wp-header-end">
			<?php if ( 'saved' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>Support group programme saved.</p></div><?php endif; ?>
			<?php if ( 'applicant-deleted' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>The applicant was permanently deleted.</p></div><?php endif; ?>
			<?php if ( in_array( $notice, array( 'invalid-name', 'invalid-session', 'duplicate' ), true ) ) : ?><div class="notice notice-error"><p>The programme could not be saved. Check the name and all six meeting dates and times.</p></div><?php endif; ?>
			<section class="hherm-form-card">
				<div class="hherm-form-card__heading"><div><h2>Programme details</h2><p>The first meeting’s start time is the automatic registration cutoff.</p></div></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hherm_save_support_group_programme"><input type="hidden" name="programme_id" value="<?php echo esc_attr( $programme['id'] ?? '' ); ?>"><?php wp_nonce_field( 'hherm_save_support_group_programme' ); ?>
					<div class="hherm-form-grid"><label class="hherm-field--wide">Programme name <input type="text" name="programme_name" required value="<?php echo esc_attr( $programme['name'] ?? '' ); ?>" placeholder="e.g. October 2026 Online Support Group"></label></div>
					<div class="hherm-support-group-session-editor"><div class="hherm-support-group-session-row is-heading"><span>Meeting</span><span>Date</span><span>Start time</span><span>End time <small>(optional)</small></span></div>
					<?php for ( $index = 0; $index < 6; $index++ ) : $session = $form_sessions[ $index ] ?? array( 'date' => '', 'start_time' => '', 'end_time' => '' ); ?><div class="hherm-support-group-session-row"><strong><?php echo esc_html( $index + 1 ); ?></strong><label><span class="hherm-visually-hidden">Meeting <?php echo esc_html( $index + 1 ); ?> date</span><input type="text" placeholder="DD/MM/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" name="session_date[]" required value="<?php echo esc_attr( Event_Datetime::display( $session['date'], 'd/m/Y' ) ); ?>"></label><label><span class="hherm-visually-hidden">Meeting <?php echo esc_html( $index + 1 ); ?> start time</span><input type="time" name="session_start_time[]" required value="<?php echo esc_attr( $session['start_time'] ); ?>"></label><label><span class="hherm-visually-hidden">Meeting <?php echo esc_html( $index + 1 ); ?> end time</span><input type="time" name="session_end_time[]" value="<?php echo esc_attr( $session['end_time'] ); ?>"></label></div><?php endfor; ?>
					</div>
					<label class="hherm-support-groups-public-toggle"><input type="checkbox" name="use_for_registration" value="true" <?php checked( $is_selected ); ?>><span><strong>Use this programme for the public schedule and registration form</strong><small>All five support-group shortcodes will use this programme by default.</small></span></label>
					<div class="hherm-support-groups-form-actions"><button type="submit" class="button button-primary">Save programme</button><a class="button" href="<?php echo esc_url( $this->page_url() ); ?>">Cancel</a></div>
				</form>
			</section>
			<?php if ( $programme ) : ?>
				<?php $this->render_applicants( $programme ); ?>
				<section class="hherm-form-card hherm-danger-zone"><div><h2>Delete programme</h2><p>Deletion requires a separate confirmation and also removes the programme’s applicant records.</p></div><a class="button hherm-danger-button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::EDIT_PAGE_SLUG, 'programme_id' => $programme['id'], 'confirm_delete' => 1 ), admin_url( 'admin.php' ) ) ); ?>">Delete programme</a></section>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save_programme(): void {
		$this->authorise( 'hherm_save_support_group_programme' );
		$id = isset( $_POST['programme_id'] ) ? sanitize_key( wp_unslash( $_POST['programme_id'] ) ) : '';
		$name = isset( $_POST['programme_name'] ) ? sanitize_text_field( wp_unslash( $_POST['programme_name'] ) ) : '';
		if ( ! $name ) {
			$this->redirect_editor( $id, 'invalid-name' );
		}
		$dates = isset( $_POST['session_date'] ) ? (array) wp_unslash( $_POST['session_date'] ) : array();
		$starts = isset( $_POST['session_start_time'] ) ? (array) wp_unslash( $_POST['session_start_time'] ) : array();
		$ends = isset( $_POST['session_end_time'] ) ? (array) wp_unslash( $_POST['session_end_time'] ) : array();
		$sessions = array();
		$unique = array();
		for ( $index = 0; $index < 6; $index++ ) {
			$session = $this->normalise_session( array( 'date' => $dates[ $index ] ?? '', 'start_time' => $starts[ $index ] ?? '', 'end_time' => $ends[ $index ] ?? '' ) );
			if ( ! $session || ( $session['end_time'] && $session['end_time'] <= $session['start_time'] ) ) {
				$this->redirect_editor( $id, 'invalid-session' );
			}
			$key = $session['date'] . 'T' . $session['start_time'];
			if ( isset( $unique[ $key ] ) ) {
				$this->redirect_editor( $id, 'duplicate' );
			}
			$unique[ $key ] = true;
			$sessions[] = $session;
		}
		usort( $sessions, array( $this, 'compare_sessions' ) );
		if ( ! $id ) {
			$id = sanitize_key( wp_generate_uuid4() );
		}
		$programmes = $this->programmes();
		$replacement = array( 'id' => $id, 'name' => $name, 'sessions' => $sessions );
		$updated = false;
		foreach ( $programmes as $index => $programme ) {
			if ( $programme['id'] === $id ) {
				$programmes[ $index ] = $replacement;
				$updated = true;
				break;
			}
		}
		if ( ! $updated ) {
			$programmes[] = $replacement;
		}
		update_option( self::OPTION_PROGRAMMES, array_values( $programmes ), false );

		$settings = $this->settings();
		$is_selected = $id === sanitize_key( (string) $settings['registration_programme_id'] );
		$make_selected = 'true' === ( isset( $_POST['use_for_registration'] ) ? sanitize_key( wp_unslash( $_POST['use_for_registration'] ) ) : '' );
		if ( $make_selected ) {
			update_option( self::OPTION_SETTINGS, array( 'registration_programme_id' => $id ), false );
		} elseif ( $is_selected ) {
			update_option( self::OPTION_SETTINGS, array( 'registration_programme_id' => '' ), false );
		}
		$this->redirect_editor( $id, 'saved' );
	}

	public function delete_programme(): void {
		$this->require_capability();
		$id = isset( $_POST['programme_id'] ) ? sanitize_key( wp_unslash( $_POST['programme_id'] ) ) : '';
		check_admin_referer( 'hherm_delete_support_group_programme_' . $id );
		if ( 'yes' !== ( isset( $_POST['confirm_delete'] ) ? sanitize_key( wp_unslash( $_POST['confirm_delete'] ) ) : '' ) ) {
			$this->redirect_editor( $id, '' );
		}
		$programmes = array_values( array_filter( $this->programmes(), static function ( array $programme ) use ( $id ): bool { return $programme['id'] !== $id; } ) );
		update_option( self::OPTION_PROGRAMMES, $programmes, false );
		$settings = $this->settings();
		if ( $id === sanitize_key( (string) $settings['registration_programme_id'] ) ) {
			update_option( self::OPTION_SETTINGS, array( 'registration_programme_id' => '' ), false );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirmed deletion removes only applicant records belonging to this plugin-owned programme.
		$wpdb->delete( self::table(), array( 'programme_id' => $id ), array( '%s' ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'hherm_notice' => 'deleted' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function decline_applicant(): void {
		$this->require_capability();
		$programme_id = isset( $_POST['programme_id'] ) ? sanitize_key( wp_unslash( $_POST['programme_id'] ) ) : '';
		$applicant_id = isset( $_POST['applicant_id'] ) ? absint( wp_unslash( $_POST['applicant_id'] ) ) : 0;
		check_admin_referer( 'hherm_decline_support_group_applicant_' . $programme_id . '_' . $applicant_id );
		$programme = self::FUTURE_PROGRAMME_ID === $programme_id ? $this->future_programme() : $this->selected_programme( $programme_id );
		$applicant = $programme ? $this->applicant_for( $programme_id, $applicant_id ) : null;
		if ( ! $applicant ) {
			wp_die( esc_html( 'The requested support group applicant could not be found.' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Updates the review status on one exact record in the plugin-owned applicant table.
		$updated = $wpdb->update(
			self::table(),
			array( 'status' => 'declined' ),
			array( 'id' => $applicant_id, 'programme_id' => $programme_id ),
			array( '%s' ),
			array( '%d', '%s' )
		);
		$this->redirect_applicant( $programme_id, $applicant_id, false === $updated ? 'action-failed' : 'declined' );
	}

	public function delete_applicant(): void {
		$this->require_capability();
		$programme_id = isset( $_POST['programme_id'] ) ? sanitize_key( wp_unslash( $_POST['programme_id'] ) ) : '';
		$applicant_id = isset( $_POST['applicant_id'] ) ? absint( wp_unslash( $_POST['applicant_id'] ) ) : 0;
		check_admin_referer( 'hherm_delete_support_group_applicant_' . $programme_id . '_' . $applicant_id );
		$programme = self::FUTURE_PROGRAMME_ID === $programme_id ? $this->future_programme() : $this->selected_programme( $programme_id );
		$applicant = $programme ? $this->applicant_for( $programme_id, $applicant_id ) : null;
		if ( ! $applicant ) {
			wp_die( esc_html( 'The requested support group applicant could not be found.' ) );
		}
		if ( 'yes' !== ( isset( $_POST['confirm_delete'] ) ? sanitize_key( wp_unslash( $_POST['confirm_delete'] ) ) : '' ) ) {
			$this->redirect_applicant( $programme_id, $applicant_id, '' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Confirmed deletion removes one exact record from the plugin-owned applicant table.
		$deleted = $wpdb->delete( self::table(), array( 'id' => $applicant_id, 'programme_id' => $programme_id ), array( '%d', '%s' ) );
		if ( false === $deleted ) {
			$this->redirect_applicant( $programme_id, $applicant_id, 'action-failed' );
		}
		if ( 'interest' === sanitize_key( (string) ( $applicant['application_type'] ?? '' ) ) ) {
			$this->redirect_interests( 'applicant-deleted' );
		}
		$this->redirect_editor( $programme_id, 'applicant-deleted' );
	}

	public function submit_registration(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html( 'Invalid registration request.' ) );
		}
		$id = isset( $_POST['programme_id'] ) ? sanitize_key( wp_unslash( $_POST['programme_id'] ) ) : '';
		$return_url = isset( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_url'] ) ) : home_url( '/' );
		$return_url = wp_validate_redirect( $return_url, home_url( '/' ) );
		$nonce = isset( $_POST['hherm_support_group_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hherm_support_group_nonce'] ) ) : '';
		$token = isset( $_POST[ Public_Form_Token::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Public_Form_Token::FIELD ] ) ) : '';
		if ( ! Public_Form_Token::verify( 'hherm_support_group_register_' . $id, $nonce, $token ) ) {
			$this->respond_registration( $return_url, $id, 'expired' );
		}
		$programme = $this->selected_programme( $id );
		if ( ! $programme && self::FUTURE_PROGRAMME_ID === $id ) {
			$programme = $this->future_programme();
		}
		if ( ! $programme ) {
			$this->respond_registration( $return_url, $id, 'closed' );
		}
		$application_type = $this->registration_is_open( $programme ) ? 'registration' : 'interest';
		$honeypot = isset( $_POST['company_website'] ) ? sanitize_text_field( wp_unslash( $_POST['company_website'] ) ) : '';
		if ( $honeypot ) {
			$this->respond_registration( $return_url, $id, 'interest' === $application_type ? 'interest' : 'pending' );
		}
		$rate_key = 'hherm_sg_' . substr( hash( 'sha256', $this->client_address() . '|' . wp_salt( 'nonce' ) ), 0, 40 );
		$attempts = absint( get_transient( $rate_key ) );
		if ( $attempts >= self::RATE_LIMIT ) {
			$this->respond_registration( $return_url, $id, 'rate' );
		}
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$attended = isset( $_POST['attended_before'] ) ? sanitize_key( wp_unslash( $_POST['attended_before'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$first_name = substr( $first_name, 0, 100 );
		$last_name = substr( $last_name, 0, 100 );
		$phone = substr( $phone, 0, 50 );
		$reason = substr( $reason, 0, 5000 );
		if ( ! $first_name || ! $last_name || ! is_email( $email ) || ! in_array( $attended, array( 'yes', 'no' ), true ) || ( 'no' === $attended && ! $reason ) ) {
			$this->respond_registration( $return_url, $id, 'invalid' );
		}
		if ( 'yes' === $attended ) {
			$reason = '';
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks this plugin-owned applicant table for one application of this type per email and programme.
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE programme_id = %s AND email = %s AND application_type = %s LIMIT 1', $table, $id, strtolower( $email ), $application_type ) );
		if ( $existing ) {
			$this->respond_registration( $return_url, $id, 'interest' === $application_type ? 'duplicate-interest' : 'duplicate' );
		}
		$status = 'interest' === $application_type ? 'interest' : ( 'yes' === $attended ? 'confirmed' : 'pending' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Stores a public registration in this plugin-owned applicant table.
		$created_at = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$table,
			array(
				'programme_id'    => $id,
				'application_type' => $application_type,
				'first_name'      => $first_name,
				'last_name'       => $last_name,
				'email'           => strtolower( $email ),
				'phone'           => $phone,
				'attended_before' => 'yes' === $attended ? 1 : 0,
				'reason'          => $reason,
				'status'          => $status,
				'email_status'    => '',
				'created_at'      => $created_at,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$this->respond_registration( $return_url, $id, 'failed' );
		}
		$application_id = absint( $wpdb->insert_id );
		$applicant = array(
			'id'              => $application_id,
			'programme_id'    => $id,
			'application_type'=> $application_type,
			'first_name'      => $first_name,
			'last_name'       => $last_name,
			'email'           => $email,
			'phone'           => $phone,
			'attended_before' => 'yes' === $attended ? 1 : 0,
			'reason'          => $reason,
			'status'          => $status,
			'created_at'      => $created_at,
		);
		$delivery = $this->send_registration_email( $applicant, $programme, $this->applicant_url( $id, $application_id ) );
		$customer_email_status = sanitize_key( (string) ( $delivery['customer'] ?? 'failed' ) );
		$internal_email_status = sanitize_key( (string) ( $delivery['team'] ?? 'failed' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Records delivery status on the newly created plugin-owned applicant record.
		$wpdb->update( $table, array( 'email_status' => $customer_email_status, 'internal_email_status' => $internal_email_status ), array( 'id' => $application_id ), array( '%s', '%s' ), array( '%d' ) );
		$customer_delivered = in_array( $customer_email_status, array( 'sent', 'logged' ), true );
		$this->respond_registration( $return_url, $id, $customer_delivered ? $status : $status . '-email-failed' );
	}

	private function render_programme_table( string $heading, array $programmes, array $settings ): void {
		?>
		<section class="hherm-list-card hherm-support-programmes-list"><div class="hherm-card-heading"><h2><?php echo esc_html( $heading ); ?></h2><span><?php echo esc_html( sprintf( 1 === count( $programmes ) ? '%d programme' : '%d programmes', count( $programmes ) ) ); ?></span></div><table class="widefat striped"><thead><tr><th>Programme</th><th>First meeting</th><th>Registration</th><th>Applicants</th><th>Public schedule</th><th>Action</th></tr></thead><tbody>
		<?php if ( ! $programmes ) : ?><tr><td class="hherm-empty-cell" colspan="6">No upcoming support programmes have been added yet.</td></tr><?php endif; ?>
		<?php foreach ( $programmes as $programme ) : $selected = $programme['id'] === sanitize_key( (string) $settings['registration_programme_id'] ); $open = $this->registration_is_open( $programme ); ?><tr><td><strong><?php echo esc_html( $programme['name'] ); ?></strong><small>Six online meetings</small></td><td><?php echo esc_html( $this->session_admin_label( $programme['sessions'][0] ) ); ?></td><td><span class="hherm-support-group-status <?php echo esc_attr( $open ? 'is-upcoming' : 'is-past' ); ?>"><?php echo esc_html( $open ? 'Open' : 'Closed' ); ?></span></td><td><?php echo esc_html( $this->applicant_count( $programme['id'] ) ); ?></td><td><?php echo $selected ? '<strong class="hherm-selected-programme">In use</strong>' : '<span class="hherm-muted">Not selected</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches are fixed plugin markup. ?></td><td><a href="<?php echo esc_url( $this->editor_url( $programme['id'] ) ); ?>">Edit</a></td></tr><?php endforeach; ?>
		</tbody></table></section>
		<?php
	}

	private function render_applicants( array $programme ): void {
		$applicants = $this->applicants_for( $programme['id'] );
		?>
		<section class="hherm-list-card hherm-support-applicants"><div class="hherm-card-heading"><div><h2>Applicants</h2><span>Open an applicant to see their complete submission and review actions.</span></div><span><?php echo esc_html( count( $applicants ) ); ?> total</span></div><table class="widefat striped"><thead><tr><th>Name</th><th>Contact</th><th>Attended before</th><th>Application</th><th>Status</th><th>Applied</th><th>Action</th></tr></thead><tbody>
		<?php if ( ! $applicants ) : ?><tr><td class="hherm-empty-cell" colspan="7">No applications have been received for this programme.</td></tr><?php endif; ?>
		<?php foreach ( $applicants as $applicant ) : $is_interest = 'interest' === ( $applicant['application_type'] ?? '' ); $status = $this->applicant_status_display( $applicant ); ?><tr><td><a class="hherm-support-applicant-link" href="<?php echo esc_url( $this->applicant_url( $programme['id'], absint( $applicant['id'] ) ) ); ?>"><strong><?php echo esc_html( $applicant['first_name'] . ' ' . $applicant['last_name'] ); ?></strong></a></td><td><a href="mailto:<?php echo esc_attr( $applicant['email'] ); ?>"><?php echo esc_html( $applicant['email'] ); ?></a><?php if ( $applicant['phone'] ) : ?><small><?php echo esc_html( $applicant['phone'] ); ?></small><?php endif; ?></td><td><?php echo esc_html( ! empty( $applicant['attended_before'] ) ? 'Yes' : 'No' ); ?></td><td><?php echo esc_html( $is_interest ? 'Expression of interest' : 'Registration' ); ?></td><td><span class="hherm-support-group-status <?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span><small>Customer email: <?php echo esc_html( ( $applicant['email_status'] ?? '' ) ?: 'not sent' ); ?></small><small>Internal email: <?php echo esc_html( ( $applicant['internal_email_status'] ?? '' ) ?: 'not sent' ); ?></small></td><td><?php echo esc_html( mysql2date( Event_Datetime::DATETIME_FORMAT, $applicant['created_at'] ) ); ?></td><td><a class="button button-small" href="<?php echo esc_url( $this->applicant_url( $programme['id'], absint( $applicant['id'] ) ) ); ?>">View details</a></td></tr><?php endforeach; ?>
		</tbody></table></section>
		<?php
	}

	private function render_interest_applicants(): void {
		$applicants = $this->interest_applicants();
		$programme_names = array();
		foreach ( $this->programmes() as $programme ) {
			$programme_names[ $programme['id'] ] = $programme['name'];
		}
		?>
		<section class="hherm-list-card hherm-support-applicants hherm-support-interest-list"><div class="hherm-card-heading"><div><h2>Expressions of interest</h2><span>People waiting to hear about a future online support group, including submissions made before a future programme has been created.</span></div><span><?php echo esc_html( count( $applicants ) ); ?> total</span></div><table class="widefat striped"><thead><tr><th>Name</th><th>Contact</th><th>Interest recorded against</th><th>Reason</th><th>Email delivery</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
		<?php if ( ! $applicants ) : ?><tr><td class="hherm-empty-cell" colspan="7">No support-group expressions of interest have been received yet.</td></tr><?php endif; ?>
		<?php foreach ( $applicants as $applicant ) : $programme_id = sanitize_key( (string) $applicant['programme_id'] ); $programme_name = self::FUTURE_PROGRAMME_ID === $programme_id ? 'No programme scheduled yet' : ( $programme_names[ $programme_id ] ?? 'Previous programme' ); $reason = trim( (string) ( $applicant['reason'] ?? '' ) ); ?><tr><td><a class="hherm-support-applicant-link" href="<?php echo esc_url( $this->applicant_url( $programme_id, absint( $applicant['id'] ) ) ); ?>"><strong><?php echo esc_html( trim( $applicant['first_name'] . ' ' . $applicant['last_name'] ) ); ?></strong></a></td><td><a href="mailto:<?php echo esc_attr( $applicant['email'] ); ?>"><?php echo esc_html( $applicant['email'] ); ?></a><?php if ( $applicant['phone'] ) : ?><small><?php echo esc_html( $applicant['phone'] ); ?></small><?php endif; ?></td><td><?php echo esc_html( $programme_name ); ?></td><td><?php echo esc_html( $reason ? ( strlen( $reason ) > 120 ? substr( $reason, 0, 117 ) . '…' : $reason ) : 'Not provided' ); ?></td><td><small>Customer: <?php echo esc_html( ( $applicant['email_status'] ?? '' ) ?: 'not sent' ); ?></small><small>Internal: <?php echo esc_html( ( $applicant['internal_email_status'] ?? '' ) ?: 'not sent' ); ?></small></td><td><?php echo esc_html( mysql2date( Event_Datetime::DATETIME_FORMAT, $applicant['created_at'] ) ); ?></td><td><a class="button button-small" href="<?php echo esc_url( $this->applicant_url( $programme_id, absint( $applicant['id'] ) ) ); ?>">View details</a></td></tr><?php endforeach; ?>
		</tbody></table></section>
		<?php
	}

	private function render_applicant_detail( array $programme, array $applicant, string $notice ): void {
		$applicant_id = absint( $applicant['id'] );
		$name         = trim( (string) $applicant['first_name'] . ' ' . (string) $applicant['last_name'] );
		$is_interest  = 'interest' === ( $applicant['application_type'] ?? '' );
		$status       = $this->applicant_status_display( $applicant );
		$applied      = mysql2date( Event_Datetime::DATETIME_FORMAT, $applicant['created_at'] );
		$back_url     = $is_interest ? $this->page_url( 'interests' ) : $this->editor_url( $programme['id'] );
		$back_label   = $is_interest ? 'Back to expressions of interest' : 'Back to programme';
		?>
		<div class="wrap hherm-support-groups-wrap">
			<header class="hherm-page-header"><div><span class="hherm-eyebrow">Support group applicant</span><h1><?php echo esc_html( $name ); ?></h1><p>Review the complete information submitted with this application.</p></div><div class="hherm-page-actions"><a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( $back_label ); ?></a></div></header><hr class="wp-header-end">
			<?php if ( 'declined' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>This applicant has been declined.</p></div><?php endif; ?>
			<?php if ( 'action-failed' === $notice ) : ?><div class="notice notice-error"><p>The applicant could not be updated. Please try again.</p></div><?php endif; ?>
			<section class="hherm-form-card hherm-support-applicant-detail">
				<div class="hherm-form-card__heading"><div><h2>Application details</h2><p><?php echo esc_html( $programme['name'] ); ?></p></div><span class="hherm-support-group-status <?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span></div>
				<dl class="hherm-support-applicant-fields">
					<div><dt>Email address</dt><dd><a href="mailto:<?php echo esc_attr( $applicant['email'] ); ?>"><?php echo esc_html( $applicant['email'] ); ?></a></dd></div>
					<div><dt>Phone number</dt><dd><?php echo esc_html( $applicant['phone'] ?: 'Not provided' ); ?></dd></div>
					<div><dt>Attended an online support group before?</dt><dd><?php echo esc_html( ! empty( $applicant['attended_before'] ) ? 'Yes' : 'No' ); ?></dd></div>
					<div><dt>Application type</dt><dd><?php echo esc_html( $is_interest ? 'Expression of interest' : 'Registration' ); ?></dd></div>
					<div><dt>Applied</dt><dd><?php echo esc_html( $applied ); ?></dd></div>
					<div><dt>Applicant email</dt><dd><?php echo esc_html( $applicant['email_status'] ? ucfirst( (string) $applicant['email_status'] ) : 'Not sent' ); ?></dd></div>
					<div><dt>Internal notification</dt><dd><?php echo esc_html( ! empty( $applicant['internal_email_status'] ) ? ucfirst( (string) $applicant['internal_email_status'] ) : 'Not sent' ); ?></dd></div>
				</dl>
				<section class="hherm-support-applicant-explanation"><h3>Why they would like to join</h3><?php if ( $applicant['reason'] ) : ?><div><?php echo nl2br( esc_html( $applicant['reason'] ) ); ?></div><?php elseif ( ! empty( $applicant['attended_before'] ) ) : ?><p class="hherm-muted">Not required because the applicant has attended an online support group before.</p><?php else : ?><p class="hherm-muted">No explanation was provided.</p><?php endif; ?></section>
			</section>
			<section class="hherm-form-card hherm-support-applicant-actions">
				<?php if ( 'declined' === ( $applicant['status'] ?? '' ) ) : ?><div><h2>Application declined</h2><p>This applicant remains in the list for reference until it is deleted.</p></div><?php else : ?><div><h2>Decline application</h2><p>Mark this application as declined. The applicant record will remain available.</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hherm_decline_support_group_applicant"><input type="hidden" name="programme_id" value="<?php echo esc_attr( $programme['id'] ); ?>"><input type="hidden" name="applicant_id" value="<?php echo esc_attr( $applicant_id ); ?>"><?php wp_nonce_field( 'hherm_decline_support_group_applicant_' . $programme['id'] . '_' . $applicant_id ); ?><button class="button hherm-decline-button" type="submit">Decline applicant</button></form><?php endif; ?>
			</section>
			<section class="hherm-form-card hherm-danger-zone"><div><h2>Delete applicant</h2><p>Deletion opens a separate confirmation screen and permanently removes only this applicant.</p></div><a class="button hherm-danger-button" href="<?php echo esc_url( $this->applicant_url( $programme['id'], $applicant_id, true ) ); ?>">Delete applicant</a></section>
		</div>
		<?php
	}

	private function render_applicant_delete_confirmation( array $programme, array $applicant ): void {
		$applicant_id = absint( $applicant['id'] );
		$name         = trim( (string) $applicant['first_name'] . ' ' . (string) $applicant['last_name'] );
		?>
		<div class="wrap hherm-support-groups-wrap"><header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Confirm applicant deletion</h1></div><div class="hherm-page-actions"><a class="button" href="<?php echo esc_url( $this->applicant_url( $programme['id'], $applicant_id ) ); ?>">Back to applicant details</a></div></header><hr class="wp-header-end"><section class="hherm-form-card hherm-delete-confirmation"><h2>Delete <?php echo esc_html( $name ); ?>?</h2><p>This permanently deletes this applicant’s submission for <?php echo esc_html( $programme['name'] ); ?>. It does not delete the support programme or any other applicants.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hherm_delete_support_group_applicant"><input type="hidden" name="programme_id" value="<?php echo esc_attr( $programme['id'] ); ?>"><input type="hidden" name="applicant_id" value="<?php echo esc_attr( $applicant_id ); ?>"><?php wp_nonce_field( 'hherm_delete_support_group_applicant_' . $programme['id'] . '_' . $applicant_id ); ?><label class="hherm-confirm-delete"><input type="checkbox" name="confirm_delete" value="yes" required> I understand that this applicant and their submitted information will be permanently deleted.</label><div class="hherm-support-groups-form-actions"><button class="button hherm-danger-button" type="submit">Permanently delete applicant</button><a class="button" href="<?php echo esc_url( $this->applicant_url( $programme['id'], $applicant_id ) ); ?>">Cancel</a></div></form></section></div>
		<?php
	}

	private function render_delete_confirmation( array $programme ): void {
		$count = $this->applicant_count( $programme['id'] );
		?>
		<div class="wrap hherm-support-groups-wrap"><header class="hherm-page-header"><div><span class="hherm-eyebrow">Heart Hub Events</span><h1>Confirm programme deletion</h1></div><div class="hherm-page-actions"><a class="button" href="<?php echo esc_url( $this->editor_url( $programme['id'] ) ); ?>">Back to programme</a></div></header><hr class="wp-header-end"><section class="hherm-form-card hherm-delete-confirmation"><h2>Delete “<?php echo esc_html( $programme['name'] ); ?>”?</h2><p>This permanently deletes the programme, all six meeting dates, and <?php echo esc_html( $count ); ?> applicant record<?php echo esc_html( 1 === $count ? '' : 's' ); ?>. This cannot be undone.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="hherm_delete_support_group_programme"><input type="hidden" name="programme_id" value="<?php echo esc_attr( $programme['id'] ); ?>"><?php wp_nonce_field( 'hherm_delete_support_group_programme_' . $programme['id'] ); ?><label class="hherm-confirm-delete"><input type="checkbox" name="confirm_delete" value="yes" required> I understand that this programme and its applicant records will be permanently deleted.</label><div class="hherm-support-groups-form-actions"><button class="button hherm-danger-button" type="submit">Permanently delete programme</button><a class="button" href="<?php echo esc_url( $this->editor_url( $programme['id'] ) ); ?>">Cancel</a></div></form></section></div>
		<?php
	}

	private function applicants_for( string $programme_id ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin reads the plugin-owned applicant records for one programme.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE programme_id = %s ORDER BY created_at DESC, id DESC LIMIT 500', $table, sanitize_key( $programme_id ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function interest_applicants(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin reads the plugin-owned support interest pool.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE application_type = %s OR status = %s ORDER BY created_at DESC, id DESC LIMIT 1000', $table, 'interest', 'interest' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function interest_count(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts the plugin-owned support interest pool for its admin tab.
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE application_type = %s OR status = %s', $table, 'interest', 'interest' ) ) );
	}

	private function applicant_for( string $programme_id, int $applicant_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin reads one exact record from the plugin-owned applicant table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND programme_id = %s LIMIT 1', $table, absint( $applicant_id ), sanitize_key( $programme_id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private function applicant_status_display( array $applicant ): array {
		$status = sanitize_key( (string) ( $applicant['status'] ?? '' ) );
		if ( 'declined' === $status ) {
			return array( 'class' => 'is-declined', 'label' => 'Declined' );
		}
		if ( 'interest' === ( $applicant['application_type'] ?? '' ) || 'interest' === $status ) {
			return array( 'class' => 'is-interest', 'label' => 'Future programme' );
		}
		if ( 'confirmed' === $status ) {
			return array( 'class' => 'is-upcoming', 'label' => 'Confirmed' );
		}
		return array( 'class' => 'is-review', 'label' => 'Needs review' );
	}

	private function applicant_count( string $programme_id ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts plugin-owned applicant records for the admin programme list.
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE programme_id = %s', $table, sanitize_key( $programme_id ) ) ) );
	}

	private function send_registration_email( array $applicant, array $programme, string $record_url ): array {
		return $this->email ? $this->email->send_support_group_submission( $applicant, $programme, $record_url ) : array( 'customer' => 'failed', 'team' => 'failed' );
	}

	private function respond_registration( string $return_url, string $programme_id, string $result ): void {
		$messages = array(
			'confirmed' => 'Your registration is confirmed. Please check your email. Someone will contact you closer to the first meeting with information about how to connect.',
			'pending'   => 'Thank you for registering. Please check your email. Someone will be in contact to confirm your registration.',
			'interest'  => 'Thank you for registering your expression of interest for the next support programme. Please check your email. Someone will be in contact when the next programme is available.',
			'confirmed-email-failed' => 'Your registration is confirmed, but we could not send the confirmation email. Someone will contact you closer to the first meeting.',
			'pending-email-failed'   => 'Thank you for registering. We could not send the acknowledgement email, but your application was saved and someone will be in contact.',
			'interest-email-failed'  => 'Thank you for registering your expression of interest. We could not send the acknowledgement email, but your application was saved and someone will be in contact when the next programme is available.',
			'invalid'            => 'Please complete all required fields and try again.',
			'expired'            => 'This form has expired. Please refresh the page and submit your details again.',
			'duplicate'          => 'This email address is already registered for the selected support programme.',
			'duplicate-interest' => 'This email address has already submitted an expression of interest for the next support programme.',
			'rate'               => 'Too many submission attempts were received. Please wait and try again later.',
			'closed'             => 'This support programme is no longer available.',
			'failed'             => 'We could not save your application. Please try again or contact us.',
		);
		$is_ajax = '1' === ( isset( $_POST['hherm_ajax'] ) ? sanitize_key( wp_unslash( $_POST['hherm_ajax'] ) ) : '' );
		if ( $is_ajax ) {
			$data = array( 'result' => $result, 'message' => $messages[ $result ] ?? $messages['failed'] );
			if ( in_array( $result, array( 'confirmed', 'pending', 'interest', 'confirmed-email-failed', 'pending-email-failed', 'interest-email-failed' ), true ) ) {
				wp_send_json_success( $data );
			}
			wp_send_json_error( $data, 400 );
		}
		$url = remove_query_arg( array( 'hherm_support_group_result', 'hherm_support_group_programme' ), $return_url );
		$url = add_query_arg( array( 'hherm_support_group_result' => sanitize_key( $result ), 'hherm_support_group_programme' => sanitize_key( $programme_id ) ), $url );
		wp_safe_redirect( $url );
		exit;
	}

	private function normalise_session( $session ) {
		if ( ! is_array( $session ) ) {
			return null;
		}
		$date = $this->normalise_date( $session['date'] ?? '' );
		$start = $this->normalise_time( $session['start_time'] ?? '' );
		$end_raw = trim( (string) ( $session['end_time'] ?? '' ) );
		$end = '' === $end_raw ? '' : $this->normalise_time( $end_raw );
		if ( ! $date || ! $start || ( '' !== $end_raw && ! $end ) ) {
			return null;
		}
		return array( 'date' => $date, 'start_time' => $start, 'end_time' => $end );
	}

	private function prevent_stale_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	private function compare_sessions( array $left, array $right ): int {
		return strcmp( $left['date'] . ' ' . $left['start_time'], $right['date'] . ' ' . $right['start_time'] );
	}

	private function session_admin_label( array $session ): string {
		$date_time = $this->date_time( $session['date'], $session['start_time'] );
		return $date_time ? wp_date( Event_Datetime::DATETIME_FORMAT, $date_time->getTimestamp(), wp_timezone() ) : '';
	}

	private function date_time( string $date, string $time ) {
		try {
			return new \DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
		} catch ( \Exception $error ) {
			return null;
		}
	}

	private function normalise_date( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return Event_Datetime::date_storage( $value );
	}

	private function normalise_time( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	private function client_address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $address, FILTER_VALIDATE_IP ) ? $address : 'unknown';
	}

	private function require_capability(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html( 'You do not have permission to manage support groups.' ) );
		}
	}

	private function authorise( string $nonce_action ): void {
		$this->require_capability();
		check_admin_referer( $nonce_action );
	}

	private function page_url( string $view = '' ): string {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( 'interests' === $view ) {
			$args['view'] = 'interests';
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function editor_url( string $programme_id = '' ): string {
		$args = array( 'page' => self::EDIT_PAGE_SLUG );
		if ( $programme_id ) {
			$args['programme_id'] = sanitize_key( $programme_id );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function applicant_url( string $programme_id, int $applicant_id, bool $confirm_delete = false ): string {
		$args = array(
			'page'         => self::EDIT_PAGE_SLUG,
			'programme_id' => sanitize_key( $programme_id ),
			'applicant_id' => absint( $applicant_id ),
		);
		if ( $confirm_delete ) {
			$args['confirm_applicant_delete'] = 1;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function redirect_editor( string $programme_id, string $notice ): void {
		$args = array( 'page' => self::EDIT_PAGE_SLUG );
		if ( $programme_id ) {
			$args['programme_id'] = sanitize_key( $programme_id );
		}
		if ( $notice ) {
			$args['hherm_notice'] = sanitize_key( $notice );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function redirect_applicant( string $programme_id, int $applicant_id, string $notice ): void {
		$url = $this->applicant_url( $programme_id, $applicant_id );
		if ( $notice ) {
			$url = add_query_arg( 'hherm_notice', sanitize_key( $notice ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function redirect_interests( string $notice = '' ): void {
		$url = $this->page_url( 'interests' );
		if ( $notice ) {
			$url = add_query_arg( 'hherm_notice', sanitize_key( $notice ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
