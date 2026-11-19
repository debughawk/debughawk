// Serve the site at whatever host reached it, so the portal URL, localhost and
// WP-CLI all work without rewriting siteurl. Portals forward HTTPS.
if ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' ) {
	$_SERVER['HTTPS'] = 'on';
}
$debughawk_dev_origin = ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
define( 'WP_HOME', $debughawk_dev_origin );
define( 'WP_SITEURL', $debughawk_dev_origin );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

// Beacons go to the orb's dev endpoint (see mu-plugins/debughawk-dev.php) and the
// beacon script is the one built from this checkout, not the CDN copy.
define( 'DEBUGHAWK_CONFIG', [
	'endpoint'   => $debughawk_dev_origin . '/debughawk-dev',
	'secret'     => 'orb-development-secret',
	'script_url' => $debughawk_dev_origin . '/wp-content/plugins/debughawk/resources/dist/beacon.js',
] );
