<?php

namespace DebugHawk\Collectors;

class ObjectCacheCollector extends Collector {
	public string $key = 'object_cache';

	public function gather(): array {
		global $wp_object_cache;

		if ( is_object( $wp_object_cache ) ) {
			$object_vars = get_object_vars( $wp_object_cache );

			if ( array_key_exists( 'cache_hits', $object_vars ) ) {
				$cache_hits = (int) $object_vars['cache_hits'];
			}

			if ( array_key_exists( 'cache_misses', $object_vars ) ) {
				$cache_misses = (int) $object_vars['cache_misses'];
			}
		}

		return [
			'persistent_enabled' => (bool) wp_using_ext_object_cache(),
			'cache_hits'         => $cache_hits ?? null,
			'cache_misses'       => $cache_misses ?? null,
		];
	}
}