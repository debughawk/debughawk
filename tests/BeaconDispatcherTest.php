<?php

namespace DebugHawk\Tests;

use DebugHawk\CollectorManager;
use DebugHawk\Dispatchers\BeaconDispatcher;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

class BeaconDispatcherTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['test_is_admin'], $GLOBALS['test_customize_preview'], $GLOBALS['pagenow'] );
	}

	/**
	 * @dataProvider screens
	 */
	public function test_excludes_screens_that_are_not_page_views( bool $is_admin, bool $customize_preview, string $pagenow, bool $excluded ): void {
		$GLOBALS['test_is_admin']          = $is_admin;
		$GLOBALS['test_customize_preview'] = $customize_preview;
		$GLOBALS['pagenow']                = $pagenow;

		$dispatcher = new class( TestConfig::make(), new CollectorManager() ) extends BeaconDispatcher {
			public function excluded(): bool {
				return $this->is_excluded_screen();
			}
		};

		$this->assertSame( $excluded, $dispatcher->excluded() );
	}

	public function screens(): array {
		return [
			// $pagenow is the script name wherever WordPress is installed,
			// including /wp/wp-admin/site-editor.php in a subdirectory install.
			'site editor'          => [ true, false, 'site-editor.php', true ],
			'customizer preview'   => [ false, true, 'index.php', true ],
			'front end'            => [ false, false, 'index.php', false ],
			'posts screen'         => [ true, false, 'edit.php', false ],
			'customizer controls'  => [ true, false, 'customize.php', false ],
		];
	}
}
