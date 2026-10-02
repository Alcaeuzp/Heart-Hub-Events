<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mutation-lock.php';
require_once __DIR__ . '/class-text-validation.php';

final class Post_Event_Feedback {
	public const CRON_HOOK = 'hherm_send_feedback_reminders';
	private const OPTION_PREFIX = 'hherm_feedback_link_';
	private const LINK_LIFETIME = 90 * DAY_IN_SECONDS;

	private $settings;
	private $repository;
	private $audit;
	private $email;
	private $theme;

	public function __construct( Settings $settings, CCT_Repository $repository, Audit_Log $audit, Email_Service $email, Public_Page_Theme $theme ) {
		$this->settings = $settings;
		$this->repository = $repository;
		$this->audit = $audit;
		$this->email = $email;
		$this->theme = $theme;
	}

	public function register(): void {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'send_due_reminders' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_public' ), 0 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function send_due_reminders(): void {
		if ( ! $this->settings->automation_enabled( 'feedback' ) ) return;
		$lock_name = 'hherm_feedback_cron_lock';
		$lock = Mutation_Lock::acquire( $lock_name, HOUR_IN_SECONDS );
		if ( is_wp_error( $lock ) ) return;
		try {
			$events = get_posts( array( 'post_type' => $this->settings->get( 'events_cpt', 'events' ), 'post_status' => array( 'publish', 'private' ), 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => false ) );
			$now = time();
			$delay = $this->settings->automation_delay( 'feedback' );
			$window = $this->settings->feedback_send_window();
			foreach ( $events as $event_id ) {
				if ( ! Mutation_Lock::owns( $lock_name, $lock ) ) return;
				if ( $this->is_cancelled( (int) $event_id ) ) continue;
				$end = $this->event_end( (int) $event_id );
				$due = $end + $delay;
				if ( ! $end || $now < $due || $now > $due + $window ) continue;
				$items = $this->repository->attendance_list( (int) $event_id );
				if ( is_wp_error( $items ) ) continue;
				$event = $this->email->event_context( (int) $event_id );
				if ( ! $event ) continue;
				foreach ( $items as $item ) {
					if ( ! Mutation_Lock::owns( $lock_name, $lock ) ) return;
					$this->send_reminder( absint( $item['_ID'] ?? 0 ), $event );
				}
			}
		} finally {
			Mutation_Lock::release( $lock_name, $lock );
		}
	}

