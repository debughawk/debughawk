<?php
// The few WordPress functions collectors call, for unit tests.

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? '';
	}
}

// Set $GLOBALS['test_is_admin'] or $GLOBALS['test_customize_preview'] in a test.
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['test_is_admin'] );
	}
}

if ( ! function_exists( 'is_customize_preview' ) ) {
	function is_customize_preview() {
		return ! empty( $GLOBALS['test_customize_preview'] );
	}
}
