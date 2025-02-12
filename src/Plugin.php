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
			->add( new RequestCollector( $this->config ) )
			->add( new WordpressCollector( $this->config ) )
			->init();

		$dispatchers
			->add( new BeaconDispatcher( $this->config, $collectors ) )
			->add( new RedirectDispatcher( $this->config, $collectors ) )
			->init();
	}
}