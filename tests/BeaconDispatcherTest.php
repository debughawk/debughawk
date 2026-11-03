<?php

namespace DebugHawk\Tests;

use DebugHawk\CollectorManager;
use DebugHawk\Dispatchers\BeaconDispatcher;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

class BeaconDispatcherTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['test_is_admin'], $GLOBALS['test_customize_preview'], $GLOBALS['pagenow'], $GLOBALS['test_enqueued_scripts'] );
	}

	public function test_excludes_iframe_screens(): void {
		$dispatcher = new class( TestConfig::make(), new CollectorManager() ) extends BeaconDispatcher {
			public function excluded(): bool {
				return $this->is_excluded_screen();
			}

			protected function is_iframe_request(): bool {
				return true;
			}
		};

		$this->assertTrue( $dispatcher->excluded() );
	}

	public function test_prints_nothing_when_the_beacon_script_is_not_enqueued(): void {
		$GLOBALS['test_enqueued_scripts'] = [];

		$this->assertSame( '', $this->output() );
	}

	public function test_prints_the_payload_when_the_beacon_script_is_enqueued(): void {
		$GLOBALS['test_enqueued_scripts'] = [ 'debughawk-beacon' ];

		$this->assertStringContainsString( "<script>window.DebugHawk = '", $this->output() );
	}

	private function output( array $config = [ 'secret' => '0123456789abcdef' ] ): string {
		$dispatcher = new BeaconDispatcher( TestConfig::make( $config ), new CollectorManager() );

		ob_start();
		$dispatcher->output_beacon_metrics();

		return (string) ob_get_clean();
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
