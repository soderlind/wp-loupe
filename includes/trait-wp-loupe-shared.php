<?php
namespace Soderlind\Plugin\WPLoupe;

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
