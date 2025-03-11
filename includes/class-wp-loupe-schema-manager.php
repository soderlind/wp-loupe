<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Schema manager for WP Loupe
 * 
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.1.0
 */
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
	public function get_schema_for_post_type(string $post_type): array {
        if (!isset($this->schema_cache[$post_type])) {
            $schema = $this->get_default_schema();
            $saved_fields = get_option('wp_loupe_fields', []);
            
            // Override or add post type specific settings, but only for fields marked as indexable
            if (isset($saved_fields[$post_type])) {
                foreach ($saved_fields[$post_type] as $field_key => $settings) {
                    if (!empty($settings['indexable'])) {
                        $schema[$field_key] = [
                            'weight' => (float)($settings['weight'] ?? 1.0),
                            'indexable' => true,
                            'filterable' => !empty($settings['filterable']),
                            'sortable' => !empty($settings['sortable']),
                            'sort_direction' => $settings['sort_direction'] ?? 'desc'
                        ];
                    }
                }
            }
            
            // Allow further customization through filter
            $this->schema_cache[$post_type] = apply_filters(
                "wp_loupe_schema_{$post_type}",
                $schema
            );
        }
        return $this->schema_cache[$post_type];
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
        $schema = [];
        
        // Core fields will only be added if they are in saved settings
        $saved_fields = get_option('wp_loupe_fields', []);
        
        // Core fields that can be configured
        $core_fields = [
            'post_title',
            'post_content',
            'post_excerpt',
            'post_date',
            'post_author'
        ];
        
        foreach ($core_fields as $field) {
            if (isset($saved_fields[$field]) && !empty($saved_fields[$field]['indexable'])) {
                $schema[$field] = $saved_fields[$field];
            } elseif ($field === 'post_date') {
                // Special case: post_date is always included with default settings
                $schema[$field] = [
                    'weight' => 1.0,
                    'indexable' => true,
                    'filterable' => true,
                    'sortable' => true,
                    'sort_direction' => 'desc'
                ];
            }
        }
        
        return $schema;
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
        // Special handling for taxonomy fields
        if ($this->is_taxonomy_field($field)) {
            $taxonomy = $this->get_taxonomy_name($field);
            if (!taxonomy_exists($taxonomy)) {
                return null;
            }
        }

        // First check if the field is indexable at all
        if (!($settings['indexable'] ?? false)) {
            return null;
        }

        switch ( $type ) {
            case 'indexable':
                return [ 
                    'field'  => $field,
                    'weight' => $settings['weight'] ?? self::$default_weight
                ];
            case 'filterable':
                return ($settings['filterable'] ?? false) ? $field : null;
            case 'sortable':
                return ($settings['sortable'] ?? false) ? [ 
                    'field'     => $field,
                    'direction' => $settings['sort_direction'] ?? self::$default_direction
                ] : null;
            default:
                return null;
        }
    }

	/**
	 * Clears the schema and fields cache.
	 */
	public function clear_cache(): void {
		$this->schema_cache = [];
		$this->fields_cache = [];
	}

	/**
	 * Check if field is a taxonomy field
	 */
	private function is_taxonomy_field(string $field): bool {
		return strpos($field, 'taxonomy_') === 0;
	}

	/**
	 * Get taxonomy name from field
	 */
	private function get_taxonomy_name(string $field): string {
		return substr($field, 9); // Remove 'taxonomy_' prefix
	}
}
