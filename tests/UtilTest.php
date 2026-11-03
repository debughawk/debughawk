<?php

namespace DebugHawk\Tests;

use DebugHawk\Util;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase {
	/**
	 * @dataProvider truncations
	 */
	public function test_truncate_never_splits_a_character( string $input, int $max_bytes, string $expected ): void {
		$result = Util::truncate( $input, $max_bytes );

		$this->assertSame( $expected, $result );
		$this->assertTrue( mb_check_encoding( $result, 'UTF-8' ) );
	}

	public function truncations(): array {
		return [
			'short enough'               => [ 'https://example.com', 256, 'https://example.com' ],
			'plain ASCII'                => [ 'abcdef', 4, 'abcd' ],
			'cut inside a 2-byte é'      => [ 'café', 4, 'caf' ],
			'cut after a 2-byte é'       => [ 'café!', 5, 'café' ],
			'cut inside a 3-byte €'      => [ 'a€', 2, 'a' ],
			'cut inside a 4-byte emoji'  => [ 'ab😀', 4, 'ab' ],
			'cut right after the emoji'  => [ 'ab😀c', 6, 'ab😀' ],
		];
	}
}
