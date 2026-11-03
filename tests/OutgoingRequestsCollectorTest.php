<?php

namespace DebugHawk\Tests;

use DebugHawk\Collectors\OutgoingRequestsCollector;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class OutgoingRequestsCollectorTest extends TestCase {
	public function test_keeps_the_slowest_requests_and_counts_them_all(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		// 30 finished requests taking 1 to 30 ms, in a shuffled order.
		$durations = range( 1, 30 );
		shuffle( $durations );

		foreach ( $durations as $ms ) {
			$this->request( $collector, "https://api.example.com/$ms", $ms );
		}

		// One that failed, so it never finishes.
		$this->request( $collector, 'https://api.example.com/timeout', null );

		$data = $collector->gather();

		// All 31 are counted, and the 20 slowest are sent, slowest first. The
		// failed one has no duration, so it's sent only when there's room.
		$this->assertSame( 31, $data['request_count'] );
		$this->assertSame( range( 30, 11 ), array_map( static function ( $request ) {
			return (int) round( $request['duration_ms'] );
		}, $data['requests'] ) );

		// Memory holds the 20 slowest finished requests plus the unfinished one.
		$this->assertCount( 21, $this->requests( $collector ) );
	}

	public function test_requests_without_a_duration_go_last(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		$this->request( $collector, 'https://api.example.com/fire-and-forget', null );
		$this->request( $collector, 'https://api.example.com/slow', 500 );

		$urls = array_column( $collector->gather()['requests'], 'url' );

		$this->assertSame( [ 'https://api.example.com/slow', 'https://api.example.com/fire-and-forget' ], $urls );
	}

	private function request( OutgoingRequestsCollector $collector, string $url, ?int $ms ): void {
		$args = $collector->make_request_traceable( [ 'blocking' => true, 'method' => 'GET' ], $url );
		$collector->track_request_start( false, $args, $url );

		if ( $ms === null ) {
			return;
		}

		// Backdate the start so the request took exactly $ms.
		$requests = $this->requests( $collector );
		$requests[ $args['_debughawk_request_id'] ]['start_time'] = microtime( true ) - $ms / 1000;
		$this->set_requests( $collector, $requests );

		$collector->track_request_end( [ 'response' => [ 'code' => 200 ] ], $args, $url );
	}

	private function requests( OutgoingRequestsCollector $collector ): array {
		$property = new ReflectionProperty( OutgoingRequestsCollector::class, 'requests' );
		$property->setAccessible( true );

		return $property->getValue( $collector );
	}

	private function set_requests( OutgoingRequestsCollector $collector, array $requests ): void {
		$property = new ReflectionProperty( OutgoingRequestsCollector::class, 'requests' );
		$property->setAccessible( true );
		$property->setValue( $collector, $requests );
	}
}
