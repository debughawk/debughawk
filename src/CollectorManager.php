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

	/**
	 * Runs every collector. One that fails is sent as null rather than
	 * stopping the rest, since this runs while the page is being output.
	 */
	public function gather(): array {
		$data = [];

		foreach ( $this->collectors as $collector ) {
			try {
				$data[ $collector->key ] = $collector->gather();
			} catch ( \Throwable $e ) {
				$data[ $collector->key ] = null;

				Util::log_error( sprintf( 'The %s collector failed: %s', $collector->key, $e->getMessage() ) );
			}
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