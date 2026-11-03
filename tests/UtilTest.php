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

	/**
	 * @dataProvider urls
	 */
	public function test_redact_url( string $input, string $expected ): void {
		$this->assertSame( $expected, Util::redact_url( $input ) );
		$this->assertSame( $expected, Util::redact_url( $expected ), 'Redacting twice changes nothing' );
	}

	public function urls(): array {
		return [
			'no query'                    => [ 'https://example.com/blog/hello-world/', 'https://example.com/blog/hello-world/' ],
			'search term'                 => [ 'https://example.com/?s=jo%40example.com', 'https://example.com/?s=?' ],
			'password reset link'         => [ 'https://example.com/wp-login.php?action=rp&key=Xk2pQ9&login=jo', 'https://example.com/wp-login.php?action=?&key=?&login=?' ],
			'WooCommerce order key'       => [ 'https://example.com/checkout/order-received/123/?key=wc_order_abc123', 'https://example.com/checkout/order-received/123/?key=?' ],
			'API key in an outgoing call' => [ 'https://maps.googleapis.com/maps/api/geocode/json?address=10+Downing+St&key=AIzaSyA', 'https://maps.googleapis.com/maps/api/geocode/json?address=?&key=?' ],
			'credentials'                 => [ 'https://user:p%40ss@api.example.com/v1/items', 'https://api.example.com/v1/items' ],
			'fragment'                    => [ 'https://example.com/page?tab=2#section', 'https://example.com/page?tab=?' ],
			'empty value and a flag'      => [ 'https://example.com/?s=&preview', 'https://example.com/?s=?&preview' ],
			'array keys'                  => [ 'https://example.com/shop/?filter[color]=red&ids[]=4', 'https://example.com/shop/?filter[color]=?&ids[]=?' ],
			'@ in the query, not a login' => [ 'https://example.com/unsubscribe?email=jo@example.com', 'https://example.com/unsubscribe?email=?' ],
		];
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
