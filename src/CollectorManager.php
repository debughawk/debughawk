<?php

namespace DebugHawk;

use DebugHawk\Collectors\Collector;

class CollectorManager {
	protected array $collectors = [];

	public function add( Collector $collector ): CollectorManager {
		$this->collectors[ $collector->key ] = $collector;

		return $this;
	}

	public function init(): CollectorManager {
		foreach ( $this->collectors as $collector ) {
			if ( $collector instanceof NeedsInitiatingInterface ) {
				$collector->init();
			}
		}

		return $this;
	}

	public function gather(): array {
		$data = [];

		foreach ( $this->collectors as $collector ) {
			$data[ $collector->key ] = $collector->gather();
		}

		return $data;
	}

	public function __get( string $name ): ?Collector {
		if ( array_key_exists( $name, $this->collectors ) ) {
			return $this->collectors[ $name ];
		}

		return null;
	}
}