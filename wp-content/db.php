<?php
/*
Plugin Name: DebugHawk Database Drop-In
Description: Database drop-in for DebugHawk to capture database metrics and slow queries.
Version: 0.5.2
Author: DebugHawk
Author URI: https://debughawk.com/
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_USER' ) ) {
	return;
}

if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

if ( php_sapi_name() === 'cli' ) {
	return;
}

if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
	return;
}

if ( is_admin() ) {
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'upgrade-plugin' ) {
		return;
	}

	if ( isset( $_POST['action'] ) && $_POST['action'] === 'update-plugin' ) {
		return;
	}
}

if ( file_exists( dirname( __FILE__, 2 ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__, 2 ) . '/vendor/autoload.php';
}

$wpdb = new \DebugHawk\DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
