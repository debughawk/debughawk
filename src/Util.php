<?php

namespace DebugHawk;

abstract class Util {
	public static function seconds_to_milliseconds( float $seconds, $precision = 2 ): float {
		return round( $seconds * 1000, $precision );
	}

	/**
	 * Shortens a string to at most $max_bytes without splitting a UTF-8
	 * character, which would make it invalid.
	 */
	public static function truncate( string $string, int $max_bytes ): string {
		if ( strlen( $string ) <= $max_bytes ) {
			return $string;
		}

		$end = $max_bytes;

		// Back up past continuation bytes (10xxxxxx) to the start of the
		// character being cut. UTF-8 characters are at most 4 bytes.
		for ( $i = 0; $i < 3 && $end > 0 && ( ord( $string[ $end ] ) & 0xC0 ) === 0x80; $i ++ ) {
			$end --;
		}

		return substr( $string, 0, $end );
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