<?php

namespace DebugHawk;

class Settings {
	private const OPTION_NAME = 'debughawk_config';
	private const PAGE_SLUG = 'debughawk';
	private const SETTINGS_GROUP = 'debughawk_settings_group';

	private Config $config;
	private bool $has_constant;
	private array $constant_config;

	public function __construct( Config $config ) {
		$this->config          = $config;
		$this->has_constant    = defined( 'DEBUGHAWK_CONFIG' );
		$this->constant_config = $this->has_constant ? DEBUGHAWK_CONFIG : [];
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		if ( ! $this->config->configured() ) {
			add_action( 'admin_notices', [ $this, 'admin_notice_unconfigured' ] );
		}
	}

	public function add_menu_page(): void {
		add_options_page(
			__( 'DebugHawk', 'debughawk' ),
			__( 'DebugHawk', 'debughawk' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);

		add_settings_section(
			'debughawk_main_section',
			__( 'DebugHawk Configuration', 'debughawk' ),
			[ $this, 'render_section_description' ],
			self::PAGE_SLUG
		);

		$this->add_settings_field( 'enabled', __( 'Enable DebugHawk', 'debughawk' ), 'render_checkbox_field' );
		$this->add_settings_field( 'endpoint', __( 'Endpoint URL', 'debughawk' ), 'render_text_field' );
		$this->add_settings_field( 'secret', __( 'Secret Key', 'debughawk' ), 'render_password_field' );
		$this->add_settings_field( 'sample_rate', __( 'Sample Rate', 'debughawk' ), 'render_number_field' );
		$this->add_settings_field( 'trace_admin_pages', __( 'Trace Admin Pages', 'debughawk' ), 'render_checkbox_field' );
		$this->add_settings_field( 'trace_redirects', __( 'Trace Redirects', 'debughawk' ), 'render_checkbox_field' );
		$this->add_settings_field( 'slow_queries_threshold', __( 'Slow Queries Threshold (ms)', 'debughawk' ), 'render_number_field' );
	}

	private function add_settings_field( string $field_id, string $title, string $callback ): void {
		add_settings_field(
			'debughawk_' . $field_id,
			$title,
			[ $this, $callback ],
			self::PAGE_SLUG,
			'debughawk_main_section',
			[ 'field' => $field_id ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( $this->has_constant ): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e( 'DebugHawk is configured via the DEBUGHAWK_CONFIG constant in wp-config.php. Settings on this page are disabled.', 'debughawk' ); ?></p>
                </div>
			<?php endif; ?>

            <form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );

				if ( ! $this->has_constant ) {
					submit_button();
				}
				?>
            </form>
        </div>
		<?php
	}

	public function render_section_description(): void {
		?>
        <p><?php esc_html_e( 'Configure DebugHawk to monitor your WordPress site performance.', 'debughawk' ); ?></p>
		<?php
	}

	public function render_checkbox_field( array $args ): void {
		$field    = $args['field'];
		$value    = $this->get_field_value( $field );
		$disabled = $this->is_field_disabled( $field );
		?>
        <input type="checkbox"
               name="<?php echo esc_attr( self::OPTION_NAME . '[' . $field . ']' ); ?>"
               id="debughawk_<?php echo esc_attr( $field ); ?>"
               value="1"
			<?php checked( 1, $value ); ?>
			<?php disabled( $disabled ); ?> />
		<?php
		$this->render_field_description( $field );
	}

	public function render_text_field( array $args ): void {
		$field    = $args['field'];
		$value    = $this->get_field_value( $field );
		$disabled = $this->is_field_disabled( $field );
		?>
        <input type="text"
               name="<?php echo esc_attr( self::OPTION_NAME . '[' . $field . ']' ); ?>"
               id="debughawk_<?php echo esc_attr( $field ); ?>"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text"
			<?php disabled( $disabled ); ?> />
		<?php
		$this->render_field_description( $field );
	}

	public function render_password_field( array $args ): void {
		$field    = $args['field'];
		$value    = $this->get_field_value( $field );
		$disabled = $this->is_field_disabled( $field );
		?>
        <input type="password"
               name="<?php echo esc_attr( self::OPTION_NAME . '[' . $field . ']' ); ?>"
               id="debughawk_<?php echo esc_attr( $field ); ?>"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text"
			<?php disabled( $disabled ); ?> />
		<?php
		$this->render_field_description( $field );
	}

	public function render_number_field( array $args ): void {
		$field    = $args['field'];
		$value    = $this->get_field_value( $field );
		$disabled = $this->is_field_disabled( $field );

		$attributes = [
			'step' => 'any',
			'min'  => '0',
		];

		if ( $field === 'sample_rate' ) {
			$attributes['max']  = '1';
			$attributes['step'] = '0.01';
		}
		?>
        <input type="number"
               name="<?php echo esc_attr( self::OPTION_NAME . '[' . $field . ']' ); ?>"
               id="debughawk_<?php echo esc_attr( $field ); ?>"
               value="<?php echo esc_attr( $value ); ?>"
               class="small-text"
			<?php foreach ( $attributes as $attr => $val ) {
				echo esc_attr( $attr ) . '="' . esc_attr( $val ) . '" ';
			} ?>
			<?php disabled( $disabled ); ?> />
		<?php
		$this->render_field_description( $field );
	}

	private function render_field_description( string $field ): void {
		$descriptions = [
			'enabled'                => __( 'Enable or disable DebugHawk monitoring.', 'debughawk' ),
			'endpoint'               => __( 'The URL endpoint where performance data will be sent.', 'debughawk' ),
			'secret'                 => __( 'Secret key for encrypting data before transmission.', 'debughawk' ),
			'sample_rate'            => __( 'Percentage of requests to monitor (0-1). For example, 0.1 means 10% of requests.', 'debughawk' ),
			'trace_admin_pages'      => __( 'Include WordPress admin pages in monitoring.', 'debughawk' ),
			'trace_redirects'        => __( 'Monitor redirect responses.', 'debughawk' ),
			'slow_queries_threshold' => __( 'Database queries taking longer than this (in milliseconds) are considered slow.', 'debughawk' ),
		];

		if ( isset( $descriptions[ $field ] ) ) {
			echo '<p class="description">' . esc_html( $descriptions[ $field ] ) . '</p>';
		}
	}

	private function get_field_value( string $field ) {
		return $this->config->$field;
	}

	private function is_field_disabled( string $field ): bool {
		return $this->has_constant;
	}

	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];

		$sanitized['enabled']                = ! empty( $input['enabled'] );
		$sanitized['endpoint']               = esc_url_raw( $input['endpoint'] ?? '' );
		$sanitized['secret']                 = sanitize_text_field( $input['secret'] ?? '' );
		$sanitized['sample_rate']            = min( 1, max( 0, floatval( $input['sample_rate'] ?? 1 ) ) );
		$sanitized['trace_admin_pages']      = ! empty( $input['trace_admin_pages'] );
		$sanitized['trace_redirects']        = ! empty( $input['trace_redirects'] );
		$sanitized['slow_queries_threshold'] = max( 0, intval( $input['slow_queries_threshold'] ?? 50 ) );

		return $sanitized;
	}

	public function enqueue_scripts( $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', '
			.form-table input[type="checkbox"]:disabled,
			.form-table input[type="text"]:disabled,
			.form-table input[type="password"]:disabled,
			.form-table input[type="number"]:disabled {
				background-color: #f0f0f0;
				cursor: not-allowed;
			}
		' );
	}

	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'debughawk_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'debughawk_activation_redirect' );

		// Don't redirect if activating multiple plugins at once
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function admin_notice_unconfigured(): void {
		// Don't show the notice on the settings page itself
		if ( isset( $_GET['page'] ) && $_GET['page'] === self::PAGE_SLUG ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %s: URL to settings page */
					esc_html__( 'Welcome to DebugHawk! %s to start seeing performance insights from your WordPress site.', 'debughawk' ),
					'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Configure your settings', 'debughawk' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	public static function get_settings(): array {
		return get_option( self::OPTION_NAME, [] );
	}
}