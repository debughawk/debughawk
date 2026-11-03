<?php

namespace DebugHawk\Collectors;

use DebugHawk\Backtrace;
use DebugHawk\NeedsInitiatingInterface;
use DebugHawk\Util;

class OutgoingRequestsCollector extends Collector implements NeedsInitiatingInterface {
	public string $key = 'outgoing_requests';

	protected array $requests = [];

	public function init(): void {
		add_filter( 'http_request_args', [ $this, 'make_request_traceable' ], 9999, 2 );
		add_filter( 'pre_http_request', [ $this, 'track_request_start' ], 9999, 3 );
		add_filter( 'http_response', [ $this, 'track_request_end' ], 9999, 3 );
	}

	public function gather(): array {
		return [
			'request_count' => count( $this->requests ),
			'requests'      => array_map( static function ( $request ) {
				$request['url'] = Util::truncate( $request['url'], 256 );

				return $request;
			}, $this->requests ),
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

		return $response;
	}
}