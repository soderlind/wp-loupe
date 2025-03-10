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
        
        // Validate sortable fields to ensure only scalar fields are marked as sortable
        $all_saved_fields = self::validate_sortable_fields($all_saved_fields);
        
        $saved_fields = $all_saved_fields;
        
        WP_Loupe_Utils::dump(['post type' => $post_type, 'saved_fields' => $saved_fields]);

        // Get all fields in one pass and extract what we need
        $attributes = [
            'indexable'  => [],
            'filterable' => [],
            'sortable'   => [],
        ];

        // use the $saved_fields to process fields effectively
        if (isset($saved_fields[$post_type])) {
            foreach ($saved_fields[$post_type] as $field_name => $settings) {
                // Add field to indexable attributes if set
                if (!empty($settings['indexable'])) {
                    $attributes['indexable'][] = $field_name;
                }
                
                // Add field to filterable attributes if set
                if (!empty($settings['filterable'])) {
                    $attributes['filterable'][] = $field_name;
                }
                
                // Add field to sortable attributes if set and safely sortable
                if (!empty($settings['sortable']) && self::is_safely_sortable($field_name, $post_type)) {
                    $attributes['sortable'][] = $field_name;
                }
            }
        }
        WP_Loupe_Utils::dump(['post_type' => $post_type, 'attributes' => $attributes]);

        $configuration = Configuration::create()
            ->withPrimaryKey('id')
            ->withSearchableAttributes($attributes['indexable'])
            ->withFilterableAttributes($attributes['filterable'])
            ->withSortableAttributes($attributes['sortable'])
            ->withLanguages([$lang])
            ->withTypoTolerance(
                TypoTolerance::create()->withFirstCharTypoCountsDouble(false)
            );

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
     * @return bool True if field can be safely sorted
     */
    private static function is_safely_sortable(string $field_name, string $post_type): bool {
        // Never allow known non-scalar fields
        if (in_array($field_name, self::$non_scalar_field_types)) {
            return false;
        }
        
        // Never allow taxonomy fields (they're arrays)
        if (strpos($field_name, 'taxonomy_') === 0) {
            return false;
        }
        
        // Always allow core fields from our safelist
        if (in_array($field_name, self::$sortable_field_types)) {
            return true;
        }
        
        // Check for date fields (naming convention)
        if (strpos($field_name, '_date') !== false || 
            strpos($field_name, 'date_') !== false ||
            strpos($field_name, '_at') !== false) {
            return true;
        }
        
        // Check for number fields (naming convention)
        if (strpos($field_name, '_count') !== false ||
            strpos($field_name, '_number') !== false ||
            strpos($field_name, '_price') !== false ||
            strpos($field_name, '_rating') !== false ||
            strpos($field_name, '_amount') !== false ||
            strpos($field_name, '_percentage') !== false ||
            strpos($field_name, '_quantity') !== false) {
            return true;
        }
        
        // Get all known sortable fields from custom schema filters
        $customizable_sortable_fields = apply_filters("wp_loupe_sortable_fields_{$post_type}", []);
        if (!empty($customizable_sortable_fields) && in_array($field_name, $customizable_sortable_fields)) {
            return true;
        }
        
        // By default, don't mark fields as sortable to prevent errors
        return false;
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