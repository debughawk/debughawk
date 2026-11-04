<?php
/**
 * Plugin Name: DebugHawk Database Drop-In
 * Plugin URI: https://debughawk.com/
 * Description: Database drop-in for DebugHawk to capture database metrics and slow queries.
 * Author: DebugHawk
 * Version: 1.3.0
 * Requires PHP: 7.4
 * Requires WP: 6.3
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_USER' ) ) {
	return;
}

if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

if ( php_sapi_name() === 'cli' ) {
	return;
}

if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
	return;
}

if ( is_admin() ) {
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'upgrade-plugin' ) {
		return;
	}

	if ( isset( $_POST['action'] ) && $_POST['action'] === 'update-plugin' ) {
		return;
	}
}

class DebugHawkDB extends wpdb {
	/**
	 * Running totals for every query, read by DebugHawk's database collector.
	 * Kept as totals rather than in $this->queries, so memory stays flat
	 * however many queries a request runs.
	 */
	public array $debughawk_stats = [
		'count' => 0,
		'time'  => 0.0,
		'types' => [],
	];

	/**
	 * Queries slower than $debughawk_slow_threshold seconds are passed to this
	 * callable. Both are set by the plugin once it loads. It's called directly,
	 * not through an action, so slow query backtraces match those from
	 * $wpdb->queries and fast queries never touch the hook system.
	 *
	 * @var callable|null
	 */
	public $debughawk_slow_query_handler = null;

	public float $debughawk_slow_threshold = 0.0;

	/**
	 * Time spent in queries run from inside the current query, such as the
	 * charset lookup wpdb makes for queries containing non-ASCII text.
	 */
	protected float $debughawk_nested_time = 0.0;

	public function query( $query ) {
		// Time each call locally: wpdb's shared timer is reset by nested queries.
		$outer_nested_time           = $this->debughawk_nested_time;
		$this->debughawk_nested_time = 0.0;

		$start   = microtime( true );
		$result  = parent::query( $query );
		$elapsed = microtime( true ) - $start;

		// Each query's time excludes its nested queries, which are counted
		// separately, so the times add up to the real total.
		$query_time                  = $elapsed - $this->debughawk_nested_time;
		$this->debughawk_nested_time = $outer_nested_time + $elapsed;

		$type = preg_match( '/^\s*([A-Za-z]+)\b/', $query, $matches ) ? strtoupper( $matches[1] ) : 'OTHER';

		if ( ! isset( $this->debughawk_stats['types'][ $type ] ) ) {
			$this->debughawk_stats['types'][ $type ] = [
				'count' => 0,
				'time'  => 0.0,
			];
		}

		$this->debughawk_stats['count'] ++;
		$this->debughawk_stats['time']                    += $query_time;
		$this->debughawk_stats['types'][ $type ]['count'] ++;
		$this->debughawk_stats['types'][ $type ]['time']  += $query_time;

		if ( $this->debughawk_slow_query_handler !== null && $query_time > $this->debughawk_slow_threshold ) {
			( $this->debughawk_slow_query_handler )( $query, $query_time, $start );
		}

		return $result;
	}
}

$wpdb = new DebugHawkDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
