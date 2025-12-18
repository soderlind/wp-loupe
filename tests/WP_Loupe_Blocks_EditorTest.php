<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Blocks;

class WP_Loupe_Blocks_EditorTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_editor_only_registers_current_screen_hook(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'current_screen', [ WP_Loupe_Blocks::class, 'on_current_screen' ] );

		// Ensure editor init does not wire front-end render/search-form hooks.
		Functions\expect( 'add_filter' )->never();
		Functions\expect( 'add_shortcode' )->never();

		WP_Loupe_Blocks::init_editor();
		$this->assertTrue( true );
	}
}
