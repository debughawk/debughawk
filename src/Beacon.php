<?php

namespace DebugHawk;

use DebugHawk\Collectors\ConfigCollector;
use DebugHawk\Collectors\DatabaseCollector;
use DebugHawk\Collectors\ExternalRequestsCollector;
use DebugHawk\Collectors\ObjectCacheCollector;
use DebugHawk\Collectors\PageCacheCollector;
use DebugHawk\Collectors\PhpCollector;
use DebugHawk\Collectors\RequestCollector;
use DebugHawk\Collectors\WordpressCollector;

class Beacon {
	protected Config $config;
	protected ScriptManager $script;
	protected array $collectors = [];

	public function __construct( Config $config, ScriptManager $script ) {
		$this->config = $config;
		$this->script = $script;
	}

	public function init(): void {
		$this->collectors['config']            = new ConfigCollector( $this->config );
		$this->collectors['database']          = new DatabaseCollector( $this->config );
		$this->collectors['external_requests'] = new ExternalRequestsCollector();
		$this->collectors['object_cache']      = new ObjectCacheCollector();
		$this->collectors['page_cache']        = new PageCacheCollector();
		$this->collectors['php']               = new PhpCollector();
		$this->collectors['request']           = new RequestCollector();
		$this->collectors['wordpress']         = new WordpressCollector();

		foreach ( $this->collectors as $collector ) {
			if ( method_exists( $collector, 'init' ) ) {
				$collector->init();
			}
		}

		$this->script->enqueue( 'debughawk-beacon', 'beacon.js' );
		add_action( 'wp_print_footer_scripts', [ $this, 'print_metrics' ], 9999 );

		if ( $this->config->trace_admin_pages ) {
			$this->script->enqueue_admin( 'debughawk-beacon', 'beacon.js' );
			add_action( 'admin_print_footer_scripts', [ $this, 'print_metrics' ], 9999 );
		}
	}

	public function print_metrics(): void {
		if ( ! $this->should_print_metrics() ) {
			return;
		}

		$metrics = [];

		foreach ( $this->collectors as $key => $collector ) {
			$metrics[ $key ] = $collector->collect();
		}

		$metrics = apply_filters( 'debughawk_beacon_metrics', $metrics );

		echo '<!-- Begin DebugHawk output -->' . "\n\n";
		echo '<script>';
		echo 'window.DebugHawkMetrics = { ';
		echo 'server: "' . $this->encrypt_payload( json_encode( $metrics ) ) . '"';
		echo ' };';
		echo '</script>';
		echo '<!-- End DebugHawk output -->' . "\n\n";
	}

	protected function should_print_metrics(): bool {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		// Don't dispatch during a Customizer preview request:
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		// Don't dispatch inside the Site Editor:
		if ( isset( $_SERVER['SCRIPT_NAME'] ) && '/wp-admin/site-editor.php' === $_SERVER['SCRIPT_NAME'] ) {
			return false;
		}

		return apply_filters( 'debughawk_beacon_should_print_metrics', true );
	}

	protected function encrypt_payload( string $payload ): string {
		$algo  = 'aes-128-ctr';
		$ivlen = openssl_cipher_iv_length( $algo );
		$iv    = openssl_random_pseudo_bytes( $ivlen );

		$encrypted = openssl_encrypt( $payload, $algo, $this->config->secret, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $encrypted );
	}
}