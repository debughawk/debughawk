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
	 * Removes values that could be secret or personal from a URL before it's
	 * sent: query values (API keys, password reset keys, order keys, search
	 * terms, emails) become ?, credentials are removed and the fragment is
	 * dropped. Query keys are kept, so the URL is still recognisable.
	 *
	 * For example, https://user:pass@api.example.com/v1/items?key=abc&page=2#top
	 * becomes https://api.example.com/v1/items?key=?&page=?
	 */
	public static function redact_url( string $url ): string {
		$hash = strpos( $url, '#' );

		if ( $hash !== false ) {
			$url = substr( $url, 0, $hash );
		}

		$url = (string) preg_replace( '#^([a-z][a-z0-9+.-]*://)[^/?@]*@#i', '$1', $url );

		$question = strpos( $url, '?' );

		if ( $question === false ) {
			return $url;
		}

		$parameters = explode( '&', substr( $url, $question + 1 ) );

		foreach ( $parameters as $index => $parameter ) {
			$equals = strpos( $parameter, '=' );

			if ( $equals !== false ) {
				$parameters[ $index ] = substr( $parameter, 0, $equals ) . '=?';
			}
		}

		return substr( $url, 0, $question + 1 ) . implode( '&', $parameters );
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