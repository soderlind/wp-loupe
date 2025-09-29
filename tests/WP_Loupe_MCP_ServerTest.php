<?php
namespace WPLoupeTests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_MCP_Server;

class WP_Loupe_MCP_ServerTest extends TestCase {
	public function test_manifest_contains_expected_commands() {
		$server   = WP_Loupe_MCP_Server::get_instance();
		$manifest = $this->invokePrivate( $server, 'get_manifest' );
		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'commands', $manifest );
		$this->assertContains( 'searchPosts', $manifest[ 'commands' ] );
		$this->assertContains( 'getPost', $manifest[ 'commands' ] );
	}

	public function test_manifest_caches_transient() {
		$server = WP_Loupe_MCP_Server::get_instance();
		$m1     = $this->invokePrivate( $server, 'get_manifest' );
		$m2     = $this->invokePrivate( $server, 'get_manifest' );
		$this->assertSame( $m1, $m2, 'Manifest should be cached between calls in test context.' );
	}

	private function invokePrivate( $object, string $method, ...$args ) {
		$ref = new \ReflectionClass( $object );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $object, $args );
	}
}
