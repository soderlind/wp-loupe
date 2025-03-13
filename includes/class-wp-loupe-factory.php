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
     * Fields that are safe to use as sortable attributes in Loupe
     * Only simple scalar data types like strings and numbers can be sortable
     */
    private static $sortable_field_types = [
        'post_date',
        'post_modified',
        'post_title',
        'post_name',
        'post_author',
    ];

    /**
     * Fields that are known to be non-scalar and cannot be sortable
     */
    private static $non_scalar_field_types = [
        'post_content', // Often contains complex HTML
        'post_excerpt', // May contain HTML
    ];

    /**
     * Cache of Loupe instances by post type
     */
    private static $instance_cache = [];

    /**
     * Create a Loupe search instance
     *
     * @since 0.1.6
     * @param string $post_type Post type to create instance for.
     * @param string $lang Language code.
     * @param WP_Loupe_DB $db Database instance.
     * @param bool $force_new Whether to force creation of a new instance regardless of cache
     * @return \Loupe\Loupe\Loupe Loupe instance
     */
    public static function create_loupe_instance(string $post_type, string $lang, WP_Loupe_DB $db): \Loupe\Loupe\Loupe {
        // Check if we already have field settings
        $all_saved_fields = get_option('wp_loupe_fields');
        $needs_save = false;
        
        // If settings don't exist at all or don't exist for this post type, create defaults
        if (empty($all_saved_fields) || !isset($all_saved_fields[$post_type])) {
            $needs_save = true;
            
            // Create default fields for this post type if they don't exist
            if (!is_array($all_saved_fields)) {
                $all_saved_fields = [];
            }
            
            // Set default fields for this post type
            $all_saved_fields[$post_type] = [
                'post_title' => [
                    'indexable' => true,
                    'weight' => 2.0,
                    'filterable' => true,
                    'sortable' => true,
                    'sort_direction' => 'desc'
                ],
                'post_content' => [
                    'indexable' => true,
                    'weight' => 1.0,
                    'filterable' => true,
                    'sortable' => false
                ],
                'post_date' => [
                    'indexable' => true,
                    'weight' => 1.0,
                    'filterable' => true,
                    'sortable' => true,
                    'sort_direction' => 'desc'
                ],
            ];
            
            // Add taxonomy fields if available
            $taxonomies = get_object_taxonomies($post_type, 'objects');
            foreach ($taxonomies as $tax_name => $tax_obj) {
                if ($tax_obj->show_ui) {
                    $all_saved_fields[$post_type]['taxonomy_' . $tax_name] = [
                        'indexable' => true,
                        'weight' => 1.5,
                        'filterable' => true,
                        'sortable' => false // Taxonomies are arrays, not scalar
                    ];
                }
            }
            
            // Save the updated fields
            if ($needs_save) {
                update_option('wp_loupe_fields', $all_saved_fields);
            }
        }
        
        $saved_fields = $all_saved_fields;
        
        // Get all fields in one pass and extract what we need
        $attributes = [
            'indexable'  => [],
            'filterable' => [],
            'sortable'   => [],
        ];
        
        // Traverse saved fields and add to attributes
        if (isset($all_saved_fields[$post_type])) {
            foreach ($saved_fields[$post_type] as $field_name => $settings) {
                // Add field to indexable attributes if set
                if (!empty($settings['indexable'])) {
                    $attributes['indexable'][] = $field_name;
                }
                
                // Add field to filterable attributes if set
                if (!empty($settings['filterable'])) {
                    $attributes['filterable'][] = $field_name;
                }
                
                // For sortable fields, we'll now include all fields marked as sortable
                // regardless of whether they are detected as "safely sortable"
                if (!empty($settings['sortable'])) {
                    // if (!empty($settings['sortable']) && self::is_safely_sortable($field_name, $post_type)) {
                    $attributes['sortable'][] = $field_name;
                }
            }
        }
        
		$advanced_settings = get_option('wp_loupe_advanced', []);

		// Configure typo tolerance
		$typo_tolerance = null;
		if (!empty($advanced_settings['typo_enabled'])) {
			$typo_tolerance = TypoTolerance::create();
			
			// Set alphabet size and index length if they exist
			if (isset($advanced_settings['alphabet_size'])) {
				$typo_tolerance->withAlphabetSize($advanced_settings['alphabet_size']);
			}
			
			if (isset($advanced_settings['index_length'])) {
				$typo_tolerance->withIndexLength($advanced_settings['index_length']);
			}
			
			// Configure first character double counting
			$typo_tolerance->withFirstCharTypoCountsDouble(!empty($advanced_settings['first_char_typo_double']));
			
			// Configure prefix search typo tolerance
			$typo_tolerance->withEnabledForPrefixSearch(!empty($advanced_settings['typo_prefix_search']));
			
			// Configure typo thresholds
			if (isset($advanced_settings['typo_thresholds']) && is_array($advanced_settings['typo_thresholds'])) {
				$typo_tolerance->withTypoThresholds($advanced_settings['typo_thresholds']);
			}
		} else {
			$typo_tolerance = TypoTolerance::disabled();
		}



        // Create the configuration with the complete set of attributes
        $configuration = Configuration::create()
            ->withPrimaryKey('id')
            ->withSearchableAttributes($attributes['indexable'])
            ->withFilterableAttributes($attributes['filterable'])
            ->withSortableAttributes($attributes['sortable'])
			->withMaxQueryTokens(isset($advanced_settings['max_query_tokens']) ? $advanced_settings['max_query_tokens'] : 12)
			->withMinTokenLengthForPrefixSearch(isset($advanced_settings['min_prefix_length']) ? $advanced_settings['min_prefix_length'] : 3)
			->withLanguages(isset($advanced_settings['languages']) ? $advanced_settings['languages'] : ['en'])
			->withTypoTolerance($typo_tolerance);
        
        // Create and return the Loupe instance
        $loupe_factory = new LoupeFactory();
        $instance = $loupe_factory->create(
            $db->get_db_path($post_type),
            $configuration
        );
        
        return $instance;
    }
    
    /**
     * Clear the instance cache for all post types or a specific one
     * 
     * @param string|null $post_type Optional post type to clear cache for
     */
    public static function clear_instance_cache(?string $post_type = null): void {
        if ($post_type === null) {
            self::$instance_cache = [];
        } else {
            foreach (array_keys(self::$instance_cache) as $key) {
                if (strpos($key, "{$post_type}:") === 0) {
                    unset(self::$instance_cache[$key]);
                }
            }
        }
    }
    
 	/**
     * Determine if a field is safe to use as a sortable attribute
     * 
     * @param string $field_name The field name to check
     * @param string $post_type The post type being processed
     * @return bool Whether the field can be safely sorted
     */
    private static function is_safely_sortable(string $field_name, string $post_type): bool {
        // Core WP fields we know are safely sortable
        if (in_array($field_name, self::$sortable_field_types)) {
            return true;
        }
        
        // Fields we know are not safely sortable
        if (in_array($field_name, self::$non_scalar_field_types)) {
            return false;
        }
        
        // Check if it's a taxonomy field (these are arrays and not safely sortable)
        if (strpos($field_name, 'taxonomy_') === 0) {
            return false;
        }
        
        // For meta fields, we need to check actual values
        // First let's determine if it's a core post field
        $core_fields = [
            'ID', 'post_author', 'post_date', 'post_date_gmt', 
            'post_content', 'post_title', 'post_excerpt', 
            'post_status', 'comment_status', 'ping_status', 
            'post_password', 'post_name', 'to_ping', 'pinged', 
            'post_modified', 'post_modified_gmt', 'post_content_filtered', 
            'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count'
        ];
        
        // If not a core field, treat as a meta field
        if (!in_array($field_name, $core_fields)) {
            // It's likely a meta field, let's check a sample value
            $args = [
                'post_type' => $post_type,
                'posts_per_page' => 5, // Check a few posts for better accuracy
                'meta_key' => $field_name,
                'meta_value_exists' => true,
                'fields' => 'ids', // We only need the IDs
            ];
            
            $query = new \WP_Query($args);
            
            if ($query->have_posts()) {
                // Check the meta values from these posts
                foreach ($query->posts as $post_id) {
                    $value = get_post_meta($post_id, $field_name, true);
                    
                    // If we find a non-scalar value, return false
                    if (!is_scalar($value) && $value !== null) {
                        return false;
                    }
                }
                
                // If we've checked all posts and found only scalar values (or null), it's probably safe to sort
                return true;
            }
        }
        
        // Allow plugins to override for custom field types
        return apply_filters("wp_loupe_is_safely_sortable_{$post_type}", false, $field_name);
    }

    /**
     * Validate sortable fields in the settings
     *
     * @param array $fields All field settings
     * @return array Validated field settings
     */
    private static function validate_sortable_fields(array $fields): array {
        $updated = false;
        
        foreach ($fields as $post_type => &$post_type_fields) {
            foreach ($post_type_fields as $field_name => &$settings) {
                // If marked as sortable but not safely sortable, correct it
                if (!empty($settings['sortable']) && !self::is_safely_sortable($field_name, $post_type)) {
                    $settings['sortable'] = false;
                    $updated = true;
                }
            }
        }
        
        // Save the corrected settings if needed
        if ($updated) {
            update_option('wp_loupe_fields', $fields);
        }
        
        return $fields;
    }

    /**
     * Public method to check if a field is safely sortable
     * 
     * @param string $field_name The field name to check
     * @param string $post_type The post type being processed
     * @return bool True if field can be safely sorted
     */
    public static function check_sortable_field(string $field_name, string $post_type): bool {
        return self::is_safely_sortable($field_name, $post_type);
    }
}