<?php

namespace DebugHawk\Collectors;

class WordpressCollector extends Collector {
	public string $key = 'wordpress';

	public function gather(): array {
		$user_id = get_current_user_id();

		return [
			'is_admin'      => is_admin(),
			'is_front_page' => is_front_page(),
			'is_search'     => is_search(),
			'is_404'        => is_404(),
			'post_id'       => is_singular() ? get_the_ID() : null,
			'post_type'     => is_singular() ? get_post_type() : null,
			'user_id'       => $user_id ?: null,
			'version'       => get_bloginfo( 'version' ),
		];
	}
}