<?php
/** Bounded dashboard/query regressions with a limit/offset/order-aware CCT adapter. */
namespace {
	define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error { public function __construct( ...$args ) {} }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( (string) $value ); }
	function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
	function wp_timezone() { return new \DateTimeZone( 'Australia/Sydney' ); }
	function get_posts( $args ) { return array(); }
	function check( $condition, $label ) { if ( ! $condition ) throw new \RuntimeException( $label ); echo 'PASS ' . $label . PHP_EOL; }
	class Synthetic_DB {
		public $rows = 50000;
		public $rows_returned = 0;
		public $queries = array();
		public $mixed = false;
		public $fail_at = null;
		public $ignore_offset = false;
		public function set_format_flag( $format ) {}
		private function row( int $id ): array {
			return array( '_ID' => $id, 'event_id' => $this->mixed && 0 === $id % 2 ? 20 : 10, 'registration_status' => $this->mixed ? ( 0 === $id % 3 ? 'expression_of_interest' : '' ) : 'pending', 'number_of_attendees' => 2, 'first_name' => 'Guest', 'email' => 'guest' . $id . '@example.test', 'phone' => '(0400) 123-456', 'registration_date' => '2026-09-27 10:00:00' );
		}
		public function query( $args, $limit, $offset, $order, ...$unused ) {
			$this->queries[] = array( 'limit' => $limit, 'offset' => $offset, 'order' => $order );
			if ( null !== $this->fail_at && $offset >= $this->fail_at ) return new WP_Error( 'query_failed' );
			if ( $this->ignore_offset ) $offset = 0;
			$result = array();
			$matched = 0;
			$ascending = 'ASC' === ( $order[0]['order'] ?? '' );
			for ( $position = 0; $position < $this->rows; ++$position ) {
				$id = $ascending ? $position + 1 : $this->rows - $position;
				$row = $this->row( $id );
				foreach ( $args as $condition ) if ( (string) $row[ $condition['field'] ] !== (string) $condition['value'] ) continue 2;
				if ( $matched++ < $offset ) continue;
				$result[] = $row;
				if ( $limit && count( $result ) >= $limit ) break;
			}
			$this->rows_returned += count( $result );
			return $result;
		}
	}
}
namespace Jet_Engine\Modules\Custom_Content_Types {
	class Module {
		public static function instance() { return (object) array( 'manager' => new class { public function get_content_types( $slug ) { return (object) array( 'db' => $GLOBALS['cct_db'] ); } } ); }
	}
}
namespace HeartHub\EventRegistrations {
	class Settings { public function get( $key, $default = '' ) { return $default; } }
	require dirname( __DIR__ ) . '/includes/class-cct-repository.php';
	$GLOBALS['cct_db'] = new \Synthetic_DB();
	$db = $GLOBALS['cct_db'];
	$repository = new CCT_Repository( new Settings() );
	$page = $repository->dashboard( array( 'page' => 1, 'per_page' => 20 ) );
	\check( 20 === count( $page['items'] ) && 50000 === $page['total'] && 50000 === $page['stats']['pending'] && 100000 === $page['stats']['pending_places'], 'dashboard retains exact totals and attendee counts across 50000 records' );
	\check( 50000 === $db->rows_returned && 101 === count( $db->queries ) && array( 500 ) === array_values( array_unique( array_column( $db->queries, 'limit' ) ) ), 'one bounded scan replaces two unlimited queries; each read requests at most 500 records' );
	\check( '_ID' === $db->queries[0]['order'][1]['orderby'] && 'DESC' === $db->queries[0]['order'][1]['order'] && 50000 === $page['items'][0]['_ID'], 'equal registration dates use a deterministic numeric ID tie break' );
	$db->rows = 1005; $db->mixed = true; $db->queries = array(); $db->rows_returned = 0;
	$filters = array( 'page' => 2, 'per_page' => 20, 'event_id' => 10, 'status' => 'pending', 'search' => '0400123456', 'date_from' => '2026-09-27', 'date_to' => '2026-09-27', 'order' => 'asc' );
	$page = $repository->dashboard( $filters );
	$expected = array_values( array_filter( range( 1, 1005 ), static function ( $id ) { return 1 === $id % 2 && 0 !== $id % 3; } ) );
	\check( count( $expected ) === $page['total'] && array_slice( $expected, 20, 20 ) === array_column( $page['items'], '_ID' ), 'event, legacy Pending, formatted phone and date filters combine correctly across batch boundaries' );
	\check( 670 === $page['stats']['pending'] && 335 === $page['stats']['interest'], 'filtered page statistics still describe all registrations including legacy interest aliases' );
	$list = $repository->list( $filters );
	\check( ! isset( $list['stats'] ) && $list['total'] === $page['total'] && array_column( $list['items'], '_ID' ) === array_column( $page['items'], '_ID' ), 'export/list path omits global statistics without changing filtered page contents' );
	$db->fail_at = 500;
	\check( isset( $repository->dashboard( array() )['error'] ), 'mid-scan storage error rejects the entire response instead of returning partial totals' );
	$db->fail_at = null; $db->ignore_offset = true;
	\check( isset( $repository->dashboard( array() )['error'] ), 'adapter that ignores pagination fails explicitly instead of looping forever' );
	echo 'MEASURED 20-row dashboard page: 50000 rows read once in batches of at most 500. Exact global totals remain O(n); this is a workload count, not a live-site timing benchmark.' . PHP_EOL;
}
