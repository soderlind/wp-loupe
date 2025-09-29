<?php
namespace WPLoupeTests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_MCP_Server;

class WP_Loupe_MCP_CursorTest extends TestCase {
	private function invokePrivate( $object, string $method, ...$args ) {
		$ref = new \ReflectionClass( $object );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $object, $args );
	}

	public function test_cursor_round_trip() {
		$server = WP_Loupe_MCP_Server::get_instance();
		$parts  = [ 'o' => 20, 'q' => md5( 'hello world' ) ];
		$cursor = $this->invokePrivate( $server, 'encode_cursor', $parts );
		$this->assertIsString( $cursor );
		$decoded = $this->invokePrivate( $server, 'decode_cursor', $cursor );
		$this->assertSame( $parts, $decoded );
	}

	public function test_cursor_tamper_detection() {
		$server = WP_Loupe_MCP_Server::get_instance();
		$parts  = [ 'o' => 10, 'q' => md5( 'query' ) ];
		$cursor = $this->invokePrivate( $server, 'encode_cursor', $parts );
		// Corrupt one character in the base64 string (while keeping length) to break HMAC
		$tampered = substr( $cursor, 0, -2 ) . 'xx';
		$decoded  = $this->invokePrivate( $server, 'decode_cursor', $tampered );
		$this->assertNull( $decoded, 'Tampered cursor should fail HMAC validation and return null' );
	}
}
