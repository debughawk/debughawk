<?php

namespace DebugHawk\Collectors;

class PageCacheCollector implements CollectorInterface {
	public function collect(): array {
		return [
			'identifier' => uniqid(),
			'timestamp'  => time(),
		];
	}
}