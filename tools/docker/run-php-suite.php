<?php
/** Run every isolated PHP fixture in its own process, including PHP 7.4. */
$root = dirname( __DIR__, 2 );
$report = $argv[1] ?? '/results/php-results.json';
$results = array();
$run = static function ( string $kind, string $file, array $arguments ) use ( &$results, $root ): void {
    $command = escapeshellarg( PHP_BINARY ) . ' -d error_reporting=E_ALL -d display_errors=1';
    foreach ( $arguments as $argument ) $command .= ' ' . escapeshellarg( $argument );
    $lines = array();
    $start = microtime( true );
    exec( $command . ' 2>&1', $lines, $exit );
    $output = implode( "\n", $lines );
    preg_match_all( '/^PASS[: ]/m', $output, $matches );
    $assertions = count( $matches[0] );
    $bad = $exit !== 0 || preg_match( '/^(?:PHP )?(?:Warning|Notice|Deprecated|Fatal error|Parse error):|^SKIP\b/im', $output ) || ( 'PHP fixture' === $kind && ! $assertions );
    $status = $bad ? 'FAIL' : 'PASS';
    $results[] = array( 'kind' => $kind, 'file' => str_replace( $root . '/', '', $file ), 'status' => $status, 'exit_code' => $exit, 'assertions' => $assertions, 'duration_ms' => round( ( microtime( true ) - $start ) * 1000 ), 'output' => $output );
    echo $status . ' ' . $kind . ': ' . str_replace( $root . '/', '', $file ) . "\n";
    if ( $bad ) echo $output . "\n";
};
$syntax = array( $root . '/heart-hub-event-registration-manager.php', $root . '/uninstall.php' );
foreach ( array( 'includes', 'tests' ) as $directory ) {
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
        if ( $file->isFile() && 'php' === $file->getExtension() ) $syntax[] = $file->getPathname();
    }
}
sort( $syntax );
foreach ( $syntax as $file ) $run( 'PHP syntax', $file, array( '-l', $file ) );
foreach ( glob( $root . '/tests/*.php' ) as $file ) $run( 'PHP fixture', $file, array( $file ) );
$failed = count( array_filter( $results, static function ( $result ) { return 'PASS' !== $result['status']; } ) );
$summary = array( 'php' => PHP_VERSION, 'extensions' => get_loaded_extensions(), 'checks' => count( $results ), 'assertions' => array_sum( array_column( $results, 'assertions' ) ), 'failed' => $failed, 'results' => $results );
if ( false === file_put_contents( $report, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) exit( 2 );
echo 'PHP ' . PHP_VERSION . ': ' . count( $results ) . ' checks, ' . $summary['assertions'] . ' assertions, ' . $failed . " failures/skips.\n";
exit( $failed ? 1 : 0 );
