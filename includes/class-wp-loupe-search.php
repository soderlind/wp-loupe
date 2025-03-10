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
	private $schema_manager;
	private $db;

	private $total_found_posts = 0;
	private $max_num_pages = 0;
	private $search_cache = [];
	private const CACHE_TTL = 3600; // 1 hour

	public function __construct( $post_types ) {
		$this->post_types     = $post_types;
		$this->db             = WP_Loupe_DB::get_instance();
		$this->schema_manager = WP_Loupe_Schema_Manager::get_instance();
		add_filter( 'posts_pre_query', array( $this, 'posts_pre_query' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'action_wp_footer' ), 999 );
		$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		$this->loupe  = [];
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[$post_type] = WP_Loupe_Factory::create_loupe_instance($post_type, $iso6391_lang, $this->db);
		}
	}

	/**
	 * Filter posts before the main query is executed. This is where we intercept the query and replace the results with
	 * our own. Also, we calculate the pagination parameters.
	 *
	 * @param array    $posts Array of posts.
	 * @param \WP_Query $query WP_Query object.
	 * @return array
	 */
	public function posts_pre_query( $posts, \WP_Query $query ) {
		if ( ! $this->should_intercept_query( $query ) ) {
			return null;
		}

		$query->set( 'post_type', $this->post_types );
		$search_term = $this->prepare_search_term( $query->query_vars[ 'search_terms' ] );
		$hits        = $this->search( $search_term );
		

		// Get all search results first	
		$all_posts               = $this->create_post_objects( $hits );
		$this->total_found_posts = count( $all_posts );

		// Get pagination parameters
		$posts_per_page = apply_filters( 'wp_loupe_posts_per_page', get_option( 'posts_per_page' ) );
		$paged          = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
		$offset         = ( $paged - 1 ) * $posts_per_page;

		// Calculate max pages
		$this->max_num_pages = ceil( $this->total_found_posts / $posts_per_page );

		// Slice the results for current page
		$paged_posts = array_slice( $all_posts, $offset, $posts_per_page );

		// Update the main query object with pagination info
		$query->found_posts   = $this->total_found_posts;
		$query->max_num_pages = $this->max_num_pages;

		// Set query pagination proper ties
		$query->is_paged = $paged > 1;

		return $paged_posts;

	}

	/**
	 * Determine if the query should be intercepted
	 *
	 * @param \WP_Query $query WP_Query object.
	 * @return bool
	 */
	private function should_intercept_query( $query ) {
		// Your existing logic to determine if this query should be intercepted
		return ! is_admin() && $query->is_main_query() && $query->is_search() && ! $query->is_admin;
	}

	/**
	 * Prepare search term
	 *
	 * @param array $search_terms Array of search terms.
	 * @return string
	 */
	private function prepare_search_term( $search_terms ) {
		return implode( ' ', array_map( function ($term) {
			return strpos( $term, ' ' ) !== false ? '"' . $term . '"' : $term;
		}, $search_terms ) );
	}

	/**
	 * Search the loupe indexes
	 *
	 * @param string $query Search query.
	 * @return array
	 */
	public function search($query) {
        $cache_key = md5($query . serialize($this->post_types));
        
        // Check transient cache first
        $cached_result = get_transient("wp_loupe_search_$cache_key");
        if (false !== $cached_result) {
            $this->log = sprintf('WP Loupe cache hit: %s ms', 0);
            return $cached_result;
        }
        
        $hits = [];
        $stats = [];
        $start_time = microtime(true);
        
        // Get all saved field settings from wp_loupe_fields
        $saved_fields = get_option('wp_loupe_fields', []);
        
        foreach ($this->post_types as $post_type) {
            // Get field settings for this post type
            $post_type_fields = $saved_fields[$post_type] ?? [];
            
            if (empty($post_type_fields)) {
                // Skip if no fields are configured
                continue;
            }
            
            // Prepare arrays for field configuration
            $indexable_fields = [];
            $sort_fields = [];
            $filterable_fields = [];
            
            // Process fields for this post type
            foreach ($post_type_fields as $field_name => $settings) {
                // Add indexable fields with weights
                if (!empty($settings['indexable'])) {
                    $indexable_fields[] = [
                        'field' => $field_name,
                        'weight' => $settings['weight'] ?? 1.0
                    ];
                }
                
                // Add sortable fields with directions
                if (!empty($settings['sortable'])) {
                    // Only add sortable fields that are safely sortable
                    if (WP_Loupe_Factory::check_sortable_field($field_name, $post_type)) {
                        $sort_direction = $settings['sort_direction'] ?? 'desc';
                        $sort_fields[] = "{$field_name}:{$sort_direction}";
                    }
                }
                
                // Add filterable fields
                if (!empty($settings['filterable'])) {
                    $filterable_fields[] = $field_name;
                }
            }
            
            // Get all fields that should be retrieved
            $retrievable_fields = array_unique(array_merge(
                ['id'],
                array_map(function($field) {
                    return $field['field'];
                }, $indexable_fields),
                $filterable_fields
            ));
            
            WP_Loupe_Utils::dump(['post_type' => $post_type, 'retrievable_fields' => $retrievable_fields, 'sort_fields' => $sort_fields]);
            
            $loupe = $this->loupe[$post_type];
            $result = $loupe->search(
                SearchParameters::create()
                    ->withQuery($query)
                    ->withAttributesToRetrieve($retrievable_fields)
                    ->withSort($sort_fields)
            );
            
            // Merge stats and add post type to hits
            $stats = array_merge_recursive($stats, (array)$result->toArray()['processingTimeMs']);
            $tmp_hits = $result->toArray()['hits'];
            
            foreach ($tmp_hits as $key => $hit) {
                $tmp_hits[$key]['post_type'] = $post_type;
            }
            
            WP_Loupe_Utils::dump(['post_type' => $post_type, 'tmp_hits' => $tmp_hits]);
            $hits = array_merge_recursive($hits, $tmp_hits);
        }
        
        $this->log = sprintf('WP Loupe processing time: %s ms', (string)array_sum($stats));
        
        // Cache the results
        set_transient("wp_loupe_search_$cache_key", $hits, self::CACHE_TTL);
        
        return $hits;
    }

	/**
	 * Create post objects with schema-based fields
	 *
	 * @param array $hits Array of hits.
	 * @return array
	 */
	private function create_post_objects($hits) {
        if (empty($hits)) {
            return [];
        }

        // Get all post IDs and organize hits by post type
        $post_ids = array_column($hits, 'id');
        $hits_by_type = [];
        foreach ($hits as $hit) {
            $hits_by_type[$hit['post_type']][] = $hit;
        }

        // Get all saved field settings from wp_loupe_fields
        $saved_fields = get_option('wp_loupe_fields', []);

        // Fetch all posts grouped by post type
        $all_posts = [];
        foreach ($hits_by_type as $post_type => $type_hits) {
            $type_posts = get_posts([
                'post_type' => $post_type,
                'post__in' => array_column($type_hits, 'id'),
                'posts_per_page' => -1,
                'orderby' => 'post__in', // Preserve Loupe's ordering
                'suppress_filters' => true
            ]);

            // Get fields configurations for this post type
            $post_type_fields = $saved_fields[$post_type] ?? [];
            $fields_to_load = [];

            // Process the field settings
            foreach ($post_type_fields as $field_name => $settings) {
                // Include fields that are filterable or sortable
                if (!empty($settings['filterable']) || !empty($settings['sortable'])) {
                    $fields_to_load[] = $field_name;
                }
            }

            // Enhance posts with additional fields
            foreach ($type_posts as $post) {
                foreach ($fields_to_load as $field) {
                    if (strpos($field, 'taxonomy_') === 0) {
                        // Load taxonomy terms
                        $taxonomy = substr($field, 9);
                        $terms = wp_get_post_terms($post->ID, $taxonomy);
                        if (!is_wp_error($terms)) {
                            $post->{$field} = $terms;
                        }
                    } elseif (!property_exists($post, $field)) {
                        // Load custom field value
                        $post->{$field} = get_post_meta($post->ID, $field, true);
                    }
                }
            }

            $all_posts = array_merge($all_posts, $type_posts);
        }

        // Create a lookup table for quick access
        $posts_lookup = array_column($all_posts, null, 'ID');

        // Map results maintaining original order from hits
        return array_map(function($hit) use ($posts_lookup) {
            return isset($posts_lookup[$hit['id']]) ? $posts_lookup[$hit['id']] : null;
        }, $hits);
    }

    private function apply_filters($query, $post_type) {
        $params = [];
        
        // Get filterable fields from wp_loupe_fields
        $saved_fields = get_option('wp_loupe_fields', []);
        $post_type_fields = $saved_fields[$post_type] ?? [];
        $filterable_fields = [];
        
        // Extract filterable fields
        foreach ($post_type_fields as $field_name => $settings) {
            if (!empty($settings['filterable'])) {
                $filterable_fields[] = $field_name;
            }
        }
        
        foreach ($filterable_fields as $field) {
            // Check if filter is present in query vars
            $filter_value = $query->get($field);
            if (!empty($filter_value)) {
                if (strpos($field, 'taxonomy_') === 0) {
                    // Handle taxonomy filters
                    $taxonomy = substr($field, 9);
                    $terms = explode(',', $filter_value);
                    $params['filter'][$field] = array_map('trim', $terms);
                } else {
                    // Handle regular field filters
                    $params['filter'][$field] = $filter_value;
                }
            }
        }
        
        return $params;
    }

    private function apply_sorting($query, $post_type) {
        $params = [];
        $orderby = $query->get('orderby', 'relevance');
        $order = strtolower($query->get('order', 'desc'));
        
        // Get sortable fields from wp_loupe_fields
        $saved_fields = get_option('wp_loupe_fields', []);
        $post_type_fields = $saved_fields[$post_type] ?? [];
        $sortable_field_names = [];
        
        // Extract sortable fields
        foreach ($post_type_fields as $field_name => $settings) {
            if (!empty($settings['sortable'])) {
                $sortable_field_names[] = $field_name;
            }
        }
        
        if ($orderby !== 'relevance' && in_array($orderby, $sortable_field_names)) {
            $params['sort'] = [
                'field' => $orderby,
                'direction' => in_array($order, ['asc', 'desc']) ? $order : 'desc'
            ];
        }
        
        return $params;
    }

	/**
	 * Write log to footer
	 */
	public function action_wp_footer() {
		if ( ! is_admin() ) {
			echo "\n<!-- {$this->log} -->\n";
		}
	}

	/**
	 * Get log
	 *
	 * @return string
	 */
	public function get_log() {
		return $this->log;
	}
}
