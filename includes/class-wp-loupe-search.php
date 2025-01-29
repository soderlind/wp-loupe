<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\SearchParameters;
use Soderlind\Plugin\WPLoupe\WP_Loupe_DB;

class WP_Loupe_Search {
	use WP_Loupe_Shared;

	private $post_types;
	private $loupe = [];
	private $log;

	public function __construct( $post_types ) {
		$this->post_types = $post_types;
		$this->db         = WP_Loupe_DB::get_instance();
		add_filter( 'posts_pre_query', array( $this, 'posts_pre_query' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'action_wp_footer' ) );

		$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		$this->loupe  = [];
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = $this->create_loupe_instance( $post_type, $iso6391_lang );
		}
	}

	public function posts_pre_query( $posts, \WP_Query $query ) {
		if ( $query->is_main_query() && $query->is_search() ) {
			$search_term = $this->prepare_search_term( $query->query_vars[ 'search_terms' ] );
			$hits        = $this->search( $search_term );
			WP_Loupe_Utils::dump( [ 'hits', $hits ] );
			return $this->create_post_objects( $hits );
		}
		return $posts;
	}

	private function prepare_search_term( $search_terms ) {
		return implode( ' ', array_map( function ($term) {
			return strpos( $term, ' ' ) !== false ? '"' . $term . '"' : $term;
		}, $search_terms ) );
	}

	public function search( $query ) {
		$hits  = [];
		$stats = [];
		foreach ( $this->post_types as $post_type ) {
			$loupe  = $this->loupe[ $post_type ];
			$result = $loupe->search(
				SearchParameters::create()
					->withQuery( $query )
					->withAttributesToRetrieve( [ 'id', 'post_title', 'post_date' ] )
					->withSort( [ 'post_date:desc' ] )
			);

			WP_Loupe_Utils::dump( [ 'result', $result ] );

			$stats = array_merge_recursive( $stats, (array) $result->toArray()[ 'processingTimeMs' ] );
			$hits  = array_merge_recursive( $hits, $result->toArray()[ 'hits' ] );
			// add post type to hits
			foreach ( $hits as $key => $hit ) {
				$hits[ $key ][ 'post_type' ] = $post_type;
			}
		}

		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) array_sum( $stats ) );
		return $this->sort_hits_by_date( $hits );
	}

	private function sort_hits_by_date( $hits ) {
		WP_Loupe_Utils::dump( [ 'sort_hits_by_date', $hits ] );
		usort( $hits, function ($a, $b) {
			return $b[ 'post_date' ] <=> $a[ 'post_date' ];
		} );
		return $hits;
	}

	private function create_post_objects( $hits ) {
		$posts = [];

		foreach ( $hits as $hit ) {
			$post     = new \stdClass();
			$post->ID = $hit[ 'id' ];

			if ( 'post' === $hit[ 'post_type' ] ) {
				$post->post_type = 'page';
				// $post->post_title  = $hit[ 'post_title' ];
				$post->post_status = 'publish';

				// $post->post_date  = $hit[ 'post_date' ];
				$post->filter = 'raw';
				// $post->post_content = get_post_field( 'post_content', $post->ID );
				$post->post_content = '';
			} else {
				$post = new \WP_Post( $post );
			}
			// else {
			// 	$post->post_content = get_post_field( 'post_content', $post->ID );
			// }
			// $post->post_content = '';
			// $tmp     = get_post( $post->ID );
			WP_Loupe_Utils::dump( [ 'tmp', $post ] );
			$posts[] = $post;

			// $posts[] = new \WP_Post( $post );
		}

		// foreach ( $hits as $post_array ) {
		// 	$post = new \stdClass();

		// 	$post_array = (array) $post_array;
		// 	// $post_data  = $post_array[ 'post_data' ];

		// 	// $post->ID      = ( isset( $post_array[ 'parent_id' ] ) && $post_array[ 'parent_id' ] ) ? $post_array[ 'parent_id' ] : $post_data->ID;
		// 	$post->ID      = $post_array[ 'id' ];
		// 	$post->site_id = get_current_blog_id();

		// 	// if ( ! empty( $post_data->site_id ) ) {
		// 	// 	$post->site_id = $post_data->site_id;
		// 	// }

		// 	$post_return_args = array(
		// 		'post_type',
		// 		'post_author',
		// 		'post_name',
		// 		'post_status',
		// 		'post_title',
		// 		'post_parent',
		// 		'post_content',
		// 		'post_excerpt',
		// 		'post_date',
		// 		'post_date_gmt',
		// 		'post_modified',
		// 		'post_modified_gmt',
		// 		'post_mime_type',
		// 		'comment_count',
		// 		'comment_status',
		// 		'ping_status',
		// 		'menu_order',
		// 		'permalink',
		// 		'terms',
		// 		'post_meta',
		// 	);

		// 	foreach ( $post_return_args as $key ) {
		// 		if ( isset( $post_data->$key ) ) {
		// 			$post->$key = $post_data->$key;
		// 		}
		// 	}

		// 	$post->wploupe = true; // Super useful for debugging

		// 	if ( $post ) {
		// 		$posts[] = $post;
		// 	}
		// }
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
