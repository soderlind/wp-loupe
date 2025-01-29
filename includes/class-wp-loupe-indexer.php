<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;



class WP_Loupe_Indexer {
	use WP_Loupe_Shared;

	private $post_types;
	private $loupe = [];
	private $db;

	public function __construct( $post_types ) {
		$this->post_types = $post_types;
		$this->db         = WP_Loupe_DB::get_instance();
		$this->init();
		$this->register_hooks();
	}

	private function register_hooks() {
		foreach ( $this->post_types as $post_type ) {
			add_action( "save_post_{$post_type}", array( $this, 'add' ), 10, 3 );
		}
		add_action( 'wp_trash_post', array( $this, 'trash_post' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_reindex' ) );
	}

	public function init() {
		$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = $this->create_loupe_instance( $post_type, $iso6391_lang );
		}
	}

	// Remove the create_loupe_instance method as it's now in the trait

	/**
	 * Add post to the loupe index
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post being updated or not.
	 * @return void
	 */
	public function add( int $post_id, \WP_Post $post, bool $update ): void { // phpcs:ignore.
		// Check if the post should be indexed.
		if ( ! $this->is_indexable( $post_id, $post ) ) {
			return;
		}

		WP_Loupe_Utils::dump( [ 'add > post', $post ] );

		$document = [ 
			'id'           => $post_id,
			'post_title'   => \get_the_title( $post ),
			'post_content' => \apply_filters( 'wp_loupe_schema_content', preg_replace( '~<!--(.*?)-->~s', '', $post->post_content ) ),
			'permalink'    => \get_permalink( $post ),
			'post_date'    => \get_post_timestamp( $post ),
		];

		$loupe = $this->loupe[ $post->post_type ];
		$loupe->deleteDocument( $post_id );
		$loupe->addDocument( $document );
	}

	/**
	 * Fires before a post is sent to the Trash.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $previous_status The status of the post about to be trashed.
	 */
	public function trash_post( int $post_id, string $previous_status ): void {
		if ( ! 'publish' === $previous_status ) {
			return;
		}
		// Verify if is trashing multiple posts.
		if ( isset( $_GET[ 'post' ] ) && is_array( $_GET[ 'post' ] ) ) {
			\check_admin_referer( 'bulk-posts' );
			// Sanitize the array of post IDs.
			$post_ids = \map_deep( $_GET[ 'post' ], 'intval' );
			$this->delete_many( $post_ids );

		} else {
			$this->delete( $post_id );
		}
	}

	/**
	 * Delete post from loupe index
	 *
	 * @param int $post_id    Post ID.
	 */
	private function delete( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		$loupe     = $this->loupe[ $post_type ];
		$loupe->deleteDocument( $post_id );
	}

	/**
	 * Delete many posts from loupe index
	 *
	 * @param array $post_ids    Array of post IDs.
	 */
	private function delete_many( array $post_ids ): void {
		$post_type = get_post_type( $post_ids[ 0 ] );
		$loupe     = $this->loupe[ $post_type ];
		$loupe->deleteDocuments( $post_ids );
	}

	/**
	 * Create a new WP_Post object for each search result.
	 * The WP_Query will then use these objects instead of querying the database.
	 *
	 * @param array     $posts Array of post objects.
	 * @param \WP_Query $query The WP_Query instance (passed by reference).
	 * @return array    Array of post objects. If empty, the WP_Query will continue, and use the database query.
	 */
	public function posts_pre_query( $posts, \WP_Query $query ) {
		// Check if the query is the main query and a search query.
		if ( /*! \is_admin() && */ $query->is_main_query() && $query->is_search() ) {
			// Get the search terms from the query variables.
			// The search terms are prefiltered by WordPress and stopwords are removed.
			$raw_search_terms = $query->query_vars[ 'search_terms' ];

			// Initialize an array to hold the processed search terms.
			$search_terms = [];
			// Loop through each raw search term.
			foreach ( $raw_search_terms as $term ) {
				// If the term contains a space, wrap it in quotes.
				if ( false !== strpos( $term, ' ' ) ) {
					$search_terms[] = '"' . $term . '"';
				} else {
					// Otherwise, add the term as is.
					$search_terms[] = $term;
				}
			}
			WP_Loupe_Utils::dump( [ 'posts_pre_query > search_terms', $search_terms ] );

			// Combine the search terms into a single string.
			$search_term = implode( ' ', $search_terms );
			WP_Loupe_Utils::dump( [ 'posts_pre_query > search_term', $search_term ] );

			// Perform the search and get the results.
			$hits = $this->search( $search_term );
			WP_Loupe_Utils::dump( [ 'posts_pre_query > search_term', $hits ] );
			// Initialize an array to hold the IDs of the search results.
			$ids = [];
			foreach ( $hits as $hit ) {
				$ids[] = $hit[ 'id' ];
			}

			// Initialize an array to hold the posts.
			$posts = [];
			// Loop through each ID.
			foreach ( $ids as $id ) {
				// Create a new WP_Post object and set its ID.
				$post     = new \WP_Post( new \stdClass() );
				$post->ID = $id;
				// Add the post to the posts array.
				$posts[] = $post;
			}
		}

		// Return the posts.
		return $posts;
	}

	/**
	 * Search the loupe indexes
	 *
	 * @param string $query Search query.
	 * @return array
	 */
	public function search( string $query ): array {
		$hits  = [];
		$stats = [];
		foreach ( $this->post_types as $post_type ) {
			WP_Loupe_Utils::dump( [ 'search > query', $query ] );
			$loupe = $this->loupe[ $post_type ];
			WP_Loupe_Utils::dump( [ 'search > loupe', $loupe ] );
			$search_parameters = SearchParameters::create()
				->withQuery( $query )
				->withAttributesToRetrieve( [ 'id', 'post_title', 'post_date' ] )
				->withSort( [ 'post_date:desc' ] );

			WP_Loupe_Utils::dump( [ 'search > search_parameters', $search_parameters ] );
			$result = $loupe->search( $search_parameters );
			WP_Loupe_Utils::dump( [ 'search > result', $result ] );
			$stats = array_merge_recursive( $stats, (array) $result->toArray()[ 'processingTimeMs' ] );
			$hits  = array_merge_recursive( $hits, $result->toArray()[ 'hits' ] );
		}
		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) array_sum( $stats ) );

