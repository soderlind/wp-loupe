<?php
namespace WPLoupeTests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_MCP_Server;

class WP_Loupe_MCP_RateLimitTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		global $wp_loupe_test_transients;
		$wp_loupe_test_transients = [];
	}

	public function test_anonymous_rate_limit_exceeded() {
		update_option( 'wp_loupe_mcp_rate_limits', [ 'anon_limit' => 2, 'anon_window' => 60, 'auth_limit' => 100, 'auth_window' => 60 ] );
		$server = WP_Loupe_MCP_Server::get_instance();
		$auth   = [ 'authenticated' => false, 'scopes' => [] ];
		$ref    = new \ReflectionClass( $server );
		$m      = $ref->getMethod( 'enforce_rate_limit' );
		$m->setAccessible( true );
		$this->assertTrue( $m->invoke( $server, $auth ) );
		$this->assertTrue( $m->invoke( $server, $auth ) );
		$result = $m->invoke( $server, $auth ); // third
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}

	public function test_authenticated_higher_limit() {
		update_option( 'wp_loupe_mcp_rate_limits', [ 'anon_limit' => 1, 'anon_window' => 60, 'auth_limit' => 3, 'auth_window' => 60 ] );
		$server = WP_Loupe_MCP_Server::get_instance();
		$auth   = [ 'authenticated' => true, 'scopes' => [ 'search.read' ] ];
		$ref    = new \ReflectionClass( $server );
		$m      = $ref->getMethod( 'enforce_rate_limit' );
		$m->setAccessible( true );
		$this->assertTrue( $m->invoke( $server, $auth ) );
		$this->assertTrue( $m->invoke( $server, $auth ) );
		$this->assertTrue( $m->invoke( $server, $auth ) );
		$result = $m->invoke( $server, $auth ); // 4th exceeds limit
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}
}
