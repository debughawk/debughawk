<?php

namespace DebugHawk;

class Plugin {
	public Config $config;
	public Beacon $beacon;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function init(): void {
		if ( ! $this->config->enabled ) {
			return;
		}

		$this->beacon = new Beacon( $this->config );
		$this->beacon->init();
	}
}