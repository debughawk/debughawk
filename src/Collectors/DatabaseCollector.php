<?php

namespace DebugHawk\Collectors;

use DebugHawk\Backtrace;
use DebugHawk\NeedsInitiatingInterface;
use DebugHawk\QueryNormalizer;
use DebugHawk\Util;

class DatabaseCollector extends Collector implements NeedsInitiatingInterface {
	public string $key = 'database';

	/** Normalized SQL longer than this is truncated before sending. */
	protected const MAX_SQL_LENGTH = 2048;

	protected ?float $execution_time = null;

	/** The slowest queries, up to slow_queries_limit of them. */
	protected ?array $slow_queries = null;

	/** How many slow queries ran, including ones not kept. */
	protected ?int $slow_query_count = null;

	/** The fastest kept query's duration, once the list is full. */
	protected ?float $fastest_kept_time = null;

	protected ?array $query_types = null;

	/** Whether the DebugHawk drop-in is keeping running totals of every query. */
	protected bool $uses_dropin_stats = false;

	/** Read once from the config, as the fallback path checks it for every query. */
	protected float $slow_threshold_seconds = 0.0;

	protected int $slow_queries_limit = 0;

	/**
	 * Distinct query shapes tracked per request. Beyond this, new shapes are
	 * ignored, so memory stays flat on requests such as imports.
	 */
	protected const MAX_FINGERPRINTS = 1000;

	/** Whether every query reaches this collector, so duplicates can be found. */
	protected bool $tracks_duplicates = false;

	/** How many times a query shape must run to count as a duplicate. */
	protected int $duplicate_threshold = 0;

	protected int $duplicate_queries_limit = 0;

	/**
	 * Every query shape seen so far, keyed by fingerprint hash: how many times
	 * it ran, for how long in total, a hash of the first run's exact SQL and
	 * whether every run so far used that exact SQL.
	 *
	 * @var array<int, array{count: int, time: float, sql_hash: int, identical: bool}>
	 */
	protected array $fingerprints = [];

	/**
	 * The run that took each shape over the duplicate threshold, keyed by
	 * fingerprint hash, with its SQL and who ran it. The SQL is raw, or already
	 * normalized (and hashed) when it's long, so a long query isn't held raw.
	 * Each time the count doubles after that, the caller is checked again, and
	 * same_caller is false once a later run came from somewhere else.
	 *
	 * @var array<int, array{sql: string, sql_hash: ?string, type: string, backtrace: ?array, same_caller: bool}>
	 */
	protected array $duplicate_samples = [];

	/** Raw SQL longer than this is normalized when sampled, rather than kept raw. */
	protected const MAX_RAW_SAMPLE_LENGTH = 8192;

	public function init(): void {
		global $wpdb;

		$this->slow_threshold_seconds  = (float) $this->config->slow_queries_threshold / 1000;
		$this->slow_queries_limit      = max( 0, (int) $this->config->slow_queries_limit );
		$this->duplicate_threshold     = max( 2, (int) $this->config->duplicate_queries_threshold );
		$this->duplicate_queries_limit = max( 0, (int) $this->config->duplicate_queries_limit );

		if ( isset( $wpdb ) && property_exists( $wpdb, 'debughawk_query_handler' ) ) {
			// The drop-in keeps running totals itself and passes every query here.
			$this->uses_dropin_stats       = true;
			$this->tracks_duplicates       = true;
			$wpdb->debughawk_query_handler = [ $this, 'track_query' ];

			return;
		}

		if ( isset( $wpdb ) && property_exists( $wpdb, 'debughawk_stats' ) ) {
			// A drop-in from before 1.4.0, which only passes slow queries here,
			// until it's updated on the next admin page load.
			$this->uses_dropin_stats            = true;
			$wpdb->debughawk_slow_threshold     = $this->slow_threshold_seconds;
			$wpdb->debughawk_slow_query_handler = [ $this, 'track_slow_query' ];

			return;
		}

		// Older DebugHawk drop-ins, Query Monitor's drop-in and SAVEQUERIES store
		// every query in $wpdb->queries and pass each one through this filter,
		// but wpdb only runs it when SAVEQUERIES is on (Query Monitor's drop-in
		// turns it on). Without it, duplicates are unknown rather than none.
		$this->tracks_duplicates = defined( 'SAVEQUERIES' ) && SAVEQUERIES;

		add_filter( 'log_query_custom_data', [ $this, 'track_logged_query' ], 10, 5 );
	}

