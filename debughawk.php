<?php
/*
 * Plugin Name: DebugHawk
 * Plugin URI:   https://deploymenthawk.com
 * Description:  DebugHawk helper plugin.
 * Author:       Ashley Rich
 * Version:      0.1
 * Requires PHP: 7.4
 */

use DebugHawk\Config;
use DebugHawk\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

$config = new Config( defined( 'DEBUGHAWK_CONFIG' ) ? DEBUGHAWK_CONFIG : [], __FILE__ );
( new Plugin( $config ) )->init();

/*
class WordPress_Apm {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
	}

	protected function save_transaction() {
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
				'post_id'   => is_singular() ? get_the_ID() : null,
				'post_type' => is_singular() ? get_post_type() : null,
				'version'   => get_bloginfo( 'version' ),
			],
		];

		echo '<!-- Begin DeploymentHawk output -->' . "\n\n";
		echo '<script>';
		echo 'window.DeploymentHawk = ' . json_encode( $payload ) . ';';
		echo '</script>';
		echo '<!-- End DeploymentHawk output -->' . "\n\n";
	}

	public function enqueue_script() {
		wp_enqueue_script(
			'apm',
			plugins_url( 'dist/main.js', __FILE__ ),
			[],
			'1.0.0',
			true,
		);
	}
}

new WordPress_Apm();
*/
