<?php

namespace DebugHawk\Tests;

use DebugHawk\Collectors\DatabaseCollector;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

/**
 * Drives the collector the way the drop-in does: every query is passed to
 * track_query() with its time in seconds.
 */
class DatabaseCollectorTest extends TestCase {
	protected ?string $plugin_dir = null;

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['test_hooks'], $GLOBALS['wp_plugin_paths'] );

		if ( $this->plugin_dir !== null ) {
			unlink( "$this->plugin_dir/example.php" );
			rmdir( $this->plugin_dir );
		}
	}

	/**
	 * @dataProvider same_shape_queries
	 */
	public function test_queries_differing_only_in_values_share_a_fingerprint( string $a, string $b ): void {
		$this->assertSame( DatabaseCollector::fingerprint( $a ), DatabaseCollector::fingerprint( $b ) );
	}

	public function same_shape_queries(): array {
		return [
			'ids'              => [ 'SELECT * FROM wp_posts WHERE ID = 1', 'SELECT * FROM wp_posts WHERE ID = 4821' ],
			'strings'          => [ "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'", "SELECT option_value FROM wp_options WHERE option_name = 'home'" ],
			'escaped quotes'   => [ "SELECT * FROM wp_users WHERE user_login = 'o\\'brien'", "SELECT * FROM wp_users WHERE user_login = 'smith'" ],
			'doubled quotes'   => [ "SELECT * FROM wp_users WHERE user_login = 'o''brien'", "SELECT * FROM wp_users WHERE user_login = 'smith'" ],
			'double quotes'    => [ 'SELECT * FROM wp_users WHERE user_login = "admin"', 'SELECT * FROM wp_users WHERE user_login = "editor"' ],
			'in lists'         => [ 'SELECT * FROM wp_postmeta WHERE post_id IN (1, 2, 3)', 'SELECT * FROM wp_postmeta WHERE post_id IN (7)' ],
			'string in lists'  => [ "SELECT * FROM wp_terms WHERE slug IN ('a','b')", "SELECT * FROM wp_terms WHERE slug IN ('news')" ],
			'multi-row insert' => [ "INSERT INTO wp_log (a, b) VALUES (1, 'x'), (2, 'y')", "INSERT INTO wp_log (a, b) VALUES (3, 'z')" ],
			'decimals'         => [ 'UPDATE wp_stats SET score = 1.5 WHERE id = 2', 'UPDATE wp_stats SET score = 10.25 WHERE id = 3' ],
			'limits'           => [ 'SELECT * FROM wp_posts LIMIT 0, 10', 'SELECT * FROM wp_posts LIMIT 20, 10' ],
		];
	}

	/**
	 * @dataProvider different_shape_queries
	 */
	public function test_queries_with_different_shapes_have_different_fingerprints( string $a, string $b ): void {
		$this->assertNotSame( DatabaseCollector::fingerprint( $a ), DatabaseCollector::fingerprint( $b ) );
	}

	public function different_shape_queries(): array {
		return [
			'tables'                 => [ 'SELECT * FROM wp_posts WHERE ID = 1', 'SELECT * FROM wp_users WHERE ID = 1' ],
			'multisite tables'       => [ 'SELECT * FROM wp_2_posts WHERE ID = 1', 'SELECT * FROM wp_3_posts WHERE ID = 1' ],
			'columns'                => [ 'SELECT * FROM wp_posts WHERE ID = 1', 'SELECT * FROM wp_posts WHERE post_parent = 1' ],
			'aliases with digits'    => [ 'SELECT t1.ID FROM wp_posts t1', 'SELECT t2.ID FROM wp_posts t2' ],
			'query types'            => [ 'SELECT * FROM wp_options', 'DELETE FROM wp_options' ],
		];
	}

	public function test_a_query_run_in_a_loop_is_reported_once_with_every_run_counted(): void {
		$collector = $this->collector();

		foreach ( range( 1, 40 ) as $post_id ) {
			$collector->track_query( "SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = $post_id", 0.001, microtime( true ) );
		}

		$data = $collector->gather();

		$this->assertSame( 40, $data['duplicate_query_count'] );
		$this->assertCount( 1, $data['duplicate_queries'] );

		$duplicate = $data['duplicate_queries'][0];

		$this->assertSame( 40, $duplicate['count'] );
		$this->assertEqualsWithDelta( 40, $duplicate['duration_ms'], 0.01 );
		$this->assertSame( 'SELECT', $duplicate['type'] );
		$this->assertFalse( $duplicate['identical'] );

		// Normalized as slow queries are, so the IDs never leave the site.
		$this->assertStringNotContainsString( '5', $duplicate['sql'] );
		$this->assertSame( md5( $duplicate['sql'] ), $duplicate['sql_hash'] );
		$this->assertArrayHasKey( 'backtrace', $duplicate );
	}

	public function test_the_exact_same_query_repeated_is_flagged_as_identical(): void {
		$collector = $this->collector();

		foreach ( range( 1, 6 ) as $run ) {
			$collector->track_query( "SELECT option_value FROM wp_options WHERE option_name = 'my_plugin_settings'", 0.0005, microtime( true ) );
		}

		$this->assertTrue( $collector->gather()['duplicate_queries'][0]['identical'] );
	}

	public function test_queries_below_the_threshold_are_not_reported(): void {
		$collector = $this->collector( [ 'duplicate_queries_threshold' => 5 ] );

		foreach ( range( 1, 4 ) as $post_id ) {
			$collector->track_query( "SELECT * FROM wp_posts WHERE ID = $post_id", 0.001, microtime( true ) );
		}

		$data = $collector->gather();

		$this->assertSame( 0, $data['duplicate_query_count'] );
		$this->assertSame( [], $data['duplicate_queries'] );
	}

	public function test_a_single_run_is_never_a_duplicate(): void {
		$collector = $this->collector( [ 'duplicate_queries_threshold' => 1 ] );

		$collector->track_query( 'SELECT * FROM wp_posts WHERE ID = 1', 0.001, microtime( true ) );

		$this->assertSame( 0, $collector->gather()['duplicate_query_count'] );
	}

	public function test_sends_the_most_repeated_queries_first_up_to_the_limit(): void {
		$collector = $this->collector( [ 'duplicate_queries_limit' => 3 ] );

		// Five shapes, run 10 to 50 times, in a shuffled order.
		$shapes = [ 10 => 'wp_a', 20 => 'wp_b', 30 => 'wp_c', 40 => 'wp_d', 50 => 'wp_e' ];
		$runs   = [];

		foreach ( $shapes as $count => $table ) {
			foreach ( range( 1, $count ) as $id ) {
				$runs[] = "SELECT * FROM $table WHERE id = $id";
			}
		}

		shuffle( $runs );

		foreach ( $runs as $query ) {
			$collector->track_query( $query, 0.001, microtime( true ) );
		}

		$data = $collector->gather();

		// Every run of all five is counted, and the three most repeated are sent.
		$this->assertSame( 150, $data['duplicate_query_count'] );
		$this->assertSame( [ 50, 40, 30 ], array_column( $data['duplicate_queries'], 'count' ) );
	}

	public function test_equal_counts_are_ordered_by_total_time(): void {
		$collector = $this->collector();

		foreach ( range( 1, 5 ) as $id ) {
			$collector->track_query( "SELECT * FROM wp_fast WHERE id = $id", 0.001, microtime( true ) );
			$collector->track_query( "SELECT * FROM wp_slow WHERE id = $id", 0.010, microtime( true ) );
		}

		$sql = array_column( $this->collector_data( $collector ), 'sql' );

		$this->assertStringContainsString( 'wp_slow', $sql[0] );
	}

	public function test_slow_queries_are_still_tracked_from_the_same_handler(): void {
		$collector = $this->collector( [ 'slow_queries_threshold' => 50 ] );

		$collector->track_query( 'SELECT * FROM wp_posts WHERE ID = 1', 0.010, microtime( true ) );
		$collector->track_query( 'SELECT SLEEP(1)', 0.200, microtime( true ) );

		$data = $collector->gather();

		$this->assertSame( 1, $data['slow_query_count'] );
		$this->assertEqualsWithDelta( 200, $data['slow_queries'][0]['duration_ms'], 0.01 );
	}

	public function test_stops_tracking_new_shapes_once_the_cap_is_reached(): void {
		$collector = $this->collector();

		// 1,000 distinct one-off shapes fill the cap.
		foreach ( range( 1, 1000 ) as $table ) {
			$collector->track_query( "SELECT * FROM wp_table_$table", 0.001, microtime( true ) );
		}

		// A new shape after that isn't tracked, however often it runs.
		foreach ( range( 1, 10 ) as $id ) {
			$collector->track_query( "SELECT * FROM wp_late WHERE id = $id", 0.001, microtime( true ) );
		}

		// But shapes already being tracked keep counting.
		foreach ( range( 1, 10 ) as $run ) {
			$collector->track_query( 'SELECT * FROM wp_table_1', 0.001, microtime( true ) );
		}

		$data = $collector->gather();

		$this->assertSame( 11, $data['duplicate_query_count'] );
		$this->assertSame( 11, $data['duplicate_queries'][0]['count'] );
	}

	public function test_drop_ins_from_before_1_4_0_only_pass_slow_queries(): void {
		$GLOBALS['wpdb'] = $this->wpdb( false );

		$collector = new DatabaseCollector( TestConfig::make() );
		$collector->init();

		$this->assertNull( $GLOBALS['wpdb']->debughawk_query_handler ?? null );
		$this->assertSame( [ $collector, 'track_slow_query' ], $GLOBALS['wpdb']->debughawk_slow_query_handler );

		// Duplicates can't be found, so they're sent as unknown rather than none.
		$data = $collector->gather();

		$this->assertNull( $data['duplicate_query_count'] );
		$this->assertNull( $data['duplicate_queries'] );
	}

	public function test_a_query_run_from_two_places_is_not_flagged_as_one_caller(): void {
		$collector = $this->collector();

		[ $run, $run_elsewhere ] = $this->plugin_call_sites();

		// One place runs it in a loop, then another runs it after the threshold.
		foreach ( range( 1, 9 ) as $id ) {
			$run( $collector, $id );
		}

		$run_elsewhere( $collector, 10 );

		$this->assertFalse( $this->collector_data( $collector )[0]['same_caller'] );
	}

	public function test_a_query_run_in_one_loop_is_flagged_as_one_caller(): void {
		$collector = $this->collector();

		[ $run ] = $this->plugin_call_sites();

		foreach ( range( 1, 40 ) as $id ) {
			$run( $collector, $id );
		}

		$duplicate = $this->collector_data( $collector )[0];

		$this->assertTrue( $duplicate['same_caller'] );
		$this->assertSame( 'example', $duplicate['backtrace']['slug'] );
	}

	public function test_long_queries_are_normalized_and_hashed_in_full_when_sampled(): void {
		$collector = $this->collector();
		$columns   = implode( ', ', array_map( static fn( $i ) => "column_$i", range( 1, 1000 ) ) );

		foreach ( range( 1, 5 ) as $id ) {
			$query = "SELECT $columns FROM wp_big WHERE secret = 'secret-$id'";
			$collector->track_query( $query, 0.001, microtime( true ) );
		}

		$duplicate = $this->collector_data( $collector )[0];

		// The hash is of the whole normalized query, as it is for short ones,
		// not of the truncated SQL that's sent.
		$this->assertSame( md5( "SELECT $columns FROM wp_big WHERE secret = ?" ), $duplicate['sql_hash'] );
		$this->assertSame( 2048, strlen( $duplicate['sql'] ) );
		$this->assertSame( 'SELECT', $duplicate['type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_duplicates_are_unknown_without_a_drop_in_or_saved_queries(): void {
		$GLOBALS['wpdb'] = (object) [ 'queries' => [], 'num_queries' => 0 ];

		$collector = new DatabaseCollector( TestConfig::make() );
		$collector->init();

		// wpdb never runs the filter without SAVEQUERIES, so no duplicates
		// doesn't mean there were none.
		$this->assertNull( $collector->gather()['duplicate_query_count'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_finds_duplicates_when_queries_are_logged_instead(): void {
		define( 'SAVEQUERIES', true );

		$GLOBALS['wpdb'] = (object) [ 'queries' => [], 'num_queries' => 0 ];

		$collector = new DatabaseCollector( TestConfig::make() );
		$collector->init();

		$this->assertArrayHasKey( 'log_query_custom_data', $GLOBALS['test_hooks'] );

		foreach ( range( 1, 5 ) as $id ) {
			$this->assertSame( [ 'custom' ], $collector->track_logged_query( [ 'custom' ], "SELECT * FROM wp_posts WHERE ID = $id", 0.001, '', microtime( true ) ) );
		}

		$this->assertSame( 5, $collector->gather()['duplicate_queries'][0]['count'] );
	}

	protected function collector( array $config = [] ): DatabaseCollector {
		$GLOBALS['wpdb'] = $this->wpdb();

		$collector = new DatabaseCollector( TestConfig::make( $config ) );
		$collector->init();

		$this->assertSame( [ $collector, 'track_query' ], $GLOBALS['wpdb']->debughawk_query_handler );

		return $collector;
	}

	/**
	 * Two functions that run the same query, from different lines of a file
	 * attributed to a plugin, as tests can't be: they're inside DebugHawk.
	 *
	 * @return callable[]
	 */
	protected function plugin_call_sites(): array {
		$dir = sys_get_temp_dir() . '/debughawk-plugin-' . getmypid();

		mkdir( $dir );
		$this->plugin_dir = $dir;

		$query = 'static function ( $collector, $id ) { $collector->track_query( "SELECT * FROM wp_posts WHERE ID = $id", 0.001, microtime( true ) ); }';

		file_put_contents( "$dir/example.php", "<?php\nreturn [\n$query,\n$query,\n];\n" );

		$GLOBALS['wp_plugin_paths'] = [ WP_PLUGIN_DIR . '/example' => realpath( $dir ) ];

		return require "$dir/example.php";
	}

	protected function collector_data( DatabaseCollector $collector ): array {
		return $collector->gather()['duplicate_queries'];
	}

	/**
	 * A stand-in for the DebugHawk drop-in's wpdb.
	 */
	protected function wpdb( bool $current = true ): object {
		$wpdb = $current
			? new class {
				public array $debughawk_stats = [ 'count' => 0, 'time' => 0.0, 'types' => [] ];
				public $debughawk_query_handler = null;
				public $debughawk_slow_query_handler = null;
				public float $debughawk_slow_threshold = 0.0;
				public int $num_queries = 0;
			}
			: new class {
				public array $debughawk_stats = [ 'count' => 0, 'time' => 0.0, 'types' => [] ];
				public $debughawk_slow_query_handler = null;
				public float $debughawk_slow_threshold = 0.0;
				public int $num_queries = 0;
			};

		return $wpdb;
	}
}
