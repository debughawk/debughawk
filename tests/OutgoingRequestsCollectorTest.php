<?php

namespace DebugHawk\Tests;

use DebugHawk\Collectors\OutgoingRequestsCollector;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Drives the collector's hooks in the order WP_Http fires them:
 * pre_http_request, then http_api_debug once the request has finished.
 */
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
		$this->request( $collector, 'https://api.example.com/fire-and-forget', 1, false );

		$data = $collector->gather();

		// All 31 are counted, and the 20 slowest are sent, slowest first. The
		// non-blocking one has no duration, so it's sent only when there's room.
		$this->assertSame( 31, $data['request_count'] );
		$this->assertSame( range( 30, 11 ), $this->durations( $data['requests'] ) );

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

	public function test_hooks_in_last_and_out_first_without_touching_request_arguments(): void {
		$GLOBALS['test_hooks'] = [];

		( new OutgoingRequestsCollector( TestConfig::make() ) )->init();

		// No http_request_args filter, so request arguments (which another
		// plugin may key a response cache on) are never changed.
		$this->assertSame( [
			'pre_http_request' => [ [ 'priority' => PHP_INT_MAX, 'accepted_args' => 3 ] ],
			'http_api_debug'   => [ [ 'priority' => PHP_INT_MIN, 'accepted_args' => 5 ] ],
		], $GLOBALS['test_hooks'] );

		unset( $GLOBALS['test_hooks'] );
	}

	public function test_requests_another_plugin_answered_are_not_recorded(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );
		$cached    = [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];

		$this->assertSame( $cached, $collector->track_request_start( $cached, [ 'blocking' => true, 'method' => 'GET' ], 'https://api.example.com/cached' ) );
		$this->assertSame( 0, $collector->gather()['request_count'] );
	}

	public function test_a_request_made_while_another_is_in_flight(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		// A starts, B is made from inside a hook and finishes, then A finishes.
		$a = $this->start( $collector, 'https://api.example.com/outer' );
		$b = $this->start( $collector, 'https://api.example.com/inner' );
		$this->finish( $collector, $b, 50 );
		$this->finish( $collector, $a, 300 );

		$this->assertSame( [
			'https://api.example.com/outer' => 300,
			'https://api.example.com/inner' => 50,
		], $this->durations_by_url( $collector ) );
	}

	public function test_ends_out_of_order_pair_by_url(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		$a = $this->start( $collector, 'https://api.example.com/a' );
		$b = $this->start( $collector, 'https://api.example.com/b' );
		$this->finish( $collector, $a, 300 );
		$this->finish( $collector, $b, 50 );

		$this->assertSame( [
			'https://api.example.com/a' => 300,
			'https://api.example.com/b' => 50,
		], $this->durations_by_url( $collector ) );
	}

	public function test_a_url_wordpress_rejected_still_pairs_with_its_start(): void {
		$collector = new OutgoingRequestsCollector( TestConfig::make() );

		// wp_http_validate_url() turns the URL into false before the request fails.
		$request        = $this->start( $collector, 'http://localhost:9/internal' );
		$request['end'] = false;
		$this->finish( $collector, $request, 1, new \WP_Error( 'http_request_failed', 'A valid URL was not provided.' ) );

		$sent = $collector->gather()['requests'][0];

		$this->assertSame( 'http://localhost:9/internal', $sent['url'] );
		$this->assertSame( 'A valid URL was not provided.', $sent['error'] );
	}

	/**
	 * Runs a whole request through the collector's hooks.
	 */
	private function request( OutgoingRequestsCollector $collector, string $url, int $ms, bool $blocking = true, $response = null ): void {
		$this->finish( $collector, $this->start( $collector, $url, $blocking ), $ms, $response );
	}

	private function start( OutgoingRequestsCollector $collector, string $url, bool $blocking = true ): array {
		$args = [ 'blocking' => $blocking, 'method' => 'GET' ];

		$collector->track_request_start( false, $args, $url );

		$keys = array_keys( $this->requests( $collector ) );

		return [ 'key' => end( $keys ), 'args' => $args, 'url' => $url ];
	}

	/**
	 * Finishes a request, with its start backdated so it took exactly $ms.
	 */
	private function finish( OutgoingRequestsCollector $collector, array $request, int $ms, $response = null ): void {
		$requests = $this->requests( $collector );
		$requests[ $request['key'] ]['start_time'] = microtime( true ) - $ms / 1000;
		$this->set_requests( $collector, $requests );

		$url = array_key_exists( 'end', $request ) ? $request['end'] : $request['url'];

		$collector->track_request_end( $response ?? [ 'response' => [ 'code' => 200 ] ], 'response', 'WpOrg\Requests\Requests', $request['args'], $url );
	}

	private function durations( array $requests ): array {
		return array_map( static function ( $request ) {
			return (int) round( $request['duration_ms'] );
		}, $requests );
	}

	private function durations_by_url( OutgoingRequestsCollector $collector ): array {
		$requests = $collector->gather()['requests'];

		return array_combine( array_column( $requests, 'url' ), $this->durations( $requests ) );
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
