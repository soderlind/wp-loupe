<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

/**
 * Shared functionality for WP Loupe classes
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.1
 */
trait WP_Loupe_Shared {

	/**
	 * Create a Loupe search instance
	 *
	 * @since 0.0.1
	 * @param string $post_type Post type to create instance for.
	 * @param string $lang      Language code.
	 * @return \Loupe\Loupe\Loupe Loupe instance
	 */
	protected function create_loupe_instance( $post_type, $lang ) {
		$schema_manager = WP_Loupe_Schema_Manager::get_instance();
		$schema         = $schema_manager->get_schema_for_post_type( $post_type );

		$indexable = $schema_manager->get_indexable_fields( $schema );
		WP_Loupe_Utils::dump( [ 'create_loupe_instance > indexable A', $indexable ] );
		// sort indexable fields, based on weight. The higher the weight, the higher the field is in the list
		uasort( $indexable, function ($a, $b) {
			return $b[ 'weight' ] <=> $a[ 'weight' ];
		} );
		WP_Loupe_Utils::dump( [ 'create_loupe_instance > indexable B', $indexable ] );
		$indexable = array_map( function ($field) {
			return "{$field[ 'field' ]}";
		}, $indexable );
		WP_Loupe_Utils::dump( [ 'create_loupe_instance > indexable C', $indexable ] );
		$filterable = $schema_manager->get_filterable_fields( $schema );

		$sortable = $schema_manager->get_sortable_fields( $schema );
		$sortable = array_map( function ($field) {
			return "{$field[ 'field' ]}";
		}, $sortable );

		WP_Loupe_Utils::dump( [ 'create_loupe_instance > filterable', $filterable ] );
		WP_Loupe_Utils::dump( [ 'create_loupe_instance > sortable', $sortable ] );
		WP_Loupe_Utils::dump( [ 'create_loupe_instance > indexable', $indexable ] );
		$configuration = Configuration::create()
			->withPrimaryKey( 'id' )
			->withSearchableAttributes( $indexable )
			->withFilterableAttributes( $filterable )
			->withSortableAttributes( $sortable )
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
