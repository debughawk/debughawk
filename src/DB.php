<?php

namespace DebugHawk;

use wpdb;

class DB extends wpdb {

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