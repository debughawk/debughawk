<?php

namespace DebugHawk;

class Plugin {
	public Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function init(): void {
		if ( ! $this->config->enabled || ! $this->config->configured() ) {
			return;
		}

		$script = new ScriptManager( $this->config );
		$beacon = new Beacon( $this->config, $script );

		$beacon->init();

		$script->process();
	}
}