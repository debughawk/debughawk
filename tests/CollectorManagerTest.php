<?php

namespace DebugHawk\Tests;

use DebugHawk\CollectorManager;
use DebugHawk\Collectors\Collector;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

class CollectorManagerTest extends TestCase {
	public function test_a_failing_collector_does_not_stop_the_others(): void {
		$config = TestConfig::make();

		$working = new class( $config ) extends Collector {
			public string $key = 'working';

			public function gather(): array {
				return [ 'ok' => true ];
			}
		};

		// An Error, not an Exception, as a TypeError or a third-party fatal would be.
		$failing = new class( $config ) extends Collector {
			public string $key = 'failing';

			public function gather(): array {
				throw new \Error( 'Call to undefined method' );
			}
		};

		$manager = ( new CollectorManager() )->add( $failing )->add( $working );

		$this->assertSame( [ 'failing' => null, 'working' => [ 'ok' => true ] ], $manager->gather() );
	}
}
