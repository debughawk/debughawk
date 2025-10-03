<?php

namespace DebugHawk;

/**
 * @property boolean $enabled
 * @property string $endpoint
 * @property string $script_url
 * @property float $sample_rate
 * @property string $secret
 * @property boolean $trace_admin_pages
 * @property boolean $trace_redirects
 * @property int $slow_queries_threshold
 * @property int $slow_queries_limit
 */
class Config {
	private const DEFAULT_CONFIG = [
		'enabled'                => true,
		'script_url'             => 'https://cdn.debughawk.com/script.js',
		'sample_rate'            => 1,
		'trace_admin_pages'      => true,
		'trace_redirects'        => true,
		'slow_queries_threshold' => 50, // 50 ms
		'slow_queries_limit'     => 3,
	];

	private array $config;
	private array $db_config;
	public string $path;
	public string $url;
	public string $version;

	public function __construct( array $config, string $path, string $version ) {
		$this->config  = $config;
		$this->path    = $path;
		$this->url     = plugin_dir_url( $path );
		$this->version = $version;

		$this->db_config = $this->load_db_config();
	}

	private function load_db_config(): array {
		if ( ! empty( $this->config ) ) {
			return [];
		}

		return get_option( 'debughawk_config', [] );
	}

	public function configured(): bool {
		return $this->endpoint && $this->secret;
	}

	public function dispatcherEndpoint( $dispatcher ): string {
		if ( ! $this->configured() ) {
			return '';
		}

		return trailingslashit( $this->endpoint ) . $dispatcher;
	}

	public function is_within_sample_range(): bool {
		return random_int( 1, 100 ) <= ( $this->sample_rate * 100 );
	}

	public function to_array(): array {
		$config = [];

		foreach ( self::DEFAULT_CONFIG as $key => $value ) {
			$config[ $key ] = $this->$key;
		}

		return $config;
	}

	public function __get( string $name ) {
		if ( array_key_exists( $name, $this->config ) ) {
			return $this->config[ $name ];
		}

		if ( array_key_exists( $name, $this->db_config ) ) {
			return $this->db_config[ $name ];
		}

		if ( array_key_exists( $name, self::DEFAULT_CONFIG ) ) {
			return self::DEFAULT_CONFIG[ $name ];
		}

		return null;
	}
}