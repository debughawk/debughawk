<?php
/**
 * Plugin Name: DebugHawk
 * Plugin URI: https://debughawk.com/
 * Description: WordPress performance debugging and monitoring, simplified.
 * Author: DebugHawk
 * Version: 0.5.4
 * Requires PHP: 7.4
 * Requires WP: 6.3
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

use DebugHawk\Config;
use DebugHawk\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

$config  = defined( 'DEBUGHAWK_CONFIG' ) ? DEBUGHAWK_CONFIG : [];
$version = '0.5.4';

( new Plugin(
	new Config( $config, __FILE__, $version ),
) )->init();
