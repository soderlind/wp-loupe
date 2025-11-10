<?php
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Token_Service;

require_once __DIR__ . '/bootstrap.php';

class WP_Loupe_TokenServiceTest extends TestCase {
	/** @test */
	public function rate_limits_are_bounded() {
		$svc      = new WP_Loupe_Token_Service();
		$incoming = [
			'anon_window'     => 99999, // should cap to 3600
			'anon_limit'      => 50000,  // cap to 1000
			'auth_window'     => 5,     // raise to min 10
			'auth_limit'      => 6000,    // cap to 5000
			'max_search_auth' => 9999, // cap to 500
			'max_search_anon' => 5000, // cap to 100
		];
		$clean    = $svc->save_rate_limits( $incoming );
		$this->assertSame( 3600, $clean[ 'anon_window' ] );
		$this->assertSame( 1000, $clean[ 'anon_limit' ] );
		$this->assertSame( 10, $clean[ 'auth_window' ] );
		$this->assertSame( 5000, $clean[ 'auth_limit' ] );
		$this->assertSame( 500, $clean[ 'max_search_auth' ] );
		$this->assertSame( 100, $clean[ 'max_search_anon' ] );
	}

	/** @test */
	public function revoke_all_tokens_clears_registry() {
		update_option( 'wp_loupe_mcp_tokens', [ 'abc' => [ 'label' => 'x' ] ] );
		$svc = new WP_Loupe_Token_Service();
		$svc->revoke_all_tokens();
		$this->assertSame( [], $svc->get_registry() );
	}
}
