<?php

namespace DebugHawk\Collectors;

use DebugHawk\Util;

class RequestCollector extends Collector {
	public string $key = 'request';

	public function gather(): array {
		$scheme = is_ssl() ? 'https' : 'http';

		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';
		$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) : '';

		return array_filter( [
			'url'          => Util::redact_url( sprintf( '%s://%s%s', $scheme, $host, $request_uri ) ),
			'method'       => $method,
			'status'       => http_response_code(),
			'identifier'   => uniqid(),
			// A number, like the browser's timestamp_ms, not a string.
			'timestamp_ms' => (int) floor( microtime( true ) * 1000 ),
		] );
	}
}