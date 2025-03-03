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
	use WP_Loupe_Shared;

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
		$this->offset         = 10;
		add_filter( 'posts_pre_query', array( $this, 'posts_pre_query' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'action_wp_footer' ), 999 );
		$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		$this->loupe  = [];
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = $this->create_loupe_instance( $post_type, $iso6391_lang );
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
		WP_Loupe_Utils::dump( [ 'hits', $hits ] );

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
	public function search( $query ) {
		$cache_key = md5( $query . serialize( $this->post_types ) );

		// Check transient cache first
		$cached_result = get_transient( "wp_loupe_search_$cache_key" );
		if ( false !== $cached_result ) {
			$this->log = sprintf( 'WP Loupe cache hit: %s ms', 0 );
			return $cached_result;
		}

		$hits       = [];
		$stats      = [];
		$start_time = microtime( true );

		foreach ( $this->post_types as $post_type ) {
			$schema = $this->schema_manager->get_schema_for_post_type( $post_type );

			// Get indexable fields with weights for search
			$indexable_fields = $this->schema_manager->get_indexable_fields( $schema );

			// Get sortable fields with their directions
			$sort_fields = array_map( function ($field) {
				return "{$field[ 'field' ]}:{$field[ 'direction' ]}";
			}, $this->schema_manager->get_sortable_fields( $schema ) );


			// Get all fields that should be retrieved
			$retrievable_fields = array_unique( array_merge(
				[ 'id' ],
				array_map( function ($field) {
					return $field[ 'field' ];
				}, $indexable_fields ),
				$this->schema_manager->get_filterable_fields( $schema )
			)
			);

			$loupe  = $this->loupe[ $post_type ];
			$result = $loupe->search(
				SearchParameters::create()
					->withQuery( $query )
					->withAttributesToRetrieve( $retrievable_fields )
					->withSort( $sort_fields )
			);

			// Merge stats and add post type to hits
			$stats    = array_merge_recursive( $stats, (array) $result->toArray()[ 'processingTimeMs' ] );
			$tmp_hits = $result->toArray()[ 'hits' ];
			foreach ( $tmp_hits as $key => $hit ) {
				$tmp_hits[ $key ][ 'post_type' ] = $post_type;
			}
			$hits = array_merge_recursive( $hits, $tmp_hits );
		}

		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) array_sum( $stats ) );

		// Cache the results
		set_transient( "wp_loupe_search_$cache_key", $hits, self::CACHE_TTL );

		return $hits;
	}

	/**
	 * Create post objects with schema-based fields
	 *
	 * @param array $hits Array of hits.
	 * @return array
	 */
	private function create_post_objects( $hits ) {
		if ( empty( $hits ) ) {
			return [];
		}

		// Get all post IDs
		$post_ids = array_column( $hits, 'id' );

		// Fetch all posts in one query
		$posts = get_posts( [ 
			'post__in'       => $post_ids,
			'posts_per_page' => -1,
			'post_type'      => $this->post_types,
			'orderby'        => 'post__in', // Maintain search result order
			'no_found_rows'  => true,
		] );

		// Create a lookup table
		$posts_lookup = array_column( $posts, null, 'ID' );

		// Map results maintaining original order
		return array_map( function ($hit) use ($posts_lookup) {
			return isset( $posts_lookup[ $hit[ 'id' ] ] ) ? $posts_lookup[ $hit[ 'id' ] ] : null;
		}, $hits );
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

	/**
	 * Create a Loupe instance
	 *
	 * @param string $post_type Post type.
	 * @param string $lang Language.
	 * @return \Loupe\Loupe\Loupe
	 */
	protected function create_loupe_instance( string $post_type, string $lang ) {
		$schema_manager = WP_Loupe_Schema_Manager::get_instance();
		$schema         = $schema_manager->get_schema_for_post_type( $post_type );

		// Get all fields in one pass and extract what we need
		$fields = [ 
			'indexable'  => [],
			'filterable' => [],
			'sortable'   => [],
		];

		// Process all fields in a single loop instead of multiple calls
		foreach ( $schema as $field_name => $settings ) {
			if ( ! is_array( $settings ) ) {
				continue;
			}

			// Handle indexable fields with weights
			if ( isset( $settings[ 'weight' ] ) ) {
				$fields[ 'indexable' ][] = [ 
					'field'  => $field_name,
					'weight' => $settings[ 'weight' ],
				];
			}

			// Handle filterable fields
			if ( ! empty( $settings[ 'filterable' ] ) ) {
				$fields[ 'filterable' ][] = $field_name;
			}

			// Handle sortable fields
			if ( ! empty( $settings[ 'sortable' ] ) ) {
				$fields[ 'sortable' ][] = $field_name;
			}
		}

		// Sort indexable fields by weight once
		if ( ! empty( $fields[ 'indexable' ] ) ) {
			usort( $fields[ 'indexable' ], fn( $a, $b ) => $b[ 'weight' ] <=> $a[ 'weight' ] );
			$fields[ 'indexable' ] = array_column( $fields[ 'indexable' ], 'field' );
		}

		return ( new LoupeFactory() )->create(
			$this->db->get_db_path( $post_type ),
			Configuration::create()
				->withPrimaryKey( 'id' )
				->withSearchableAttributes( $fields[ 'indexable' ] )
				->withFilterableAttributes( $fields[ 'filterable' ] )
				->withSortableAttributes( $fields[ 'sortable' ] )
				->withLanguages( [ $lang ] )
				->withTypoTolerance(
					TypoTolerance::create()->withFirstCharTypoCountsDouble( false )
				)
		);
	}
}
