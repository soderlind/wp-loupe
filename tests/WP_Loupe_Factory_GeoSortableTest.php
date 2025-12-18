<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Factory;

class WP_Loupe_Factory_GeoSortableTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		global $wp_loupe_test_posts, $wp_loupe_test_post_meta;
		$wp_loupe_test_posts     = [];
		$wp_loupe_test_post_meta = [];
	}

	public function test_geo_point_meta_field_is_treated_as_sortable_safe(): void {
		global $wp_loupe_test_posts;
		$wp_loupe_test_posts[ 1 ] = [ 'post_type' => 'post' ];
		$wp_loupe_test_posts[ 2 ] = [ 'post_type' => 'post' ];

		update_post_meta( 1, 'location', [ 'lat' => 59.9139, 'lng' => 10.7522 ] );
		update_post_meta( 2, 'location', [ 'lat' => 60.0, 'lon' => 11.0 ] );

		$this->assertTrue( WP_Loupe_Factory::check_sortable_field( 'location', 'post' ) );
	}

	public function test_non_scalar_non_geo_meta_field_is_not_sortable_safe(): void {
		global $wp_loupe_test_posts;
		$wp_loupe_test_posts[ 1 ] = [ 'post_type' => 'post' ];

		update_post_meta( 1, 'weird', [ 'a' => 'b' ] );

		$this->assertFalse( WP_Loupe_Factory::check_sortable_field( 'weird', 'post' ) );
	}
}
