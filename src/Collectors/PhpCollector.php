<?php

namespace DebugHawk\Collectors;

use DebugHawk\Util;

class PhpCollector extends Collector {
	public string $key = 'php';
	
	public function gather(): array {
		$request_time = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0;
		
		return [
			'duration_ms'  => Util::seconds_to_milliseconds( microtime( true ) - $request_time ),
			'memory_usage' => memory_get_peak_usage(),
			'version'      => phpversion(),
		];
	}
}