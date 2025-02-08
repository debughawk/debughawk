<?php

namespace DebugHawk;

abstract class Util {
	public static function seconds_to_milliseconds( float $seconds, $precision = 2 ): float {
		return round( $seconds * 1000, $precision );
	}
}