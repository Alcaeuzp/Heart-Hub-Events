<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';

final class Checkin_Manager {
	public const PAGE_SLUG = 'hherm-feedback';
	public const CHECKIN_PAGE_SLUG = 'hherm-checkin-page';
	private const CREATE_ACTION = 'hherm_create_checkin';
	private const REVOKE_ACTION = 'hherm_revoke_checkin';
	private const PREVIEW_ACTION = 'hherm_preview_checkin';
	private const OPTION_PREFIX = 'hherm_checkin_event_';
	private const RATE_LIMIT = 10;
	private const IP_RATE_LIMIT = 60;
	private const RATE_WINDOW = 600;

	private $settings;
	private $repository;
	private $audit;
	private $capacity;
	private $theme;
	private $verified_item = array();

	public function __construct( Settings $settings, CCT_Repository $repository, Audit_Log $audit, ?Capacity_Manager $capacity = null, ?Public_Page_Theme $theme = null ) {
		$this->settings = $settings;
		$this->repository = $repository;
		$this->audit = $audit;
		$this->capacity = $capacity;
		$this->theme = $theme ?: new Public_Page_Theme( $settings );
	}

	public function register(): void {
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'create' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( $this, 'revoke' ) );
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'render_admin_preview' ) );
		add_action( 'template_redirect', array( $this, 'maybe_download_calendar' ), -1 );
		add_action( 'template_redirect', array( $this, 'maybe_render_public' ), 0 );
	}

	public function create(): void {
		$this->require_access();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		check_admin_referer( self::CREATE_ACTION . '_' . $event_id, 'hherm_checkin_nonce' );
		if ( ! $this->valid_event( $event_id ) ) {
			$this->redirect( $event_id, 'The selected event could not be found.' );
		}
		$window = $this->event_day_window( $event_id );
		if ( ! $window || time() >= $window['expires_at'] ) {
			$this->redirect( $event_id, 'The event must have a valid future or current start and end time before a check-in link can be generated.' );
		}
		$token = bin2hex( random_bytes( 24 ) );
		$record = array(
			'token'      => $token,
			'created_at' => time(),
			'starts_at'  => $window['starts_at'],
			'expires_at' => $window['expires_at'],
			'created_by' => get_current_user_id(),
		);
		update_option( self::OPTION_PREFIX . $event_id, $record, false );
		$this->audit->write( 0, 'checkin_link', 'created', array( 'event_id' => $event_id, 'expires_at' => $record['expires_at'] ) );
		$this->redirect( $event_id, '', 'created' );
	}

	public function revoke(): void {
		$this->require_access();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		check_admin_referer( self::REVOKE_ACTION . '_' . $event_id, 'hherm_checkin_nonce' );
		delete_option( self::OPTION_PREFIX . $event_id );
		$this->audit->write( 0, 'checkin_link', 'revoked', array( 'event_id' => $event_id ) );
		$window = $this->event_day_window( $event_id );
		if ( $window && time() < $window['expires_at'] ) {
			$record = array( 'token' => bin2hex( random_bytes( 24 ) ), 'created_at' => time(), 'starts_at' => $window['starts_at'], 'expires_at' => $window['expires_at'], 'created_by' => get_current_user_id() );
			update_option( self::OPTION_PREFIX . $event_id, $record, false );
			$this->audit->write( 0, 'checkin_link', 'created', array( 'event_id' => $event_id, 'expires_at' => $record['expires_at'], 'regenerated' => true ) );
			$this->redirect( $event_id, '', 'regenerated' );
		}
		$this->redirect( $event_id, '', 'revoked' );
	}

	public function preview_url( int $event_id ): string {
		$record = $this->active_record( $event_id );
		return $record ? $this->public_url( $record['token'] ) : '';
	}

	public function admin_preview_url( int $event_id ): string {
		if ( ! $this->valid_event( $event_id ) ) return '';
		return wp_nonce_url(
			add_query_arg( array( 'action' => self::PREVIEW_ACTION, 'event_id' => $event_id ), admin_url( 'admin-post.php' ) ),
			self::PREVIEW_ACTION . '_' . $event_id
		);
	}

	public function render_admin_preview(): void {
		$this->require_access();
		$event_id = isset( $_REQUEST['event_id'] ) ? absint( wp_unslash( $_REQUEST['event_id'] ) ) : 0;
		check_admin_referer( self::PREVIEW_ACTION . '_' . $event_id );
		if ( ! $this->valid_event( $event_id ) ) {
			wp_die( esc_html__( 'The selected event could not be previewed.', 'heart-hub-event-registration-manager' ), esc_html__( 'Preview unavailable', 'heart-hub-event-registration-manager' ), array( 'response' => 404 ) );
		}
		$window = $this->event_day_window( $event_id );
		$name = $this->event_label( $event_id );
		$venue = (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true );
		$this->theme->security_headers();
		$this->render_public_page_markup( $event_id, 'preview', '', false, $name, $venue, absint( $window['expires_at'] ?? 0 ), true );
		exit;
	}

	public function preview_expiry( int $event_id ): int {
		$record = $this->active_record( $event_id );
		return $record ? absint( $record['expires_at'] ) : 0;
	}

	public function render_event_panel( int $event_id ): void {
		$record = $this->active_record( $event_id );
		?>
		<section class="hherm-checkin-card">
			<div class="hherm-checkin-copy"><span class="hherm-eyebrow">Self check-in</span><h2>Check-in QR code</h2><p>Print it or show it on a phone at the door. Scanning opens a temporary check-in page for this event only, from the event start until one hour after the end time.</p>
			<?php if ( $record ) : $url = $this->public_url( $record['token'] ); ?>
				<p><strong>Active:</strong> <?php echo esc_html( wp_date( 'j M Y, g:i a', (int) $record['starts_at'] ) ); ?> – <?php echo esc_html( wp_date( 'j M Y, g:i a', (int) $record['expires_at'] - 1 ) ); ?></p>
				<div class="hherm-checkin-url"><input type="text" readonly value="<?php echo esc_attr( $url ); ?>" aria-label="Temporary check-in URL"><button type="button" class="button" data-copy-checkin>Copy link</button><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $url ); ?>">Open page</a></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><?php wp_nonce_field( self::REVOKE_ACTION . '_' . $event_id, 'hherm_checkin_nonce' ); ?><button class="button hherm-revoke-button" data-hherm-confirm="Replace the check-in link? Any QR codes already printed or shared will stop working.">Revoke &amp; regenerate</button></form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_ACTION ); ?>"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>"><?php wp_nonce_field( self::CREATE_ACTION . '_' . $event_id, 'hherm_checkin_nonce' ); ?><button class="button button-primary">Generate event-day check-in link</button></form>
			<?php endif; ?></div>
			<?php if ( $record ) : ?><div class="hherm-checkin-qr" data-checkin-qr data-url="<?php echo esc_attr( $this->public_url( $record['token'] ) ); ?>" role="img" aria-label="QR code for attendee check-in"></div><?php endif; ?>
		</section>
		<?php
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The expiring bearer token is verified by find_record(); POST mutations verify a separate nonce in process_public_submission().
	public function maybe_render_public(): void {
		if ( ! isset( $_GET['hherm_checkin'] ) ) return;
		$token = sanitize_text_field( wp_unslash( $_GET['hherm_checkin'] ) );
		$match = $this->find_record( $token );
		if ( ! $match ) {
			$this->render_public_page( 0, '', 'This check-in link is invalid or has expired.', false );
		}
		$event_id = $match['event_id'];
		$message = '';
		$success = false;
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === strtoupper( $method ) ) {
			$message = $this->process_public_submission( $event_id, $token );
			$success = 'Your attendance has been recorded. Thank you.' === $message;
		}
		$this->render_public_page( $event_id, $token, $message, $success, absint( $match['expires_at'] ?? 0 ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only calendar download authenticated by the expiring bearer token in find_record().
	public function maybe_download_calendar(): void {
		if ( ! isset( $_GET['hherm_checkin_calendar'], $_GET['hherm_checkin'] ) ) return;
		$token = sanitize_text_field( wp_unslash( $_GET['hherm_checkin'] ) );
		$match = $this->find_record( $token );
		if ( ! $match ) {
			$this->theme->security_headers( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo esc_html__( 'This calendar link is invalid or has expired.', 'heart-hub-event-registration-manager' );
			exit;
		}
		$event_id = absint( $match['event_id'] );
		$start_raw = (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true );
		$end_raw = (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true );
		$start = $this->parse_event_datetime( $start_raw );
		$end = $this->parse_event_datetime( $end_raw );
		if ( ! $start || ! $end ) {
			$this->theme->security_headers( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo esc_html__( 'This event does not have valid calendar dates.', 'heart-hub-event-registration-manager' );
			exit;
		}
		$title = $this->event_label( $event_id );
		$venue = (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true );
		$description = (string) get_post_meta( $event_id, $this->settings->get( 'event_info', '_description' ), true );
		if ( ! $description ) {
			$post = get_post( $event_id );
			$description = $post ? (string) $post->post_content : '';
		}
		$host = sanitize_key( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ?: 'wordpress';
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Heart Hub//Event Registration Manager//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:hherm-' . $event_id . '-' . substr( hash( 'sha256', $token ), 0, 12 ) . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\\THis\\Z' ),
			'DTSTART:' . gmdate( 'Ymd\\THis\\Z', $start->getTimestamp() ),
			'DTEND:' . gmdate( 'Ymd\\THis\\Z', $end->getTimestamp() ),
			'SUMMARY:' . $this->ics_escape( $title ),
			'LOCATION:' . $this->ics_escape( $venue ),
			'DESCRIPTION:' . $this->ics_escape( wp_strip_all_tags( $description ) ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		$this->theme->security_headers();
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $title ?: 'event' ) . '.ics"' );
		$calendar = implode( "\r\n", array_map( array( $this, 'ics_fold' ), $lines ) ) . "\r\n";
		echo $calendar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped and folded according to RFC 5545 above.
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function process_public_submission( int $event_id, string $token ): string {
		if ( ! isset( $_POST['hherm_public_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hherm_public_nonce'] ) ), 'hherm_public_checkin_' . hash( 'sha256', $token ) ) ) return 'We could not complete check-in. Please refresh and try again.';
		if ( ! empty( $_POST['company_website'] ) ) return 'We could not complete check-in. Please ask the event organiser for help.';
		$email = isset( $_POST['identity'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['identity'] ) ) ) : '';
		$ip_rate_key = 'hherm_ci_ip_' . substr( hash( 'sha256', $token . '|' . $this->client_address() ), 0, 40 );
		$ip_attempts = absint( get_transient( $ip_rate_key ) );
		if ( $ip_attempts >= self::IP_RATE_LIMIT ) return 'Too many attempts. Please wait ten minutes and try again.';
		$rate_key = 'hherm_ci_' . substr( hash( 'sha256', $token . '|' . $this->client_address() . '|' . $email ), 0, 40 );
		$attempts = absint( get_transient( $rate_key ) );
		if ( $attempts >= self::RATE_LIMIT ) return 'Too many attempts. Please wait ten minutes and try again.';
		set_transient( $rate_key, $attempts + 1, self::RATE_WINDOW );
		$feedback = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
		if ( strlen( $feedback ) > 5000 ) $feedback = substr( $feedback, 0, 5000 );
		$score = isset( $_POST['feedback_score'] ) ? min( 5, max( 0, absint( wp_unslash( $_POST['feedback_score'] ) ) ) ) : 0;
		if ( 'true' !== $this->settings->get( 'checkin_feedback_enabled', 'false' ) ) { $feedback = ''; $score = 0; }
		if ( ! is_email( $email ) ) {
			set_transient( $ip_rate_key, $ip_attempts + 1, self::RATE_WINDOW );
			return 'Sorry, we can\'t find you on the attendance register. Please check the email you registered with, or talk to a staff member.';
		}
		$item = $this->repository->find_approved_by_email( $event_id, $email );
		if ( is_wp_error( $item ) ) {
			set_transient( $ip_rate_key, $ip_attempts + 1, self::RATE_WINDOW );
			return 'Sorry, we can\'t find you on the attendance register. Please check the email you registered with or talk to a staff member.';
		}
		$id = absint( $item['_ID'] ?? 0 );
		$lock_name = 'hherm_review_lock_' . $id;
		$lock_token = Mutation_Lock::acquire( $lock_name );
		if ( is_wp_error( $lock_token ) ) return 'This registration is being updated. Please try again.';
		try {
			$operation = function () use ( $id, $event_id, $email, $feedback, $score, $rate_key, $lock_token ) { return $this->complete_checkin( $id, $event_id, $email, $feedback, $score, $rate_key, $lock_token ); };
			$result = $this->capacity ? $this->capacity->with_event_lock( $event_id, $operation ) : $operation();
			return is_wp_error( $result ) ? 'This event is being updated. Please try again.' : $result;
		} finally {
			Mutation_Lock::release( $lock_name, $lock_token );
		}
	}

	private function complete_checkin( int $id, int $event_id, string $email, string $feedback, int $score, string $rate_key, string $lock_token ): string {
		$item = $this->repository->get( $id );
		$lock_lost = 'This registration changed while your check-in was being processed. Please ask the event organiser to check your attendance.';
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $lock_lost;
		if ( is_wp_error( $item ) || absint( $item['event_id'] ?? 0 ) !== $event_id || 'approved' !== sanitize_key( $item['registration_status'] ?? '' ) || strtolower( sanitize_email( $item['email'] ?? '' ) ) !== $email ) return 'This registration changed. Please ask the event organiser for help.';
		$attendee_key = $this->capacity ? ( $this->capacity->attendee_key() ?: 'number_of_attendees' ) : 'number_of_attendees';
		$reserved = max( 1, absint( $item[ $attendee_key ] ?? 1 ) );
		if ( ! empty( $item['checked_in_at'] ) ) {
			$recorded_party = $item['checked_in_party_size'] ?? '';
			if ( ! is_scalar( $recorded_party ) || ! preg_match( '/^[1-9]\d*$/', (string) $recorded_party ) || (int) $recorded_party > $reserved ) return 'Your saved check-in is incomplete. Please ask the event organiser to check your attendance record.';
			$attending = (int) $recorded_party;
			if ( $attending > 0 && $attending < $reserved && ! $this->reconcile_checkin_capacity( $id, $event_id, $reserved - $attending ) ) return 'Your check-in was saved, but the unused places could not be returned. Please try again or ask the event organiser for help.';
			delete_transient( $rate_key );
			return 'Your attendance has been recorded. Thank you.';
		}
		$this->verified_item = $item;
		$step = isset( $_POST['checkin_step'] ) ? sanitize_text_field( wp_unslash( $_POST['checkin_step'] ) ) : '';
		if ( 'confirm' !== $step ) return '';
		if ( isset( $_POST['party_size'] ) && ! is_scalar( $_POST['party_size'] ) ) return 'Please choose how many of your reserved guests are attending.';
		$raw_party = isset( $_POST['party_size'] ) ? sanitize_text_field( wp_unslash( $_POST['party_size'] ) ) : '';
		if ( ! is_scalar( $raw_party ) || ! preg_match( '/^[1-9]\d*$/', (string) $raw_party ) || (int) $raw_party > $reserved ) return 'Please choose how many of your reserved guests are attending.';
		$party_size = (int) $raw_party;
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $lock_lost;
		$result = $this->repository->update_self_checkin( $id, $feedback, $party_size, $party_size < $reserved ? 'partial' : 'attended', $score, static function () use ( $id, $lock_token ) { return Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ); } );
		if ( is_wp_error( $result ) ) return 'We could not save your check-in. Please talk to a staff member.';
		if ( ! Mutation_Lock::owns( 'hherm_review_lock_' . $id, $lock_token ) ) return $lock_lost;
		$capacity_saved = $party_size >= $reserved || $this->reconcile_checkin_capacity( $id, $event_id, $reserved - $party_size );
		delete_transient( $rate_key );
		$this->audit->write( $id, 'self_checkin', $party_size < $reserved ? 'partial' : 'attended', array( 'event_id' => $event_id, 'feedback_provided' => '' !== $feedback, 'party_size' => $party_size, 'reserved' => $reserved ) );
		if ( ! $capacity_saved ) return 'Your check-in was saved, but the unused places could not be returned. Please try again or ask the event organiser for help.';
		return 'Your attendance has been recorded. Thank you.';
	}

	private function reconcile_checkin_capacity( int $id, int $event_id, int $unused ): bool {
		if ( ! $this->capacity ) return true;
		$result = $this->capacity->release_unused( $id, $event_id, $unused );
		if ( ! is_wp_error( $result ) ) return true;
		$this->audit->write( $id, 'capacity_error', 'checkin_release_failed', array( 'event_id' => $event_id, 'unused' => $unused ) );
		return false;
	}

	private function render_public_page( int $event_id, string $token, string $message, bool $success, int $expires_at = 0 ): void {
		$this->theme->security_headers( $event_id ? 200 : 404 );
		$name = $event_id ? $this->event_label( $event_id ) : 'Event check-in';
		$venue = $event_id ? (string) get_post_meta( $event_id, $this->settings->get( 'event_venue', 'venue' ), true ) : '';
		?><?php $this->render_public_page_markup( $event_id, $token, $message, $success, $name, $venue, $expires_at ); ?><?php exit;
	}

	private function render_public_page_markup( int $event_id, string $token, string $message, bool $success, string $name, string $venue, int $expires_at = 0, bool $preview = false ): void {
		$start_raw = $event_id ? (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ) : '';
		$end_raw = $event_id ? (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true ) : '';
		$start = $this->parse_event_datetime( $start_raw );
		$end = $this->parse_event_datetime( $end_raw );
		if ( ! $expires_at ) $expires_at = $end ? $end->modify( '+1 hour' )->getTimestamp() : 0;
		$parking = $event_id ? (string) get_post_meta( $event_id, 'parking_access', true ) : '';
		$expectations = $event_id ? get_post_meta( $event_id, 'what_to_expect', true ) : array();
		if ( $event_id && ! Event_Public_Display::normalise_flag( get_post_meta( $event_id, 'show_what_to_expect', true ), true ) ) $expectations = array();
		if ( is_string( $expectations ) ) { $expectations = json_decode( $expectations, true ); }
		$expectations = is_array( $expectations ) ? array_slice( $expectations, 0, 6 ) : array();
		$contact = sanitize_email( $this->settings->get( 'contact_email', get_option( 'admin_email' ) ) ) ?: 'the event organiser';
		$event_line = $start ? wp_date( 'g:i a', $start->getTimestamp(), wp_timezone() ) . ( $venue ? ' at ' . $venue : '' ) : $venue;
		$label = $this->theme->copy( 'checkin_label' );
		$expiry_html = $expires_at ? '<span class="hherm-public-expiry">Open until ' . esc_html( wp_date( 'g:i a', $expires_at, wp_timezone() ) ) . '</span>' : '';
		$calendar_url = $token && 'preview' !== $token ? add_query_arg( array( 'hherm_checkin' => rawurlencode( $token ), 'hherm_checkin_calendar' => 1 ), home_url( '/' ) ) : '';
		?>
		<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( ( $label ?: 'Event check-in' ) . ' — ' . $name ); ?></title><?php wp_enqueue_style( 'hherm-public-checkin', HHERM_URL . 'assets/checkin.css', array(), HHERM_VERSION ); wp_print_styles( 'hherm-public-checkin' ); ?><?php echo $this->theme->style_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></head><body class="hherm-public-checkin">
		<?php echo $this->theme->header( $label, 'hherm-public-header', $expiry_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<main class="hherm-public-main">
		<?php if ( $preview ) : ?><div class="hherm-public-preview-note" role="status">Preview only — attendee submissions are not saved here.</div><?php endif; ?>
		<?php if ( ! $event_id ) : ?><section class="hherm-public-error"><span class="hherm-public-eyebrow">Check-in closed</span><h1>This check-in link has closed</h1><p>This link is invalid or has expired. Please visit the events page for upcoming opportunities.</p><a class="hherm-public-button" href="<?php echo esc_url( $this->settings->get( 'events_url', home_url( '/events/' ) ) ); ?>">View upcoming events</a></section>
		<?php else : ?><div class="hherm-public-eyebrow"><?php echo esc_html( $start ? wp_date( 'l, j F Y', $start->getTimestamp(), wp_timezone() ) : 'Event day' ); ?></div><h1><?php echo esc_html( $name ); ?></h1><p class="hherm-public-intro"><?php if ( $event_line ) : ?><strong><?php echo esc_html( $event_line ); ?>.</strong> <?php endif; ?><?php echo nl2br( esc_html( $this->theme->copy( 'checkin_intro', array( '{event_name}' => $name, '{contact_email}' => $contact ) ) ) ); ?></p>
		<?php if ( $success ) : ?><section class="hherm-public-success" role="status"><div class="hherm-public-tick">✓</div><h2><?php echo esc_html( $this->theme->copy( 'checkin_success_heading' ) ); ?></h2><p><?php echo nl2br( esc_html( $this->theme->copy( 'checkin_success_text', array( '{event_name}' => $name, '{contact_email}' => $contact ) ) ) ); ?></p></section><?php if ( $expectations ) : ?><section class="hherm-public-section"><h2>What to expect</h2><ol class="hherm-public-expectations"><?php foreach ( $expectations as $expectation ) : if ( ! is_array( $expectation ) ) continue; ?><li><strong><?php echo esc_html( $expectation['title'] ?? '' ); ?></strong><span><?php echo esc_html( $expectation['content'] ?? '' ); ?></span></li><?php endforeach; ?></ol></section><?php endif; ?><?php if ( $parking ) : ?><section class="hherm-public-parking"><h2>Parking &amp; access</h2><p><?php echo nl2br( esc_html( $parking ) ); ?></p></section><?php endif; ?><?php if ( $calendar_url ) : ?><a class="hherm-public-outline" href="<?php echo esc_url( $calendar_url ); ?>">Add to calendar</a><?php endif; ?><form method="get" class="hherm-public-reset"><input type="hidden" name="hherm_checkin" value="<?php echo esc_attr( $token ); ?>"><button type="submit">Not you? Start again</button></form><p class="hherm-public-footnote">This page closes at the time shown in the header.</p>
		<?php else : ?><?php if ( $message ) : ?><div class="hherm-public-message" role="alert"><?php echo esc_html( $message ); ?></div><?php endif; ?><?php $this->render_checkin_form( $token, $preview ); ?><p class="hherm-public-help"><?php echo nl2br( esc_html( $this->theme->copy( 'checkin_help', array( '{event_name}' => $name, '{contact_email}' => $contact ?: 'the event organiser' ) ) ) ); ?></p><?php endif; ?>
		<?php endif; ?></main><?php echo $this->theme->footer( 'hherm-public-footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></body></html>
		<?php
	}

	private function render_checkin_form( string $token, bool $preview ): void {
		$item = $this->verified_item;
		$key = $this->capacity ? ( $this->capacity->attendee_key() ?: 'number_of_attendees' ) : 'number_of_attendees';
		$party = max( 1, (int) ( $item[ $key ] ?? 1 ) );
		?>
		<section class="hherm-public-form-card"><form method="post">
		<?php if ( $item ) : ?>
			<p><strong>Registration found</strong><br><?php echo esc_html( sprintf( 'You have %d place(s) reserved for this event.', $party ) ); ?></p>
			<input type="hidden" name="identity" value="<?php echo esc_attr( $item['email'] ); ?>">
			<input type="hidden" name="checkin_step" value="confirm">
			<label for="hherm-party-size">How many of you are attending?</label>
			<select id="hherm-party-size" name="party_size" required><option value="<?php echo esc_attr( $party ); ?>">All <?php echo esc_html( $party ); ?> of us</option><?php for ( $count = $party - 1; $count >= 1; --$count ) : ?><option value="<?php echo esc_attr( $count ); ?>"><?php echo esc_html( sprintf( '%d of %d attending', $count, $party ) ); ?></option><?php endfor; ?></select>
			<?php if ( 'true' === $this->settings->get( 'checkin_feedback_enabled', 'false' ) ) : ?>
			<label for="hherm-feedback-score">How would you rate the event? <em>Optional</em></label><select id="hherm-feedback-score" name="feedback_score"><option value="">Choose a rating</option><option value="5">5 — Excellent</option><option value="4">4 — Good</option><option value="3">3 — Okay</option><option value="2">2 — Needs improvement</option><option value="1">1 — Poor</option></select>
			<label for="hherm-public-feedback">Comments or suggestions <em>Optional</em></label><textarea id="hherm-public-feedback" name="feedback" maxlength="5000" rows="4"></textarea>
			<?php endif; ?>
		<?php else : ?>
			<label for="hherm-public-identity">Your email</label><input id="hherm-public-identity" type="email" name="identity" autocomplete="email" required placeholder="Email address"><small>Use the email you registered with. We’ll find your reserved places for this event.</small><input type="hidden" name="checkin_step" value="lookup">
		<?php endif; ?>
		<div class="hherm-public-honeypot" aria-hidden="true"><label>Website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
		<?php wp_nonce_field( 'hherm_public_checkin_' . hash( 'sha256', $token ), 'hherm_public_nonce' ); ?>
		<button class="hherm-public-button" type="submit" <?php disabled( $preview ); ?>><?php echo $item ? 'Confirm check-in' : 'Find my registration'; ?></button>
		</form><?php if ( $item ) : ?><a class="hherm-public-outline" href="<?php echo esc_url( $this->public_url( $token ) ); ?>">Use a different email</a><?php endif; ?></section>
		<?php
	}

	private function active_record( int $event_id ): array {
		$record = (array) get_option( self::OPTION_PREFIX . $event_id, array() );
		$window = $this->event_day_window( $event_id );
		$times_changed = $window && ( absint( $record['starts_at'] ?? 0 ) !== absint( $window['starts_at'] ) || absint( $record['expires_at'] ?? 0 ) !== absint( $window['expires_at'] ) );
		if ( empty( $record['token'] ) || ! $this->valid_event( $event_id ) || $this->is_cancelled( $event_id ) || ! $window || $times_changed || time() >= absint( $record['expires_at'] ?? 0 ) ) {
			if ( $record ) delete_option( self::OPTION_PREFIX . $event_id );
			return array();
		}
		return $record;
	}

	private function ics_escape( string $value ): string {
		$value = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $value );
		return str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );
	}

	private function ics_fold( string $line ): string {
		$line = wp_check_invalid_utf8( $line, true );
		if ( strlen( $line ) <= 75 ) return $line;
		if ( ! preg_match_all( '/./us', $line, $matches ) ) return substr( $line, 0, 75 );
		$folded = '';
		$current = '';
		$limit = 75;
		foreach ( $matches[0] as $character ) {
			if ( '' !== $current && strlen( $current ) + strlen( $character ) > $limit ) {
				$folded .= ( '' === $folded ? '' : "\r\n " ) . $current;
				$current = $character;
				$limit = 74;
			} else {
				$current .= $character;
			}
		}
		return $folded . ( '' === $folded ? '' : "\r\n " ) . $current;
	}

	private function find_record( string $token ): array {
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) return array();
		global $wpdb;
		$pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locates only plugin-owned temporary link options; token is verified below.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		foreach ( (array) $names as $name ) {
			$event_id = absint( substr( $name, strlen( self::OPTION_PREFIX ) ) );
			$record = $this->active_record( $event_id );
			if ( $record && time() >= absint( $record['starts_at'] ?? 0 ) && hash_equals( (string) $record['token'], $token ) && $this->valid_event( $event_id ) && ! $this->is_cancelled( $event_id ) ) return array( 'event_id' => $event_id ) + $record;
		}
		return array();
	}

	private function public_url( string $token ): string { return add_query_arg( 'hherm_checkin', rawurlencode( $token ), home_url( '/' ) ); }
	private function event_day_window( int $event_id ): array {
		$start_raw = trim( (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true ) );
		$end_raw = trim( (string) get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true ) );
		if ( ! $start_raw || ! $end_raw ) return array();
		$start = $this->parse_event_datetime( $start_raw );
		$end = $this->parse_event_datetime( $end_raw );
		if ( ! $start || ! $end || $end->getTimestamp() <= $start->getTimestamp() ) return array();
		return array( 'starts_at' => $start->getTimestamp(), 'expires_at' => $end->modify( '+1 hour' )->getTimestamp() );
	}
	private function parse_event_datetime( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = absint( $value );
			return $timestamp ? ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() ) : false;
		}
		$value = trim( (string) $value );
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			if ( $date ) return $date;
		}
		return false;
	}
	private function client_address(): string { return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ); }
	private function valid_event( int $event_id ): bool { $post = get_post( $event_id ); return $post && 'trash' !== $post->post_status && $this->settings->get( 'events_cpt', 'events' ) === $post->post_type; }
	private function is_cancelled( int $event_id ): bool { return in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true ); }
	public function event_label( int $event_id ): string { $name = (string) get_post_meta( $event_id, 'event_name', true ); return $name ?: get_the_title( $event_id ) ?: 'Untitled event'; }
	private function require_access(): void { if ( ! current_user_can( Plugin::CAPABILITY ) ) wp_die( esc_html__( 'You do not have permission to manage event check-in.', 'heart-hub-event-registration-manager' ), esc_html__( 'Access denied', 'heart-hub-event-registration-manager' ), array( 'response' => 403 ) ); }
	private function redirect( int $event_id, string $error = '', string $notice = '' ): void { $args = array( 'page' => Attendance_Manager::PAGE_SLUG, 'event_id' => $event_id ); if ( $error ) $args['checkin_error'] = $error; if ( $notice ) $args['checkin_notice'] = $notice; wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit; }
}
