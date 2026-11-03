<?php

namespace DebugHawk;

abstract class Util {
	public static function seconds_to_milliseconds( float $seconds, $precision = 2 ): float {
		return round( $seconds * 1000, $precision );
	}

	/**
	 * Logs an error when WP_DEBUG is on, so failures are visible while
	 * debugging without filling production logs.
	 */
	public static function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'DebugHawk: ' . $message );
		}
	}
}