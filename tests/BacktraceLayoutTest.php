<?php

namespace DebugHawk\Tests;

use DebugHawk\Backtrace;
use DebugHawk\Collectors\DatabaseCollector;
use PHPUnit\Framework\TestCase;

/**
 * Attribution across WordPress layouts: subdirectory installs, moved
 * directories, nesting and symlinks.
 */
class BacktraceLayoutTest extends TestCase {
	private ?string $temp = null;

	protected function setUp(): void {
		$GLOBALS['wp_plugin_paths'] = [];
	}

	protected function tearDown(): void {
		if ( $this->temp !== null ) {
			exec( 'rm -rf ' . escapeshellarg( $this->temp ) );
		}
	}

	public function test_wordpress_in_a_subdirectory(): void {
		$directories = $this->layout( '/srv/www/wp/', '/srv/www/wp/wp-content' );

		// The site root's index.php, outside ABSPATH, loads WordPress.
		$plugin = $this->query_from( [
			[ 'file' => '/srv/www/wp/wp-content/plugins/acme/acme.php', 'line' => 5, 'function' => 'query', 'class' => 'wpdb' ],
			[ 'file' => '/srv/www/wp/wp-includes/class-wp-hook.php', 'line' => 324, 'function' => 'acme_init' ],
			[ 'file' => '/srv/www/wp/wp-blog-header.php', 'line' => 13, 'function' => 'require_once' ],
			[ 'file' => '/srv/www/index.php', 'line' => 17, 'function' => 'require' ],
		] );
		$core   = $this->query_from( [
			[ 'file' => '/srv/www/wp/wp-includes/option.php', 'line' => 1100, 'function' => 'get_results', 'class' => 'wpdb' ],
			[ 'file' => '/srv/www/wp/wp-settings.php', 'line' => 400, 'function' => 'wp_load_alloptions' ],
			[ 'file' => '/srv/www/index.php', 'line' => 17, 'function' => 'require' ],
		] );

		$this->assertAttribution( 'plugin', 'acme', $plugin, $directories );
		$this->assertAttribution( 'core', null, $core, $directories );
	}

	public function test_bedrock_layout(): void {
		$directories = $this->layout( '/srv/app/web/wp/', '/srv/app/web/app' );

		$plugin = $this->query_from( [
			[ 'file' => '/srv/app/vendor/acme/sdk/src/Client.php', 'line' => 40, 'function' => 'get_results', 'class' => 'wpdb' ],
			[ 'file' => '/srv/app/web/app/plugins/acme/acme.php', 'line' => 12, 'function' => 'fetch', 'class' => 'Acme\\Sdk\\Client' ],
		] );
		$theme  = $this->query_from( [
			[ 'file' => '/srv/app/web/app/themes/sage/app/setup.php', 'line' => 30, 'function' => 'query', 'class' => 'wpdb' ],
		] );
		$mu     = $this->query_from( [
			[ 'file' => '/srv/app/web/app/mu-plugins/bedrock-autoloader.php', 'line' => 8, 'function' => 'query', 'class' => 'wpdb' ],
		] );

		$this->assertAttribution( 'plugin', 'acme', $plugin, $directories );
		$this->assertAttribution( 'theme', 'sage', $theme, $directories );
		$this->assertAttribution( 'mu-plugin', null, $mu, $directories );
	}

	public function test_plugins_directory_outside_wp_content(): void {
		$directories = $this->layout( '/srv/www/', '/srv/www/wp-content', [ 'plugins' => '/srv/plugins' ] );

		$trace = $this->query_from( [
			[ 'file' => '/srv/plugins/acme/acme.php', 'line' => 5, 'function' => 'query', 'class' => 'wpdb' ],
		] );

		$this->assertAttribution( 'plugin', 'acme', $trace, $directories );
	}

	public function test_theme_directory_inside_a_plugin(): void {
		$directories = $this->layout( '/srv/www/', '/srv/www/wp-content', [
			'theme_roots' => [ '/srv/www/wp-content/themes', '/srv/www/wp-content/plugins/builder/themes' ],
		] );

		$theme  = $this->query_from( [
			[ 'file' => '/srv/www/wp-content/plugins/builder/themes/starter/functions.php', 'line' => 9, 'function' => 'query', 'class' => 'wpdb' ],
		] );
		$plugin = $this->query_from( [
			[ 'file' => '/srv/www/wp-content/plugins/builder/builder.php', 'line' => 9, 'function' => 'query', 'class' => 'wpdb' ],
		] );

		$this->assertAttribution( 'theme', 'starter', $theme, $directories );
		$this->assertAttribution( 'plugin', 'builder', $plugin, $directories );
	}