		// Sort the results by date.
		usort(
			$hits,
			function ($a, $b) {
				return $b[ 'post_date' ] <=> $a[ 'post_date' ];
			}
		);
		return $hits;
	}

	/**
	 * Handle reindexing
	 *
	 * @return void
	 */
	public function handle_reindex() {

		if (
			isset( $_POST[ 'action' ], $_POST[ 'wp_loupe_nonce_field' ], $_POST[ 'wp_loupe_reindex' ] ) &&
			'update' === $_POST[ 'action' ] && 'on' === $_POST[ 'wp_loupe_reindex' ] &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ 'wp_loupe_nonce_field' ] ) ), 'wp_loupe_nonce_action' )
		) {
			add_settings_error( 'wp-loupe', 'wp-loupe-reindex', __( 'Reindexing in progress', 'wp-loupe' ), 'updated' );
			$this->reindex_all();
		}
	}


	/**
	 * Reindex all posts
	 *
	 * @return void
	 */
	public function reindex_all() {

		$this->delete_index();
		$this->init();

		foreach ( $this->post_types as $post_type ) {
			$posts     = \get_posts(
				[ 
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				]
			);
			$documents = [];
			foreach ( $posts as $post ) {
				$document    = [ 
					'id'           => $post->ID,
					'post_title'   => \get_the_title( $post ),
					'post_content' => \apply_filters( 'wp_loupe_schema_content', preg_replace( '~<!--(.*?)-->~s', '', $post->post_content ) ),
					'permalink'    => \get_permalink( $post ),
					'post_date'    => \get_post_timestamp( $post ),
				];
				$documents[] = $document;
			}
			$loupe = $this->loupe[ $post_type ];
			$loupe->addDocuments( $documents );
		}
	}

	/**
	 * Delete the index.
	 *
	 * @return void
	 */
	private function delete_index() {
		// Include the base filesystem class from WordPress core.
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';

		// Include the direct filesystem class from WordPress core.
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		// Create a new instance of the direct filesystem class.
		$file_system_direct = new \WP_Filesystem_Direct( false );

		// Apply filter to get cache path, default is 'WP_CONTENT_DIR/cache/a-faster-load-textdomain'.
		$cache_path = apply_filters( 'wp_loupe_db_path', WP_CONTENT_DIR . '/wp-loupe-db' );

		// If the cache directory exists, remove it and its contents.
		if ( $file_system_direct->is_dir( $cache_path ) ) {
			$file_system_direct->rmdir( $cache_path, true );
		}
	}

	/**
	 * Check if the post should be indexed.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return bool
	 */
	private function is_indexable( int $post_id, \WP_Post $post ): bool {
		// Check if the post is a revision.
		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		// Check if the post is an autosave.
		if ( \wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		// Check if the post type is in the list of post types to be indexed.
		if ( ! \in_array( $post->post_type, $this->post_types, true ) ) {
			return false;
		}

		// Check if the post status is 'publish'.
		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		// Check if the post is password protected.
		if ( ! \apply_filters( 'wp_loupe_index_protected', empty( $post->post_password ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add processing time to wp_footer
	 */
	public function action_wp_footer(): void {
		if ( ! is_admin() ) {
			echo "\n" . '<!--' . $this->log . ' -->' . "\n";
		}
	}

}
