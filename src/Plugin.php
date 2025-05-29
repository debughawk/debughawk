<?php

namespace DebugHawk;

use DebugHawk\Collectors\ConfigCollector;
use DebugHawk\Collectors\DatabaseCollector;
use DebugHawk\Collectors\ObjectCacheCollector;
use DebugHawk\Collectors\OutgoingRequestsCollector;
use DebugHawk\Collectors\PhpCollector;
use DebugHawk\Collectors\RequestCollector;
use DebugHawk\Collectors\WordpressCollector;
use DebugHawk\Dispatchers\BeaconDispatcher;
use DebugHawk\Dispatchers\RedirectDispatcher;

class Plugin {
	public Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_update_dropin' ) );

		register_activation_hook( $this->config->path, array( $this, 'activate' ) );
		register_deactivation_hook( $this->config->path, array( $this, 'deactivate' ) );

		if ( ! $this->config->enabled || ! $this->config->configured() ) {
			return;
		}

		$collectors  = new CollectorManager();
		$dispatchers = new DispatcherManager();

		$collectors
			->add( new ConfigCollector( $this->config ) )
			->add( new DatabaseCollector( $this->config ) )
			->add( new OutgoingRequestsCollector( $this->config ) )
			->add( new ObjectCacheCollector( $this->config ) )
			->add( new PhpCollector( $this->config ) )
			->add( new RequestCollector( $this->config ) )
			->add( new WordpressCollector( $this->config ) )
			->init();

		$dispatchers
			->add( new BeaconDispatcher( $this->config, $collectors ) )
			->add( new RedirectDispatcher( $this->config, $collectors ) )
			->init();
	}

	public function maybe_update_dropin(): void {
		$db_file     = WP_CONTENT_DIR . '/db.php';
		$plugin_file = plugin_dir_path( $this->config->path ) . 'wp-content/db.php';

		if ( ! file_exists( $db_file ) ) {
			return;
		}

		$dropin = get_plugin_data( $db_file );
		$plugin = get_plugin_data( $plugin_file );

		if ( $dropin['AuthorURI'] !== $plugin['AuthorURI'] ) {
			return;
		}

		if ( version_compare( $dropin['Version'], $plugin['Version'], '>=' ) ) {
			return;
		}

		if ( is_writable( WP_CONTENT_DIR ) ) {
			copy( $plugin_file, $db_file );
		}
	}

	public function activate(): void {
		$db_file = WP_CONTENT_DIR . '/db.php';

		if ( is_writable( WP_CONTENT_DIR ) && ! file_exists( $db_file ) ) {
			copy( plugin_dir_path( $this->config->path ) . 'wp-content/db.php', $db_file );
		}
	}

	public function deactivate(): void {
		$db_file = WP_CONTENT_DIR . '/db.php';

		if ( ! file_exists( $db_file ) ) {
			return;
		}

		$db_file_data = get_plugin_data( $db_file );

		if ( empty( $db_file_data['AuthorURI'] ) || $db_file_data['AuthorURI'] !== 'https://debughawk.com/' ) {
			return;
		}

		unlink( $db_file );
	}

}