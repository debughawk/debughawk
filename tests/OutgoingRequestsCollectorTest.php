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

		// A non-blocking one, which has no duration.
		$this->request( $collector, 'https://api.example.com/fire-and-forget', null, false );

		$data = $collector->gather();

		// All 31 are counted, and the 20 slowest are sent, slowest first. The
		// non-blocking one has no duration, so it's sent only when there's room.
		$this->assertSame( 31, $data['request_count'] );
		$this->assertSame( range( 30, 11 ), array_map( static function ( $request ) {
			return (int) round( $request['duration_ms'] );
		}, $data['requests'] ) );

		// Memory holds the 20 slowest finished requests plus the non-blocking one.
		$this->assertCount( 21, $this->requests( $collector ) );
	}

	public function test_requests_without_a_duration_go_last(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		$this->request( $collector, 'https://api.example.com/fire-and-forget', 5, false );
		$this->request( $collector, 'https://api.example.com/slow', 500 );

		$urls = array_column( $collector->gather()['requests'], 'url' );

		$this->assertSame( [ 'https://api.example.com/slow', 'https://api.example.com/fire-and-forget' ], $urls );
	}

	public function test_failed_blocking_requests_get_their_duration_and_error(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		$this->request( $collector, 'https://api.example.com/ok', 300 );
		$this->request( $collector, 'https://api.example.com/timeout', 5000, true, new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' ) );

		$requests = $collector->gather()['requests'];

		// The timeout held the page for 5 seconds, so it's the slowest.
		$this->assertSame( 'https://api.example.com/timeout', $requests[0]['url'] );
		$this->assertEqualsWithDelta( 5000, $requests[0]['duration_ms'], 50 );
		$this->assertSame( 'cURL error 28: Operation timed out after 5001 milliseconds', $requests[0]['error'] );
		$this->assertArrayNotHasKey( 'http_status', $requests[0] );

		$this->assertSame( 200, $requests[1]['http_status'] );
		$this->assertArrayNotHasKey( 'error', $requests[1] );
	}

	public function test_non_blocking_requests_have_no_duration(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		$this->request( $collector, 'https://api.example.com/fire-and-forget', 5, false );

		$request = $collector->gather()['requests'][0];

		$this->assertFalse( $request['blocking'] );
		$this->assertArrayNotHasKey( 'duration_ms', $request );
	}

	/**
	 * Runs a request through the collector's hooks as WP_Http would. $ms is
	 * how long it took, or null if it never finished (preempted, say).
	 */
	private function request( OutgoingRequestsCollector $collector, string $url, ?int $ms, bool $blocking = true, $response = null ): void {
		$args = $collector->make_request_traceable( [ 'blocking' => $blocking, 'method' => 'GET' ], $url );
		$collector->track_request_start( false, $args, $url );

		if ( $ms === null ) {
			return;
		}

		// Backdate the start so the request took exactly $ms.
		$requests = $this->requests( $collector );
		$requests[ $args['_debughawk_request_id'] ]['start_time'] = microtime( true ) - $ms / 1000;
		$this->set_requests( $collector, $requests );

		$collector->track_request_end( $response ?? [ 'response' => [ 'code' => 200 ] ], 'response', 'WpOrg\Requests\Requests', $args, $url );
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