	public function test_mu_plugins_directory_inside_the_plugins_directory(): void {
		$directories = $this->layout( '/srv/www/', '/srv/www/wp-content', [ 'mu_plugins' => '/srv/www/wp-content/plugins/mu' ] );

		$trace = $this->query_from( [
			[ 'file' => '/srv/www/wp-content/plugins/mu/host.php', 'line' => 9, 'function' => 'query', 'class' => 'wpdb' ],
		] );

		$this->assertAttribution( 'mu-plugin', null, $trace, $directories );
	}

	public function test_symlinked_theme(): void {
		$root = $this->filesystem( [ 'wp-content/themes', 'shared/agency-theme' ] );
		symlink( "$root/shared/agency-theme", "$root/wp-content/themes/agency" );

		$trace = $this->query_from( [
			[ 'file' => realpath( "$root/shared/agency-theme" ) . '/functions.php', 'line' => 9, 'function' => 'query', 'class' => 'wpdb' ],
		], "$root/wp-content" );

		$this->assertAttribution( 'theme', 'agency', $trace, $this->layout( "$root/", "$root/wp-content" ) );
	}

	public function test_symlinked_mu_plugin_file_and_folder(): void {
		$root = $this->filesystem( [ 'wp-content/mu-plugins', 'host/mu-folder' ] );
		touch( "$root/host/host-plugin.php" );
		symlink( "$root/host/host-plugin.php", "$root/wp-content/mu-plugins/host-plugin.php" );
		symlink( "$root/host/mu-folder", "$root/wp-content/mu-plugins/mu-folder" );

		$file   = $this->query_from( [
			[ 'file' => realpath( "$root/host/host-plugin.php" ), 'line' => 3, 'function' => 'query', 'class' => 'wpdb' ],
		], "$root/wp-content" );
		$folder = $this->query_from( [
			[ 'file' => realpath( "$root/host/mu-folder" ) . '/inc.php', 'line' => 3, 'function' => 'query', 'class' => 'wpdb' ],
		], "$root/wp-content" );

		$directories = $this->layout( "$root/", "$root/wp-content" );

		$this->assertAttribution( 'mu-plugin', null, $file, $directories );
		$this->assertAttribution( 'mu-plugin', null, $folder, $directories );
	}

	public function test_symlinked_wp_content(): void {
		$root = $this->filesystem( [ 'wp', 'shared/wp-content/plugins/acme' ] );
		symlink( "$root/shared/wp-content", "$root/wp/wp-content" );

		// PHP reports the real path, outside ABSPATH.
		$trace = $this->query_from( [
			[ 'file' => realpath( "$root/shared/wp-content" ) . '/plugins/acme/acme.php', 'line' => 5, 'function' => 'query', 'class' => 'wpdb' ],
		], "$root/wp/wp-content" );

		$this->assertAttribution( 'plugin', 'acme', $trace, $this->layout( "$root/wp/", "$root/wp/wp-content" ) );
	}

	public function test_symlinked_single_file_plugin(): void {
		$root = $this->filesystem( [ 'wp-content/plugins', 'shared' ] );
		touch( "$root/shared/hello-dolly-1.7.php" );
		symlink( "$root/shared/hello-dolly-1.7.php", "$root/wp-content/plugins/hello.php" );

		// As wp_register_plugin_realpath() records it: the directories, not the file.
		$GLOBALS['wp_plugin_paths'] = [ "$root/wp-content/plugins" => realpath( "$root/shared" ) ];

		$trace = $this->query_from( [
			[ 'file' => realpath( "$root/shared/hello-dolly-1.7.php" ), 'line' => 70, 'function' => 'query', 'class' => 'wpdb' ],
		], "$root/wp-content" );

		$this->assertAttribution( 'plugin', 'hello.php', $trace, $this->layout( "$root/", "$root/wp-content" ) );
	}

