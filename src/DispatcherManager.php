<?php

namespace DebugHawk;

use DebugHawk\Dispatchers\Dispatcher;

class DispatcherManager {
	protected array $dispatchers = [];

	public function add( Dispatcher $dispatcher ): DispatcherManager {
		$this->dispatchers[] = $dispatcher;

		return $this;
	}

	public function init(): DispatcherManager {
		foreach ( $this->dispatchers as $dispatcher ) {
			if ( $dispatcher instanceof NeedsInitiatingInterface ) {
				$dispatcher->init();
			}
		}

		return $this;
	}
}