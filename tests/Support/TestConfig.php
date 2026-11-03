<?php

namespace DebugHawk\Tests\Support;

use DebugHawk\Config;
use ReflectionClass;

/**
 * Builds a Config without its constructor, which needs WordPress.
 */
class TestConfig {
	public static function make( array $values = [] ): Config {
		$reflection = new ReflectionClass( Config::class );
		$config     = $reflection->newInstanceWithoutConstructor();

		foreach ( [ 'config' => $values, 'db_config' => [] ] as $property => $value ) {
			$property = $reflection->getProperty( $property );
			$property->setAccessible( true );
			$property->setValue( $config, $value );
		}

		$config->version = '1.0.0';

		return $config;
	}
}
