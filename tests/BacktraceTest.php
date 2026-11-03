<?php

namespace DebugHawk\Tests;

use DebugHawk\Backtrace;
use DebugHawk\Collectors\DatabaseCollector;
use DebugHawk\Collectors\OutgoingRequestsCollector;
use PHPUnit\Framework\TestCase;

/**
 * Traces are innermost frame first, shaped like real debug_backtrace() output:
 * each frame's file and line are where its function was called from.
 */
class BacktraceTest extends TestCase {
	private const CORE    = '/srv/www/wp-includes';
	private const PLUGINS = '/srv/www/wp-content/plugins';
	private const THEMES  = '/srv/www/wp-content/themes';

	protected function setUp(): void {
		$GLOBALS['wp_theme_directories'] = [ self::THEMES ];
		$GLOBALS['wp_plugin_paths']      = [];
	}

	/**
	 * @dataProvider traces
	 */
	public function test_attributes_to_the_responsible_code( array $trace, ?array $expected ): void {
		$this->assertSame( $expected, ( new Backtrace( $trace ) )->parse() );
	}

	public function traces(): array {
		return [
			'plugin callback on an action'                => [
				$this->query_from( [
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 15, 'function' => 'query', 'class' => 'DebugHawkDB' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'acme_on_init' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 348, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/plugin.php', 'line' => 517, 'function' => 'do_action', 'class' => 'WP_Hook' ],
					[ 'file' => '/srv/www/wp-settings.php', 'line' => 700, 'function' => 'do_action' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 15, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'acme' ],
			],
			'theme calls a plugin function that queries'  => [
				$this->query_from( [
					[ 'file' => self::PLUGINS . '/acme/inc/queries.php', 'line' => 8, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::THEMES . '/twentyten/single.php', 'line' => 30, 'function' => 'acme_related_posts' ],
					[ 'file' => self::CORE . '/template-loader.php', 'line' => 106, 'function' => 'include' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/inc/queries.php', 'line' => 8, 'function' => 'get_results', 'class' => 'wpdb', 'slug' => 'acme' ],
			],
			'theme queries directly'                      => [
				$this->query_from( [
					[ 'file' => self::THEMES . '/twentyten/functions.php', 'line' => 42, 'function' => 'query', 'class' => 'DebugHawkDB' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'twentyten_setup' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 348, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
				] ),
				[ 'component' => 'theme', 'file' => self::THEMES . '/twentyten/functions.php', 'line' => 42, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'twentyten' ],
			],
			'plugin calls a core API'                     => [
				$this->query_from( [
					[ 'file' => self::CORE . '/class-wp-query.php', 'line' => 3300, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/class-wp-query.php', 'line' => 3900, 'function' => 'get_posts', 'class' => 'WP_Query' ],
					[ 'file' => self::CORE . '/post.php', 'line' => 2500, 'function' => 'query', 'class' => 'WP_Query' ],
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 60, 'function' => 'get_posts' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 60, 'function' => 'get_posts', 'slug' => 'acme' ],
			],
			'one plugin calls another'                    => [
				$this->query_from( [
					[ 'file' => self::PLUGINS . '/other/other.php', 'line' => 4, 'function' => 'query', 'class' => 'DebugHawkDB' ],
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 55, 'function' => 'other_plugin_query' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/other/other.php', 'line' => 4, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'other' ],
			],
			'plugin uses a library outside WordPress'     => [
				$this->query_from( [
					[ 'file' => '/srv/vendor/acme/lib/Repository.php', 'line' => 9, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 59, 'function' => 'find', 'class' => 'Acme\\Repository' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 59, 'function' => 'find', 'class' => 'Acme\\Repository', 'slug' => 'acme' ],
			],
			'child theme calls a parent theme function'   => [
				$this->query_from( [
					[ 'file' => self::THEMES . '/parent/inc/helpers.php', 'line' => 12, 'function' => 'query', 'class' => 'DebugHawkDB' ],
					[ 'file' => self::THEMES . '/parent-child/functions.php', 'line' => 3, 'function' => 'parent_helper' ],
				] ),
				[ 'component' => 'theme', 'file' => self::THEMES . '/parent/inc/helpers.php', 'line' => 12, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'parent' ],
			],
			'single-file plugin'                          => [
				$this->query_from( [
					[ 'file' => self::PLUGINS . '/hello.php', 'line' => 70, 'function' => 'query', 'class' => 'DebugHawkDB' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/hello.php', 'line' => 70, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'hello.php' ],
			],
			'mu-plugin'                                   => [
				$this->query_from( [
					[ 'file' => '/srv/www/wp-content/mu-plugins/loader.php', 'line' => 3, 'function' => 'query', 'class' => 'DebugHawkDB' ],
				] ),
				[ 'component' => 'mu-plugin', 'file' => '/srv/www/wp-content/mu-plugins/loader.php', 'line' => 3, 'function' => 'query', 'class' => 'wpdb' ],
			],
			'other wp-content code'                       => [
				$this->query_from( [
					[ 'file' => '/srv/www/wp-content/object-cache.php', 'line' => 90, 'function' => 'get_var', 'class' => 'wpdb' ],
				] ),
				[ 'component' => 'other', 'file' => '/srv/www/wp-content/object-cache.php', 'line' => 90, 'function' => 'get_var', 'class' => 'wpdb' ],
			],
			'core on its own'                             => [
				$this->query_from( [
					[ 'file' => self::CORE . '/option.php', 'line' => 1100, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/option.php', 'line' => 160, 'function' => 'wp_load_alloptions' ],
					[ 'file' => '/srv/www/wp-settings.php', 'line' => 400, 'function' => 'get_option' ],
				] ),
				[ 'component' => 'core', 'file' => self::CORE . '/option.php', 'line' => 160, 'function' => 'wp_load_alloptions' ],
			],
			'core callback on a hook fired by a theme'    => [
				$this->query_from( [
					[ 'file' => self::CORE . '/link-template.php', 'line' => 4000, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'rel_canonical' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 348, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/plugin.php', 'line' => 517, 'function' => 'do_action', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/general-template.php', 'line' => 3050, 'function' => 'do_action' ],
					[ 'file' => self::THEMES . '/twentyten/header.php', 'line' => 20, 'function' => 'wp_head' ],
				] ),
				// The callback's name, with the file and line inside it rather than WP_Hook's.
				[ 'component' => 'core', 'file' => self::CORE . '/link-template.php', 'line' => 4000, 'function' => 'rel_canonical' ],
			],
			'core callback on a plugin\'s own hook'        => [
				// Intended: the core callback made the query, so the plugin that
				// fired the hook isn't blamed.
				$this->query_from( [
					[ 'file' => self::CORE . '/option.php', 'line' => 1200, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'wp_load_alloptions' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 348, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/plugin.php', 'line' => 517, 'function' => 'do_action', 'class' => 'WP_Hook' ],
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 80, 'function' => 'do_action' ],
				] ),
				[ 'component' => 'core', 'file' => self::CORE . '/option.php', 'line' => 1200, 'function' => 'wp_load_alloptions' ],
			],
			'drop-in called on behalf of a plugin'        => [
				$this->query_from( [
					[ 'file' => '/srv/www/wp-content/object-cache.php', 'line' => 90, 'function' => 'get_var', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/cache.php', 'line' => 150, 'function' => 'get', 'class' => 'WP_Object_Cache' ],
					[ 'file' => self::CORE . '/option.php', 'line' => 120, 'function' => 'wp_cache_get' ],
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 31, 'function' => 'get_option' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 31, 'function' => 'get_option', 'slug' => 'acme' ],
			],
			'drop-in called from a core hook callback'    => [
				$this->query_from( [
					[ 'file' => '/srv/www/wp-content/object-cache.php', 'line' => 90, 'function' => 'get_var', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/option.php', 'line' => 120, 'function' => 'wp_cache_get' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'wp_load_alloptions' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 348, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::THEMES . '/twentyten/header.php', 'line' => 20, 'function' => 'do_action' ],
				] ),
				[ 'component' => 'other', 'file' => '/srv/www/wp-content/object-cache.php', 'line' => 90, 'function' => 'get_var', 'class' => 'wpdb' ],
			],
			'plugin callback on a hook fired by a theme'  => [
				$this->query_from( [
					[ 'file' => self::PLUGINS . '/seo/seo.php', 'line' => 77, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'seo_head' ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 348, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/plugin.php', 'line' => 517, 'function' => 'do_action', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/general-template.php', 'line' => 3050, 'function' => 'do_action' ],
					[ 'file' => self::THEMES . '/twentyten/header.php', 'line' => 20, 'function' => 'wp_head' ],
				] ),
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/seo/seo.php', 'line' => 77, 'function' => 'get_results', 'class' => 'wpdb', 'slug' => 'seo' ],
			],
			'theme calls a core API directly'             => [
				$this->query_from( [
					[ 'file' => self::CORE . '/post-thumbnail-template.php', 'line' => 100, 'function' => 'get_results', 'class' => 'wpdb' ],
					[ 'file' => self::THEMES . '/twentyten/single.php', 'line' => 12, 'function' => 'the_post_thumbnail' ],
				] ),
				[ 'component' => 'theme', 'file' => self::THEMES . '/twentyten/single.php', 'line' => 12, 'function' => 'the_post_thumbnail', 'slug' => 'twentyten' ],
			],
			'query logged through log_query_custom_data'  => [
				[
					[ 'file' => $this->repo( 'src/Collectors/DatabaseCollector.php' ), 'line' => 1, 'function' => '__construct', 'class' => Backtrace::class ],
					[ 'file' => $this->repo( 'src/Collectors/DatabaseCollector.php' ), 'line' => 2, 'function' => 'add_slow_query', 'class' => DatabaseCollector::class ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'track_logged_query', 'class' => DatabaseCollector::class ],
					[ 'file' => self::CORE . '/plugin.php', 'line' => 205, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/class-wpdb.php', 'line' => 2400, 'function' => 'apply_filters' ],
					[ 'file' => self::CORE . '/class-wpdb.php', 'line' => 2300, 'function' => 'log_query', 'class' => 'wpdb' ],
					[ 'file' => self::CORE . '/class-wpdb.php', 'line' => 2200, 'function' => '_do_query', 'class' => 'wpdb' ],
					[ 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 15, 'function' => 'query', 'class' => 'wpdb' ],
				],
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/acme.php', 'line' => 15, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'acme' ],
			],
			'plugin makes an HTTP request'                => [
				[
					[ 'file' => $this->repo( 'src/Collectors/OutgoingRequestsCollector.php' ), 'line' => 1, 'function' => '__construct', 'class' => Backtrace::class ],
					[ 'file' => self::CORE . '/class-wp-hook.php', 'line' => 324, 'function' => 'track_request_start', 'class' => OutgoingRequestsCollector::class ],
					[ 'file' => self::CORE . '/plugin.php', 'line' => 205, 'function' => 'apply_filters', 'class' => 'WP_Hook' ],
					[ 'file' => self::CORE . '/class-wp-http.php', 'line' => 250, 'function' => 'apply_filters' ],
					[ 'file' => self::CORE . '/http.php', 'line' => 160, 'function' => 'request', 'class' => 'WP_Http' ],
					[ 'file' => self::PLUGINS . '/acme/api.php', 'line' => 22, 'function' => 'wp_remote_get' ],
				],
				[ 'component' => 'plugin', 'file' => self::PLUGINS . '/acme/api.php', 'line' => 22, 'function' => 'wp_remote_get', 'slug' => 'acme' ],
			],
			'only DebugHawk frames'                       => [
				[
					[ 'file' => $this->repo( 'src/Collectors/DatabaseCollector.php' ), 'line' => 1, 'function' => '__construct', 'class' => Backtrace::class ],
				],
				null,
			],
			'empty trace'                                 => [ [], null ],
		];
	}

	public function test_attributes_symlinked_plugins(): void {
		$GLOBALS['wp_plugin_paths'] = [ self::PLUGINS . '/linked' => '/home/dev/linked' ];

		$trace = $this->query_from( [
			[ 'file' => '/home/dev/linked/linked.php', 'line' => 4, 'function' => 'query', 'class' => 'DebugHawkDB' ],
			[ 'file' => self::THEMES . '/twentyten/functions.php', 'line' => 9, 'function' => 'linked_query' ],
		] );

		$this->assertSame(
			[ 'component' => 'plugin', 'file' => '/home/dev/linked/linked.php', 'line' => 4, 'function' => 'query', 'class' => 'wpdb', 'slug' => 'linked' ],
			( new Backtrace( $trace ) )->parse()
		);
	}

	public function test_attributes_themes_in_registered_theme_directories(): void {
		$GLOBALS['wp_theme_directories'] = [ self::THEMES, '/srv/shared-themes' ];

		$trace = $this->query_from( [
			[ 'file' => '/srv/shared-themes/agency/functions.php', 'line' => 5, 'function' => 'query', 'class' => 'DebugHawkDB' ],
		] );

		$this->assertSame( 'theme', ( new Backtrace( $trace ) )->parse()['component'] );
		$this->assertSame( 'agency', ( new Backtrace( $trace ) )->parse()['slug'] );
	}

	/**
	 * Prepends the frames DebugHawk adds when the drop-in reports a slow query.
	 */
	private function query_from( array $frames ): array {
		return array_merge( [
			[ 'file' => $this->repo( 'src/Collectors/DatabaseCollector.php' ), 'line' => 1, 'function' => '__construct', 'class' => Backtrace::class ],
			[ 'file' => $this->repo( 'src/Collectors/DatabaseCollector.php' ), 'line' => 2, 'function' => 'add_slow_query', 'class' => DatabaseCollector::class ],
			[ 'file' => '/srv/www/wp-content/db.php', 'line' => 90, 'function' => 'track_slow_query', 'class' => DatabaseCollector::class ],
		], $frames );
	}

	private function repo( string $path ): string {
		return dirname( __DIR__ ) . '/' . $path;
	}
}
