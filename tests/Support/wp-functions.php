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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// Set $GLOBALS['test_enqueued_scripts'] to the handles a test treats as enqueued.
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		return in_array( $handle, $GLOBALS['test_enqueued_scripts'] ?? [], true );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
	function wp_print_inline_script_tag( $data, $attributes = [] ) {
		echo '<script>' . $data . '</script>';
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
