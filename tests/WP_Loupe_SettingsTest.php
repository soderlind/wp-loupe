<?php
/**
 * Tests for WP Loupe settings behaviours.
 */

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WPLoupe_Settings_Page;

require_once __DIR__ . '/bootstrap.php';

class WP_Loupe_SettingsTest extends TestCase {

	/** @test */
	public function auto_update_option_defaults_true() {
		// Simulate absence of option.
		delete_option( 'wp_loupe_auto_update_enabled' );
		$value = get_option( 'wp_loupe_auto_update_enabled', true );
		$this->assertTrue( (bool) $value, 'Auto update should default to true when option missing.' );
	}

	/** @test */
	public function can_disable_auto_update_option() {
		update_option( 'wp_loupe_auto_update_enabled', false );
		$this->assertFalse( (bool) get_option( 'wp_loupe_auto_update_enabled', true ), 'Auto update should be false after disabling.' );
	}

	/** @test */
	public function sanitize_advanced_settings_numeric_and_boolean() {
		$settings_page = $this->make_settings_page();
		$input         = [
			'max_query_tokens'       => '25',
			'min_prefix_length'      => '2',
			'alphabet_size'          => '7',
			'index_length'           => '20',
			'typo_enabled'           => '1',
			'typo_prefix_search'     => '',
			'first_char_typo_double' => '1',
		];
		$sanitized     = $settings_page->sanitize_advanced_settings( $input );
		$this->assertSame( 25, $sanitized[ 'max_query_tokens' ] );
		$this->assertSame( 2, $sanitized[ 'min_prefix_length' ] );
		$this->assertSame( 7, $sanitized[ 'alphabet_size' ] );
		$this->assertSame( 20, $sanitized[ 'index_length' ] );
		$this->assertTrue( $sanitized[ 'typo_enabled' ] );
		$this->assertFalse( $sanitized[ 'typo_prefix_search' ] );
		$this->assertTrue( $sanitized[ 'first_char_typo_double' ] );
	}

	/** @test */
	public function sanitize_fields_settings_filters_non_indexable() {
		$settings_page = $this->make_settings_page();
		$input         = [
			'post' => [
				'post_title'   => [ 'indexable' => 1, 'weight' => '2.2', 'filterable' => 1, 'sortable' => 1, 'sort_direction' => 'asc' ],
				'post_content' => [ 'weight' => '3.0' ], // missing indexable flag -> should be dropped
			],
		];
		$sanitized     = $settings_page->sanitize_fields_settings( $input );
		$this->assertArrayHasKey( 'post', $sanitized );
		$this->assertArrayHasKey( 'post_title', $sanitized[ 'post' ] );
		$this->assertArrayNotHasKey( 'post_content', $sanitized[ 'post' ], 'Field without indexable should be dropped.' );
		$title = $sanitized[ 'post' ][ 'post_title' ];
		$this->assertTrue( $title[ 'indexable' ] );
		$this->assertSame( 2.2, $title[ 'weight' ] );
		$this->assertTrue( $title[ 'filterable' ] );
		$this->assertTrue( $title[ 'sortable' ] );
		$this->assertSame( 'asc', $title[ 'sort_direction' ] );
	}

	/** Helper to instantiate settings page without WP hooks side effects */
	private function make_settings_page() {
		// We suppress constructor hooks by creating an instance via reflection if needed.
		return new WPLoupe_Settings_Page();
	}
}
