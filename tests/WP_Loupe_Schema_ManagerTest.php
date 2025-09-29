<?php
namespace WPLoupeTests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Schema_Manager;

class WP_Loupe_Schema_ManagerTest extends TestCase {
	public function test_default_schema_includes_post_date() {
		$mgr    = WP_Loupe_Schema_Manager::get_instance();
		$schema = $mgr->get_schema_for_post_type( 'post' );
		$this->assertArrayHasKey( 'post_date', $schema, 'post_date should always be present' );
	}

	public function test_indexable_fields_structure() {
		$mgr       = WP_Loupe_Schema_Manager::get_instance();
		$schema    = $mgr->get_schema_for_post_type( 'post' );
		$indexable = $mgr->get_indexable_fields( $schema );
		$this->assertIsArray( $indexable );
		$this->assertNotEmpty( $indexable );
		$first = $indexable[ 0 ];
		$this->assertArrayHasKey( 'field', $first );
		$this->assertArrayHasKey( 'weight', $first );
	}
}
