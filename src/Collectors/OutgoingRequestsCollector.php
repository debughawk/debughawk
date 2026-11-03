<?php

namespace DebugHawk\Collectors;

use DebugHawk\Backtrace;
use DebugHawk\NeedsInitiatingInterface;
use DebugHawk\Util;

class OutgoingRequestsCollector extends Collector implements NeedsInitiatingInterface {
	public string $key = 'outgoing_requests';

	/**
	 * Only the slowest requests are sent, so the payload stays within
	 * sendBeacon's 64 KB limit however many requests a page makes.
	 */
	protected const MAX_REQUESTS = 20;

	protected array $requests = [];

	/** How many requests were made, including ones not kept. */
	protected int $request_count = 0;

	/**
	 * Requests that have started but not finished, newest last, each with the
	 * URL and method used to match its end.
	 *
	 * @var array<int, array{key: int, url: string, method: string}>
	 */
	protected array $in_flight = [];

	/**
	 * Request arguments are never changed: another plugin may key a response
	 * cache on them. Starting last means a request another plugin answers
	 * (with a mock or a cached response) is never timed, and ending first
	 * means other plugins' http_api_debug callbacks aren't timed with it.
	 */
	public function init(): void {
		add_filter( 'pre_http_request', [ $this, 'track_request_start' ], PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', [ $this, 'track_request_end' ], PHP_INT_MIN, 5 );
	}

	public function gather(): array {
		$requests = array_values( $this->requests );

		// Slowest first. Non-blocking requests have no duration, since they
		// don't hold up the page, so they go last.
		usort( $requests, static function ( $a, $b ) {
			return ( $b['duration_ms'] ?? -1 ) <=> ( $a['duration_ms'] ?? -1 );
		} );

		return [
			'request_count' => $this->request_count,
			'requests'      => array_map( static function ( $request ) {
				$request['url'] = Util::truncate( $request['url'], 256 );

				return $request;
			}, array_slice( $requests, 0, self::MAX_REQUESTS ) ),
		];
	}

	public function track_request_start( $preempt, $args, $url ) {
		// Another plugin answered it, so it never goes over the network.
		if ( $preempt !== false ) {
			return $preempt;
		}

		$key    = ++ $this->request_count;
		$method = $args['method'] ?? 'GET';

		$backtrace = new Backtrace();

		$this->requests[ $key ] = [
			'url'         => Util::redact_url( (string) $url ),
			'blocking'    => $args['blocking'] ?? true,
			'start_time'  => microtime( true ),
			'http_method' => $method,
			'backtrace'   => $backtrace->parse(),
		];

		$this->in_flight[] = [
			'key'    => $key,
			'url'    => (string) $url,
			'method' => $method,
		];

		return $preempt;
	}

	/**
	 * Runs on http_api_debug, which fires for every outcome. Failed blocking
	 * requests, such as timeouts, hold up the page for as long as they take
	 * but never reach the http_response filter, so they had no duration.
	 *
	 * Non-blocking requests return straight away without slowing the page,
	 * so they're left without a duration.
	 */
	public function track_request_end( $response, $context, $class, $args, $url ): void {
		$key = $this->finish_in_flight( (string) $url, $args['method'] ?? 'GET' );

		if ( $key === null || ! isset( $this->requests[ $key ] ) || empty( $args['blocking'] ) ) {
			return;
		}

		$duration = microtime( true ) - $this->requests[ $key ]['start_time'];

		$this->requests[ $key ]['duration_ms'] = Util::seconds_to_milliseconds( $duration );

		if ( is_wp_error( $response ) ) {
			// Such as "cURL error 28: Operation timed out after 5001 milliseconds".
			$this->requests[ $key ]['error'] = Util::truncate( $response->get_error_message(), 256 );
		} else {
			$this->requests[ $key ]['http_status'] = wp_remote_retrieve_response_code( $response );
		}

		$this->drop_fastest_finished_request();
	}

	/**
	 * Finds which in-flight request has ended. Requests through WP_Http never
	 * overlap, since each call returns only once its request is done, so it's
	 * the newest one. Preferring a request with the same URL and method means
	 * an unusual interleaving can't pair an end with the wrong start.
	 *
	 * WordPress can empty or change the URL while validating it, before
	 * failing the request, so with no URL match the newest request with the
	 * same method is used.
	 */
	protected function finish_in_flight( string $url, string $method ): ?int {
		$match = null;

		for ( $index = count( $this->in_flight ) - 1; $index >= 0; $index -- ) {
			if ( $this->in_flight[ $index ]['method'] !== $method ) {
				continue;
			}

			if ( $this->in_flight[ $index ]['url'] === $url ) {
				$match = $index;
				break;
			}

			$match = $match ?? $index;
		}

		if ( $match === null ) {
			return null;
		}

		$key = $this->in_flight[ $match ]['key'];

		array_splice( $this->in_flight, $match, 1 );

		return $key;
	}

	/**
	 * Keeps memory flat on requests that make many HTTP calls, such as
	 * imports: once more than MAX_REQUESTS have finished, the fastest goes.
	 */
	protected function drop_fastest_finished_request(): void {
		$finished = array_filter( $this->requests, static function ( $request ) {
			return isset( $request['duration_ms'] );
		} );

		if ( count( $finished ) <= self::MAX_REQUESTS ) {
			return;
		}

		uasort( $finished, static function ( $a, $b ) {
			return $a['duration_ms'] <=> $b['duration_ms'];
		} );

		unset( $this->requests[ array_key_first( $finished ) ] );
	}
}