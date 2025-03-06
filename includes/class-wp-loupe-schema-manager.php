<?php
namespace Soderlind\Plugin\WPLoupe;

class WP_Loupe_Schema_Manager {
	private static $instance = null;
	private static float $default_weight = 1.0;
	private static string $default_direction = 'desc';
	private $schema_cache = [];
	private $fields_cache = [];

	/**
	 * Gets the singleton instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Retrieves the schema for a specific post type.
	 *
	 * @param string $post_type The post type to retrieve the schema for.
	 * @return array The schema for the specified post type.
	 */
	public function get_schema_for_post_type( string $post_type ): array {
		if ( ! isset( $this->schema_cache[ $post_type ] ) ) {
			$this->schema_cache[ $post_type ] = apply_filters(
				"wp_loupe_schema_{$post_type}",
				$this->get_default_schema()
			);
		}
		return $this->schema_cache[ $post_type ];
	}

	/**
	 * Retrieves the indexable fields for a specific post type.
	 *
	 * @param array $schema The schema to retrieve the indexable fields from.
	 * @return array The indexable fields.
	 */
	public function get_indexable_fields( array $schema ): array {
		return $this->get_fields_by_type( $schema, 'indexable' );
	}

	/**
	 * Retrieves the filterable fields for a specific post type.
	 *
	 * @param array $schema The schema to retrieve the filterable fields from.
	 * @return array The filterable fields.
	 */
	public function get_filterable_fields( array $schema ): array {
		return $this->get_fields_by_type( $schema, 'filterable' );
	}

	/**
	 * Retrieves the sortable fields for a specific post type.
	 *
	 * @param array $schema The schema to retrieve the sortable fields from.
	 * @return array The sortable fields.
	 */
	public function get_sortable_fields( array $schema ): array {
		return $this->get_fields_by_type( $schema, 'sortable' );
	}

	/**
	 * Retrieves the default schema.
	 *
	 * @return array The default schema.
	 */
	private function get_default_schema(): array {
		return [ 
			'post_title'   => [ 
				'weight'     => 2,
				'filterable' => true,
				'sortable'   => [ 'direction' => 'asc' ],
			],
			'post_content' => [ 'weight' => self::$default_weight ],
			'post_excerpt' => [ 'weight' => 1.5 ],
			'post_date'    => [ 
				'weight'     => self::$default_weight,
				'filterable' => true,
				'sortable'   => [ 'direction' => self::$default_direction ],
			],
			'post_author'  => [ 
				'weight'     => self::$default_weight,
				'filterable' => true,
				'sortable'   => [ 'direction' => 'asc' ],
			],
			'permalink'    => [ 'weight' => self::$default_weight ],
		];
	}

	/**
	 * Retrieves the fields of a specific type from a schema.
	 *
	 * @param array  $schema The schema to retrieve the fields from.
	 * @param string $type   The type of fields to retrieve.
	 * @return array The fields of the specified type.
	 */
	private function get_fields_by_type( array $schema, string $type ): array {
		$cache_key = md5( serialize( $schema ) . $type );

		if ( isset( $this->fields_cache[ $cache_key ] ) ) {
			return $this->fields_cache[ $cache_key ];
		}

		$processed_fields = [];
		foreach ( $schema as $field => $settings ) {
			
			$processed_fields[] = $this->process_field( $field, $settings, $type );
		}

		$processed_fields                 = array_unique( $processed_fields, SORT_REGULAR );
		$processed_fields                 = array_values( array_filter( $processed_fields ) );
		$this->fields_cache[ $cache_key ] = $processed_fields;

		return $processed_fields;
	}

	/**
	 * Processes a field based on its type.
	 *
	 * @param string $field    The field to process.
	 * @param array  $settings The settings of the field.
	 * @param string $type     The type of the field.
	 * @return mixed The processed field.
	 */
	private function process_field( string $field, array $settings, string $type ) {
		switch ( $type ) {
			case 'indexable':
				return [ 
					'field'  => $field,
					'weight' => $settings[ 'weight' ] ?? self::$default_weight
				];
			case 'filterable':
				return ( $settings[ 'filterable' ] ?? false ) ? $field : null;
			case 'sortable':
				return isset( $settings[ 'sortable' ] ) ? [ 
					'field'     => $field,
					'direction' => $settings[ 'sortable' ][ 'direction' ] ?? self::$default_direction
				] : null;
			default:
				return null;
		}
	}
}
