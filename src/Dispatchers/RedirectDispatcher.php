<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\NeedsInitiatingInterface;

class RedirectDispatcher extends Dispatcher implements NeedsInitiatingInterface {
	public function init(): void {
		add_filter( 'wp_redirect', [ $this, 'send_redirect_metrics' ], 9999 );
	}

	public function send_redirect_metrics( $location ) {
		$metrics = $this->gather_and_encrypt();

		$args = apply_filters( 'debughawk_send_redirect_metrics_args', [
			'body'    => json_encode( [ 'server' => $metrics ] ),
			'headers' => [
				'Connection'   => 'keep-alive',
				'Content-Type' => 'application/json',
			],
			'timeout' => 0.01,
		], $location );

		wp_remote_post( $this->config->dispatcherEndpoint( 'redirect' ), $args );

		return $location;
	}
}