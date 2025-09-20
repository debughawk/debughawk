<?php
/**
 * Plugin Name: DebugHawk Database Drop-In
 * Plugin URI: https://debughawk.com/
 * Description: Database drop-in for DebugHawk to capture database metrics and slow queries.
 * Author: DebugHawk
 * Version: 1.0.1
 * Requires PHP: 7.4
 * Requires WP: 6.3
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
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

class DebugHawkDB extends wpdb {

	public function query( $query ) {
		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
			$this->timer_start();
		}

		$result = parent::query( $query );

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
			$this->log_query(
				$query,
				$this->timer_stop(),
				'',
				$this->time_start,
				[],
			);
		}

		return $result;
	}
}

$wpdb = new DebugHawkDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
