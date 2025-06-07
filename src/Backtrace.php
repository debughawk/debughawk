<?php

namespace DebugHawk;

class Backtrace {
	protected array $trace;

	protected array $ignored_classes = [
		Backtrace::class,
		Collectors\ConfigCollector::class,
		Collectors\DatabaseCollector::class,
		Collectors\ObjectCacheCollector::class,
		Collectors\OutgoingRequestsCollector::class,
		Collectors\PhpCollector::class,
		Collectors\RequestCollector::class,
		Collectors\WordpressCollector::class,
		\DebugHawkDB::class,
		\WP_Hook::class,
		\WP_Http::class,
		\wpdb::class,
		\QM_DB::class
	];

	protected array $ignored_functions = [
		'apply_filters',
		'download_url',
		'include',
		'include_once',
		'require',
		'require_once',
		'wp_remote_fopen',
	];

	public function __construct( array $trace = null ) {
		if ( is_null( $trace ) ) {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		}

		$this->trace = $trace;
	}

	public function parse(): ?array {
		$found_frame = null;

		foreach ( $this->trace as $frame ) {
			if ( isset( $frame['class'] ) && in_array( $frame['class'], $this->ignored_classes ) ) {
				continue;
			}

			if ( isset( $frame['function'] ) && in_array( $frame['function'], $this->ignored_functions ) ) {
				continue;
			}

			$found_frame = $frame;
			break;
		}

		return $found_frame
			? $this->determine_component( $found_frame )
			: null;
	}

	protected function determine_component( array $frame ): ?array {
		if ( empty( $frame['file'] ) ) {
			return null;
		}

		$component = null;

		foreach ( $this->component_dirs() as $type => $dir ) {
			if ( strpos( $frame['file'], trailingslashit( $dir ) ) === 0 ) {
				$component = $type;
				break;
			}
		}

		if ( ! $component ) {
			return null;
		}

		$component = in_array( $component, [ 'stylesheet', 'template' ] ) ? 'theme' : $component;

		return array_filter( [
			'component' => $component,
			'file'      => $frame['file'],
			'line'      => $frame['line'] ?? null,
			'function'  => $frame['function'] ?? null,
			'class'     => $frame['class'] ?? null,
			'slug'      => $this->determine_slug( $component, $frame['file'] ),
		] );
	}

	protected function component_dirs(): array {
		return [
			'plugin'     => WP_PLUGIN_DIR,
			'mu-plugin'  => WPMU_PLUGIN_DIR,
			'stylesheet' => get_stylesheet_directory(),
			'template'   => get_template_directory(),
			'other'      => WP_CONTENT_DIR,
			'core'       => ABSPATH,
		];
	}

	protected function determine_slug( string $component, string $file ): ?string {
		if ( $component === 'plugin' ) {
			return $this->determine_plugin_slug( $file );
		}

		if ( $component === 'theme' ) {
			return $this->determine_theme_slug( $file );
		}

		return null;
	}

	protected function determine_plugin_slug( string $file ): ?string {
		$plugin = plugin_basename( $file );

		if ( strpos( $plugin, '/' ) ) {
			$plugin = explode( '/', $plugin );
			$plugin = reset( $plugin );
		} else {
			$plugin = basename( $plugin );
		}

		return $plugin;
	}

	protected function determine_theme_slug( string $file ): ?string {
		$theme_root = get_theme_root();

		if ( strpos( $file, $theme_root ) !== 0 ) {
			return null;
		}

		$relative_path = str_replace( trailingslashit( $theme_root ), '', $file );
		$parts         = explode( '/', $relative_path );

		return $parts[0] ?? null;
	}
}