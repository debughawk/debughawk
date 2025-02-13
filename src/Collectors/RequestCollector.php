<?php

namespace DebugHawk\Collectors;

use DebugHawk\NeedsInitiatingInterface;

class RequestCollector extends Collector implements NeedsInitiatingInterface {
	public string $key = 'request';

	protected ?string $redirect_location = null;

	public function init(): void {
		add_filter( 'wp_redirect', [ $this, 'capture_redirect' ], 9998 );
	}

	public function gather(): array {
		$scheme = is_ssl() ? 'https' : 'http';

		return array_filter( [
			'url'               => sprintf( '%s://%s%s', $scheme, $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ),
			'method'            => $_SERVER['REQUEST_METHOD'],
			'status'            => http_response_code(),
			'redirect_location' => $this->redirect_location,
			'identifier'        => uniqid(),
			'timestamp_ms'      => date_create()->format( 'Uv' ),
		] );
	}

	public function capture_redirect( $location ) {
		$this->redirect_location = $location;

		return $location;
	}
}