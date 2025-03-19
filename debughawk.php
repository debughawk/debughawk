<?php
/*
 * Plugin Name: DebugHawk
 * Plugin URI:   https://deploymenthawk.com
 * Description:  DebugHawk helper plugin.
 * Author:       Ashley Rich
 * Version:      0.2
 * Requires PHP: 7.4
 * Requires WP:  6.3
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
$version = '0.2';

( new Plugin(
	new Config( $config, __FILE__, $version ),
) )->init();
