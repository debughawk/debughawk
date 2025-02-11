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
	];

	protected array $target_functions = [];

	protected array $ignored_functions = [];

	public function __construct( array $trace = null ) {
		if ( is_null( $trace ) ) {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		}

		$this->trace = $trace;
	}

	public function find( array $functions ): Backtrace {
		$this->target_functions = $functions;

		return $this;
	}

	public function ignoring( array $functions ): Backtrace {
		$this->ignored_functions = $functions;

		return $this;
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

			if ( $found_frame ) {
				break;
			}

			if ( isset( $frame['function'] ) && in_array( $frame['function'], $this->target_functions ) ) {
				$found_frame = $frame;
			}
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

		return array_filter( [
			'component' => in_array( $component, [ 'stylesheet', 'template' ] ) ? 'theme' : $component,
			'file'      => $frame['file'],
			'line'      => $frame['line'] ?? null,
			'plugin'    => $component === 'plugin' ? $this->determine_plugin( $frame['file'] ) : null,
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

	protected function determine_plugin( string $file ): ?string {
		$plugin = plugin_basename( $file );

		if ( strpos( $plugin, '/' ) ) {
			$plugin = explode( '/', $plugin );
			$plugin = reset( $plugin );
		} else {
			$plugin = basename( $plugin );
		}

		return $plugin;
	}
}