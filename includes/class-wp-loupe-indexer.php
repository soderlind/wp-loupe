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

    private $post_types;
    private $loupe = [];
    private $db;
    private $schema_manager;
    private $iso6391_lang;

    public function __construct($post_types = null) {
        $this->db = WP_Loupe_DB::get_instance();
        $this->schema_manager = new WP_Loupe_Schema_Manager();
        $this->iso6391_lang = ('' === get_locale()) ? 'en' : strtolower(substr(get_locale(), 0, 2));
        
        $this->set_post_types($post_types);
        $this->register_hooks();
        $this->init_loupe_instances();
    }

    /**
     * Set post types from settings or provided array
     *
     * @param array|null $post_types Optional post types array
     */
    private function set_post_types($post_types = null) {
        if ($post_types === null) {
            $options = get_option('wp_loupe_custom_post_types', []);
            $this->post_types = !empty($options) && isset($options['wp_loupe_post_type_field']) 
                ? (array)$options['wp_loupe_post_type_field']
                : ['post', 'page'];
        } else {
            $this->post_types = (array)$post_types;
        }
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
	 * Register hooks
	 *
	 * @return void
	 */
	private function register_hooks() {
		foreach ( $this->post_types as $post_type ) {
			add_action( "save_post_{$post_type}", array( $this, 'add' ), 10, 3 );
		}
		add_action( 'wp_trash_post', array( $this, 'trash_post' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_reindex' ) );
		add_filter( 'wp_loupe_field_post_content', 'wp_strip_all_tags' );
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
			WP_Loupe_Utils::dump( $_POST);
			$this->reindex_all();
			// add_settings_error( 'wp-loupe', 'wp-loupe-reindex', __( 'Reindexing completed successfully!', 'wp-loupe' ), 'updated' );

		}
	}

	/**
	 * Reindex all posts
	 *
	 * @return void
	 */
	public function reindex_all() {
        // First, clear the search cache
        WP_Loupe_Utils::remove_transient('wp_loupe_search_');
        
        // Delete existing indices
        $this->delete_index();
        
        // Refresh post types from settings
        $this->set_post_types();
        
        // After deleting the index, we need to recreate the Loupe instances
        $this->init_loupe_instances();

        // Now process each post type and index its content
        foreach ($this->post_types as $post_type) {
            $posts = get_posts([
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);

            $documents = array_map(
                [$this, 'prepare_document'],
                $posts
            );

            if (!empty($documents)) {
                $this->loupe[$post_type]->addDocuments($documents);
            }
        }
    }

	/**
	 * Delete the index.
	 *
	 * @return void
	 */
	private function delete_index() {
        global $wpdb;

        // Clear schema cache
        $this->schema_manager->clear_cache();
        
        // Clear the Loupe instance cache
        WP_Loupe_Factory::clear_instance_cache();

        // Include the base filesystem class from WordPress core if not already included
        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }

        $file_system_direct = new \WP_Filesystem_Direct(false);
        $cache_path = apply_filters('wp_loupe_db_path', WP_CONTENT_DIR . '/wp-loupe-db');

        if ($file_system_direct->is_dir($cache_path)) {
            $file_system_direct->rmdir($cache_path, true);
        }

        $this->loupe = [];
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
	 * Prepare document
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private function prepare_document( \WP_Post $post ): array {
        $schema = $this->schema_manager->get_schema_for_post_type($post->post_type);
        $indexable_fields = $this->schema_manager->get_indexable_fields($schema);
        $saved_fields = get_option('wp_loupe_fields', []);

        $document = ['id' => $post->ID, 'post_type' => $post->post_type];

        foreach ($indexable_fields as $field) {
            $field_name = str_contains($field['field'], '.') 
                ? substr($field['field'], strpos($field['field'], '.') + 1) 
                : $field['field'];

            // Skip if field isn't selected for indexing
            if (isset($saved_fields[$post->post_type][$field_name]) && 
                !$saved_fields[$post->post_type][$field_name]['indexable']) {
                continue;
            }

            if (property_exists($post, $field_name)) {
                $document[$field_name] = apply_filters("wp_loupe_field_{$field_name}", $post->{$field_name});
            } elseif (strpos($field_name, 'taxonomy_') === 0) {
                $taxonomy = substr($field_name, 9);
                $terms = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'names']);
                if (!is_wp_error($terms)) {
                    $document[$field_name] = implode(' ', $terms);
                }
            } else {
                $meta_value = get_post_meta($post->ID, $field_name, true);
                if (!empty($meta_value)) {
                    $document[$field_name] = $meta_value;
                }
            }
        }

        return $document;
    }

}
