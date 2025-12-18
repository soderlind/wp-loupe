<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class WP_Loupe_Blocks_EditorTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkipped( 'WP Loupe no longer ships the block editor integration.' );
		\Brain\Monkey\setUp();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_editor_only_registers_current_screen_hook(): void {
		$this->assertTrue( true );
	}
}
