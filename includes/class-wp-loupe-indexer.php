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

	public function __construct( $post_types = null ) {
		$this->db             = WP_Loupe_DB::get_instance();
		$this->schema_manager = new WP_Loupe_Schema_Manager();
		$this->iso6391_lang   = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );

		$this->set_post_types( $post_types );
		$this->init_loupe_instances();
		$this->register_hooks();
	}

	/**
	 * Set post types from settings or provided array
	 *
	 * @param array|null $post_types Optional post types array
	 */
	private function set_post_types( $post_types = null ) {
		if ( $post_types === null ) {
			$options          = get_option( 'wp_loupe_custom_post_types', [] );
			$this->post_types = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
				? (array) $options[ 'wp_loupe_post_type_field' ]
				: [ 'post', 'page' ];
		} else {
			$this->post_types = (array) $post_types;
		}
	}

	/**
	 * Initialize Loupe instances for selected post types
	 */
	private function init_loupe_instances() {
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = WP_Loupe_Factory::create_loupe_instance(
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
		// $loupe->deleteDocument( $post_id );
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
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$loupe = $this->loupe[ $post_type ];
		$loupe->deleteDocument( $post_id );
	}

	/**
	 * Delete many posts from loupe index
	 *
	 * @param array $post_ids    Array of post IDs.
	 */
	private function delete_many( array $post_ids ): void {
		$post_type = get_post_type( $post_ids[ 0 ] );
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$loupe = $this->loupe[ $post_type ];
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
			WP_Loupe_Utils::dump( $_POST );
			$this->reindex_all();
			add_settings_error( 'wp-loupe', 'wp-loupe-reindex', __( 'Reindexing completed successfully!', 'wp-loupe' ), 'updated' );

		}
	}

	/**
	 * Reindex all posts
	 *
	 * @return void
	 */
	public function reindex_all() {
		// Ensure required core columns exist (migration for post_date)
		$this->ensure_required_columns();
		// First, clear the search cache
		WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );

		// Save settings before starting the indexing process
		$this->save_settings();

		// Refresh post types from settings
		$this->set_post_types();

		// Clear instance cache to ensure we're using the latest configuration
		WP_Loupe_Factory::clear_instance_cache();

		// Clear schema cache
		$this->schema_manager->clear_cache();

		// Initialize Loupe instances with the latest settings
		$this->init_loupe_instances();

		// Now update each post type's index
		foreach ( $this->post_types as $post_type ) {
			// Get all published posts for this post type
			$posts = get_posts( [
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			] );

			if ( empty( $posts ) ) {
				continue;
			}

			// Extract post IDs for deletion
			$post_ids = array_map( function ( $post ) {
				return $post->ID;
			}, $posts );

			// Delete existing documents for these posts
			if ( ! empty( $post_ids ) ) {
				$this->loupe[ $post_type ]->deleteDocuments( $post_ids );
			}

			// Prepare documents and add them to the index
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
	 * Migration helper: ensure documents table has required columns (post_date).
	 * Older installations may lack the column if it became filterable/sortable later.
	 */
	private function ensure_required_columns(): void {
		foreach ( $this->post_types as $post_type ) {
			$db_dir      = $this->db->get_db_path( $post_type );
			$sqlite_file = trailingslashit( $db_dir ) . 'loupe.db';
			if ( ! file_exists( $sqlite_file ) ) {
				continue; // Fresh index will create schema.
			}
			try {
				$pdo           = new \PDO( 'sqlite:' . $sqlite_file );
				$stmt          = $pdo->query( 'PRAGMA table_info(documents)' );
				$has_post_date = false;
				if ( $stmt ) {
					foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $col ) {
						if ( isset( $col[ 'name' ] ) && 'post_date' === $col[ 'name' ] ) {
							$has_post_date = true;
							break;
						}
					}
				}
				if ( ! $has_post_date ) {
					// Add column similar to Loupe's default (TEXT with default 'null').
					$pdo->exec( "ALTER TABLE documents ADD COLUMN post_date TEXT NOT NULL DEFAULT 'null'" );
					// Create an index to mirror Loupe's performance expectations.
					$pdo->exec( 'CREATE INDEX IF NOT EXISTS documents_post_date_idx ON documents(post_date)' );
				}
			} catch (\Throwable $e) {
				// Silently ignore; worst case a full delete will be required.
			}
		}
	}

	/**
	 * Save settings before reindexing
	 *
	 * @return void
	 */
	private function save_settings() {
		// Ensure the wp_loupe_fields option is properly saved before indexing
		$saved_fields = get_option( 'wp_loupe_fields', [] );

		if ( empty( $saved_fields ) ) {
			// No fields configured - create and save defaults
			$default_fields = $this->create_default_fields_configuration();
			update_option( 'wp_loupe_fields', $default_fields );
		} else {
			// Ensure all post types have configurations
			$updated_fields = $this->ensure_post_types_have_configurations( $saved_fields );

			// Only update if changes were made
			if ( $updated_fields !== $saved_fields ) {
				update_option( 'wp_loupe_fields', $updated_fields );
			}
		}

		// Clear schema cache to ensure new settings are used
		$this->schema_manager->clear_cache();
	}

	/**
	 * Create default fields configuration for all post types
	 *
	 * @return array Default fields configuration
	 */
	private function create_default_fields_configuration() {
		$default_fields = [];

		foreach ( $this->post_types as $post_type ) {
			// Add core fields first
			$default_fields[ $post_type ] = $this->get_default_core_fields();

			// Add taxonomy fields if available
			$default_fields[ $post_type ] = array_merge(
				$default_fields[ $post_type ],
				$this->get_taxonomy_fields_for_post_type( $post_type )
			);
		}

		return $default_fields;
	}

	/**
	 * Get default core fields configuration
	 *
	 * @return array Core fields with default settings
	 */
	private function get_default_core_fields() {
		return [
			'post_title'   => [
				'indexable'      => true,
				'weight'         => 2.0,
				'filterable'     => true,
				'sortable'       => true,
				'sort_direction' => 'desc',
			],
			'post_content' => [
				'indexable'  => true,
				'weight'     => 1.0,
				'filterable' => true,
				'sortable'   => false,
			],
			'post_date'    => [
				'indexable'      => true,
				'weight'         => 1.0,
				'filterable'     => true,
				'sortable'       => true,
				'sort_direction' => 'desc',
			],
		];
	}

	/**
	 * Get taxonomy fields configuration for a post type
	 *
	 * @param string $post_type Post type to get taxonomies for
	 * @return array Taxonomy fields configuration
	 */
	private function get_taxonomy_fields_for_post_type( $post_type ) {
		$taxonomy_fields = [];

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $tax_name => $tax_obj ) {
			if ( $tax_obj->show_ui ) {
				$taxonomy_fields[ 'taxonomy_' . $tax_name ] = [
					'indexable'  => true,
					'weight'     => 1.5,
					'filterable' => true,
					'sortable'   => false,
				];
			}
		}

		return $taxonomy_fields;
	}

	/**
	 * Ensure all post types have field configurations
	 *
	 * @param array $saved_fields Existing field configurations
	 * @return array Updated field configurations
	 */
	private function ensure_post_types_have_configurations( $saved_fields ) {
		$updated = false;

		foreach ( $this->post_types as $post_type ) {
			if ( ! isset( $saved_fields[ $post_type ] ) || empty( $saved_fields[ $post_type ] ) ) {
				$updated = true;

				// Add core fields
				$saved_fields[ $post_type ] = $this->get_default_core_fields();

				// Add taxonomy fields
				$saved_fields[ $post_type ] = array_merge(
					$saved_fields[ $post_type ],
					$this->get_taxonomy_fields_for_post_type( $post_type )
				);
			}
		}

		return $saved_fields;
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
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$file_system_direct = new \WP_Filesystem_Direct( false );
		$cache_path         = apply_filters( 'wp_loupe_db_path', WP_CONTENT_DIR . '/wp-loupe-db' );

		if ( $file_system_direct->is_dir( $cache_path ) ) {
			$file_system_direct->rmdir( $cache_path, true );
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
	public function prepare_document( \WP_Post $post ): array {
		$schema           = $this->schema_manager->get_schema_for_post_type( $post->post_type );
		$indexable_fields = $this->schema_manager->get_indexable_fields( $schema );
		$saved_fields     = get_option( 'wp_loupe_fields', [] );

		$document = [ 'id' => $post->ID, 'post_type' => $post->post_type ];

		// First, process standard fields that we know are indexable
		foreach ( $indexable_fields as $field ) {
			$field_name = str_contains( $field[ 'field' ], '.' )
				? substr( $field[ 'field' ], strpos( $field[ 'field' ], '.' ) + 1 )
				: $field[ 'field' ];

			// Skip if field isn't selected for indexing
			if ( isset( $saved_fields[ $post->post_type ][ $field_name ] ) &&
				! $saved_fields[ $post->post_type ][ $field_name ][ 'indexable' ] ) {
				continue;
			}

			// Get the field value from the appropriate source
			$field_value = null;

			if ( property_exists( $post, $field_name ) ) {
				$field_value = apply_filters( "wp_loupe_field_{$field_name}", $post->{$field_name} );
			} elseif ( strpos( $field_name, 'taxonomy_' ) === 0 ) {
				$taxonomy = substr( $field_name, 9 );
				$terms    = wp_get_post_terms( $post->ID, $taxonomy, [ 'fields' => 'names' ] );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					// For taxonomies, we can store as array of strings
					$field_value = $terms;
				}
			} else {
				$meta_value = get_post_meta( $post->ID, $field_name, true );
				if ( ! empty( $meta_value ) ) {
					$field_value = $meta_value;
				}
			}

			// Validate and sanitize the field value
			if ( $field_value !== null ) {
				$field_value = $this->sanitize_field_value( $field_value );
			}

			// Only add non-null values to the document
			if ( $field_value !== null ) {
				$document[ $field_name ] = $field_value;
			}
		}

		// Now ensure all fields marked as sortable have a value
		if ( isset( $saved_fields[ $post->post_type ] ) ) {
			foreach ( $saved_fields[ $post->post_type ] as $field_name => $settings ) {
				// If this field is marked as sortable but not already in the document
				if ( ! empty( $settings[ 'sortable' ] ) && ! isset( $document[ $field_name ] ) ) {
					$field_value = null;

					// Try to get a value for this field from post meta
					if ( ! property_exists( $post, $field_name ) && strpos( $field_name, 'taxonomy_' ) !== 0 ) {
						$meta_value = get_post_meta( $post->ID, $field_name, true );
						if ( ! empty( $meta_value ) ) {
							$field_value = $this->sanitize_field_value( $meta_value );

							// Only add non-null values to the document
							if ( $field_value !== null ) {
								$document[ $field_name ] = $field_value;
							}
						} else {
							// Add an empty value to ensure the field exists for sorting
							$document[ $field_name ] = "";
						}
					}
				}
			}
		}

		return $document;
	}

	/**
	 * Sanitize field value for Loupe indexing
	 * Loupe supports: number, string, array of strings
	 * Empty values must be set to null
	 * 
	 * @param mixed $value Value to sanitize
	 * @return mixed Sanitized value (null, number, string, array of strings)
	 */
	private function sanitize_field_value( $value ) {
		// Return null for empty values
		if ( $value === null || $value === '' || $value === [] || $value === false ) {
			return null;
		}

		// Handle numbers
		if ( is_numeric( $value ) ) {
			return $value;
		}

		// Handle strings
		if ( is_string( $value ) ) {
			$value = trim( $value );
			return ! empty( $value ) ? $value : null;
		}

		// Handle arrays
		if ( is_array( $value ) ) {
			$sanitized = [];

			foreach ( $value as $item ) {
				// Handle arrays of strings
				if ( is_string( $item ) ) {
					$item = trim( $item );
					if ( ! empty( $item ) ) {
						$sanitized[] = $item;
					}
				}
				// If it's not a string, we don't include it
			}

			return ! empty( $sanitized ) ? $sanitized : null;
		}

		// Convert objects to strings if possible
		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			$string_value = (string) $value;
			return ! empty( $string_value ) ? $string_value : null;
		}

		// For other object types, try to convert to string
		if ( is_object( $value ) ) {
			$string_value = wp_strip_all_tags( strval( $value ) );
			return ! empty( $string_value ) ? $string_value : null;
		}

		// For all other types, return null
		return null;
	}



}
