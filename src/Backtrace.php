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

	/** wpdb drop-in classes, reported as wpdb. */
	protected const WPDB_DROPINS = [ 'DebugHawkDB', 'QM_DB' ];

	/** Path prefixes, rebuilt only when the directories they came from change. */
	protected static ?array $prefixes = null;

	protected static ?array $prefixes_directories = null;

	public function __construct( ?array $trace = null ) {
		if ( is_null( $trace ) ) {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		}

		$this->trace = $trace;
	}

	/**
	 * Finds the code responsible for the current call (a query or request).
	 *
	 * Walks the stack from the innermost frame outward and returns the first
	 * frame whose file belongs to a plugin, mu-plugin, theme or other
	 * wp-content code: the line where that code called into WordPress or
	 * wpdb. Core files, DebugHawk itself, the db.php drop-in and files outside
	 * WordPress (such as Composer libraries) are skipped, so the call is
	 * attributed to the plugin or theme that made it however it got there,
	 * including from hook callbacks.
	 *
	 * A hook dispatch is a boundary: if the walk reaches one before finding
	 * any such frame, the call was made by a hook callback made only of core
	 * code (such as a core callback on wp_head, fired from a theme's
	 * header.php), so it's attributed to core rather than to whoever fired
	 * the hook. The hooks DebugHawk itself listens on, at the start of the
	 * stack, don't count.
	 *
	 * Only when no such frame exists is the call attributed to core.
	 */
	public function parse(): ?array {
		$prefixes    = $this->prefixes();
		$trace       = array_values( $this->trace );
		$core_index  = null;
		$other       = null;
		$in_plumbing = true;

		foreach ( $trace as $index => $frame ) {
			$is_ignored = $this->is_ignored( $frame );

			if ( $core_index === null && ! $is_ignored ) {
				$core_index = $index;
			}

			// The frames at the start are DebugHawk, the hook it listens on and
			// wpdb or WP_Http. The first frame after them is where the caller is.
			if ( $in_plumbing && ! $is_ignored ) {
				$in_plumbing = false;
			}

			if ( ! empty( $frame['file'] ) ) {
				$component = $this->classify( $frame['file'], $prefixes );

				if ( $component !== null && $component['component'] === 'other' ) {
					// Drop-ins such as object-cache.php usually run on behalf of a
					// plugin or theme further out, so they're only a fallback.
					$other = $other ?? $this->describe( $frame, $component );
				} elseif ( $component !== null && $component['component'] !== 'core' ) {
					return $this->describe( $frame, $component );
				}
			}

			if ( ! $in_plumbing && $this->is_hook_dispatch( $frame ) ) {
				break;
			}
		}

		if ( $other !== null ) {
			return $other;
		}

		return $core_index !== null
			? $this->describe_core( $trace, $core_index, $prefixes )
			: null;
	}

	/**
	 * Describes the most useful place in core: the first frame outside the
	 * hook, HTTP and wpdb plumbing. When that frame is a hook callback, its
	 * file and line are WP_Hook's, so they're taken from inside the callback
	 * instead (where it made its next call), keeping the callback as the
	 * function.
	 */
	protected function describe_core( array $trace, int $index, array $prefixes ): ?array {
		$frame    = $trace[ $index ];
		$location = $frame;

		if ( $index > 0 && ! empty( $trace[ $index - 1 ]['file'] ) && $this->is_hook_dispatch( $trace[ $index + 1 ] ?? [] ) ) {
			$location = $trace[ $index - 1 ];
		}

		foreach ( [ $location, $frame ] as $candidate ) {
			if ( empty( $candidate['file'] ) ) {
				continue;
			}

			$component = $this->classify( $candidate['file'], $prefixes );

			if ( $component !== null ) {
				return $this->describe( $frame, $component, $candidate );
			}
		}

		return null;
	}

	protected function is_hook_dispatch( array $frame ): bool {
		return ( $frame['class'] ?? null ) === 'WP_Hook'
			&& in_array( $frame['function'] ?? null, [ 'apply_filters', 'do_action', 'do_all_hook' ], true );
	}

	protected function is_ignored( array $frame ): bool {
		if ( isset( $frame['class'] ) && in_array( $frame['class'], $this->ignored_classes, true ) ) {
			return true;
		}

		return isset( $frame['function'] ) && in_array( $frame['function'], $this->ignored_functions, true );
	}

	/**
	 * @return array{component: string, slug: ?string}|null Null for files that
	 *                                                      are never responsible.
	 */
	protected function classify( string $file, array $prefixes ): ?array {
		$path = DIRECTORY_SEPARATOR === '\\' ? str_replace( '\\', '/', $file ) : $file;

		$match = $this->match_prefix( $path, $prefixes );

		// A symlinked plugin outside every known directory: WordPress records
		// where its real path maps to, so try again with that.
		if ( $match === false ) {
			foreach ( $GLOBALS['wp_plugin_paths'] ?? [] as $plugin_path => $real_path ) {
				if ( strpos( $path, $real_path . '/' ) === 0 ) {
					$match = $this->match_prefix( $plugin_path . substr( $path, strlen( $real_path ) ), $prefixes );
					break;
				}
			}
		}

		return $match === false ? null : $match;
	}

	/**
	 * @return array{component: string, slug: ?string}|false|null The component,
	 *         false if no prefix matched, or null for files that are never responsible.
	 */
	protected function match_prefix( string $path, array $prefixes ) {
		// Longest prefix first, so the most specific directory wins.
		foreach ( $prefixes as [ $prefix, $component, $slug ] ) {
			if ( strpos( $path, $prefix ) !== 0 ) {
				continue;
			}

			if ( $component === null ) {
				return null;
			}

			return [
				'component' => $component,
				'slug'      => $slug ?? $this->slug( $component, substr( $path, strlen( $prefix ) ) ),
			];
		}

		return false;
	}

	/**
	 * The first path segment under the plugins or theme directory: the plugin
	 * or theme folder, or the file name for a single-file plugin.
	 */
	protected function slug( string $component, string $relative_path ): ?string {
		if ( $component !== 'plugin' && $component !== 'theme' ) {
			return null;
		}

		$slash = strpos( $relative_path, '/' );

		return $slash === false ? $relative_path : substr( $relative_path, 0, $slash );
	}

	/**
	 * @param array|null $location The frame to take the file and line from,
	 *                             if not the frame itself.
	 */
	protected function describe( array $frame, array $component, ?array $location = null ): array {
		$class    = $frame['class'] ?? null;
		$location = $location ?? $frame;

		if ( in_array( $class, self::WPDB_DROPINS, true ) ) {
			$class = 'wpdb';
		}

		return array_filter( [
			'component' => $component['component'],
			'file'      => $location['file'],
			'line'      => $location['line'] ?? null,
			'function'  => $frame['function'] ?? null,
			'class'     => $class,
			'slug'      => $component['slug'],
		] );
	}

	/**
	 * The directories WordPress code lives in, from its constants and globals,
	 * so any layout works: WordPress in a subdirectory, a moved wp-content,
	 * plugins or mu-plugins directory, or extra theme directories.
	 */
	protected function directories(): array {
		$content     = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : null;
		$theme_roots = $GLOBALS['wp_theme_directories'] ?? [];

		if ( ! $theme_roots && $content !== null ) {
			$theme_roots = [ $content . '/themes' ];
		}

		return [
			'abspath'     => defined( 'ABSPATH' ) ? ABSPATH : null,
			'content'     => $content,
			'plugins'     => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : null,
			'mu_plugins'  => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : null,
			'theme_roots' => array_values( $theme_roots ),
			'debughawk'   => dirname( __DIR__ ),
		];
	}

	/**
	 * Path prefixes, longest first, each with its component (null for files
	 * that are never responsible) and a fixed slug where the path can't give one.
	 *
	 * @return array<int, array{0: string, 1: ?string, 2: ?string}>
	 */
	protected function prefixes(): array {
		$directories = $this->directories();

		if ( self::$prefixes !== null && self::$prefixes_directories === $directories ) {
			return self::$prefixes;
		}

		$prefixes = $this->directory_prefixes( $directories['debughawk'], null );

		if ( $directories['content'] !== null ) {
			array_push(
				$prefixes,
				...$this->file_prefixes( $directories['content'] . '/db.php', null ),
				...$this->directory_prefixes( $directories['content'], 'other' )
			);
		}

		if ( $directories['plugins'] !== null ) {
			array_push(
				$prefixes,
				...$this->directory_prefixes( $directories['plugins'], 'plugin' ),
				...$this->symlink_prefixes( $directories['plugins'], 'plugin' )
			);
		}

		if ( $directories['mu_plugins'] !== null ) {
			array_push(
				$prefixes,
				...$this->directory_prefixes( $directories['mu_plugins'], 'mu-plugin' ),
				...$this->symlink_prefixes( $directories['mu_plugins'], 'mu-plugin' )
			);
		}

		foreach ( $directories['theme_roots'] as $theme_root ) {
			array_push(
				$prefixes,
				...$this->directory_prefixes( $theme_root, 'theme' ),
				...$this->symlink_prefixes( $theme_root, 'theme' )
			);
		}

		if ( $directories['abspath'] !== null ) {
			array_push( $prefixes, ...$this->directory_prefixes( $directories['abspath'], 'core' ) );
		}

		// Longest first. On a tie, files that are never responsible win, so
		// DebugHawk is skipped even when it's a symlinked plugin itself.
		usort( $prefixes, static function ( array $a, array $b ): int {
			return ( strlen( $b[0] ) <=> strlen( $a[0] ) ) ?: ( ( $a[1] !== null ) <=> ( $b[1] !== null ) );
		} );

		self::$prefixes_directories = $directories;

		return self::$prefixes = $prefixes;
	}

	/**
	 * A directory as a prefix with a trailing slash, plus its real path if
	 * it's symlinked, since PHP reports real paths in backtraces.
	 */
	protected function directory_prefixes( string $directory, ?string $component ): array {
		$prefixes = [];

		foreach ( array_unique( [ $directory, (string) realpath( $directory ) ] ) as $path ) {
			if ( $path !== '' ) {
				$prefixes[] = [ rtrim( str_replace( '\\', '/', $path ), '/' ) . '/', $component, null ];
			}
		}

		return $prefixes;
	}

	/**
	 * A single file, plus its real path if it's symlinked.
	 */
	protected function file_prefixes( string $file, ?string $component ): array {
		$prefixes = [];

		foreach ( array_unique( [ $file, (string) realpath( $file ) ] ) as $path ) {
			if ( $path !== '' ) {
				$prefixes[] = [ str_replace( '\\', '/', $path ), $component, null ];
			}
		}

		return $prefixes;
	}

	/**
	 * Real paths of symlinked plugins, themes or mu-plugins in a directory,
	 * so each gets its folder or file name as its slug. WordPress records
	 * symlinked plugins in $wp_plugin_paths, but not themes or mu-plugins
	 * (which managed hosts often symlink), and its mapping names a symlinked
	 * single-file plugin after its target.
	 */
	protected function symlink_prefixes( string $directory, string $component ): array {
		$entries = is_dir( $directory ) ? scandir( $directory ) : false;

		if ( ! $entries ) {
			return [];
		}

		$prefixes = [];

		foreach ( $entries as $entry ) {
			$path = $directory . '/' . $entry;

			if ( $entry === '.' || $entry === '..' || ! is_link( $path ) ) {
				continue;
			}

			$real_path = realpath( $path );

			if ( $real_path === false ) {
				continue;
			}

			$real_path = str_replace( '\\', '/', $real_path );

			$prefixes[] = [
				is_dir( $real_path ) ? rtrim( $real_path, '/' ) . '/' : $real_path,
				$component,
				$component === 'mu-plugin' ? null : $entry,
			];
		}

		return $prefixes;
	}
}
