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
			$query->set( 'post_type', $this->post_types );
			$search_term = $this->prepare_search_term( $query->query_vars[ 'search_terms' ] );
			$hits        = $this->search( $search_term );
			WP_Loupe_Utils::dump( [ 'posts_pre_query > hits', $hits ] );
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
