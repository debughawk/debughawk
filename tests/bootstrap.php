<?php

require dirname( __DIR__ ) . '/vendor/autoload.php';

// A WordPress layout for Backtrace tests. None of these paths need to exist.
define( 'ABSPATH', '/srv/www/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', '/srv/www/wp-content' );
define( 'WP_PLUGIN_DIR', '/srv/www/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins' );
