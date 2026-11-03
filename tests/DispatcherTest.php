<?php

namespace DebugHawk\Tests;

use DebugHawk\CollectorManager;
use DebugHawk\Collectors\Collector;
use DebugHawk\Dispatchers\Dispatcher;
use DebugHawk\Tests\Support\TestConfig;
use PHPUnit\Framework\TestCase;

class DispatcherTest extends TestCase {
	public function test_invalid_utf8_does_not_drop_the_payload(): void {
		$config = TestConfig::make( [ 'secret' => '0123456789abcdef' ] );

		$collector = new class( $config ) extends Collector {
			public string $key = 'outgoing_requests';

			public function gather(): array {
				// Half of a 2-byte character, as a byte-based cut can leave.
				return [ 'url' => "https://example.com/caf\xC3", 'count' => 1 ];
			}
		};

		$dispatcher = new class( $config, ( new CollectorManager() )->add( $collector ) ) extends Dispatcher {
			public function encrypted(): string {
				return $this->gather_and_encrypt();
			}
		};

		$encrypted = base64_decode( $dispatcher->encrypted() );
		$iv_length = openssl_cipher_iv_length( 'aes-128-ctr' );
		$payload   = openssl_decrypt( substr( $encrypted, $iv_length ), 'aes-128-ctr', '0123456789abcdef', OPENSSL_RAW_DATA, substr( $encrypted, 0, $iv_length ) );

		$this->assertSame(
			[ 'outgoing_requests' => [ 'url' => "https://example.com/caf\u{FFFD}", 'count' => 1 ] ],
			json_decode( $payload, true )
		);
	}
}
