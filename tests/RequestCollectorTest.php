<?php

namespace DebugHawk\Tests;

use DebugHawk\Collectors\RequestCollector;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

class RequestCollectorTest extends TestCase {
	public function test_timestamp_is_a_number_in_milliseconds(): void {
		$before = (int) floor( microtime( true ) * 1000 );

		$timestamp = ( new RequestCollector( TestConfig::make() ) )->gather()['timestamp_ms'];

		$this->assertIsInt( $timestamp );
		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThan( $before + 1000, $timestamp );
	}
}
