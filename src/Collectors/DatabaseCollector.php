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

	protected ?array $slow_queries = null;

	protected ?array $query_types = null;

	public function init(): void {
		add_filter( 'log_query_custom_data', [ $this, 'track_slow_queries' ], 10, 5 );
	}

	public function gather(): array {
		global $wpdb;

		$this->parse_queries();

		$slow_queries = null;

		if ( is_array( $this->slow_queries ) ) {
			usort( $this->slow_queries, static function ( $a, $b ) {
				return $b['duration_ms'] <=> $a['duration_ms'];
			} );

			// Only normalize the queries that are sent.
			$slow_queries = array_map(
				[ $this, 'normalize_slow_query' ],
				array_slice( $this->slow_queries, 0, $this->config->slow_queries_limit )
			);
		}

		return [
			'duration_ms'      => $this->execution_time,
			'query_count'      => $wpdb->num_queries ?? null,
			'slow_query_count' => is_array( $this->slow_queries )
				? count( $this->slow_queries )
				: null,
			'slow_queries'     => $slow_queries,
			'query_types'      => $this->query_types,
		];
	}

	public function track_slow_queries( $query_data, $query, $query_time, $query_callstack, $query_start ): array {
		$query_time = Util::seconds_to_milliseconds( $query_time );

		if ( $query_time > $this->config->slow_queries_threshold ) {
			$query = trim( $query );

			if ( is_null( $this->slow_queries ) ) {
				$this->slow_queries = [];
			}

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
		}

		return $query_data;
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
