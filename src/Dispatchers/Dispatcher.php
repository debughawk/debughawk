<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\CollectorManager;
use DebugHawk\Config;
use Exception;

abstract class Dispatcher {
	protected Config $config;

	protected CollectorManager $collectors;

	public function __construct( Config $config, CollectorManager $collectors ) {
		$this->config     = $config;
		$this->collectors = $collectors;
	}

	protected function gather_and_encrypt(): string {
		try {
			$payload = json_encode( $this->collectors->gather(), JSON_THROW_ON_ERROR );

			$algo  = 'aes-128-ctr';
			$ivlen = openssl_cipher_iv_length( $algo );
			$iv    = openssl_random_pseudo_bytes( $ivlen );

			$encrypted = openssl_encrypt( $payload, $algo, $this->config->secret, OPENSSL_RAW_DATA, $iv );

			return base64_encode( $iv . $encrypted );
		} catch ( Exception $e ) {
			return '';
		}
	}
}