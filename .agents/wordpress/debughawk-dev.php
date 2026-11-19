<?php
/**
 * Plugin Name: DebugHawk orb development helpers
 * Description: Installed by .agents/setup into the orb's WordPress site only.
 *
 * - /debughawk-dev/beacon receives beacons (DEBUGHAWK_CONFIG points its endpoint
 *   here), decrypts the server payload and appends each one as a JSON line to
 *   wp-content/debughawk-beacons.log.
 * - /?dev-login signs in as the admin user and opens wp-admin.
 */

$debughawk_dev_path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

if ( $debughawk_dev_path === '/debughawk-dev/beacon' && ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
	$beacon = json_decode( file_get_contents( 'php://input' ), true );

	if ( is_array( $beacon ) && is_string( $beacon['server'] ?? null ) ) {
		$raw    = base64_decode( $beacon['server'] );
		$ivlen  = openssl_cipher_iv_length( 'aes-128-ctr' );
		$server = openssl_decrypt( substr( $raw, $ivlen ), 'aes-128-ctr', DEBUGHAWK_CONFIG['secret'], OPENSSL_RAW_DATA, substr( $raw, 0, $ivlen ) );

		$beacon['server'] = json_decode( (string) $server, true );
	}

	file_put_contents( WP_CONTENT_DIR . '/debughawk-beacons.log', json_encode( $beacon ) . "\n", FILE_APPEND | LOCK_EX );

	status_header( 204 );
	exit;
}

if ( isset( $_GET['dev-login'] ) ) {
	add_action( 'init', function () {
		$user = get_user_by( 'login', 'admin' );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		wp_safe_redirect( admin_url() );
		exit;
	} );
}
