<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\CollectorManager;
use DebugHawk\Config;
use DebugHawk\Util;

abstract class Dispatcher {
	protected Config $config;

	protected CollectorManager $collectors;

	public function __construct( Config $config, CollectorManager $collectors ) {
		$this->config     = $config;
		$this->collectors = $collectors;
	}

	protected function gather_and_encrypt(): string {
		try {
			// Invalid UTF-8 (from a URL or SQL, say) is replaced rather than
			// failing the whole payload.
			$payload = json_encode( $this->collectors->gather(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );

			$algo  = 'aes-128-ctr';
			$ivlen = openssl_cipher_iv_length( $algo );
			$iv    = openssl_random_pseudo_bytes( $ivlen );

			$encrypted = openssl_encrypt( $payload, $algo, $this->config->secret, OPENSSL_RAW_DATA, $iv );

			return base64_encode( $iv . $encrypted );
		} catch ( \Throwable $e ) {
			// Never break the page DebugHawk is measuring.
			Util::log_error( 'Could not prepare metrics: ' . $e->getMessage() );

			return '';
		}
	}
}