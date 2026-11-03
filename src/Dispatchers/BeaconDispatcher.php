<?php

namespace DebugHawk\Dispatchers;

use DebugHawk\NeedsInitiatingInterface;

class BeaconDispatcher extends Dispatcher implements NeedsInitiatingInterface {
	public function init(): void {
		if ( ! $this->should_output_beacon() ) {
			return;
		}

		add_action( 'wp_print_footer_scripts', [ $this, 'output_beacon_metrics' ], 9999 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_beacon' ] );

		if ( $this->config->trace_admin_pages ) {
			add_action( 'admin_print_footer_scripts', [ $this, 'output_beacon_metrics' ], 9999 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_beacon' ] );
			add_action( 'login_enqueue_scripts', [ $this, 'enqueue_beacon' ] );
		}
	}

	public function output_beacon_metrics(): void {
		// Without the beacon script nothing sends the payload, so don't spend
		// time gathering it. That covers login pages when admin pages aren't
		// traced, and embed pages, which never enqueue scripts.
		if ( $this->is_excluded_screen() || ! wp_script_is( 'debughawk-beacon', 'enqueued' ) ) {
			return;
		}

		echo '<!-- DebugHawk -->' . "\n\n";
		wp_print_inline_script_tag( "window.DebugHawk = '" . esc_js( $this->gather_and_encrypt() ) . "';" );
	}

	protected function should_output_beacon(): bool {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		return apply_filters( 'debughawk_should_output_beacon', true );
	}

	/**
	 * Screens that aren't real page views. Checked when the page is output,
	 * not when the plugin loads: the Customizer isn't set up until
	 * plugins_loaded, so checking any earlier never matched.
	 */
	protected function is_excluded_screen(): bool {
		// A Customizer preview.
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}

		// Admin screens shown in an iframe, such as the plugin details modal,
		// the theme installer and the media upload screen. Each would
		// otherwise be counted as a separate page view.
		if ( $this->is_iframe_request() ) {
			return true;
		}

		// The Site Editor. $pagenow works wherever WordPress is installed,
		// unlike matching the script path against /wp-admin/site-editor.php.
		return is_admin() && ( $GLOBALS['pagenow'] ?? '' ) === 'site-editor.php';
	}

	protected function is_iframe_request(): bool {
		return defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST;
	}

	public function enqueue_beacon(): void {
		if ( $this->is_excluded_screen() ) {
			return;
		}

		wp_enqueue_script( 'debughawk-beacon', $this->config->script_url, [], null, [
			'strategy' => 'async',
		] );

		wp_localize_script( 'debughawk-beacon', 'DebugHawkConfig', [
			'endpoint'    => $this->config->dispatcherEndpoint( 'beacon' ),
			'sample_rate' => $this->config->sample_rate,
			'urls'        => [
				'admin'    => admin_url(),
				'home'     => home_url(),
				'includes' => includes_url(),
				'plugin'   => trailingslashit( WP_PLUGIN_URL ),
				'theme'    => trailingslashit( get_theme_root_uri() ),
				'uploads'  => wp_get_upload_dir()['baseurl'],
			],
		] );
	}
}