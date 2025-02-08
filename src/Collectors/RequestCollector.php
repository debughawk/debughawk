<?php

namespace DebugHawk\Collectors;

class RequestCollector implements CollectorInterface {
	public function collect(): array {
		$scheme = is_ssl() ? 'https' : 'http';

		return [
			'url'    => sprintf( '%s://%s%s', $scheme, $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ),
			'method' => $_SERVER['REQUEST_METHOD'],
			'status' => http_response_code(),
		];
	}
}