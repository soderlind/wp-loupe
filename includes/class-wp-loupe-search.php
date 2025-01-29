<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\SearchParameters;
use Soderlind\Plugin\WPLoupe\WP_Loupe_DB;

class WP_Loupe_Search {
	use WP_Loupe_Shared;

	private $post_types;
	private $loupe = [];
	private $log;

	private $total_found_posts = 0;
	private $max_num_pages = 0;

	public function __construct( $post_types ) {
		$this->post_types = $post_types;
		$this->db         = WP_Loupe_DB::get_instance();
		$this->offset     = 10;
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
			return $posts;
		}

		$query->set( 'post_type', $this->post_types );
		$search_term = $this->prepare_search_term( $query->query_vars[ 'search_terms' ] );
		$hits        = $this->search( $search_term );
		WP_Loupe_Utils::dump( [ 'posts_pre_query > hits', $hits ] );

		// Get all search results first
		$all_posts               = $this->create_post_objects( $hits );
		$this->total_found_posts = count( $all_posts );

		// Get pagination parameters
		$posts_per_page = get_option( 'posts_per_page' );
		$paged          = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
		$offset         = ( $paged - 1 ) * $posts_per_page;

		// Calculate max pages
		$this->max_num_pages = ceil( $this->total_found_posts / $posts_per_page );

		// Slice the results for current page
		$paged_posts = array_slice( $all_posts, $offset, $posts_per_page );

		// Update the main query object with pagination info
		$query->found_posts   = $this->total_found_posts;
		$query->max_num_pages = $this->max_num_pages;

		// Set query pagination properties
		$query->is_paged = $paged > 1;

		return $paged_posts;

	}

	private function should_intercept_query( $query ) {
		// Your existing logic to determine if this query should be intercepted
		return ! is_admin() && $query->is_main_query() && $query->is_search() && ! $query->is_admin;
	}

	private function prepare_search_term( $search_terms ) {
		return implode( ' ', array_map( function ($term) {
			return strpos( $term, ' ' ) !== false ? '"' . $term . '"' : $term;
		}, $search_terms ) );
	}

	public function search( $query ) {
		$hits  = [];
		$stats = [];
		WP_Loupe_Utils::dump( [ 'search > post_types', $this->post_types ] );
		foreach ( $this->post_types as $post_type ) {
			$loupe  = $this->loupe[ $post_type ];
			$result = $loupe->search(
				SearchParameters::create()
					->withQuery( $query )
					->withAttributesToRetrieve( [ 'id', 'post_title', 'post_date' ] )
					->withSort( [ 'post_date:desc' ] )
			);

			WP_Loupe_Utils::dump( [ 'search > result', $result ] );

			$stats = array_merge_recursive( $stats, (array) $result->toArray()[ 'processingTimeMs' ] );

			// add post type to hits
			$tmp_hits = $result->toArray()[ 'hits' ];
			foreach ( $tmp_hits as $key => $hit ) {
				$tmp_hits[ $key ][ 'post_type' ] = $post_type;
			}

			$hits = array_merge_recursive( $hits, $tmp_hits );
			WP_Loupe_Utils::dump( [ 'search > hits', $hits ] );

		}

		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) array_sum( $stats ) );
		return $this->sort_hits_by_date( $hits );
		// return $hits;
	}

	private function sort_hits_by_date( $hits ) {
		WP_Loupe_Utils::dump( [ 'sort_hits_by_date > hits', $hits ] );
		usort( $hits, function ($a, $b) {
			return $b[ 'post_date' ] <=> $a[ 'post_date' ];
		} );
		return $hits;
	}

	private function create_post_objects( $hits ) {
		$posts = [];

		foreach ( $hits as $hit ) {
			$post            = new \stdClass();
			$post->ID        = $hit[ 'id' ];
			$post->post_type = $hit[ 'post_type' ];


			$post = new \WP_Post( $post );


			WP_Loupe_Utils::dump( [ 'create_post_objects > post', $post ] );
			$posts[] = $post;

		}
		WP_Loupe_Utils::dump( [ 'create_post_objects > posts', $posts ] );
		return $posts;
	}

	public function action_wp_footer() {
		if ( ! is_admin() ) {
			echo "\n<!-- {$this->log} -->\n";
		}
	}

	public function get_log() {
		return $this->log;
	}
}
