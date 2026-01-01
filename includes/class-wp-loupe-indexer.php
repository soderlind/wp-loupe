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
	private $last_reindex_rebuilds = [];
	private $register_hooks;
	private const LOUPE_SCHEMA_MISMATCH_SUBSTRINGS = [
		'no such column: _user_id',
		'no such column: _id',
	];

	public function __construct( $post_types = null, bool $register_hooks = true ) {
		$this->db             = WP_Loupe_DB::get_instance();
		$this->schema_manager = new WP_Loupe_Schema_Manager();
		$this->iso6391_lang   = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		$this->register_hooks = $register_hooks;

		$this->set_post_types( $post_types );
		$this->init_loupe_instances();
		if ( $this->register_hooks ) {
			$this->register_hooks();
		}
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
		$this->maybe_migrate_loupe_before_delete( $loupe, $post_id );
		try {
			$loupe->deleteDocument( $post_id );
		} catch (\Throwable $e) {
			if ( $this->is_loupe_schema_mismatch_error( $e ) ) {
				$this->maybe_migrate_loupe_before_delete( $loupe, $post_id );
				try {
					$loupe->deleteDocument( $post_id );
					return;
				} catch (\Throwable $e2) {
					WP_Loupe_Utils::debug_log( '[WP Loupe] deleteDocument failed after migration retry: ' . $e2->getMessage() );
					return;
				}
			}
			throw $e;
		}
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
		$this->maybe_migrate_loupe_before_delete( $loupe, $post_ids[ 0 ] );
		try {
			$loupe->deleteDocuments( $post_ids );
		} catch (\Throwable $e) {
			if ( $this->is_loupe_schema_mismatch_error( $e ) ) {
				$this->maybe_migrate_loupe_before_delete( $loupe, $post_ids[ 0 ] );
				try {
					$loupe->deleteDocuments( $post_ids );
					return;
				} catch (\Throwable $e2) {
					WP_Loupe_Utils::debug_log( '[WP Loupe] deleteDocuments failed after migration retry: ' . $e2->getMessage() );
					return;
				}
			}
			throw $e;
		}
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
			$this->reindex_all();
			$this->maybe_add_reindex_rebuild_notice();
			add_settings_error( 'wp-loupe', 'wp-loupe-reindex', __( 'Reindexing completed successfully!', 'wp-loupe' ), 'updated' );

		}
	}

	/**
	 * Reindex all posts
	 *
	 * @return void
	 */
	public function reindex_all() {
		$this->last_reindex_rebuilds = [];
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

			// If Loupe indicates the on-disk schema/version needs a rebuild, do that first.
			// This avoids calling deleteAllDocuments() against an old schema (e.g. missing _id / _user_id).
			$should_rebuild = false;
			if ( isset( $this->loupe[ $post_type ] ) && method_exists( $this->loupe[ $post_type ], 'needsReindex' ) ) {
				try {
					$should_rebuild = (bool) $this->loupe[ $post_type ]->needsReindex();
				} catch (\Throwable $e) {
					$should_rebuild = false;
				}
			}
			if ( $should_rebuild ) {
				WP_Loupe_Utils::debug_log( '[WP Loupe] Rebuilding index for post type due to Loupe needsReindex(): ' . $post_type );
				$this->record_reindex_rebuild( $post_type, 'needsReindex()' );
				$this->delete_index_for_post_type( $post_type );
				$this->init_loupe_instances();
			}

			// Always clear the index for this post type, even if there are zero published posts.
			// Using deleteAllDocuments on a current schema is fine; for old schemas we rebuild above or in the catch below.
			try {
				$this->loupe[ $post_type ]->deleteAllDocuments();
			} catch (\Throwable $e) {
				if ( $this->is_loupe_schema_mismatch_error( $e ) ) {
					WP_Loupe_Utils::debug_log( '[WP Loupe] deleteAllDocuments schema mismatch during reindex for ' . $post_type . ': ' . $e->getMessage() );
					$this->record_reindex_rebuild( $post_type, 'schema mismatch during deleteAllDocuments()' );
					$this->delete_index_for_post_type( $post_type );
					$this->init_loupe_instances();
				} else {
					throw $e;
				}
			}

			if ( empty( $posts ) ) {
				// Index is now cleared; nothing to add.
				continue;
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
	 * Initialize a batched reindex state.
	 *
	 * This is intended for large sites where a full synchronous rebuild can time out.
	 * The returned state is designed to be serialized into a cursor for REST/CLI.
	 *
	 * @param array|null $post_types Optional post types to reindex. Defaults to plugin setting.
	 * @return array Batched reindex state.
	 */
	public function reindex_batch_init( $post_types = null ): array {
		$this->last_reindex_rebuilds = [];
		// Refresh post types (either from provided list or from settings)
		$this->set_post_types( $post_types );
		// Ensure required core columns exist (migration for post_date)
		$this->ensure_required_columns();
		// Clear the search cache
		WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );
		// Ensure settings are sane (defaults exist)
		$this->save_settings();
		// Clear instance cache to ensure we're using the latest configuration
		WP_Loupe_Factory::clear_instance_cache();
		// Clear schema cache
		$this->schema_manager->clear_cache();
		// Initialize Loupe instances with the latest settings
		$this->init_loupe_instances();

		return [
			'v'            => 1,
			'post_types'   => array_values( $this->post_types ),
			'idx'          => 0,
			'last_id'      => 0,
			'cleared'      => false,
			'processed'    => 0,
			'processed_pt' => 0,
		];
	}

	/**
	 * Process a single batch step.
	 *
	 * Cursor strategy is keyset pagination using last processed post ID.
	 * This avoids large-offset queries for big sites.
	 *
	 * @param array $state Batched reindex state (from reindex_batch_init or prior step).
	 * @param int   $batch_size Number of posts to process per step.
	 * @return array Updated state. Contains a 'done' boolean when complete.
	 */
	public function reindex_batch_step( array $state, int $batch_size = 500 ): array {
		$batch_size   = max( 10, min( 2000, (int) $batch_size ) );
		$post_types   = isset( $state[ 'post_types' ] ) && is_array( $state[ 'post_types' ] ) ? array_values( $state[ 'post_types' ] ) : [];
		$idx          = isset( $state[ 'idx' ] ) ? max( 0, (int) $state[ 'idx' ] ) : 0;
		$last_id      = isset( $state[ 'last_id' ] ) ? max( 0, (int) $state[ 'last_id' ] ) : 0;
		$cleared      = ! empty( $state[ 'cleared' ] );
		$processed    = isset( $state[ 'processed' ] ) ? max( 0, (int) $state[ 'processed' ] ) : 0;
		$processed_pt = isset( $state[ 'processed_pt' ] ) ? max( 0, (int) $state[ 'processed_pt' ] ) : 0;

		if ( empty( $post_types ) ) {
			return [ 'done' => true ] + $state;
		}

		if ( $idx >= count( $post_types ) ) {
			return [ 'done' => true ] + $state;
		}

		// Keep internals in sync with state.
		$this->set_post_types( $post_types );
		// Clear the search cache on each step (cheap and keeps results consistent).
		WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );

		$post_type = (string) $post_types[ $idx ];
		if ( '' === $post_type ) {
			$state[ 'idx' ]          = $idx + 1;
			$state[ 'last_id' ]      = 0;
			$state[ 'cleared' ]      = false;
			$state[ 'processed_pt' ] = 0;
			return $state;
		}

		// Ensure Loupe instance exists.
		if ( ! isset( $this->loupe[ $post_type ] ) ) {
			$this->init_loupe_instances();
		}

		// One-time per post type: clear index (with rebuild protection).
		if ( ! $cleared ) {
			$should_rebuild = false;
			if ( isset( $this->loupe[ $post_type ] ) && method_exists( $this->loupe[ $post_type ], 'needsReindex' ) ) {
				try {
					$should_rebuild = (bool) $this->loupe[ $post_type ]->needsReindex();
				} catch (\Throwable $e) {
					$should_rebuild = false;
				}
			}
			if ( $should_rebuild ) {
				$this->record_reindex_rebuild( $post_type, 'needsReindex()' );
				$this->delete_index_for_post_type( $post_type );
				$this->init_loupe_instances();
			}
			try {
				$this->loupe[ $post_type ]->deleteAllDocuments();
			} catch (\Throwable $e) {
				if ( $this->is_loupe_schema_mismatch_error( $e ) ) {
					$this->record_reindex_rebuild( $post_type, 'schema mismatch during deleteAllDocuments()' );
					$this->delete_index_for_post_type( $post_type );
					$this->init_loupe_instances();
					// Fresh index; safe to proceed.
				} else {
					throw $e;
				}
			}
			$cleared      = true;
			$last_id      = 0;
			$processed_pt = 0;
		}

		$ids = $this->get_published_post_ids_after( $post_type, $last_id, $batch_size );
		if ( empty( $ids ) ) {
			// Done with this post type.
			$idx++;
			$state[ 'idx' ]          = $idx;
			$state[ 'last_id' ]      = 0;
			$state[ 'cleared' ]      = false;
			$state[ 'processed_pt' ] = 0;
			$state[ 'processed' ]    = $processed;
			return ( $idx >= count( $post_types ) ) ? [ 'done' => true ] + $state : $state;
		}

		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'orderby'        => 'post__in',
			'posts_per_page' => count( $ids ),
		] );

		$documents = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$documents[] = $this->prepare_document( $post );
			}
		}

		if ( ! empty( $documents ) ) {
			$this->loupe[ $post_type ]->addDocuments( $documents );
		}

		$processed    += count( $ids );
		$processed_pt += count( $ids );
		$last_id       = max( $ids );

		$state[ 'idx' ]          = $idx;
		$state[ 'last_id' ]      = $last_id;
		$state[ 'cleared' ]      = $cleared;
		$state[ 'processed' ]    = $processed;
		$state[ 'processed_pt' ] = $processed_pt;
		$state[ 'post_types' ]   = $post_types;
		return $state;
	}

	/**
	 * Fetch published post IDs for a post type, using keyset pagination by ID.
	 *
	 * @param string $post_type
	 * @param int    $after_id Only return IDs greater than this.
	 * @param int    $limit
	 * @return int[]
	 */
	private function get_published_post_ids_after( string $post_type, int $after_id, int $limit ): array {
		global $wpdb;
		$limit    = max( 1, (int) $limit );
		$after_id = max( 0, (int) $after_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$post_type,
				$after_id,
				$limit
			)
		);
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_map( 'intval', $ids );
	}

	private function record_reindex_rebuild( string $post_type, string $reason ): void {
		$this->last_reindex_rebuilds[ $post_type ] = $reason;
	}

	private function maybe_add_reindex_rebuild_notice(): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		if ( empty( $this->last_reindex_rebuilds ) ) {
			return;
		}
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		$parts = [];
		foreach ( $this->last_reindex_rebuilds as $post_type => $reason ) {
			$parts[] = sprintf( '%s (%s)', $post_type, $reason );
		}

		$message = sprintf(
			/* translators: %s: list of post types and reasons */
			__( 'WP Loupe debug: index rebuild triggered for %s.', 'wp-loupe' ),
			esc_html( implode( ', ', $parts ) )
		);

		add_settings_error( 'wp-loupe', 'wp-loupe-reindex-rebuild', $message, 'warning' );
	}

	/**
	 * Deletes the on-disk Loupe index for a single post type.
	 *
	 * @param string $post_type
	 */
	private function delete_index_for_post_type( string $post_type ): void {
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
		$path               = $this->db->get_db_path( $post_type );

		if ( $file_system_direct->is_dir( $path ) ) {
			$file_system_direct->rmdir( $path, true );
		}

		unset( $this->loupe[ $post_type ] );
	}

	/**
	 * Loupe 0.13.x introduced internal schema changes (e.g. documents._user_id).
	 * Migrations are triggered during addDocuments(), but deleteDocuments() will fail on old schema.
	 *
	 * When Loupe reports it needs reindex, we trigger migration by indexing the current document (if available)
	 * before attempting deletes.
	 *
	 * @param \Loupe\Loupe\Loupe $loupe
	 * @param int $post_id
	 */
	private function maybe_migrate_loupe_before_delete( $loupe, int $post_id ): void {
		if ( ! is_object( $loupe ) || ! method_exists( $loupe, 'needsReindex' ) ) {
			return;
		}

		try {
			if ( ! $loupe->needsReindex() ) {
				return;
			}
		} catch (\Throwable $e) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			WP_Loupe_Utils::debug_log( '[WP Loupe] Loupe needsReindex but no post available to trigger migration for delete: ' . $post_id );
			return;
		}

		try {
			$loupe->addDocument( $this->prepare_document( $post ) );
		} catch (\Throwable $e) {
			WP_Loupe_Utils::debug_log( '[WP Loupe] Failed triggering Loupe migration before delete: ' . $e->getMessage() );
		}
	}

	private function is_loupe_schema_mismatch_error( \Throwable $e ): bool {
		$message = strtolower( $e->getMessage() );
		foreach ( self::LOUPE_SCHEMA_MISMATCH_SUBSTRINGS as $substr ) {
			if ( false !== strpos( $message, $substr ) ) {
				return true;
			}
		}
		return false;
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
		$cache_path         = $this->db->get_base_path();

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
			// Loupe Geo-point support: { lat: float, lng: float }
			// Accept both lng and lon as input; normalize to lng.
			if ( isset( $value[ 'lat' ] ) && ( isset( $value[ 'lng' ] ) || isset( $value[ 'lon' ] ) ) ) {
				$lat = $value[ 'lat' ];
				$lng = isset( $value[ 'lng' ] ) ? $value[ 'lng' ] : $value[ 'lon' ];
				if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
					return [
						'lat' => (float) $lat,
						'lng' => (float) $lng,
					];
				}
				return null;
			}

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
