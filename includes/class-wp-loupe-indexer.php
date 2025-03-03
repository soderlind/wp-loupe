<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;


/**
 * Indexer class for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_Indexer {
	use WP_Loupe_Shared;

	private $post_types;
	private $loupe = [];
	private $db;
	private $schema_manager;

	public function __construct( $post_types ) {
		$this->post_types     = $post_types;
		$this->db             = WP_Loupe_DB::get_instance();
		$this->schema_manager = new WP_Loupe_Schema_Manager();
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

	/**
	 * Add post to the loupe index
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post being updated or not.
	 * @return void
	 */
	public function add( int $post_id, \WP_Post $post, bool $update ): void {
		if ( ! $this->is_indexable( $post_id, $post ) ) {
			return;
		}

		WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );

		$document = $this->prepare_document( $post );
		$loupe    = $this->loupe[ $post->post_type ];
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
		WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );
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
		WP_Loupe_Utils::dump( [ 'post_types', $this->post_types ] );

		$this->delete_index();
		WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );
		$this->init();

		foreach ( $this->post_types as $post_type ) {
			$posts = \get_posts( [ 
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			] );

			$documents = array_map(
				[ $this, 'prepare_document' ],
				$posts
			);

			if ( ! empty( $documents ) ) {
				$this->loupe[ $post_type ]->addDocuments( $documents );
			}
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

	private function prepare_document( \WP_Post $post ): array {
		$schema           = $this->schema_manager->get_schema_for_post_type( $post->post_type );
		$indexable_fields = $this->schema_manager->get_indexable_fields( $schema );

		$document = [ 'id' => $post->ID ];

		foreach ( $indexable_fields as $field ) {
			if ( property_exists( $post, $field[ 'field' ] ) ) {
				$document[ $field[ 'field' ] ] = $post->{$field[ 'field' ]};
			} else {
				// Handle custom fields
				$document[ $field[ 'field' ] ] = get_post_meta( $post->ID, $field[ 'field' ], true );
			}
		}

		// Apply content filter
		if ( isset( $document[ 'post_content' ] ) ) {
			$document[ 'post_content' ] = apply_filters( 'wp_loupe_schema_content',
				preg_replace( '~<!--(.*?)-->~s', '', $document[ 'post_content' ] )
			);
		}

		return $document;
	}

}