	public function test_plugin_symlinked_to_a_folder_inside_wordpress(): void {
		$root = $this->filesystem( [ 'wp-content/plugins', 'shared-plugins/acme' ] );
		symlink( "$root/shared-plugins/acme", "$root/wp-content/plugins/acme" );

		// The real path is inside ABSPATH, so without the symlink it would look like core.
		$trace = $this->query_from( [
			[ 'file' => realpath( "$root/shared-plugins/acme" ) . '/acme.php', 'line' => 5, 'function' => 'query', 'class' => 'wpdb' ],
		], "$root/wp-content" );

		$this->assertAttribution( 'plugin', 'acme', $trace, $this->layout( "$root/", "$root/wp-content" ) );
	}

	public function test_symlinked_debughawk_is_skipped(): void {
		$root = $this->filesystem( [ 'wp-content/plugins/acme', 'repos/debughawk/src/Collectors' ] );
		symlink( "$root/repos/debughawk", "$root/wp-content/plugins/debughawk" );

		// WordPress records DebugHawk's own real path, as it does for any symlinked plugin.
		$GLOBALS['wp_plugin_paths'] = [ "$root/wp-content/plugins/debughawk" => realpath( "$root/repos/debughawk" ) ];

		$debughawk = realpath( "$root/repos/debughawk" );
		$trace     = [
			[ 'file' => "$debughawk/src/Collectors/DatabaseCollector.php", 'line' => 1, 'function' => '__construct', 'class' => Backtrace::class ],
			[ 'file' => "$debughawk/src/Collectors/DatabaseCollector.php", 'line' => 2, 'function' => 'add_slow_query', 'class' => DatabaseCollector::class ],
			[ 'file' => "$root/wp-content/db.php", 'line' => 90, 'function' => 'track_slow_query', 'class' => DatabaseCollector::class ],
			[ 'file' => "$root/wp-content/plugins/acme/acme.php", 'line' => 5, 'function' => 'query', 'class' => 'wpdb' ],
		];

		// dirname( __DIR__ ) in Backtrace is always the real path.
		$this->assertAttribution( 'plugin', 'acme', $trace, $this->layout( "$root/", "$root/wp-content", [ 'debughawk' => $debughawk ] ) );
	}

	private function assertAttribution( string $component, ?string $slug, array $trace, array $directories ): void {
		$result = ( new LayoutBacktrace( $trace, $directories ) )->parse();

		$this->assertNotNull( $result );
		$this->assertSame( $component, $result['component'] );
		$this->assertSame( $slug, $result['slug'] ?? null );
	}

	private function layout( string $abspath, string $content, array $overrides = [] ): array {
		return array_merge( [
			'abspath'     => $abspath,
			'content'     => $content,
			'plugins'     => $content . '/plugins',
			'mu_plugins'  => $content . '/mu-plugins',
			'theme_roots' => [ $content . '/themes' ],
			'debughawk'   => dirname( __DIR__ ),
		], $overrides );
	}

	/**
	 * Prepends the frames DebugHawk adds when the drop-in reports a slow query.
	 */
	private function query_from( array $frames, string $content = '/srv/www/wp-content' ): array {
		return array_merge( [
			[ 'file' => dirname( __DIR__ ) . '/src/Collectors/DatabaseCollector.php', 'line' => 1, 'function' => '__construct', 'class' => Backtrace::class ],
			[ 'file' => dirname( __DIR__ ) . '/src/Collectors/DatabaseCollector.php', 'line' => 2, 'function' => 'add_slow_query', 'class' => DatabaseCollector::class ],
			[ 'file' => $content . '/db.php', 'line' => 90, 'function' => 'track_slow_query', 'class' => DatabaseCollector::class ],
		], $frames );
	}

	private function filesystem( array $directories ): string {
		$this->temp = sys_get_temp_dir() . '/debughawk-' . bin2hex( random_bytes( 4 ) );

		foreach ( $directories as $directory ) {
			mkdir( $this->temp . '/' . $directory, 0777, true );
		}

		return $this->temp;
	}
}

/**
 * Backtrace with the directories given, instead of WordPress's constants.
 */
class LayoutBacktrace extends Backtrace {
	private array $layout;

	public function __construct( array $trace, array $layout ) {
		parent::__construct( $trace );

		$this->layout = $layout;
	}

	protected function directories(): array {
		return $this->layout;
	}
}
