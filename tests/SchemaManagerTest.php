<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Schema_Manager;

/**
 * @covers \Soderlind\Plugin\WPLoupe\WP_Loupe_Schema_Manager
 */
final class SchemaManagerTest extends TestCase {

	protected function setUp(): void {
		// Provide minimal WordPress option shim if not running inside WP.
		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $name, $default = false ) {
				static $options = [];
				return $options[ $name ] ?? $default;
			}
		}
		if ( ! function_exists( 'apply_filters' ) ) {
			function apply_filters( $hook, $value ) {
				return $value;
			}
		}
	}

	public function test_default_schema_contains_only_post_date(): void {
		$mgr    = WP_Loupe_Schema_Manager::get_instance();
		$schema = $mgr->get_schema_for_post_type( 'post' );
		$this->assertArrayHasKey( 'post_date', $schema, 'Baseline schema must contain post_date' );
		$this->assertCount( 1, $schema, 'Baseline schema should only include mandatory post_date field' );
		$settings = $schema[ 'post_date' ];
		$this->assertTrue( $settings[ 'indexable' ] );
		$this->assertTrue( $settings[ 'filterable' ] );
		$this->assertTrue( $settings[ 'sortable' ] );
		$this->assertSame( 'desc', $settings[ 'sort_direction' ] );
	}
}
