<?php
/** wp eval-file: actual competing processes, native wpdb and MariaDB metadata. */
if ( '1' !== getenv( 'HHERM_INTEGRATION_TEST' ) || ! defined( 'WP_CLI' ) || home_url() !== 'http://hherm.test' ) throw new RuntimeException( 'Disposable Docker environment required.' );
use HeartHub\EventRegistrations\Audit_Log;
use HeartHub\EventRegistrations\Capacity_Manager;
use HeartHub\EventRegistrations\Settings;
$checks = array();
$batches = array();
$events = array();
$registration_base = 700000000 + random_int( 0, 1000000 ) * 100;
$check = static function ( $condition, string $name ) use ( &$checks ): void {
    $checks[] = array( 'name' => $name, 'status' => $condition ? 'PASS' : 'FAIL' );
    echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . "\n";
    if ( ! $condition ) throw new RuntimeException( $name );
};
$parallel = static function ( string $mode, int $event, array $registrations, int $party ) use ( &$batches ): array {
    $barrier = sys_get_temp_dir() . '/hherm-capacity-' . bin2hex( random_bytes( 6 ) );
    $processes = array();
    try {
        foreach ( $registrations as $index => $registration ) {
            $arguments = array( PHP_BINARY, '/plugin/tests/integration/capacity-worker.php', $mode, $event, $registration, $party, $barrier, $index );
            $command = implode( ' ', array_map( 'escapeshellarg', $arguments ) );
            $process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
            if ( ! is_resource( $process ) ) throw new RuntimeException( 'Worker failed to start.' );
            fclose( $pipes[0] );
            $processes[] = array( $process, $pipes );
        }
        $deadline = microtime( true ) + 80;
        do {
            $ready = count( glob( $barrier . '.*.ready' ) );
            if ( $ready === count( $registrations ) ) break;
            usleep( 20000 );
        } while ( microtime( true ) < $deadline );
        if ( $ready !== count( $registrations ) ) throw new RuntimeException( 'Workers did not reach the concurrency barrier.' );
        touch( $barrier );
        $results = array();
        foreach ( $processes as $index => $entry ) {
            list( $process, $pipes ) = $entry;
            $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] );
            fclose( $pipes[1] ); fclose( $pipes[2] );
            $code = proc_close( $process ); unset( $processes[ $index ] );
            $result = json_decode( trim( $stdout ), true );
            if ( $code || trim( $stderr ) || ! is_array( $result ) ) throw new RuntimeException( 'Worker failed: ' . $stdout . $stderr );
            $results[] = $result;
        }
        $batches[] = array( 'mode' => $mode, 'workers' => $results );
        return $results;
    } finally {
        foreach ( $processes as $entry ) {
            proc_terminate( $entry[0] );
            foreach ( array( 1, 2 ) as $pipe ) if ( is_resource( $entry[1][ $pipe ] ) ) fclose( $entry[1][ $pipe ] );
            proc_close( $entry[0] );
        }
        foreach ( glob( $barrier . '.*.ready' ) as $file ) unlink( $file );
        if ( file_exists( $barrier ) ) unlink( $barrier );
    }
};
$audit = new Audit_Log();
$capacity = new Capacity_Manager( new Settings(), $audit );
$error = null;
try {
    Audit_Log::install();
    $event = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Disposable concurrency test', 'post_status' => 'draft' ), true );
    if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_message() );
    $events[] = $event;
    update_post_meta( $event, 'event_capacity', 3 ); update_post_meta( $event, 'current_event_capacity', 3 );
    $results = $parallel( 'reserve', $event, range( $registration_base, $registration_base + 7 ), 1 );
    $winners = array_values( array_filter( $results, static function ( $row ) { return 'ok' === $row['status']; } ) );
    $full = array_filter( $results, static function ( $row ) { return 'hherm_event_at_capacity' === $row['status']; } );
    $check( count( $winners ) === 3 && count( $full ) === 5, 'eight simultaneous requests allocate exactly three final seats' );
    wp_cache_delete( $event, 'post_meta' );
    $check( 0 === $capacity->remaining( $event ), 'real metadata never overbooks final seats' );
    $results = $parallel( 'release', $event, array_fill( 0, 8, $winners[0]['registration'] ), 1 );
    $check( array_unique( array_column( $results, 'status' ) ) === array( 'ok' ), 'eight repeated concurrent releases complete idempotently' );
    wp_cache_delete( $event, 'post_meta' );
    $check( 1 === $capacity->remaining( $event ), 'concurrent retries return one reserved seat exactly once' );
    $results = $parallel( 'reserve', $event, array_fill( 0, 8, $registration_base + 8 ), 1 );
    $check( array_unique( array_column( $results, 'status' ) ) === array( 'ok' ), 'duplicate simultaneous reservation requests are idempotent' );
    wp_cache_delete( $event, 'post_meta' );
    $check( 0 === $capacity->remaining( $event ), 'one duplicate reservation consumes only one seat' );
    $partial_event = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Disposable partial attendance test', 'post_status' => 'draft' ), true );
    if ( is_wp_error( $partial_event ) ) throw new RuntimeException( $partial_event->get_error_message() );
    $events[] = $partial_event;
    update_post_meta( $partial_event, 'event_capacity', 3 ); update_post_meta( $partial_event, 'current_event_capacity', 3 );
    $check( true === $capacity->ensure_reserved( $registration_base + 9, $partial_event, 3 ), 'partial attendance setup reserves three places in MariaDB' );
    $results = $parallel( 'partial', $partial_event, array_fill( 0, 8, $registration_base + 9 ), 2 );
    $check( array_unique( array_column( $results, 'status' ) ) === array( 'ok' ), 'simultaneous partial attendance retries complete idempotently' );
    wp_cache_delete( $partial_event, 'post_meta' );
    $check( 2 === $capacity->remaining( $partial_event ), 'parallel partial check-ins release the two unused places exactly once' );
    $check( false === get_option( 'hherm_capacity_lock_' . $event ) && false === get_option( 'hherm_capacity_lock_' . $partial_event ), 'real competing processes leave no capacity locks behind' );
} catch ( Throwable $failure ) {
    $error = $failure->getMessage();
    echo 'FAIL ' . $error . "\n";
} finally {
    global $wpdb;
    foreach ( $events as $event_id ) { wp_delete_post( $event_id, true ); delete_option( 'hherm_capacity_lock_' . $event_id ); }
    $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE registration_id BETWEEN %d AND %d', Audit_Log::table(), $registration_base, $registration_base + 9 ) );
    file_put_contents( '/results/capacity-concurrency.json', wp_json_encode( array( 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'database' => $wpdb->db_version(), 'assertions' => count( $checks ), 'status' => $error ? 'FAIL' : 'PASS', 'error' => $error, 'checks' => $checks, 'batches' => $batches ), JSON_PRETTY_PRINT ) . "\n" );
}
if ( $error ) WP_CLI::error( $error );
WP_CLI::success( 'Real MariaDB concurrency: ' . count( $checks ) . ' assertions passed across 32 competing PHP processes.' );
