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

	public function init(): void {
		global $wpdb;

		if ( isset( $wpdb ) && property_exists( $wpdb, 'debughawk_stats' ) ) {
			// The drop-in counts every query itself and only calls back for slow ones.
			$this->uses_dropin_stats        = true;
			$wpdb->debughawk_slow_threshold = (float) $this->config->slow_queries_threshold / 1000;

			add_action( 'debughawk_slow_query', [ $this, 'track_slow_query' ], 10, 3 );

			return;
		}

		// Older DebugHawk drop-ins, Query Monitor's drop-in and SAVEQUERIES store
		// every query in $wpdb->queries and pass each one through this filter.
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

		return [
			'duration_ms'      => $this->execution_time,
			'query_count'      => $wpdb->num_queries ?? null,
			'slow_query_count' => $this->slow_query_count,
			'slow_queries'     => $slow_queries,
			'query_types'      => $this->query_types,
		];
	}

	/**
	 * Called by the drop-in for queries over the slow threshold.
	 */
	public function track_slow_query( string $query, float $query_time, float $query_start ): void {
		$this->add_slow_query( $query, Util::seconds_to_milliseconds( $query_time ), $query_start );
	}

	/**
	 * Called for every query when queries are stored in $wpdb->queries.
	 */
	public function track_logged_query( $query_data, $query, $query_time, $query_callstack, $query_start ) {
		$query_time = Util::seconds_to_milliseconds( $query_time );

		if ( $query_time > $this->config->slow_queries_threshold ) {
			$this->add_slow_query( $query, $query_time, $query_start );
		}

		return $query_data;
	}

	/**
	 * Keeps only the slowest queries, so memory stays flat and queries that
	 * won't be sent don't pay for a backtrace.
	 */
	protected function add_slow_query( string $query, float $query_time, $query_start ): void {
		$limit = max( 0, (int) $this->config->slow_queries_limit );

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

		$slow_query['sql']      = strlen( $sql ) > self::MAX_SQL_LENGTH
			? substr( $sql, 0, self::MAX_SQL_LENGTH )
			: $sql;
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
