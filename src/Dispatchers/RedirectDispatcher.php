<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\NeedsInitiatingInterface;

class RedirectDispatcher extends Dispatcher implements NeedsInitiatingInterface {
	public function init(): void {
		if ( ! $this->should_send_redirect_metrics() ) {
			return;
		}

		add_filter( 'wp_redirect', [ $this, 'send_redirect_metrics' ], 9999 );
	}

	public function send_redirect_metrics( $location ) {
		if ( $this->is_ignored_uri() ) {
			return $location;
		}

		$metrics = $this->gather_and_encrypt();

		$args = apply_filters( 'debughawk_send_redirect_metrics_args', [
			'body'     => json_encode( [ 'server' => $metrics ] ),
			'headers'  => [
				'Connection'   => 'keep-alive',
				'Content-Type' => 'application/json',
			],
			'blocking' => false,
			'timeout'  => 0.01,
		], $location );

		wp_remote_post( $this->config->dispatcherEndpoint( 'redirect' ), $args );

		return $location;
	}

	public function should_send_redirect_metrics() {
		return apply_filters( 'debughawk_should_send_redirect_metrics', $this->config->is_within_sample_range() );
	}

	protected function is_ignored_uri(): bool {
		$ignored_uris = apply_filters( 'debughawk_ignored_redirect_uris', [
			'/favicon.ico',
		] );

		return in_array( $_SERVER['REQUEST_URI'], $ignored_uris, false );
	}
}