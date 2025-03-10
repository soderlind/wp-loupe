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
     * Only simple data types like strings, numbers, and dates can be sortable
     */
    private static $sortable_field_types = [
        'post_date',
        'post_modified',
        'post_title',
        'post_name',
        'post_author',
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
    public static function create_loupe_instance(string $post_type, string $lang, WP_Loupe_DB $db, bool $force_new = false): \Loupe\Loupe\Loupe {
        $cache_key = "{$post_type}:{$lang}";

        // Return cached instance if available and not forcing new
        if (!$force_new && isset(self::$instance_cache[$cache_key])) {
            return self::$instance_cache[$cache_key];
        }

        try {
            $schema_manager = WP_Loupe_Schema_Manager::get_instance();
            $schema = $schema_manager->get_schema_for_post_type($post_type);
            $saved_fields = get_option('wp_loupe_fields', []);

            // Get all fields in one pass and extract what we need
            $fields = [
                'indexable'  => [],
                'filterable' => [],
                'sortable'   => [],
            ];

            // Process all fields in a single loop instead of multiple calls
            foreach ($schema as $field_name => $settings) {
                // Skip fields that aren't marked as indexable in settings or via post_date special case
                if (!is_array($settings) || 
                    (!$settings['indexable'] && $field_name !== 'post_date') ||
                    ($field_name !== 'post_date' && isset($saved_fields[$post_type][$field_name]) && !$saved_fields[$post_type][$field_name]['indexable'])) {
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
                if (!empty($settings['filterable'])) {
                    $fields['filterable'][] = $clean_field_name;
                }

                // Add to sortable if explicitly set AND field type is compatible with sorting
                if (!empty($settings['sortable']) && self::is_safely_sortable($clean_field_name, $post_type)) {
                    $fields['sortable'][] = $clean_field_name;
                }
            }

            // Ensure post_date is always included in indexable fields if not already present
            if (!in_array('post_date', array_column($fields['indexable'], 'field'))) {
                $fields['indexable'][] = [
                    'field' => 'post_date',
                    'weight' => 1.0
                ];
            }

            // Sort indexable fields by weight once
            if (!empty($fields['indexable'])) {
                usort($fields['indexable'], fn($a, $b) => $b['weight'] <=> $a['weight']);
                $fields['indexable'] = array_column($fields['indexable'], 'field');
            }

            // For debugging purposes
            if (WP_DEBUG) {
                WP_Loupe_Utils::dump(['post type' => $post_type, 'fields' => $fields]);
            }

            $configuration = Configuration::create()
                ->withPrimaryKey('id')
                ->withSearchableAttributes($fields['indexable'])
                ->withFilterableAttributes($fields['filterable'])
                ->withSortableAttributes($fields['sortable'])
                ->withLanguages([$lang])
                ->withTypoTolerance(
                    TypoTolerance::create()->withFirstCharTypoCountsDouble(false)
                );

            $loupe_factory = new LoupeFactory();
            $instance = $loupe_factory->create(
                $db->get_db_path($post_type),
                $configuration
            );
            
            // Cache the instance
            self::$instance_cache[$cache_key] = $instance;
            
            return $instance;
        } catch (\Exception $e) {
            // Log the error
            error_log('WP Loupe: Error creating Loupe instance: ' . $e->getMessage());
            
            // Return a minimal configuration as fallback
            $configuration = Configuration::create()
                ->withPrimaryKey('id')
                ->withSearchableAttributes(['post_title', 'post_content'])
                ->withLanguages([$lang]);
                
            $loupe_factory = new LoupeFactory();
            $instance = $loupe_factory->create(
                $db->get_db_path($post_type),
                $configuration
            );
            
            // Cache the fallback instance
            self::$instance_cache[$cache_key] = $instance;
            
            return $instance;
        }
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
}