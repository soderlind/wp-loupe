<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

trait WP_Loupe_Shared {
	protected function create_loupe_instance( $post_type, $lang ) {
		$filterable_attributes = apply_filters(
			"wp_loupe_filterable_attribute_{$post_type}",
			[ 'post_title', 'post_date', 'post_content' ]
		);

		$configuration = Configuration::create()
			->withPrimaryKey( 'id' )
			->withFilterableAttributes( $filterable_attributes )
			->withSortableAttributes( [ 'post_date', 'post_title' ] )
			->withLanguages( [ $lang ] )
			->withTypoTolerance(
				TypoTolerance::create()->withFirstCharTypoCountsDouble( false )
			);

		$loupe_factory = new LoupeFactory();
		return $loupe_factory->create(
			$this->db->get_db_path( $post_type ),
			$configuration
		);
	}
}