	public function gather(): array {
		global $wpdb;

		if ( $this->uses_dropin_stats ) {
			$this->read_dropin_stats();
		} else {
			$this->parse_queries();
		}

		$slow_queries = null;

		if ( is_array( $this->slow_queries ) ) {
			$slow_queries = $this->slow_queries;

			usort( $slow_queries, static function ( $a, $b ) {
				return $b['duration_ms'] <=> $a['duration_ms'];
			} );

			$slow_queries = array_map( [ $this, 'normalize_slow_query' ], $slow_queries );
		}

		$duplicates = $this->tracks_duplicates ? $this->gather_duplicates() : null;

		return [
			'duration_ms'           => $this->execution_time,
			'query_count'           => $wpdb->num_queries ?? null,
			'slow_query_count'      => $this->slow_query_count,
			'slow_queries'          => $slow_queries,
			'duplicate_query_count' => $duplicates['count'] ?? null,
			'duplicate_queries'     => $duplicates['queries'] ?? null,
			'query_types'           => $this->query_types,
		];
	}

	/**
	 * Called by the drop-in for every query.
	 */
	public function track_query( string $query, float $query_time, float $query_start ): void {
		// Compare in seconds, so fast queries skip the conversion.
		if ( $query_time > $this->slow_threshold_seconds ) {
			$this->add_slow_query( $query, Util::seconds_to_milliseconds( $query_time ), $query_start );
		}

		$this->track_duplicate( $query, $query_time );
	}

	/**
	 * Called by drop-ins from before 1.4.0 for queries over the slow threshold.
	 */
	public function track_slow_query( string $query, float $query_time, float $query_start ): void {
		$this->add_slow_query( $query, Util::seconds_to_milliseconds( $query_time ), $query_start );
	}

	/**
	 * Called for every query when queries are stored in $wpdb->queries.
	 */
	public function track_logged_query( $query_data, $query, $query_time, $query_callstack, $query_start ) {
		$this->track_query( (string) $query, (float) $query_time, (float) $query_start );

		return $query_data;
	}

	/**
	 * Rough SQL with literal values replaced, so queries that differ only in
	 * their values (such as the post ID in a loop) share a fingerprint. It's
	 * cheap enough to run on every query, unlike QueryNormalizer, and is never
	 * sent: only used for grouping.
	 */
	public static function fingerprint( string $query ): string {
		$fingerprint = preg_replace(
			[
				// Quoted strings, with backslash escapes or doubled quotes.
				"/'(?:[^'\\\\]++|\\\\.|'')*+'/s",
				'/"(?:[^"\\\\]++|\\\\.|"")*+"/s',
				// Numbers, but not digits inside identifiers such as wp_2_posts.
				'/\b(?:0x[0-9a-f]+|\d+(?:\.\d+)?(?:e[+-]?\d+)?)\b/i',
				// Lists of values, such as IN (?, ?, ?), however long.
				'/\(\s*\?(?:\s*,\s*\?)*+\s*\)/',
				// Rows of values in a multi-row INSERT.
				'/\(\?\)(?:\s*,\s*\(\?\))++/',
			],
			[ '?', '?', '?', '(?)', '(?)' ],
			$query
		);

		// preg_replace() fails on PCRE limits, so fall back to the exact SQL.
		return $fingerprint ?? $query;
	}

