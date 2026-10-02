<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Email_Automation {
	public const CRON_HOOK = 'hherm_send_scheduled_email';
	public const QUEUE_OPTION = 'hherm_scheduled_email_jobs';
	private const JOB_OPTION_PREFIX = 'hherm_email_job_lock_job_';
	private const LOCK_OPTION_PREFIX = 'hherm_email_job_lock_';
	private const MIGRATION_OPTION = 'hherm_email_job_lock_migrated_v2';
	private const MAX_ATTEMPTS = 3;
	private const LOCK_LIFETIME = 10 * MINUTE_IN_SECONDS;
	private const DELIVERY_LOCK_LIFETIME = HOUR_IN_SECONDS;
	private const LOCK_ATTEMPTS = 5;

	private $settings;
	private $repository;
	private $audit;
	private $email;

	public function __construct( Settings $settings, CCT_Repository $repository, Audit_Log $audit, Email_Service $email ) {
		$this->settings = $settings;
		$this->repository = $repository;
		$this->audit = $audit;
		$this->email = $email;
	}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'send_queued' ), 10, 2 );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 30 );
		add_action( 'updated_option', array( $this, 'maybe_reschedule' ), 10, 3 );
		add_action( 'added_option', array( $this, 'maybe_reschedule_added' ), 10, 2 );
	}

	public static function unschedule(): void {
		wp_unschedule_hook( self::CRON_HOOK, true );
	}

	public function send_decision( array $item, array $event, string $status ): array {
		$type = 'approved' === sanitize_key( $status ) ? 'approval' : 'decline';
		return $this->dispatch( $type, $item, $event, sanitize_key( $status ), 'decision_email' );
	}

	public function send_waitlist( array $item, array $event ): array {
		return $this->dispatch( 'waitlist', $item, $event, 'waitlist', 'waitlist_email' );
	}

	public function send_interest_received( array $item, array $event ): array {
		return $this->email->send_interest_received( $item, $event );
	}

	public function send_internal_notification( array $item, array $context, string $action, string $record_url = '' ): string {
		return $this->email->send_internal_notification( $item, $context, $action, $record_url );
	}

	public function send_cancellation( array $item, array $event ): array {
		$id = absint( $item['_ID'] ?? 0 );
		if ( $id && $this->audit->has_status( $id, 'cancellation_email', 'sent' ) ) {
			return array( 'status' => 'skipped' );
		}
		return $this->dispatch( 'cancellation', $item, $event, 'cancelled', 'cancellation_email' );
	}

	public function send_schedule_confirmed( array $item, array $event ): array {
		return $this->dispatch( 'schedule_confirmation', $item, $event, 'scheduled', 'schedule_confirmation_email' );
	}

	public function notify_event_scheduled( int $event_id ): array {
		$items = $this->repository->interest_recipients( $event_id );
		if ( is_wp_error( $items ) ) {
			$this->audit->write( $event_id, 'schedule_confirmation_batch', 'failed', array( 'event_id' => $event_id, 'reason' => $items->get_error_message() ) );
			return array( 'failed' => 1 );
		}
		$event = $this->email->event_context( $event_id );
		$summary = array( 'sent' => 0, 'scheduled' => 0, 'logged' => 0, 'skipped' => 0, 'failed' => 0 );
		foreach ( $items as $item ) {
			$result = $this->send_schedule_confirmed( $item, $event );
			$status = sanitize_key( $result['status'] ?? 'failed' );
			isset( $summary[ $status ] ) ? ++$summary[ $status ] : ++$summary['failed'];
		}
		$this->audit->write( $event_id, 'schedule_confirmation_batch', 'complete', array( 'event_id' => $event_id, 'summary' => $summary ) );
		return $summary;
	}

	public function send_queued( string $job_key, string $generation = '' ): void {
		$job_key = sanitize_key( $job_key );
		$generation = sanitize_key( $generation );
		if ( ! $job_key ) return;

		$lock_token = $this->acquire_lock( $job_key );
		if ( ! $lock_token ) return;

		try {
			$job = $this->get_job( $job_key );
			if ( ! $job ) {
				$this->clear_schedule( $job_key, $generation );
				return;
			}

			$current_generation = sanitize_key( $job['generation'] ?? '' );
			if ( ! $generation || ! $current_generation || ! hash_equals( $current_generation, $generation ) ) {
				$this->clear_schedule( $job_key, $generation );
				if ( $current_generation && ! $this->schedule_job( $job_key, $job ) ) {
					$this->audit_schedule_failure( $job, 'The current email job could not be restored after an obsolete cron event.' );
				}
				return;
			}
			if ( ! empty( $job['terminal'] ) ) {
				$this->finish_job( $job_key, $generation );
				return;
			}

			$due_at = absint( $job['due_at'] ?? 0 );
			if ( $due_at > time() ) {
				if ( ! $this->schedule_job( $job_key, $job ) ) {
					$this->audit_schedule_failure( $job, 'WP-Cron rejected the future email job.' );
				}
				return;
			}

			$type = sanitize_key( $job['type'] ?? '' );
			$id = absint( $job['registration_id'] ?? 0 );
			$event_id = absint( $job['event_id'] ?? 0 );
			$audit_type = sanitize_key( $job['audit_type'] ?? '' );
			if ( ! $this->settings->automation_enabled( $type ) ) {
				$this->audit->write( $id, $audit_type, 'skipped_disabled', array( 'event_id' => $event_id, 'email_type' => $type ) );
				$this->finish_job( $job_key, $generation );
				return;
			}

			$item = $this->repository->get( $id );
			if ( is_wp_error( $item ) || ! $this->trigger_is_current( $job, $item ) ) {
				$this->audit->write( $id, $audit_type, 'skipped_stale', array( 'event_id' => $event_id, 'email_type' => $type ) );
				$this->finish_job( $job_key, $generation );
				return;
			}

			$event = $this->email->event_context( $event_id );
			if ( ! $event ) {
				$this->audit->write( $id, $audit_type, 'skipped_stale', array( 'event_id' => $event_id, 'email_type' => $type, 'reason' => 'Event unavailable' ) );
				$this->finish_job( $job_key, $generation );
				return;
			}
			if ( $this->already_delivered( $id, $audit_type ) ) {
				$this->finish_job( $job_key, $generation );
				return;
			}
			if ( ! $this->extend_lock( $job_key, $lock_token ) ) return;

			try {
				$result = $this->deliver( $type, $item, $event, (string) ( $job['status'] ?? '' ) );
			} catch ( \Throwable $error ) {
				if ( ! $this->owns_lock( $job_key, $lock_token ) ) return;
				$this->audit->write( $id, $audit_type, 'failed', array( 'event_id' => $event_id, 'email_type' => $type, 'reason' => $this->error_message( $error ) ) );
				$this->retry_job( $job_key, $job );
				return;
			}
			if ( ! $this->owns_lock( $job_key, $lock_token ) ) return;

			if ( $this->should_retry( $result, $item ) ) {
				$this->retry_job( $job_key, $job );
				return;
			}
			$this->finish_job( $job_key, $generation );
		} finally {
			$this->release_lock( $job_key, $lock_token );
		}
	}

	public function ensure_scheduled(): void {
		$this->migrate_legacy_queue();
		foreach ( $this->job_records() as $key => $record ) {
			$generation = is_array( $record ) ? sanitize_key( $record['generation'] ?? '' ) : '';
			if ( $generation && wp_next_scheduled( self::CRON_HOOK, array( $key, $generation ) ) ) continue;
			$lock_token = $this->acquire_lock( $key );
			if ( ! $lock_token ) continue;
			try {
				$stored = get_option( $this->job_option_name( $key ), null );
				if ( ! is_array( $stored ) || ! $stored ) {
					delete_option( $this->job_option_name( $key ) );
					$this->clear_schedule( $key, $generation );
					continue;
				}
				$job = $stored;
				if ( ! empty( $job['terminal'] ) ) {
					$this->finish_job( $key, sanitize_key( $job['generation'] ?? '' ) );
					continue;
				}
				if ( empty( $job['generation'] ) ) {
					$job['generation'] = $this->new_generation();
					if ( ! $this->save_job( $key, $job ) ) {
						$this->audit_schedule_failure( $job, 'The recovered email job could not be upgraded for scheduling.' );
						continue;
					}
				}
				$this->clear_schedule( $key, '' );
				if ( ! $this->schedule_job( $key, $job ) ) {
					$this->audit_schedule_failure( $job, 'WP-Cron rejected the recovered email job.' );
				}
			} finally {
				$this->release_lock( $key, $lock_token );
			}
		}
	}

	public function maybe_reschedule( string $option, $old_value, $value ): void {
		if ( Settings::OPTION_AUTOMATION === $option ) $this->reschedule_jobs();
	}

	public function maybe_reschedule_added( string $option, $value ): void {
		if ( Settings::OPTION_AUTOMATION === $option ) $this->reschedule_jobs();
	}

	private function dispatch( string $type, array $item, array $event, string $status, string $audit_type ): array {
		$id = absint( $item['_ID'] ?? 0 );
		$event_id = absint( $item['event_id'] ?? 0 );
		if ( ! $event_id ) $event_id = absint( $event['id'] ?? 0 );
		$key = $this->job_key( $type, $id );
		$lock_token = $this->acquire_lock( $key );
		if ( ! $lock_token ) {
			$this->audit->write( $id, $audit_type, 'failed', array( 'reason' => 'The email queue was busy', 'event_id' => $event_id, 'email_type' => $type ) );
			return array( 'status' => 'failed' );
		}

		try {
			$this->cancel_job( $key );
			if ( $this->already_delivered( $id, $audit_type ) ) return array( 'status' => 'skipped' );
			if ( ! $this->settings->automation_enabled( $type ) ) {
				$this->audit->write( $id, $audit_type, 'skipped_disabled', array( 'event_id' => $event_id, 'email_type' => $type ) );
				return array( 'status' => 'skipped' );
			}

			$context = $this->email->event_context( $event_id );
			if ( $context ) $event = $context;
			$delay = $this->settings->automation_delay( $type );
			if ( $id < 1 || $event_id < 1 ) {
				$this->audit->write( $id, $audit_type, 'failed', array( 'reason' => 'Email could not be queued', 'event_id' => $event_id, 'email_type' => $type ) );
				return array( 'status' => 'failed' );
			}

			$triggered_at = time();
			$job = array(
				'type'            => $type,
				'registration_id' => $id,
				'event_id'         => $event_id,
				'status'           => $status,
				'audit_type'       => $audit_type,
				'triggered_at'     => $triggered_at,
				'due_at'           => $triggered_at + $delay,
				'fingerprint'      => sanitize_text_field( (string) ( $item['reviewed_date'] ?? $item['registration_date'] ?? '' ) ),
				'attempts'         => 0,
				'generation'       => $this->new_generation(),
			);
			if ( ! $this->save_job( $key, $job ) ) {
				$this->audit->write( $id, $audit_type, 'failed', array( 'reason' => 'The email job could not be stored', 'event_id' => $event_id, 'email_type' => $type ) );
				return array( 'status' => 'failed' );
			}

			$scheduled = $this->schedule_job( $key, $job );
			if ( $delay > 0 ) {
				$this->audit->write( $id, $audit_type, $scheduled ? 'scheduled' : 'schedule_failed', array( 'event_id' => $event_id, 'email_type' => $type, 'send_at' => $job['due_at'] ) );
				return array( 'status' => 'scheduled', 'send_at' => $job['due_at'], 'schedule_pending' => ! $scheduled );
			}

			if ( ! $scheduled ) {
				$this->audit_schedule_failure( $job, 'WP-Cron rejected the immediate recovery email job.' );
			}
			if ( ! $this->extend_lock( $key, $lock_token ) ) {
				return array( 'status' => 'scheduled', 'send_at' => $job['due_at'], 'schedule_pending' => ! $scheduled );
			}
			try {
				$result = $this->deliver( $type, $item, $event, $status );
			} catch ( \Throwable $error ) {
				if ( ! $this->owns_lock( $key, $lock_token ) ) return array( 'status' => 'scheduled', 'send_at' => $job['due_at'] );
				$this->audit->write( $id, $audit_type, 'failed', array( 'event_id' => $event_id, 'email_type' => $type, 'reason' => $this->error_message( $error ) ) );
				return $this->retry_job( $key, $job );
			}
			if ( ! $this->owns_lock( $key, $lock_token ) ) return array( 'status' => 'scheduled', 'send_at' => $job['due_at'] );
			if ( $this->should_retry( $result, $item ) ) {
				return $this->retry_job( $key, $job );
			}
			$this->finish_job( $key, sanitize_key( $job['generation'] ?? '' ) );
			return $result;
		} finally {
			$this->release_lock( $key, $lock_token );
		}
	}

	private function deliver( string $type, array $item, array $event, string $status ): array {
		if ( in_array( $type, array( 'approval', 'decline' ), true ) ) return $this->email->send_decision( $item, $event, $status );
		if ( 'waitlist' === $type ) return $this->email->send_waitlist( $item, $event );
		if ( 'schedule_confirmation' === $type ) return $this->email->send_schedule_confirmed( $item, $event );
		return $this->email->send_cancellation( $item, $event );
	}

	private function trigger_is_current( array $job, array $item ): bool {
		$type = sanitize_key( $job['type'] ?? '' );
		$status = sanitize_key( $item['registration_status'] ?? '' );
		$event_id = absint( $job['event_id'] ?? 0 );
		if ( $event_id < 1 || absint( $item['event_id'] ?? 0 ) !== $event_id ) return false;
		$event_cancelled = in_array( strtolower( (string) get_post_meta( $event_id, 'event_cancelled', true ) ), array( '1', 'true', 'yes', 'on' ), true );
		if ( 'cancellation' !== $type && $event_cancelled ) return false;
		if ( 'approval' === $type || 'decline' === $type ) {
			if ( $status !== sanitize_key( $job['status'] ?? '' ) ) return false;
			$fingerprint = sanitize_text_field( (string) ( $job['fingerprint'] ?? '' ) );
			return ! $fingerprint || $fingerprint === sanitize_text_field( (string) ( $item['reviewed_date'] ?? '' ) );
		}
		if ( 'waitlist' === $type ) {
			$fingerprint = sanitize_text_field( (string) ( $job['fingerprint'] ?? '' ) );
			return 'waitlist' === $status && ( ! $fingerprint || $fingerprint === sanitize_text_field( (string) ( $item['registration_date'] ?? '' ) ) );
		}
		if ( 'schedule_confirmation' === $type ) {
			$event_status = sanitize_key( (string) get_post_meta( $event_id, 'event_schedule_status', true ) );
			$start = (string) get_post_meta( $event_id, $this->settings->get( 'event_start', 'start_date' ), true );
			return 'interest' === $status && 'scheduled' === $event_status && '' !== $start;
		}
		if ( 'cancellation' === $type ) return $event_cancelled && in_array( $status, array( 'pending', 'approved', 'waitlist' ), true );
		return false;
	}

	private function reschedule_jobs(): void {
		foreach ( $this->job_records() as $key => $record ) {
			$lock_token = $this->acquire_lock( $key );
			if ( ! $lock_token ) continue;
			try {
				$job = $this->get_job( $key );
				if ( ! $job ) {
					delete_option( $this->job_option_name( $key ) );
					$this->clear_schedule( $key, '' );
					continue;
				}
				if ( ! empty( $job['terminal'] ) ) {
					$this->finish_job( $key, sanitize_key( $job['generation'] ?? '' ) );
					continue;
				}

				$type = sanitize_key( $job['type'] ?? '' );
				$id = absint( $job['registration_id'] ?? 0 );
				$audit_type = sanitize_key( $job['audit_type'] ?? '' );
				$event_id = absint( $job['event_id'] ?? 0 );
				$old_generation = sanitize_key( $job['generation'] ?? '' );
				$this->clear_schedule( $key, $old_generation );
				if ( ! $this->settings->automation_enabled( $type ) ) {
					$this->audit->write( $id, $audit_type, 'skipped_disabled', array( 'event_id' => $event_id, 'email_type' => $type ) );
					$this->finish_job( $key, $old_generation );
					continue;
				}

				$triggered_at = absint( $job['triggered_at'] ?? 0 );
				if ( ! $triggered_at ) $triggered_at = time();
				if ( absint( $job['attempts'] ?? 0 ) < 1 ) $job['due_at'] = $triggered_at + $this->settings->automation_delay( $type );
				$job['generation'] = $this->new_generation();
				if ( ! $this->save_job( $key, $job ) ) {
					$this->audit_schedule_failure( $job, 'The rescheduled email job could not be stored.' );
					$this->restore_stored_schedule( $key );
					continue;
				}
				$scheduled = $this->schedule_job( $key, $job );
				$this->audit->write( $id, $audit_type, $scheduled ? 'rescheduled' : 'schedule_failed', array( 'event_id' => $event_id, 'email_type' => $type, 'send_at' => $job['due_at'] ) );
			} finally {
				$this->release_lock( $key, $lock_token );
			}
		}
	}

	private function retry_job( string $key, array $job ): array {
		$attempts = absint( $job['attempts'] ?? 0 ) + 1;
		$generation = sanitize_key( $job['generation'] ?? '' );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->clear_schedule( $key, $generation );
			$job['attempts'] = $attempts;
			$job['terminal'] = 'max_attempts';
			$job['generation'] = $this->new_generation();
			$this->audit_terminal_failure( $job, 'Maximum automatic delivery attempts reached.' );
			if ( $this->save_job( $key, $job ) ) {
				$this->finish_job( $key, sanitize_key( $job['generation'] ?? '' ) );
			} else {
				$this->audit_schedule_failure( $job, 'The terminal email-job state could not be stored.' );
				$this->finish_job( $key, '' );
			}
			return array( 'status' => 'failed' );
		}

		$this->clear_schedule( $key, $generation );
		$job['attempts'] = $attempts;
		$job['due_at'] = time() + ( $attempts * 15 * MINUTE_IN_SECONDS );
		$job['generation'] = $this->new_generation();
		if ( ! $this->save_job( $key, $job ) ) {
			$this->audit_schedule_failure( $job, 'The retry email job could not be stored.' );
			$this->audit_terminal_failure( $job, 'Automatic delivery stopped because the retry job could not be stored.' );
			$this->finish_job( $key, '' );
			return array( 'status' => 'failed' );
		}
		$scheduled = $this->schedule_job( $key, $job );
		$this->audit->write( absint( $job['registration_id'] ?? 0 ), sanitize_key( $job['audit_type'] ?? '' ), $scheduled ? 'retry_scheduled' : 'schedule_failed', array( 'event_id' => absint( $job['event_id'] ?? 0 ), 'email_type' => sanitize_key( $job['type'] ?? '' ), 'attempt' => $attempts + 1, 'send_at' => $job['due_at'] ) );
		return array( 'status' => 'scheduled', 'retry' => true, 'send_at' => $job['due_at'], 'schedule_pending' => ! $scheduled );
	}

	private function should_retry( array $result, array $item ): bool {
		return 'failed' === sanitize_key( $result['status'] ?? '' )
			&& (bool) ( $result['retryable'] ?? true )
			&& is_email( sanitize_email( $item['email'] ?? '' ) );
	}

	private function already_delivered( int $registration_id, string $audit_type ): bool {
		if ( $registration_id < 1 || ! $audit_type ) return false;
		// A registration can be reviewed again after an attendee resubmits changes. Each staff
		// decision (audit type "decision") needs its own email, so only count deliveries since then.
		if ( 'decision_email' === $audit_type ) {
			return $this->audit->has_status_since( $registration_id, $audit_type, array( 'sent', 'logged', 'failed_terminal' ), 'decision' );
		}
		return $this->audit->has_status( $registration_id, $audit_type, 'sent' )
			|| $this->audit->has_status( $registration_id, $audit_type, 'logged' )
			|| $this->audit->has_status( $registration_id, $audit_type, 'failed_terminal' );
	}

	private function restore_stored_schedule( string $key ): void {
		$stored = $this->get_job( $key );
		if ( $stored && ! $this->schedule_job( $key, $stored ) ) {
			$this->audit_schedule_failure( $stored, 'WP-Cron rejected the last stored email job while recovering from a persistence failure.' );
		}
	}

	private function finish_job( string $key, string $generation ): bool {
		$current = $this->get_job( $key );
		if ( $current ) {
			$current_generation = sanitize_key( $current['generation'] ?? '' );
			if ( $generation && $current_generation && ! hash_equals( $current_generation, $generation ) ) return false;
		}
		$this->clear_schedule( $key, $generation );
		delete_option( $this->job_option_name( $key ) );
		if ( ! $this->get_job( $key ) ) return true;
		$this->audit->write(
			absint( $current['registration_id'] ?? 0 ),
			sanitize_key( $current['audit_type'] ?? '' ),
			'cleanup_failed',
			array(
				'event_id'  => absint( $current['event_id'] ?? 0 ),
				'email_type' => sanitize_key( $current['type'] ?? '' ),
			)
		);
		return false;
	}

	private function cancel_job( string $key ): void {
		$current = $this->get_job( $key );
		$this->clear_schedule( $key, sanitize_key( $current['generation'] ?? '' ) );
		delete_option( $this->job_option_name( $key ) );
	}

	private function schedule_job( string $key, array $job ): bool {
		$generation = sanitize_key( $job['generation'] ?? '' );
		if ( ! $generation ) return false;
		$args = array( $key, $generation );
		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) return true;
		$result = wp_schedule_single_event( max( time() + 5, absint( $job['due_at'] ?? 0 ) ), self::CRON_HOOK, $args, true );
		return true === $result;
	}

	private function clear_schedule( string $key, string $generation ): void {
		if ( $generation ) wp_clear_scheduled_hook( self::CRON_HOOK, array( $key, $generation ), true );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $key ), true );
	}

	private function job_key( string $type, int $id ): string {
		$group = in_array( $type, array( 'approval', 'decline' ), true ) ? 'decision' : $type;
		return sanitize_key( $group . '_' . $id );
	}

	private function get_job( string $key ): array {
		$job = get_option( $this->job_option_name( $key ), array() );
		return is_array( $job ) ? $job : array();
	}

	private function save_job( string $key, array $job ): bool {
		update_option( $this->job_option_name( $key ), $job, false );
		$saved = $this->get_job( $key );
		$generation = sanitize_key( $job['generation'] ?? '' );
		$saved_generation = sanitize_key( $saved['generation'] ?? '' );
		return $generation && $saved_generation && hash_equals( $generation, $saved_generation );
	}

	private function job_records(): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::JOB_OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerates only this plugin's non-autoloaded email-job options.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$records = array();
		foreach ( (array) $names as $name ) {
			$key = sanitize_key( substr( (string) $name, strlen( self::JOB_OPTION_PREFIX ) ) );
			if ( $key ) $records[ $key ] = get_option( (string) $name, null );
		}
		return $records;
	}

	private function job_option_name( string $key ): string {
		return self::JOB_OPTION_PREFIX . sanitize_key( $key );
	}

	private function migrate_legacy_queue(): void {
		if ( get_option( self::MIGRATION_OPTION ) ) return;
		$lock_token = $this->acquire_lock( 'legacy_migration' );
		if ( ! $lock_token ) return;
		try {
			if ( get_option( self::MIGRATION_OPTION ) ) return;
			$legacy = get_option( self::QUEUE_OPTION, array() );
			$migration_failed = false;
			wp_unschedule_hook( self::CRON_HOOK, true );
			foreach ( is_array( $legacy ) ? $legacy : array() as $key => $job ) {
				$key = sanitize_key( (string) $key );
				if ( ! $key || ! is_array( $job ) ) continue;
				$job_lock_token = $this->acquire_lock( $key );
				if ( ! $job_lock_token ) {
					$migration_failed = true;
					continue;
				}
				try {
					if ( $this->get_job( $key ) ) continue;
					$job['generation'] = $this->new_generation();
					if ( empty( $job['due_at'] ) ) $job['due_at'] = time() + 5;
					if ( ! $this->save_job( $key, $job ) ) {
						$migration_failed = true;
						$this->audit_schedule_failure( $job, 'The legacy email job could not be migrated.' );
					}
				} finally {
					$this->release_lock( $key, $job_lock_token );
				}
			}
			if ( ! $migration_failed ) {
				delete_option( self::QUEUE_OPTION );
				update_option( self::MIGRATION_OPTION, time(), false );
			}
		} finally {
			$this->release_lock( 'legacy_migration', $lock_token );
		}
	}

	private function acquire_lock( string $key ): string {
		$name = self::LOCK_OPTION_PREFIX . substr( hash( 'sha256', $key ), 0, 32 );
		$token = $this->new_generation();
		for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; ++$attempt ) {
			$now = time();
			$value = array( 'token' => $token, 'created_at' => $now, 'expires_at' => $now + self::LOCK_LIFETIME );
			if ( add_option( $name, $value, '', false ) ) return $token;
			$current = get_option( $name, array() );
			$created_at = is_array( $current ) ? absint( $current['created_at'] ?? 0 ) : absint( $current );
			$expires_at = is_array( $current ) ? absint( $current['expires_at'] ?? 0 ) : 0;
			if ( ! $expires_at && $created_at ) $expires_at = $created_at + self::LOCK_LIFETIME;
			if ( ! $created_at || $expires_at <= $now ) {
				$this->delete_lock_if_unchanged( $name, $current );
				continue;
			}
			if ( $attempt + 1 < self::LOCK_ATTEMPTS ) usleep( 50000 );
		}
		return '';
	}

	private function extend_lock( string $key, string $token ): bool {
		$name = self::LOCK_OPTION_PREFIX . substr( hash( 'sha256', $key ), 0, 32 );
		$current = get_option( $name, array() );
		$current_token = is_array( $current ) ? (string) ( $current['token'] ?? '' ) : '';
		if ( ! $current_token || ! hash_equals( $current_token, $token ) ) return false;

		$extended = $current;
		$extended['expires_at'] = time() + self::DELIVERY_LOCK_LIFETIME;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-update prevents extending a replacement lock.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $extended ), $name, maybe_serialize( $current ) ) );
		wp_cache_delete( $name, 'options' );
		return 1 === $updated;
	}

	private function owns_lock( string $key, string $token ): bool {
		$name = self::LOCK_OPTION_PREFIX . substr( hash( 'sha256', $key ), 0, 32 );
		$current = get_option( $name, array() );
		$current_token = is_array( $current ) ? (string) ( $current['token'] ?? '' ) : '';
		$created_at = is_array( $current ) ? absint( $current['created_at'] ?? 0 ) : 0;
		$expires_at = is_array( $current ) ? absint( $current['expires_at'] ?? 0 ) : 0;
		if ( ! $expires_at && $created_at ) $expires_at = $created_at + self::LOCK_LIFETIME;
		return $current_token && hash_equals( $current_token, $token ) && $expires_at > time();
	}

	private function release_lock( string $key, string $token ): void {
		$name = self::LOCK_OPTION_PREFIX . substr( hash( 'sha256', $key ), 0, 32 );
		$current = get_option( $name, array() );
		$current_token = is_array( $current ) ? (string) ( $current['token'] ?? '' ) : '';
		if ( $current_token && hash_equals( $current_token, $token ) ) $this->delete_lock_if_unchanged( $name, $current );
	}

	private function delete_lock_if_unchanged( string $name, $expected ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete prevents a stale owner from deleting a replacement lock.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, maybe_serialize( $expected ) ) );
		wp_cache_delete( $name, 'options' );
		return 1 === $deleted;
	}

	private function new_generation(): string {
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	private function audit_schedule_failure( array $job, string $reason ): void {
		$this->audit->write( absint( $job['registration_id'] ?? 0 ), sanitize_key( $job['audit_type'] ?? '' ), 'schedule_failed', array( 'event_id' => absint( $job['event_id'] ?? 0 ), 'email_type' => sanitize_key( $job['type'] ?? '' ), 'reason' => sanitize_text_field( $reason ) ) );
	}

	private function audit_terminal_failure( array $job, string $reason ): void {
		$this->audit->write( absint( $job['registration_id'] ?? 0 ), sanitize_key( $job['audit_type'] ?? '' ), 'failed_terminal', array( 'event_id' => absint( $job['event_id'] ?? 0 ), 'email_type' => sanitize_key( $job['type'] ?? '' ), 'reason' => sanitize_text_field( $reason ) ) );
	}

	private function error_message( \Throwable $error ): string {
		$message = sanitize_text_field( $error->getMessage() );
		return $message ? substr( $message, 0, 500 ) : 'Email delivery raised an unexpected error.';
	}
}
