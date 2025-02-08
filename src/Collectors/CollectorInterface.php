<?php

namespace DebugHawk\Collectors;

interface CollectorInterface {
	public function collect(): array;
}