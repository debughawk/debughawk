<?php

namespace DebugHawk;

class ScriptManager {
	public Config $config;
	private $wp_scripts = array();
	private $admin_scripts = array();

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function enqueue( string $handle, string $src ): void {
		$this->wp_scripts[] = array(
			'handle' => $handle,
			'src'    => "resources/dist/{$src}",
		);
	}

	public function enqueue_admin( string $handle, string $src ): void {
		$this->admin_scripts[] = array(
			'handle' => $handle,
			'src'    => "resources/dist/{$src}",
		);
	}

	public function process(): void {
		add_action( 'wp_enqueue_scripts', function () {
			foreach ( $this->wp_scripts as $script ) {
				wp_enqueue_script(
					$script['handle'],
					plugins_url( $script['src'], $this->config->path ),
					array(),
					$this->config->version,
					array(
						'strategy' => 'async',
					),
				);
			}
		} );

		add_action( 'admin_enqueue_scripts', function () {
			foreach ( $this->admin_scripts as $script ) {
				wp_enqueue_script(
					$script['handle'],
					plugins_url( $script['src'], $this->config->path ),
					[],
					$this->config->version,
					true,
				);
			}
		} );
	}
}