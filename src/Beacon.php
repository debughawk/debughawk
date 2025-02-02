<?php

namespace DebugHawk;

class Beacon {
	public Config $config;
	public ScriptManager $script;
	public array $api_calls = [];

	public function __construct( Config $config, ScriptManager $script ) {
		$this->config = $config;
		$this->script = $script;
	}

	public function init(): void {
		add_filter( 'http_request_args', array( $this, 'make_request_traceable' ), 9999, 2 );
		add_filter( 'pre_http_request', array( $this, 'track_request_start' ), 9999, 3 );
		add_filter( 'http_response', array( $this, 'track_request_end' ), 9999, 3 );

		$this->script->enqueue( 'debughawk-beacon', 'beacon.js' );
		add_action( 'wp_print_footer_scripts', array( $this, 'maybe_print_metrics' ), 9999 );

		if ( $this->config->trace_admin_pages ) {
			$this->script->enqueue_admin( 'debughawk-beacon', 'beacon.js' );
			add_action( 'admin_print_footer_scripts', array( $this, 'maybe_print_metrics' ), 9999 );
		}
	}

	public function make_request_traceable( $args, $url ) {
		$args['_debughawk_request_id'] = uniqid( 'http_', true );

		return $args;
	}

	public function track_request_start( $preempt, $args, $url ) {
		$request_id = $args['_debughawk_request_id'] ?? null;

		if ( ! $request_id ) {
			return $preempt;
		}

		$this->api_calls[ $request_id ] = [
			'url'         => $url,
			'start_time'  => microtime( true ),
			'http_method' => $args['method'] ?? 'GET',
		];

		return $preempt;
	}

	public function track_request_end( $response, $args, $url ) {
		$request_id = $args['_debughawk_request_id'] ?? null;

		if ( ! $request_id || ! isset( $this->api_calls[ $request_id ] ) ) {
			return $response;
		}

		$duration = microtime( true ) - $this->api_calls[ $request_id ]['start_time'];

		$this->api_calls[ $request_id ]['duration']    = $duration * 1000;
		$this->api_calls[ $request_id ]['http_status'] = wp_remote_retrieve_response_code( $response );

		return $response;
	}

	public function maybe_print_metrics(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		// Don't dispatch during a Customizer preview request:
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}

		// Don't dispatch inside the Site Editor:
		if ( isset( $_SERVER['SCRIPT_NAME'] ) && '/wp-admin/site-editor.php' === $_SERVER['SCRIPT_NAME'] ) {
			return;
		}

		$this->print_metrics();
	}

	protected function print_metrics(): void {
		global $wpdb, $wp_object_cache;

		$database_time = null;
		if ( $wpdb->queries ) {
			$database_time = 0;

			foreach ( $wpdb->queries as $query ) {
				$database_time += $query[1];
			}
		}

		if ( is_object( $wp_object_cache ) ) {
			$object_vars = get_object_vars( $wp_object_cache );

			if ( array_key_exists( 'cache_hits', $object_vars ) ) {
				$cache_hits = (int) $object_vars['cache_hits'];
			}

			if ( array_key_exists( 'cache_misses', $object_vars ) ) {
				$cache_misses = (int) $object_vars['cache_misses'];
			}
		}

		$scheme = is_ssl() ? 'https' : 'http';

		$payload = [
			'database'     => [
				'execution_time' => $database_time ? $database_time * 1000 : null,
				'query_count'    => $wpdb->num_queries,
			],
			'http'         => [
				'url'    => sprintf( '%s://%s%s', $scheme, $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ),
				'method' => $_SERVER['REQUEST_METHOD'],
				'status' => http_response_code(),
			],
			'object_cache' => [
				'enabled'      => (bool) wp_using_ext_object_cache(),
				'cache_hits'   => $cache_hits ?? null,
				'cache_misses' => $cache_misses ?? null,
			],
			'page_cache'   => [
				'identifier' => uniqid(),
				'timestamp'  => time(),
			],
			'php'          => [
				'execution_time' => ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000,
				'memory_usage'   => memory_get_peak_usage(),
				'version'        => phpversion(),
			],
			'wordpress'    => [
				'is_admin'  => is_admin(),
				'post_id'   => is_singular() ? get_the_ID() : null,
				'post_type' => is_singular() ? get_post_type() : null,
				'version'   => get_bloginfo( 'version' ),
				'api_calls' => $this->api_calls,
			],
		];

		echo '<!-- Begin DebugHawk output -->' . "\n\n";
		echo '<script>';
		echo 'window.DebugHawkMetrics = { ';
		echo 'server: "' . $this->encrypt_payload( json_encode( $payload ) ) . '"';
		echo ' };';
		echo '</script>';
		echo '<!-- End DebugHawk output -->' . "\n\n";
	}

	protected function encrypt_payload( string $payload ): string {
		$algo  = 'aes-128-ctr';
		$ivlen = openssl_cipher_iv_length( $algo );
		$iv    = openssl_random_pseudo_bytes( $ivlen );

		$encrypted = openssl_encrypt( $payload, $algo, $this->config->secret, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . $encrypted );
	}
}