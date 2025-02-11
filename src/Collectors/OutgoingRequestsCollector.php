<?php

namespace DebugHawk\Collectors;

use DebugHawk\Backtrace;
use DebugHawk\Util;

class OutgoingRequestsCollector implements CollectorInterface {
	protected const BACKTRACE_FUNCTIONS = [
		'wp_safe_remote_request',
		'wp_safe_remote_get',
		'wp_safe_remote_post',
		'wp_safe_remote_head',
		'wp_remote_request',
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_head',
	];

	protected const BACKTRACE_IGNORED_FUNCTIONS = [
		'wp_remote_fopen',
		'download_url',
	];

	protected array $requests = [];

	public function init(): void {
		add_filter( 'http_request_args', [ $this, 'make_request_traceable' ], 9999, 2 );
		add_filter( 'pre_http_request', [ $this, 'track_request_start' ], 9999, 3 );
		add_filter( 'http_response', [ $this, 'track_request_end' ], 9999, 3 );
	}

	public function collect(): array {
		return [
			'request_count' => count( $this->requests ),
			'requests'      => array_map( static function ( $request ) {
				$request['url'] = strlen( $request['url'] ) > 256
					? substr( $request['url'], 0, 256 )
					: $request['url'];

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
			'url'         => $url,
			'blocking'    => $args['blocking'],
			'start_time'  => microtime( true ),
			'http_method' => $args['method'] ?? 'GET',
			'backtrace'   => $backtrace->find( self::BACKTRACE_FUNCTIONS )
			                           ->ignoring( self::BACKTRACE_IGNORED_FUNCTIONS )
			                           ->parse(),
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