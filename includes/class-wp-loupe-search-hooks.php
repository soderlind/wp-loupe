<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Front-end only hook integration for WP Loupe.
 *
 * Owns WordPress query interception + footer timing output.
 */
class WP_Loupe_Search_Hooks {
	/** @var WP_Loupe_Search_Engine */
	private $engine;

	/** @var array */
	private $post_types;

	/** @var int */
	private $total_found_posts = 0;

	/** @var int */
	private $max_num_pages = 0;

	/**
	 * Constructor.
	 *
	 * @param WP_Loupe_Search_Engine $engine Search engine instance.
	 */
	public function __construct( WP_Loupe_Search_Engine $engine ) {
		$this->engine     = $engine;
		$this->post_types = $engine->get_post_types();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );
		add_action( 'wp_footer', [ $this, 'action_wp_footer' ], 999 );
	}

	/**
	 * Filter posts before the main query runs.
	 *
	 * Intercepts search queries and returns WP Loupe results instead.
	 *
	 * @param array|null $posts Null to allow WP to run its query, or array of posts to short-circuit.
	 * @param \WP_Query  $query The WP_Query instance.
	 * @return array|null Array of WP_Post objects on success, null to fall back to default query.
	 */
	public function posts_pre_query( $posts, \WP_Query $query ) {
		if ( ! $this->should_intercept_query( $query ) ) {
			return null;
		}

		$query->set( 'post_type', $this->post_types );
		$search_term = $this->prepare_search_term( $query->query_vars[ 'search_terms' ] ?? [] );
		$hits        = $this->engine->search( $search_term );
		$all_posts   = $this->create_post_objects( $hits );

		$this->total_found_posts = count( $all_posts );
		$posts_per_page          = apply_filters( 'wp_loupe_posts_per_page', $query->get( 'posts_per_page' ) ?: get_option( 'posts_per_page' ) );
		$paged                   = $query->get( 'paged' ) ? $query->get( 'paged' ) : 1;
		$offset                  = ( $paged - 1 ) * $posts_per_page;
		$this->max_num_pages     = (int) ceil( $this->total_found_posts / $posts_per_page );
		$paged_posts             = array_slice( $all_posts, $offset, $posts_per_page );

		$query->found_posts   = $this->total_found_posts;
		$query->max_num_pages = $this->max_num_pages;
		$query->is_paged      = $paged > 1;
		return $paged_posts;
	}

	/**
	 * Determine if WP Loupe should intercept the given query.
	 *
	 * Checks for front-end search queries with indexed post types.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return bool True if WP Loupe should handle this query.
	 */
	private function should_intercept_query( \WP_Query $query ): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( ! $query->is_search() || ( ! $query->is_main_query() && ! wp_doing_ajax() ) ) {
			return false;
		}

		// Only intercept if all queried post types are indexed by WP Loupe.
		$queried_type = $query->get( 'post_type' );
		if ( empty( $queried_type ) || 'any' === $queried_type ) {
			// When no post_type specified, only intercept if we index all public searchable types.
			$searchable_types = get_post_types( [ 'public' => true, 'exclude_from_search' => false ] );
			if ( array_diff( $searchable_types, $this->post_types ) ) {
				return false;
			}
		} else {
			$queried_types = (array) $queried_type;
			if ( array_diff( $queried_types, $this->post_types ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prepare search terms for Loupe query.
	 *
	 * Wraps multi-word terms in quotes for phrase matching.
	 *
	 * @param array|mixed $search_terms Array of search terms from WP_Query.
	 * @return string Prepared search string.
	 */
	private function prepare_search_term( $search_terms ): string {
		$search_terms = is_array( $search_terms ) ? $search_terms : [];
		return implode( ' ', array_map( function ( $term ) {
			$term = (string) $term;
			return strpos( $term, ' ' ) !== false ? '"' . $term . '"' : $term;
		}, $search_terms ) );
	}

	/**
	 * Convert Loupe search hits to WP_Post objects.
	 *
	 * Fetches full post data and attaches filterable/sortable meta fields.
	 *
	 * @param array $hits Array of Loupe search result hits.
	 * @return array Array of WP_Post objects ordered by relevance.
	 */
	private function create_post_objects( $hits ): array {
		if ( empty( $hits ) || ! is_array( $hits ) ) {
			return [];
		}

		$saved_fields = get_option( 'wp_loupe_fields', [] );
		$hits_by_type = [];
		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) || empty( $hit[ 'post_type' ] ) ) {
				continue;
			}
			$hits_by_type[ $hit[ 'post_type' ] ][] = $hit;
		}

		$all_posts = [];
		foreach ( $hits_by_type as $post_type => $type_hits ) {
			$type_posts = get_posts( [
				'post_type'        => $post_type,
				'post__in'         => array_column( $type_hits, 'id' ),
				'posts_per_page'   => -1,
				'orderby'          => 'post__in',
				'suppress_filters' => true,
			] );

			$post_type_fields = is_array( $saved_fields ) ? ( $saved_fields[ $post_type ] ?? [] ) : [];
			$fields_to_load   = [];
			foreach ( (array) $post_type_fields as $field_name => $settings ) {
				if ( ! empty( $settings[ 'filterable' ] ) || ! empty( $settings[ 'sortable' ] ) ) {
					$fields_to_load[] = $field_name;
				}
			}

			foreach ( $type_posts as $post ) {
				foreach ( $fields_to_load as $field ) {
					if ( strpos( $field, 'taxonomy_' ) === 0 ) {
						$taxonomy = substr( $field, 9 );
						$terms    = wp_get_post_terms( $post->ID, $taxonomy );
						if ( ! is_wp_error( $terms ) ) {
							$post->{$field} = $terms;
						}
					} elseif ( ! property_exists( $post, $field ) ) {
						$post->{$field} = get_post_meta( $post->ID, $field, true );
					}
				}
			}

			$all_posts = array_merge( $all_posts, $type_posts );
		}

		$posts_lookup = array_column( $all_posts, null, 'ID' );
		return array_values( array_filter( array_map( function ( $hit ) use ( $posts_lookup ) {
			return isset( $hit[ 'id' ] ) && isset( $posts_lookup[ $hit[ 'id' ] ] ) ? $posts_lookup[ $hit[ 'id' ] ] : null;
		}, $hits ) ) );
	}

	/**
	 * Output search timing info as HTML comment in footer.
	 *
	 * @return void
	 */
	public function action_wp_footer(): void {
		if ( is_admin() ) {
			return;
		}
		$log = $this->engine->get_log();
		if ( $log ) {
			echo "\n<!-- {$log} -->\n";
		}
	}
}
