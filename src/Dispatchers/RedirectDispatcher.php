<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\NeedsInitiatingInterface;
use Exception;

class RedirectDispatcher extends Dispatcher implements NeedsInitiatingInterface {
	public function init(): void {
		if ( ! $this->should_send_redirect_metrics() ) {
			return;
		}

		add_filter( 'shutdown', [ $this, 'send_redirect_metrics' ], 9 );
	}

	public function send_redirect_metrics(): void {
		if ( ! $this->collectors->request->is_redirect() || $this->is_ignored_uri() ) {
			return;
		}

		$metrics = $this->gather_and_encrypt();

		try {
			$args = apply_filters( 'debughawk_send_redirect_metrics_args', [
				'body'     => json_encode( [ 'server' => $metrics ] ),
				'headers'  => [
					'Connection'   => 'keep-alive',
					'Content-Type' => 'application/json',
				],
				'blocking' => false,
				'timeout'  => 0.01,
			] );

			wp_remote_post( $this->config->dispatcherEndpoint( 'redirect' ), $args );
		} catch ( Exception $e ) {
			error_log( 'DebugHawk error sending redirect metrics: ' . $e->getMessage() );
		}
	}

	public function should_send_redirect_metrics() {
		return apply_filters( 'debughawk_should_send_redirect_metrics', $this->config->trace_redirects && $this->config->is_within_sample_range() );
	}

	protected function is_ignored_uri(): bool {
		$ignored_uris = apply_filters( 'debughawk_ignored_redirect_uris', [
			'/favicon.ico',
			'/wp-login.php',
		] );

		return in_array( $_SERVER['REQUEST_URI'] ?? '', $ignored_uris, false );
	}
}