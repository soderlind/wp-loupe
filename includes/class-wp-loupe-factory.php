<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

/**
 * Factory class for creating Loupe instances
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.1.6
 */
class WP_Loupe_Factory {
    /**
     * Create a Loupe search instance
     *
     * @since 0.1.6
     * @param string $post_type Post type to create instance for.
     * @param string $lang Language code.
     * @param WP_Loupe_DB $db Database instance.
     * @return \Loupe\Loupe\Loupe Loupe instance
	 */
	public static function create_loupe_instance( string $post_type, string $lang, WP_Loupe_DB $db ): \Loupe\Loupe\Loupe {
		$schema_manager = WP_Loupe_Schema_Manager::get_instance();
		$schema = $schema_manager->get_schema_for_post_type( $post_type );

		// Get all fields in one pass and extract what we need
		$fields = [ 
			'indexable'  => [],
			'filterable' => [],
			'sortable'   => [],
		];

		// Process all fields in a single loop instead of multiple calls
		foreach ( $schema as $field_name => $settings ) {
			// Skip fields not marked as indexable
			if ( ! is_array( $settings ) || empty( $settings['indexable'] ) ) {
				continue;
			}

			 // Remove table aliases from field name (e.g., "d.post_title" becomes "post_title")
			$clean_field_name = preg_replace('/^[a-zA-Z]+\./', '', $field_name);

			 // Add to indexable fields with weight
			$fields['indexable'][] = [ 
				'field'  => $clean_field_name,
				'weight' => $settings['weight'] ?? 1.0,
			];

			 // Add to filterable if explicitly set
			if ( ! empty( $settings['filterable'] ) ) {
				$fields['filterable'][] = $clean_field_name;
			}

			 // Add to sortable if explicitly set
			if ( ! empty( $settings['sortable'] ) ) {
				$fields['sortable'][] = $clean_field_name;
			}
		}

		// Sort indexable fields by weight once
		if ( ! empty( $fields[ 'indexable' ] ) ) {
			usort( $fields[ 'indexable' ], fn( $a, $b ) => $b[ 'weight' ] <=> $a[ 'weight' ] );
			$fields[ 'indexable' ] = array_column( $fields[ 'indexable' ], 'field' );
		}

		// Note: We maintain this dump for debugging purposes
		WP_Loupe_Utils::dump( [ 'post type' => $post_type, 'fields' =>	 $fields ] );

		$configuration = Configuration::create()
				->withPrimaryKey( 'id' )
				->withSearchableAttributes( $fields[ 'indexable' ] )
				->withFilterableAttributes( $fields[ 'filterable' ] )
				->withSortableAttributes( $fields[ 'sortable' ] )
				->withLanguages( [ $lang ] )
				->withTypoTolerance(
					TypoTolerance::create()->withFirstCharTypoCountsDouble( false )
				);
				
		$loupe_factory = new LoupeFactory();
       
		return $loupe_factory->create(
			$db->get_db_path($post_type),
			$configuration
		);

	}
}