	/** Re-read eligibility while holding the same lock used for token creation and delivery. */
	private function send_reminder( int $id, array $event ): void {
		if ( $id < 1 ) return;
		$name = 'hherm_feedback_delivery_' . $id;
		$lock = Mutation_Lock::acquire( $name, HOUR_IN_SECONDS );
		if ( is_wp_error( $lock ) ) return;
		try {
			$item = $this->repository->get( $id );
			if ( is_wp_error( $item ) || ! Mutation_Lock::owns( $name, $lock ) ) return;
			$event_id = absint( $event['id'] ?? 0 );
			if ( absint( $item['event_id'] ?? 0 ) !== $event_id || 'approved' !== sanitize_key( $item['registration_status'] ?? '' ) || $this->is_cancelled( $event_id ) ) return;
			if ( ! in_array( sanitize_key( $item['attendance_status'] ?? '' ), array( 'attended', 'partial' ), true ) || $this->has_feedback( $item ) ) return;
			if ( $this->audit->has_status( $id, 'feedback_email', 'sent' ) || $this->audit->has_status( $id, 'feedback_email', 'logged' ) ) return;
			if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
			$url = $this->feedback_url( $item );
			if ( is_wp_error( $url ) ) {
				$this->audit->write( $id, 'feedback_email', 'failed', array( 'event_id' => $event_id, 'reason' => $url->get_error_message() ) );
				return;
			}
			if ( ! Mutation_Lock::owns( $name, $lock ) ) return;
			$item['feedback_url'] = $url;
			$this->email->send_feedback_request( $item, $event );
		} catch ( \Throwable $error ) {
			$this->audit->write( $id, 'feedback_email', 'failed', array( 'event_id' => absint( $event['id'] ?? 0 ), 'reason' => 'The feedback request could not be handed to the mail transport.' ) );
		} finally {
			Mutation_Lock::release( $name, $lock );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- resolve() verifies the expiring attendee bearer token; feedback writes verify a separate nonce in submit().
	public function maybe_render_public(): void {
		if ( ! isset( $_GET['hherm_feedback'] ) ) return;
		$id = absint( wp_unslash( $_GET['hherm_feedback'] ) );
		$token = isset( $_GET['hherm_feedback_token'] ) ? sanitize_text_field( wp_unslash( $_GET['hherm_feedback_token'] ) ) : '';
		$item = $this->resolve( $id, $token );
		if ( is_wp_error( $item ) ) $this->render( array(), $item->get_error_message(), false, $token );
		if ( $this->has_feedback( $item ) ) $this->render( $item, '', true, $token );
		$error = '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === strtoupper( $method ) ) {
			$result = $this->submit( $item, $token );
			if ( ! is_wp_error( $result ) ) $this->render( $item, '', true, $token );
			$error = $result->get_error_message();
		}
		$this->render( $item, $error, false, $token );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function submit( array $item, string $token ) {
		$id = absint( $item['_ID'] ?? 0 );
		if ( ! empty( $_POST['company_website'] ) ) return new \WP_Error( 'hherm_feedback_invalid', 'We could not save your feedback. Please try again.' );
		$nonce = isset( $_POST['hherm_feedback_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hherm_feedback_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $this->nonce_action( $id, $token ) ) ) return new \WP_Error( 'hherm_feedback_nonce', 'This form expired. Please refresh the page and try again.' );
		$feedback = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
		if ( ! Text_Validation::within_limit( $feedback, 5000 ) ) return new \WP_Error( 'hherm_feedback_length', 'Please keep your feedback to 5,000 characters or fewer.' );
		$score = isset( $_POST['feedback_score'] ) ? absint( wp_unslash( $_POST['feedback_score'] ) ) : 0;
		if ( $score < 1 || $score > 5 ) return new \WP_Error( 'hherm_feedback_score', 'Please choose a rating from 1 to 5.' );
		$result = $this->repository->update_feedback( $id, $feedback, $score );
		if ( is_wp_error( $result ) ) return $result;
		$this->audit->write( $id, 'feedback_submitted', 'saved', array( 'event_id' => absint( $item['event_id'] ?? 0 ), 'score' => $score, 'comment_provided' => '' !== $feedback ) );
		return $result;
	}

	private function feedback_url( array $item ) {
		$id = absint( $item['_ID'] ?? 0 );
		$event_id = absint( $item['event_id'] ?? 0 );
		$name = self::OPTION_PREFIX . $id;
		$existing = get_option( $name, array() );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) > time() && ! empty( $existing['token_hash'] ) ) {
			$token = (string) ( $existing['token'] ?? '' );
			if ( absint( $existing['event_id'] ?? 0 ) !== $event_id || ! preg_match( '/^[a-f0-9]{48}$/', $token ) || ! hash_equals( (string) $existing['token_hash'], hash( 'sha256', $token ) ) ) {
				// Older records only kept the hash. Never revoke an already mailed link
				// merely because its successful delivery audit was unavailable.
				return new \WP_Error( 'hherm_feedback_existing_link', 'The existing feedback link could not safely be reused.' );
			}
			return add_query_arg( array( 'hherm_feedback' => $id, 'hherm_feedback_token' => $token ), home_url( '/' ) );
		}
		$token = bin2hex( random_bytes( 24 ) );
		$record = array( 'token' => $token, 'token_hash' => hash( 'sha256', $token ), 'event_id' => $event_id, 'expires_at' => time() + self::LINK_LIFETIME );
		update_option( $name, $record, false );
		if ( get_option( $name, array() ) !== $record ) return new \WP_Error( 'hherm_feedback_link_storage', 'The feedback link could not be saved. Delivery will be retried.' );
		return add_query_arg( array( 'hherm_feedback' => $id, 'hherm_feedback_token' => rawurlencode( $token ) ), home_url( '/' ) );
	}

	private function resolve( int $id, string $token ) {
		if ( $id < 1 || ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) return new \WP_Error( 'hherm_feedback_link', 'This feedback link is invalid or has expired.' );
		$record = (array) get_option( self::OPTION_PREFIX . $id, array() );
		if ( empty( $record['token_hash'] ) || time() >= absint( $record['expires_at'] ?? 0 ) || ! hash_equals( (string) $record['token_hash'], hash( 'sha256', $token ) ) ) {
			if ( $record && time() >= absint( $record['expires_at'] ?? 0 ) ) delete_option( self::OPTION_PREFIX . $id );
			return new \WP_Error( 'hherm_feedback_link', 'This feedback link is invalid or has expired.' );
		}
		$item = $this->repository->get( $id );
		if ( is_wp_error( $item ) || absint( $item['event_id'] ?? 0 ) !== absint( $record['event_id'] ?? 0 ) || 'approved' !== sanitize_key( $item['registration_status'] ?? '' ) ) return new \WP_Error( 'hherm_feedback_link', 'This feedback link is invalid or has expired.' );
		return $item;
	}

	private function render( array $item, string $error, bool $complete, string $token ): void {
		$event_id = absint( $item['event_id'] ?? 0 );
		$name = $event_id ? $this->event_label( $event_id ) : 'Event feedback';
		$this->theme->security_headers( $item ? 200 : 404 );
		$label = $this->theme->copy( 'feedback_label' );
		$contact = sanitize_email( $this->settings->get( 'contact_email', get_option( 'admin_email' ) ) ) ?: 'the event organiser';
		$copy_tokens = array( '{event_name}' => $name, '{contact_email}' => $contact );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( ( $label ?: 'Event feedback' ) . ' — ' . $name ); ?></title><?php wp_enqueue_style( 'hherm-public-checkin', HHERM_URL . 'assets/checkin.css', array(), HHERM_VERSION ); wp_print_styles( 'hherm-public-checkin' ); ?><?php echo $this->theme->style_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated only from sanitized settings. ?></head><body class="hherm-public-checkin"><?php echo $this->theme->header( $label, 'hherm-public-header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><main class="hherm-public-main"><div class="hherm-public-eyebrow">Thank you for attending</div><h1><?php echo esc_html( $name ); ?></h1><?php if ( ! $item ) : ?><section class="hherm-public-error"><p><?php echo esc_html( $error ); ?></p></section><?php elseif ( $complete ) : ?><section class="hherm-public-success"><div class="hherm-public-tick">✓</div><h2><?php echo esc_html( $this->theme->copy( 'feedback_success_heading' ) ); ?></h2><p><?php echo nl2br( esc_html( $this->theme->copy( 'feedback_success_text', $copy_tokens ) ) ); ?></p></section><?php else : ?><p class="hherm-public-intro"><?php echo nl2br( esc_html( $this->theme->copy( 'feedback_intro', $copy_tokens ) ) ); ?></p><?php if ( $error ) : ?><div class="hherm-public-message" role="alert"><?php echo esc_html( $error ); ?></div><?php endif; ?><section class="hherm-public-form-card"><form method="post"><label for="hherm-feedback-score">How would you rate the event?</label><select id="hherm-feedback-score" name="feedback_score" required><option value="">Choose a rating</option><option value="5">5 — Excellent</option><option value="4">4 — Good</option><option value="3">3 — Okay</option><option value="2">2 — Needs improvement</option><option value="1">1 — Poor</option></select><label for="hherm-public-feedback">Comments or suggestions <em>Optional</em></label><textarea id="hherm-public-feedback" name="feedback" maxlength="5000" rows="5" placeholder="What worked well? What could we improve? Share any suggestions for future events."></textarea><div class="hherm-public-honeypot" aria-hidden="true"><label>Website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div><?php wp_nonce_field( $this->nonce_action( absint( $item['_ID'] ), $token ), 'hherm_feedback_nonce' ); ?><button class="hherm-public-button" type="submit">Submit feedback</button></form></section><?php endif; ?></main><?php echo $this->theme->footer( 'hherm-public-footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></body></html><?php exit;
	}

	private function nonce_action( int $id, string $token ): string { return 'hherm_feedback_' . $id . '_' . substr( hash( 'sha256', $token ), 0, 16 ); }
	private function has_feedback( array $item ): bool { $field = sanitize_key( $this->settings->get( 'feedback_field', 'event_feedback' ) ); return ( $field && '' !== trim( (string) ( $item[ $field ] ?? '' ) ) ) || absint( $item['feedback_score'] ?? 0 ) >= 1; }
	private function event_end( int $event_id ): int {
		$raw = get_post_meta( $event_id, $this->settings->get( 'event_end', 'end_date__time' ), true );
		if ( is_numeric( $raw ) ) return absint( $raw );
		$raw = trim( (string) $raw );
		foreach ( array( 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $raw, wp_timezone() );
			if ( $date ) return $date->getTimestamp();
		}
		return 0;
	}
	private function is_cancelled( int $event_id ): bool { return in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true ); }
	private function event_label( int $event_id ): string { $name = (string) get_post_meta( $event_id, 'event_name', true ); return $name ?: get_the_title( $event_id ) ?: 'Untitled event'; }
}
