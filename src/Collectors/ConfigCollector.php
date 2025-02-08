<?php

namespace DebugHawk\Collectors;

use DebugHawk\Config;

class ConfigCollector implements CollectorInterface {
	protected Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function collect(): array {
		return [
			'sample_rate'            => $this->config->sample_rate,
			'slow_queries_threshold' => $this->config->slow_queries_threshold,
		];
	}
}