<?php

namespace DebugHawk;

class Beacon {
	public Config $config;
	public ScriptManager $script;

	public function __construct( Config $config, ScriptManager $script ) {
		$this->config = $config;
		$this->script = $script;
	}

	public function init(): void {
		$this->script->enqueue( 'debughawk-beacon', 'beacon.js' );
		add_action( 'wp_print_footer_scripts', array( $this, 'maybe_print_metrics' ), 9999 );

		if ( $this->config->trace_admin_pages ) {
			$this->script->enqueue_admin( 'debughawk-beacon', 'beacon.js' );
			add_action( 'admin_print_footer_scripts', array( $this, 'maybe_print_metrics' ), 9999 );
		}
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

		$time = 0;
		foreach ( $wpdb->queries as $query ) {
			$time += $query[1];
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
				'execution_time' => $time * 1000,
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
				'identifier'      => uniqid(),
				'generation_time' => time(),
			],
			'php'          => [
				'execution_time' => ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000,
				'memory_usage'   => memory_get_peak_usage(),
				'version'        => phpversion(),
			],
			'wordpress'    => [
				'admin_request' => is_admin(),
				'post_id'       => is_singular() ? get_the_ID() : null,
				'post_type'     => is_singular() ? get_post_type() : null,
				'version'       => get_bloginfo( 'version' ),
			],
		];

		echo '<!-- Begin DebugHawk output -->' . "\n\n";
		echo '<script>';
		echo 'window.debughawkMetrics = ' . json_encode( $payload ) . ';';
		echo 'console.log( window.debughawkMetrics );';
		echo '</script>';
		echo '<!-- End DebugHawk output -->' . "\n\n";
	}
}