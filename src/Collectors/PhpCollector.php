<?php

namespace DebugHawk\Collectors;

use DebugHawk\Util;

class PhpCollector extends Collector {
	public string $key = 'php';
	
	public function gather(): array {
		return [
			'duration_ms'  => Util::seconds_to_milliseconds( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ),
			'memory_usage' => memory_get_peak_usage(),
			'version'      => phpversion(),
		];
	}
}