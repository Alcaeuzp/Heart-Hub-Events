<?php
/** Child process for the disposable real-WordPress concurrency test. */
if ( '1' !== getenv( 'HHERM_INTEGRATION_TEST' ) || PHP_SAPI !== 'cli' ) exit( 2 );
require '/var/www/html/wp-load.php';
if ( home_url() !== 'http://hherm.test' ) exit( 2 );
$mode = $argv[1];
$event = (int) $argv[2];
$registration = (int) $argv[3];
$party = (int) $argv[4];
$barrier = $argv[5];
$worker = $argv[6];
$capacity = new \HeartHub\EventRegistrations\Capacity_Manager( new \HeartHub\EventRegistrations\Settings(), new \HeartHub\EventRegistrations\Audit_Log() );
touch( $barrier . '.' . $worker . '.ready' );
$deadline = microtime( true ) + 90;
while ( ! file_exists( $barrier ) && microtime( true ) < $deadline ) { clearstatcache(); usleep( 10000 ); }
if ( ! file_exists( $barrier ) ) exit( 3 );
$deadline = microtime( true ) + 20;
do {
    if ( 'reserve' === $mode ) $result = $capacity->ensure_reserved( $registration, $event, $party );
    elseif ( 'release' === $mode ) $result = $capacity->release( $registration, $event, $party );
    elseif ( 'partial' === $mode ) $result = $capacity->release_unused( $registration, $event, $party );
    else exit( 4 );
    if ( ! is_wp_error( $result ) || 'hherm_capacity_locked' !== $result->get_error_code() ) break;
    usleep( random_int( 1000, 20000 ) );
} while ( microtime( true ) < $deadline );
echo wp_json_encode( array( 'worker' => $worker, 'registration' => $registration, 'status' => is_wp_error( $result ) ? $result->get_error_code() : 'ok' ) ) . "\n";
