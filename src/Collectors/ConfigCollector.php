<?php

namespace DebugHawk\Collectors;

class ConfigCollector extends Collector {
	public string $key = 'config';

	public function gather(): array {
		return [
			'sample_rate'            => $this->config->sample_rate,
			'slow_queries_threshold' => $this->config->slow_queries_threshold,
		];
	}
}