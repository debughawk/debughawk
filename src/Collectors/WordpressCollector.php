<?php

namespace DebugHawk\Collectors;

class WordpressCollector implements CollectorInterface {
	public function collect(): array {
		$user_id = get_current_user_id();
		
		return [
			'is_admin'  => is_admin(),
			'post_id'   => is_singular() ? get_the_ID() : null,
			'post_type' => is_singular() ? get_post_type() : null,
			'user_id'   => $user_id === 0 ? null : $user_id,
			'version'   => get_bloginfo( 'version' ),
		];
	}
}