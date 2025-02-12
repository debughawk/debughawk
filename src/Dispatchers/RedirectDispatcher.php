<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\NeedsInitiatingInterface;

class RedirectDispatcher extends Dispatcher implements NeedsInitiatingInterface {
	public function init(): void {
		add_filter( 'wp_redirect', [ $this, 'filter_wp_redirect' ], 9999, 2 );
	}

	public function filter_wp_redirect( $location, $status ) {
		$metrics = $this->gather_and_encrypt();

		// Send non-blocking HTTP request to ingest endpoint
		error_log( print_r( $metrics, true ) );

		return $location;
	}
}