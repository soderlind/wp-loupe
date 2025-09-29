<?php
namespace WPLoupeTests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_MCP_Server;

class WP_Loupe_MCP_TokenTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Ensure tokens option empty for clean slate
		update_option( 'wp_loupe_mcp_tokens', [] );
	}

	private function invokePrivate( $object, string $method, ...$args ) {
		$ref = new \ReflectionClass( $object );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $object, $args );
	}

	public function test_issue_token_with_scope_filtering() {
		$server = WP_Loupe_MCP_Server::get_instance();
		// Request a mix of valid + invalid scopes; invalid should be dropped resulting in valid subset.
		$result = $this->invokePrivate( $server, 'oauth_issue_access_token', 'wp-loupe-local', '', [ 'search.read', 'bogus.scope' ] );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayHasKey( 'scope', $result );
		$scopes = explode( ' ', $result[ 'scope' ] );
		$this->assertContains( 'search.read', $scopes );
		$this->assertNotContains( 'bogus.scope', $scopes );
	}

	public function test_issue_indefinite_token() {
		$server = WP_Loupe_MCP_Server::get_instance();
		$result = $this->invokePrivate( $server, 'oauth_issue_access_token', 'wp-loupe-local', '', [ 'search.read' ], 0 );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result[ 'expires_in' ], 'Indefinite token should report expires_in = 0' );
		$token = $result[ 'access_token' ];
		// Fetch internal record via validate (public path used in real flow); treat as private though
		$validation = $this->invokePrivate( $server, 'oauth_validate_bearer', $token );
		$this->assertIsArray( $validation );
		$this->assertSame( 0, $validation[ 'expires_at' ] );
	}
}
