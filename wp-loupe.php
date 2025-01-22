<?php
/**
 * WP Loupe
 *
 * @package     soderlind\plugin\WPLoupe
 * @author      Per Soderlind
 * @copyright   2021 Per Soderlind
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: WP Loupe
 * Plugin URI: https://github.com/soderlind/wp-loupe
 * GitHub Plugin URI: https://github.com/soderlind/wp-loupe
 * Description: Search engine for WordPress. It uses the Loupe search engine to create a search index for your posts and pages and to search the index.
 * Version:     0.0.9
 * Author:      Per Soderlind
 * Author URI:  https://soderlind.no
 * Text Domain: wp-loupe
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

declare(strict_types=1);
namespace soderlind\plugin\WPLoupe;

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

require_once 'includes/class-wploupe-settings-page.php';

require_once 'vendor/autoload.php';

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;

define( 'WP_LOUPE_URL', \plugin_dir_url( __FILE__ ) );

/**
 * Dump to Ray
 *
 * @param mixed $var
 * @return void
 */
function dump( $var ) {
	if ( function_exists( '\ray' ) ) {
		\ray( $var );
	}
}

/**
 * Class WPLoupe
 *
 * @package soderlind\plugin\WPLoupe
 */
class WPLoupe {
	/**
	 * Loupe
	 *
	 * @var array
	 */
	private $loupe = [];

	/**
	 * Post types
	 *
	 * @var array
	 */
	private $post_types;

	/**
	 * Log
	 *
	 * @var string
	 */
	private $log;
	/**
	 * WPLoupe constructor.
	 */
	public function __construct() {

		// Check if SQLite is installed and has the correct version.
		if ( ! $this->has_sqlite() ) {
			return;
		}

		\add_action( 'plugin_loaded', [ $this, 'init' ] );
		\add_filter(
			'wp_loupe_post_types',
			function ($post_types) {
				$options           = get_option( 'wp_loupe_custom_post_types', [] );
				$custom_post_types = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] ) && ! empty( $options[ 'wp_loupe_post_type_field' ] ) ? (array) $options[ 'wp_loupe_post_type_field' ] : [];

				return array_merge( $post_types, $custom_post_types );
			}
		);
		$this->post_types = \apply_filters( 'wp_loupe_post_types', [ 'post', 'page' ] );
		foreach ( $this->post_types as $post_type ) {
			\add_action( "save_post_{$post_type}", [ $this, 'add' ], 10, 3 );
		}

		\add_action( 'wp_trash_post', [ $this, 'trash_post' ], 10, 2 );

		\add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );

		\add_action( 'admin_init', [ $this, 'handle_reindex' ] );

		\add_action( 'wp_footer', [ $this, 'action_wp_footer' ] );

		\load_plugin_textdomain( 'wp-loupe', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Create a new Loupe instance for each post type.
	 *
	 * @return void
	 */
	public function init(): void {
		$iso6391_lang = ( '' === \get_locale() ) ? 'en' : strtolower( substr( \get_locale(), 0, 2 ) );
		foreach ( $this->post_types as $post_type ) {

			$filterable_attributes = \apply_filters( "wp_loupe_filterable_attribute_{$post_type}", [ 'title', 'content' ] );

			$configuration = Configuration::create()
				->withPrimaryKey( 'id' )
				->withFilterableAttributes( $filterable_attributes )
				->withSortableAttributes( [ 'date', 'title' ] )
				->withLanguages( [ $iso6391_lang ] )
				->withTypoTolerance( TypoTolerance::create()->withFirstCharTypoCountsDouble( false ) );

			$db_path                   = apply_filters( 'wp_loupe_db_path', WP_CONTENT_DIR . '/wp-loupe-db' );
			$db_path                   = "{$db_path}/{$post_type}";
			$loupe_factory             = new LoupeFactory();
			$this->loupe[ $post_type ] = $loupe_factory->create( $db_path, $configuration );
		}
	}

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

		$document = [ 
			'id'      => $post_id,
			'title'   => \get_the_title( $post ),
			'content' => \apply_filters( 'wp_loupe_schema_content', preg_replace( '~<!--(.*?)-->~s', '', $post->post_content ) ),
			'url'     => \get_permalink( $post ),
			'date'    => \get_post_timestamp( $post ),
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
			dump( $search_terms );

			// Combine the search terms into a single string.
			$search_term = implode( ' ', $search_terms );
			dump( $search_term );

			// Perform the search and get the results.
			$hits = $this->search( $search_term );
			dump( $hits );
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
			dump( 'query: ' . $$query );
			$loupe = $this->loupe[ $post_type ];
			dump( $loupe );
			$search_parameters = SearchParameters::create()
				->withQuery( $query )
				->withAttributesToRetrieve( [ 'id', 'title', 'date' ] )
				->withSort( [ 'date:desc' ] );

			dump( $search_parameters );
			$result = $loupe->search( $search_parameters );
			dump( $result );
			$stats = array_merge_recursive( $stats, (array) $result->toArray()[ 'processingTimeMs' ] );
			$hits  = array_merge_recursive( $hits, $result->toArray()[ 'hits' ] );
		}
		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) array_sum( $stats ) );

		// Sort the results by date.
		usort(
			$hits,
			function ($a, $b) {
				return $b[ 'date' ] <=> $a[ 'date' ];
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
					'id'      => $post->ID,
					'title'   => \get_the_title( $post ),
					'content' => \apply_filters( 'wp_loupe_schema_content', preg_replace( '~<!--(.*?)-->~s', '', $post->post_content ) ),
					'url'     => \get_permalink( $post ),
					'date'    => \get_post_timestamp( $post ),
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

	/**
	 * Check if SQLite is installed and has the correct version.
	 *
	 * @link https://wordpress.org/plugins/wp-force-login/
	 * @return bool
	 */
	public static function has_sqlite(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! class_exists( 'SQLite3' ) ) {
			self::display_error_and_deactivate_plugin(
				__( 'SQLite3 not install', 'wp-loupe' ),
				__( 'WP Loupe requires SQLite3 version 3.16.0 or newer to be installed.', 'wp-loupe' )
			);
			return false;
		} else {
			$version = \SQLite3::version();
			if ( version_compare( $version[ 'versionString' ], '3.16.0', '<' ) ) {
				self::display_error_and_deactivate_plugin(
					__( 'SQLite3 version too old', 'wp-loupe' ),
					__( 'WP Loupe requires SQLite3 version 3.16.0 or newer to be installed', 'wp-loupe' )
				);
				return false;
			}
		}
		return true;
	}

	/**
	 * Display error message and deactivate plugin.
	 *
	 * @param string $error_title Error title.
	 * @param string $error_message Error message.
	 * @return void
	 */
	private static function display_error_and_deactivate_plugin( string $error_title, string $error_message ) {
		add_action(
			'all_admin_notices',
			function () use ($error_title, $error_message) {
				$msg   = [];
				$msg[] = '<div class="notice notice-error is-dismissible ">';
				$msg[] = '<p><strong>' . esc_html( $error_title ) . '</strong></p>';
				$msg[] = '<p>' . esc_html( $error_message ) . '</p>';
				$msg[] = '</div>';
				echo implode( PHP_EOL, $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	
				deactivate_plugins( WP_LOUPE_NAME );
				if ( is_multisite() ) {
					deactivate_plugins( WP_LOUPE_NAME, false, true );
				}
			}
		);
	}
}

new WPLoupe();
