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
		echo '<!-- DebugHawk -->' . "\n\n";
		wp_print_inline_script_tag( "window.DebugHawk = '" . esc_js( $this->gather_and_encrypt() ) . "';" );
	}

	protected function should_output_beacon(): bool {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		// Don't dispatch during a Customizer preview request:
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		// Don't dispatch inside the Site Editor:
		if ( isset( $_SERVER['SCRIPT_NAME'] ) && $_SERVER['SCRIPT_NAME'] === '/wp-admin/site-editor.php' ) {
			return false;
		}

		return apply_filters( 'debughawk_should_output_beacon', true );
	}

	public function enqueue_beacon(): void {
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