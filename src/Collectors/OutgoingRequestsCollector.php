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

	public function init(): void {
		add_filter( 'http_request_args', [ $this, 'make_request_traceable' ], 9999, 2 );
		add_filter( 'pre_http_request', [ $this, 'track_request_start' ], 9999, 3 );
		add_filter( 'http_response', [ $this, 'track_request_end' ], 9999, 3 );
	}

	public function gather(): array {
		$requests = array_values( $this->requests );

		// Slowest first. Requests without a duration (failed or non-blocking)
		// go last, since how long they took isn't known.
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

	public function make_request_traceable( $args, $url ) {
		$args['_debughawk_request_id'] = uniqid( 'http_', true );

		return $args;
	}

	public function track_request_start( $preempt, $args, $url ) {
		$request_id = $args['_debughawk_request_id'] ?? null;

		if ( ! $request_id ) {
			return $preempt;
		}

		$this->request_count ++;

		$backtrace = new Backtrace();

		$this->requests[ $request_id ] = [
			'url'         => Util::redact_url( $url ),
			'blocking'    => $args['blocking'],
			'start_time'  => microtime( true ),
			'http_method' => $args['method'] ?? 'GET',
			'backtrace'   => $backtrace->parse(),
		];

		return $preempt;
	}

	public function track_request_end( $response, $args, $url ) {
		$request_id = $args['_debughawk_request_id'] ?? null;

		if ( ! $request_id || ! isset( $this->requests[ $request_id ] ) ) {
			return $response;
		}

		$duration = microtime( true ) - $this->requests[ $request_id ]['start_time'];

		$this->requests[ $request_id ]['duration_ms'] = Util::seconds_to_milliseconds( $duration );
		$this->requests[ $request_id ]['http_status'] = wp_remote_retrieve_response_code( $response );

		$this->drop_fastest_finished_request();

		return $response;
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