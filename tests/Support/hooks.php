<?php
// Records add_filter() and add_action() calls in $GLOBALS['test_hooks'], for unit tests.

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['test_hooks'][ $hook ][] = [ 'priority' => $priority, 'accepted_args' => $accepted_args ];

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