	/**
	 * Counts every query by its fingerprint. When a shape reaches the
	 * duplicate threshold, that run is sampled with a backtrace, so it's
	 * attributed to whatever is running it repeatedly, typically a loop.
	 */
	protected function track_duplicate( string $query, float $query_time ): void {
		$key      = crc32( self::fingerprint( $query ) );
		$sql_hash = crc32( $query );

		if ( ! isset( $this->fingerprints[ $key ] ) ) {
			if ( count( $this->fingerprints ) >= self::MAX_FINGERPRINTS ) {
				return;
			}

			$this->fingerprints[ $key ] = [
				'count'     => 0,
				'time'      => 0.0,
				'sql_hash'  => $sql_hash,
				'identical' => true,
			];
		}

		$fingerprint = &$this->fingerprints[ $key ];

		$fingerprint['count'] ++;
		$fingerprint['time'] += $query_time;

		if ( $fingerprint['identical'] && $fingerprint['sql_hash'] !== $sql_hash ) {
			$fingerprint['identical'] = false;
		}

		$count = $fingerprint['count'];

		// Sample at the threshold, then each time the count doubles: a few
		// backtraces per shape, however long the loop.
		if ( $count < $this->duplicate_threshold || $count % $this->duplicate_threshold !== 0 ) {
			return;
		}

		$doublings = intdiv( $count, $this->duplicate_threshold );

		if ( ( $doublings & ( $doublings - 1 ) ) !== 0 ) {
			return;
		}

		if ( isset( $this->duplicate_samples[ $key ] ) ) {
			$sample = &$this->duplicate_samples[ $key ];

			if ( $sample['same_caller'] ) {
				$backtrace = new Backtrace();

				$sample['same_caller'] = $backtrace->parse() === $sample['backtrace'];
			}

			return;
		}

		$query     = trim( $query );
		$backtrace = new Backtrace();
		$sql_hash  = null;

		if ( strlen( $query ) > self::MAX_RAW_SAMPLE_LENGTH ) {
			$sql      = QueryNormalizer::normalize( $query );
			$sql_hash = md5( $sql );
			$query    = Util::truncate( $sql, self::MAX_SQL_LENGTH );
		}

		$this->duplicate_samples[ $key ] = [
			'sql'         => $query,
			'sql_hash'    => $sql_hash,
			'type'        => $this->determine_query_type( $query ),
			'backtrace'   => $backtrace->parse(),
			'same_caller' => true,
		];
	}

	/**
	 * The query shapes that ran most often, at least as many times as the
	 * duplicate threshold, with normalized SQL and their total time, and how
	 * many times repeated queries ran in total.
	 *
	 * @return array{count: int, queries: array}
	 */
	protected function gather_duplicates(): array {
		$fingerprints = array_intersect_key( $this->fingerprints, $this->duplicate_samples );

		// Most runs first, then most time.
		uasort( $fingerprints, static function ( $a, $b ) {
			return ( $b['count'] <=> $a['count'] ) ?: ( $b['time'] <=> $a['time'] );
		} );

		$queries = [];

		// Only the ones sent are normalized, as normalizing is slower.
		foreach ( array_slice( $fingerprints, 0, $this->duplicate_queries_limit, true ) as $key => $fingerprint ) {
			$sample = $this->duplicate_samples[ $key ];

			if ( $sample['sql_hash'] === null ) {
				$sql                = QueryNormalizer::normalize( $sample['sql'] );
				$sample['sql']      = Util::truncate( $sql, self::MAX_SQL_LENGTH );
				$sample['sql_hash'] = md5( $sql );
			}

			$queries[] = [
				'sql'         => $sample['sql'],
				'sql_hash'    => $sample['sql_hash'],
				'type'        => $sample['type'],
				'count'       => $fingerprint['count'],
				'duration_ms' => Util::seconds_to_milliseconds( $fingerprint['time'] ),
				// Every run used the exact same SQL, which usually means a
				// result that could have been cached, rather than a loop.
				'identical'   => $fingerprint['identical'],
				'backtrace'   => $sample['backtrace'],
				// Every sampled run came from the backtrace's caller. When false,
				// other code runs this query too and the backtrace is just one of them.
				'same_caller' => $sample['same_caller'],
			];
		}

		return [
			// Every run of a repeated query, including those not sent, as
			// slow_query_count counts every slow query.
			'count'   => array_sum( array_column( $fingerprints, 'count' ) ),
			'queries' => $queries,
		];
	}

