<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\SearchParameters;

/**
 * REST API handler for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_REST {

    private $post_types;
    private $loupe = [];
    private $db;
    private $schema_manager;
    private $iso6391_lang;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = WP_Loupe_DB::get_instance();
        $this->schema_manager = new WP_Loupe_Schema_Manager();
        $this->iso6391_lang = ('' === get_locale()) ? 'en' : strtolower(substr(get_locale(), 0, 2));
        
        $this->set_post_types();
        $this->init_loupe_instances();
        $this->register_rest_routes();
    }

    /**
     * Set post types from settings
     */
    private function set_post_types() {
        $options = get_option('wp_loupe_custom_post_types', []);
        $this->post_types = !empty($options) && isset($options['wp_loupe_post_type_field']) 
            ? (array)$options['wp_loupe_post_type_field']
            : ['post', 'page'];
    }

    /**
     * Initialize Loupe instances for selected post types
     */
    private function init_loupe_instances() {
        foreach ($this->post_types as $post_type) {
            $this->loupe[$post_type] = WP_Loupe_Factory::create_loupe_instance(
                $post_type, 
                $this->iso6391_lang, 
                $this->db
            );
        }
    }

    /**
     * Register REST API routes
     */
    private function register_rest_routes() {
        add_action('rest_api_init', function () {
            register_rest_route('wp-loupe/v1', '/search', [
                'methods' => 'GET',
                'callback' => [$this, 'handle_search_request'],
                'permission_callback' => '__return_true',
                'args' => [
                    'q' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'post_type' => [
                        'default' => 'all',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'per_page' => [
                        'default' => 10,
                        'sanitize_callback' => 'absint',
                    ],
                    'page' => [
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);
        });
    }

    /**
     * Handle search REST request
     *
     * @param \WP_REST_Request $request REST request object
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_search_request(\WP_REST_Request $request) {
        $query = $request->get_param('q');
        $post_type = $request->get_param('post_type');
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        
        // Generate a cache key based on the search parameters
        $cache_key = 'wp_loupe_search_' . md5($query . $post_type . $per_page . $page);
        $cached_results = get_transient($cache_key);
        
        if (false !== $cached_results) {
            return rest_ensure_response($cached_results);
        }
        
        $search_results = $this->perform_search($query, $post_type, $per_page, $page);
        
        // Cache the results for 12 hours
        set_transient($cache_key, $search_results, 12 * HOUR_IN_SECONDS);
        
        return rest_ensure_response($search_results);
    }
    
    /**
     * Perform search against Loupe index
     *
     * @param string $query Search query
     * @param string $post_type Post type to search in or 'all'
     * @param int $per_page Items per page
     * @param int $page Current page number
     * @return array Search results
     */
    private function perform_search($query, $post_type, $per_page, $page) {
        $results = [
            'hits' => [],
            'took' => 0
        ];
        
        $post_types_to_search = $post_type === 'all' ? $this->post_types : [$post_type];
        
        foreach ($post_types_to_search as $type) {
            if (isset($this->loupe[$type])) {
                $loupe = $this->loupe[$type];
                $search_params = (new SearchParameters())
                    ->setLimit($per_page)
                    ->setOffset(($page - 1) * $per_page);
                
                $search_result = $loupe->search($query, $search_params);
                
                if (!empty($search_result['hits'])) {
                    $type_results = $this->format_search_results($search_result, $type);
                    $results['hits'] = array_merge($results['hits'], $type_results['hits']);
                    $results['took'] += $type_results['took'];
                }
            }
        }
        
        // Sort by relevance score (descending)
        usort($results['hits'], function($a, $b) {
            return $b['_score'] <=> $a['_score'];
        });
        
        // Apply pagination to combined results
        $total_hits = count($results['hits']);
        $results['hits'] = array_slice($results['hits'], 0, $per_page);
        $results['pagination'] = [
            'total' => $total_hits,
            'per_page' => $per_page,
            'current_page' => $page,
            'total_pages' => ceil($total_hits / $per_page)
        ];
        
        return $results;
    }
    
    /**
     * Format search results into a standardized structure
     *
     * @param array $search_result Raw search result from Loupe
     * @param string $post_type Current post type
     * @return array Formatted search results
     */
    private function format_search_results($search_result, $post_type) {
        $formatted = [
            'hits' => [],
            'took' => $search_result['processingTimeMs'] ?? 0,
        ];
        
        foreach ($search_result['hits'] as $hit) {
            $post_id = $hit['id'];
            $post = get_post($post_id);
            
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            
            $formatted_hit = [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'url' => get_permalink($post_id),
                'post_type' => $post_type,
                'post_type_label' => get_post_type_object($post_type)->labels->singular_name,
                'excerpt' => get_the_excerpt($post_id),
                '_score' => $hit['_score'] ?? 0,
            ];
            
            // Add featured image if available
            if (has_post_thumbnail($post_id)) {
                $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'thumbnail');
                if ($featured_image) {
                    $formatted_hit['featured_image'] = $featured_image[0];
                }
            }
            
            $formatted['hits'][] = $formatted_hit;
        }
        
        return $formatted;
    }
}
