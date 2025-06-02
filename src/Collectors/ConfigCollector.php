<?php

namespace DebugHawk\Collectors;

class ConfigCollector extends Collector {
	public string $key = 'config';

	public function gather(): array {
		return $this->config->to_array();
	}
}