	/**
	 * Keeps only the slowest queries, so memory stays flat and queries that
	 * won't be sent don't pay for a backtrace.
	 */
	protected function add_slow_query( string $query, float $query_time, $query_start ): void {
		$limit = $this->slow_queries_limit;

		$this->slow_query_count = ( $this->slow_query_count ?? 0 ) + 1;

		if ( is_null( $this->slow_queries ) ) {
			$this->slow_queries = [];
		}

		// Once the list is full, only a slower query can get in. On a tie, the
		// earlier query stays, as it did when the whole list was sorted.
		if ( count( $this->slow_queries ) >= $limit && ( $limit === 0 || $query_time <= $this->fastest_kept_time ) ) {
			return;
		}

		$query      = trim( $query );
		$backtrace  = new Backtrace();
		$query_type = $this->determine_query_type( $query );

		$this->slow_queries[] = [
			// Raw SQL, normalized in gather() before anything is sent.
			'sql'         => $query,
			'start_time'  => $query_start,
			'duration_ms' => $query_time,
			'type'        => $query_type,
			'backtrace'   => $backtrace->parse(),
		];

		if ( count( $this->slow_queries ) > $limit ) {
			$this->drop_fastest_slow_query();
		}

		if ( count( $this->slow_queries ) === $limit ) {
			$this->fastest_kept_time = min( array_column( $this->slow_queries, 'duration_ms' ) );
		}
	}

	/**
	 * On a tie, drops the most recent query, which a stable sort puts last.
	 */
	protected function drop_fastest_slow_query(): void {
		$fastest = null;

		foreach ( $this->slow_queries as $index => $slow_query ) {
			if ( $fastest === null || $slow_query['duration_ms'] <= $this->slow_queries[ $fastest ]['duration_ms'] ) {
				$fastest = $index;
			}
		}

		unset( $this->slow_queries[ $fastest ] );

		$this->slow_queries = array_values( $this->slow_queries );
	}

	/**
	 * Replaces literal values so they never leave the site.
	 */
	protected function normalize_slow_query( array $slow_query ): array {
		$sql = QueryNormalizer::normalize( $slow_query['sql'] );

		$slow_query['sql']      = Util::truncate( $sql, self::MAX_SQL_LENGTH );
		$slow_query['sql_hash'] = md5( $sql );

		return $slow_query;
	}

	protected function read_dropin_stats(): void {
		global $wpdb;

		$stats = $wpdb->debughawk_stats;

		if ( $stats['count'] === 0 ) {
			return;
		}

		$this->execution_time = Util::seconds_to_milliseconds( $stats['time'] );
		$this->query_types    = array_map( static function ( array $type ): array {
			return [
				'count' => $type['count'],
				'time'  => Util::seconds_to_milliseconds( $type['time'] ),
			];
		}, $stats['types'] );
	}

	protected function parse_queries(): void {
		global $wpdb;

		if ( $wpdb->queries ) {
			$execution_time = 0;

			$this->query_types = [];

			foreach ( $wpdb->queries as $query ) {
				$query_time     = Util::seconds_to_milliseconds( $query[1] );
				$execution_time += $query_time;

				$query_type = $this->determine_query_type( $query[0] );
				$this->count_query_type( $query_type, $query_time );
			}

			$this->execution_time = $execution_time;
		}
	}

	protected function determine_query_type( string $sql ): string {
		if ( preg_match( '/^\s*([A-Za-z]+)\b/', $sql, $matches ) ) {
			return strtoupper( $matches[1] );
		}

		return 'OTHER';
	}

	protected function count_query_type( $type, $query_time ): void {
		if ( ! isset( $this->query_types[ $type ] ) ) {
			$this->query_types[ $type ] = [
				'count' => 0,
				'time'  => 0,
			];
		}

		$this->query_types[ $type ]['count'] ++;
		$this->query_types[ $type ]['time'] += $query_time;
	}
}
