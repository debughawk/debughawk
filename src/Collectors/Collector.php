<?php

namespace DebugHawk\Collectors;

use DebugHawk\Config;

abstract class Collector {
	protected Config $config;
	
	public string $key = 'collector';

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	abstract public function gather(): array;
}