<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
use Soderlind\Plugin\WPLoupe\WP_Loupe_DB;

/**
 * Search class for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_Search {

    private $post_types;
    private $loupe = [];
    private $log;
    private $db;
    private $total_found_posts = 0;
    private $max_num_pages = 0;
    private const CACHE_TTL = 3600; // 1 hour
    private $saved_fields;

    public function __construct($post_types) {
        $this->post_types = $post_types;
        $this->db = WP_Loupe_DB::get_instance();
        $this->saved_fields = get_option('wp_loupe_fields', []);
        add_filter('posts_pre_query', [$this, 'posts_pre_query'], 10, 2);
        add_action('wp_footer', [$this, 'action_wp_footer'], 999);
        $iso6391_lang = ('' === get_locale()) ? 'en' : strtolower(substr(get_locale(), 0, 2));
        foreach ($this->post_types as $post_type) {
            $this->loupe[$post_type] = WP_Loupe_Factory::create_loupe_instance($post_type, $iso6391_lang, $this->db);
        }
    }

    public function posts_pre_query($posts, \WP_Query $query) {
        if (!$this->should_intercept_query($query)) {
            return null;
        }
        $query->set('post_type', $this->post_types);
        $search_term = $this->prepare_search_term($query->query_vars['search_terms']);
        $hits = $this->search($search_term);
        $all_posts = $this->create_post_objects($hits);
        $this->total_found_posts = count($all_posts);
        $posts_per_page = apply_filters('wp_loupe_posts_per_page', get_option('posts_per_page'));
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;
        $offset = ($paged - 1) * $posts_per_page;
        $this->max_num_pages = ceil($this->total_found_posts / $posts_per_page);
        $paged_posts = array_slice($all_posts, $offset, $posts_per_page);
        $query->found_posts = $this->total_found_posts;
        $query->max_num_pages = $this->max_num_pages;
        $query->is_paged = $paged > 1;
        return $paged_posts;
    }

    private function should_intercept_query($query) {
        return !is_admin() && $query->is_main_query() && $query->is_search() && !$query->is_admin;
    }

    private function prepare_search_term($search_terms) {
        return implode(' ', array_map(function ($term) {
            return strpos($term, ' ') !== false ? '"' . $term . '"' : $term;
        }, $search_terms));
    }

    public function search($query) {
        $cache_key = md5($query . serialize($this->post_types));
        $cached_result = get_transient("wp_loupe_search_$cache_key");
        if (false !== $cached_result) {
            $this->log = sprintf('WP Loupe cache hit: %s ms', 0);
            return $cached_result;
        }
        
        $hits = [];
        $stats = [];
        $start_time = microtime(true);
        
        foreach ($this->post_types as $post_type) {
            $post_type_fields = $this->saved_fields[$post_type] ?? [];
            if (empty($post_type_fields)) {
                continue;
            }
            
            try {
                $indexable_fields = [];
                $sort_fields = [];
                $filterable_fields = [];
                
                // Process field settings
                foreach ($post_type_fields as $field_name => $settings) {
                    if (!empty($settings['indexable'])) {
                        $indexable_fields[] = [
                            'field' => $field_name,
                            'weight' => $settings['weight'] ?? 1.0
                        ];
                    }
                    
                    if (!empty($settings['filterable'])) {
                        $filterable_fields[] = $field_name;
                    }
                }
                
                // Get all retrievable fields
                $retrievable_fields = array_unique(array_merge(
                    ['id'],
                    array_map(function ($field) {
                        return $field['field'];
                    }, $indexable_fields),
                    $filterable_fields
                ));
                
                // Create search parameters
                $search_params = SearchParameters::create()
                    ->withQuery($query)
                    ->withAttributesToRetrieve($retrievable_fields);
                
                // Apply sort fields separately to handle possible errors
                // Only use fields that are explicitly marked as sortable in the schema
                $valid_sort_fields = [];
                foreach ($post_type_fields as $field_name => $settings) {
                    if (!empty($settings['sortable'])) {
                        $sort_direction = $settings['sort_direction'] ?? 'desc';
                        $valid_sort_fields[] = "{$field_name}:{$sort_direction}";
                    }
                }
                
                // Only apply sort if we have valid sort fields
                if (!empty($valid_sort_fields)) {
                    try {
                        $search_params = $search_params->withSort($valid_sort_fields);
                    } catch (\Exception $e) {
                        // Log the sort error but continue with search
                        error_log("WP Loupe sort error for {$post_type}: " . $e->getMessage());
                    }
                }
                
                // Execute search
                $loupe = $this->loupe[$post_type];
                $result = $loupe->search($search_params);
                
                $stats = array_merge_recursive($stats, (array)$result->toArray()['processingTimeMs']);
                $tmp_hits = $result->toArray()['hits'];
                
                // Add post type to each hit for identification
                foreach ($tmp_hits as $key => $hit) {
                    $tmp_hits[$key]['post_type'] = $post_type;
                }
                
                $hits = array_merge_recursive($hits, $tmp_hits);
            } catch (\Exception $e) {
                error_log("WP Loupe search error for {$post_type}: " . $e->getMessage());
                // Continue with other post types even if one fails
            }
        }
        
        $this->log = sprintf('WP Loupe processing time: %s ms', (string)array_sum($stats));
        set_transient("wp_loupe_search_$cache_key", $hits, self::CACHE_TTL);
        return $hits;
    }

    private function create_post_objects($hits) {
        if (empty($hits)) {
            return [];
        }
        $post_ids = array_column($hits, 'id');
        $hits_by_type = [];
        foreach ($hits as $hit) {
            $hits_by_type[$hit['post_type']][] = $hit;
        }
        $all_posts = [];
        foreach ($hits_by_type as $post_type => $type_hits) {
            $type_posts = get_posts([
                'post_type' => $post_type,
                'post__in' => array_column($type_hits, 'id'),
                'posts_per_page' => -1,
                'orderby' => 'post__in',
                'suppress_filters' => true
            ]);
            $post_type_fields = $this->saved_fields[$post_type] ?? [];
            $fields_to_load = [];
            foreach ($post_type_fields as $field_name => $settings) {
                if (!empty($settings['filterable']) || !empty($settings['sortable'])) {
                    $fields_to_load[] = $field_name;
                }
            }
            foreach ($type_posts as $post) {
                foreach ($fields_to_load as $field) {
                    if (strpos($field, 'taxonomy_') === 0) {
                        $taxonomy = substr($field, 9);
                        $terms = wp_get_post_terms($post->ID, $taxonomy);
                        if (!is_wp_error($terms)) {
                            $post->{$field} = $terms;
                        }
                    } elseif (!property_exists($post, $field)) {
                        $post->{$field} = get_post_meta($post->ID, $field, true);
                    }
                }
            }
            $all_posts = array_merge($all_posts, $type_posts);
        }
        $posts_lookup = array_column($all_posts, null, 'ID');
        return array_map(function ($hit) use ($posts_lookup) {
            return isset($posts_lookup[$hit['id']]) ? $posts_lookup[$hit['id']] : null;
        }, $hits);
    }

    public function action_wp_footer() {
        if (!is_admin()) {
            echo "\n<!-- {$this->log} -->\n";
        }
    }

    public function get_log() {
        return $this->log;
    }
}
