<?php
namespace Soderlind\Plugin\WPLoupe;

// Namespaced compatibility shims: allow static analysis / tests without full WP while delegating to global functions when available.
if ( ! function_exists( __NAMESPACE__ . '\register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args ) {
		if ( function_exists( '\register_rest_route' ) ) {
			return \register_rest_route( $namespace, $route, $args );
		}
		return null;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\__' ) ) {
	function __( $text, $domain = null ) {
		if ( function_exists( '\__' ) ) {
			return \__( $text, $domain );
		}
		return $text;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post_id ) {
		return function_exists( '\has_post_thumbnail' ) ? \has_post_thumbnail( $post_id ) : false;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ) {
		return function_exists( '\get_post_thumbnail_id' ) ? \get_post_thumbnail_id( $post_id ) : 0;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) {
		return function_exists( '\wp_get_attachment_image_src' ) ? \wp_get_attachment_image_src( $id, $size ) : false;
	}
}

use Loupe\Loupe\SearchParameters;

/**
 * REST API handler for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_REST {

	private $post_types;
	private $loupe = [];
	/** @var object|null Search service with a search($query) method (engine or test stub). */
	private $search_service = null;
	private $db;
	private $schema_manager;
	private $iso6391_lang;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->db             = WP_Loupe_DB::get_instance();
		$this->schema_manager = new WP_Loupe_Schema_Manager();
		$this->iso6391_lang   = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );

		$this->set_post_types();
		$this->init_loupe_instances();
		// Side-effect free engine for REST usage.
		$this->search_service = new WP_Loupe_Search_Engine( $this->post_types, $this->db );
		$this->register_rest_routes();
	}

	/**
	 * Set post types from settings
	 */
	private function set_post_types() {
		$options          = get_option( 'wp_loupe_custom_post_types', [] );
		$this->post_types = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
			? (array) $options[ 'wp_loupe_post_type_field' ]
			: [ 'post', 'page' ];
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
	 * Register REST API routes
	 */
	private function register_rest_routes() {
		add_action( 'rest_api_init', [ $this, 'on_rest_api_init' ] );
		// Defensive: if this instance is created after rest_api_init already fired (edge cases), register immediately.
		if ( did_action( 'rest_api_init' ) ) {
			$this->log( 'rest_api_init already fired – registering routes immediately.' );
			$this->on_rest_api_init();
		} else {
			$this->log( 'Hooked into rest_api_init for route registration.' );
		}
	}

	/**
	 * Hook callback to actually register routes (avoids anonymous closure for easier introspection/testing).
	 */
	public function on_rest_api_init() {
		$this->log( 'Registering routes.' );
		register_rest_route( 'wp-loupe/v1', '/search', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_search_request' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'q'         => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'post_type' => [
					'default'           => 'all',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'per_page'  => [
					'default'           => 10,
					'sanitize_callback' => 'absint',
				],
				'page'      => [
					'default'           => 1,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Dynamic field discovery for settings UI.
		register_rest_route( 'wp-loupe/v1', '/post-type-fields/(?P<post_type>[a-zA-Z0-9_-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_post_type_fields_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Create database (directory + option update) for a post type.
		register_rest_route( 'wp-loupe/v1', '/create-database', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_create_database_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Delete database for a post type.
		register_rest_route( 'wp-loupe/v1', '/delete-database', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_delete_database_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
		$this->log( 'Routes registered.' );
	}

	/**
	 * Return available fields for a post type (schema + meta keys) with labels.
	 * Used by admin.js for dynamic Field Settings table.
	 *
	 * @param mixed $request Request object implementing get_param().
	 */
	public function handle_post_type_fields_request( $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'wp_loupe_invalid_post_type', __( 'Invalid post type.', 'wp-loupe' ), [ 'status' => 404 ] );
		}

		// Start with core WordPress fields that should always be available
		$fields = [];
		foreach ( array_keys( $this->get_core_fields() ) as $core_field ) {
			$fields[ $core_field ] = [ 'label' => $this->prettify_field_label( $core_field ) ];
		}

		// Add schema-based fields (saved indexable configuration)
		$schema_manager = WP_Loupe_Schema_Manager::get_instance();
		$schema         = $schema_manager->get_schema_for_post_type( $post_type );
		foreach ( $schema as $field_key => $_settings ) {
			if ( ! isset( $fields[ $field_key ] ) ) {
				$fields[ $field_key ] = [ 'label' => $this->prettify_field_label( $field_key ) ];
			}
		}

		// Add discovered meta keys (non-protected, existing values)
		$meta_keys = $this->get_post_type_meta_keys_with_values( $post_type );
		foreach ( array_keys( $meta_keys ) as $meta_key ) {
			if ( ! isset( $fields[ $meta_key ] ) ) {
				$fields[ $meta_key ] = [ 'label' => $this->prettify_field_label( $meta_key ) ];
			}
		}

		return rest_ensure_response( $fields );
	}

	/**
	 * Simple label prettifier (shared logic local copy).
	 */
	private function prettify_field_label( $key ) {
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Get core WordPress fields that should always be available
	 * 
	 * @return array Associative array of field_key => true
	 */
	private function get_core_fields() {
		return [
			'post_title'    => true,
			'post_content'  => true,
			'post_excerpt'  => true,
			'post_date'     => true,
			'post_modified' => true,
			'post_author'   => true,
			'permalink'     => true,
		];
	}

	/**
	 * Create database (ensure directory, update post types option) for a post type.
	 *
	 * @param mixed $request REST request implementing get_param().
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create_database_request( $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'wp_loupe_invalid_post_type', __( 'Invalid post type.', 'wp-loupe' ), [ 'status' => 404 ] );
		}

		// Update option list if not already present.
		$options = get_option( 'wp_loupe_custom_post_types', [] );
		if ( ! isset( $options[ 'wp_loupe_post_type_field' ] ) || ! is_array( $options[ 'wp_loupe_post_type_field' ] ) ) {
			$options[ 'wp_loupe_post_type_field' ] = [];
		}
		if ( ! in_array( $post_type, $options[ 'wp_loupe_post_type_field' ], true ) ) {
			$options[ 'wp_loupe_post_type_field' ][] = $post_type;
			update_option( 'wp_loupe_custom_post_types', $options );
		}

		// Ensure directory exists.
		$db      = WP_Loupe_DB::get_instance();
		$dir     = $db->get_db_path( $post_type );
		$base    = $db->get_base_path();
		$created = false;
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		if ( ! file_exists( $dir ) ) {
			$created = wp_mkdir_p( $dir );
		}

		// Clear factory & schema caches so subsequent indexing uses updated post type list.
		WP_Loupe_Factory::clear_instance_cache();
		WP_Loupe_Schema_Manager::get_instance()->clear_cache();

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf( __( 'Database structure ready for %s.', 'wp-loupe' ), $post_type ),
			'created' => $created,
		] );
	}

	/**
	 * Delete database (directory removal and option update) for a post type.
	 *
	 * @param mixed $request REST request implementing get_param().
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_database_request( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$options   = get_option( 'wp_loupe_custom_post_types', [] );

		if ( isset( $options[ 'wp_loupe_post_type_field' ] ) && is_array( $options[ 'wp_loupe_post_type_field' ] ) ) {
			$options[ 'wp_loupe_post_type_field' ] = array_values( array_filter( $options[ 'wp_loupe_post_type_field' ], function ( $pt ) use ( $post_type ) {
				return $pt !== $post_type;
			} ) );
			update_option( 'wp_loupe_custom_post_types', $options );
		}

		$db      = WP_Loupe_DB::get_instance();
		$dir     = $db->get_db_path( $post_type );
		$deleted = false;
		if ( file_exists( $dir ) && is_dir( $dir ) ) {
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$fs      = new \WP_Filesystem_Direct( false );
			$deleted = $fs->rmdir( $dir, true );
		}

		WP_Loupe_Factory::clear_instance_cache();
		WP_Loupe_Schema_Manager::get_instance()->clear_cache();

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf( __( 'Database removed for %s.', 'wp-loupe' ), $post_type ),
			'deleted' => $deleted,
		] );
	}

	/**
	 * Discover meta keys with non-empty values for the post type.
	 */
	private function get_post_type_meta_keys_with_values( $post_type ) {
		global $wpdb;
		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}
		$sql  = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key NOT LIKE '\_%'
               AND pm.meta_value <> ''
             LIMIT 500",
			$post_type
		);
		$keys = $wpdb->get_col( $sql );
		$out  = [];
		if ( is_array( $keys ) ) {
			foreach ( $keys as $k ) {
				if ( is_string( $k ) && strlen( $k ) < 128 ) {
					$out[ $k ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Handle search REST request
	 *
	 * @param mixed $request Request object implementing get_param().
	 * @return array Response array (later wrapped by rest_ensure_response).
	 */
	public function handle_search_request( $request ) {
		$query     = $request->get_param( 'q' );
		$post_type = $request->get_param( 'post_type' );
		$per_page  = $request->get_param( 'per_page' );
		$page      = $request->get_param( 'page' );

		// Generate a cache key based on the search parameters unless a custom injected search service (tests) is detected.
		$cache_key      = 'wp_loupe_search_' . md5( $query . $post_type . $per_page . $page );
		$use_cache      = is_object( $this->search_service ) && ( $this->search_service instanceof WP_Loupe_Search_Engine );
		$cached_results = $use_cache ? get_transient( $cache_key ) : false;
		if ( $use_cache && false !== $cached_results ) {
			return rest_ensure_response( $cached_results );
		}

		$search_results = $this->perform_search( $query, $post_type, $per_page, $page );

		// Cache the results for 12 hours if using the real search service.
		if ( $use_cache ) {
			set_transient( $cache_key, $search_results, 12 * HOUR_IN_SECONDS );
		}

		return rest_ensure_response( $search_results );
	}

	/**
	 * Perform search against Loupe index
	 *
	 * @param string $query Search query
	 * @param string $post_type Post type to search in or 'all'
	 * @param int $per_page Items per page
	 * @param int $page Current page number
	 * @return array Search results
	 */
	private function perform_search( $query, $post_type, $per_page, $page ) {
		// Determine scope.
		$post_types_to_search = ( 'all' === $post_type ) ? $this->post_types : [ $post_type ];

		// Instantiate a narrowed search service if filtering to a single type (saves internal loops).
		$service = ( count( $post_types_to_search ) === count( $this->post_types ) )
			? $this->search_service
			: new WP_Loupe_Search_Engine( $post_types_to_search, $this->db );

		$raw_hits = $service->search( $query ); // Returns combined hit arrays w/ post_type & id.

		// Safety filter: ensure only hits from requested post types (important when test stubs or custom services return broader sets).
		$allowed  = array_flip( $post_types_to_search );
		$raw_hits = array_values( array_filter( $raw_hits, function ( $hit ) use ( $allowed ) {
			return isset( $hit[ 'post_type' ] ) && isset( $allowed[ $hit[ 'post_type' ] ] );
		} ) );

		// Enrich hits into formatted structure (mirrors prior shape) before slicing.
		$enriched = [];
		foreach ( $raw_hits as $hit ) {
			if ( empty( $hit[ 'id' ] ) || empty( $hit[ 'post_type' ] ) ) {
				continue;
			}
			$post_id  = (int) $hit[ 'id' ];
			$ptype    = $hit[ 'post_type' ];
			$post_obj = get_post( $post_id );
			if ( ! $post_obj || 'publish' !== $post_obj->post_status ) {
				continue;
			}
			$ptype_obj  = get_post_type_object( $ptype );
			$enriched[] = [
				'id'              => $post_id,
				'title'           => get_the_title( $post_id ),
				'url'             => get_permalink( $post_id ),
				'post_type'       => $ptype,
				'post_type_label' => $ptype_obj && isset( $ptype_obj->labels->singular_name ) ? $ptype_obj->labels->singular_name : $ptype,
				'excerpt'         => get_the_excerpt( $post_id ),
				'_score'          => $hit[ '_score' ] ?? 0,
			];
		}

		// Relevance sort & paginate.
		usort( $enriched, function ( $a, $b ) {
			return $b[ '_score' ] <=> $a[ '_score' ];
		} );
		$total_hits = count( $enriched );
		$offset     = max( 0, ( $page - 1 ) * $per_page );
		$paged_hits = array_slice( $enriched, $offset, $per_page );

		return [
			'hits'       => $paged_hits,
			'pagination' => [
				'total'        => $total_hits,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => ( $per_page > 0 ) ? (int) ceil( $total_hits / $per_page ) : 0,
			],
		];
	}

	/**
	 * Format search results into a standardized structure
	 *
	 * @param array $search_result Raw search result from Loupe
	 * @param string $post_type Current post type
	 * @return array Formatted search results
	 */
	private function format_search_results( $search_result, $post_type ) {
		$formatted = [
			'hits' => [],
			'took' => $search_result[ 'processingTimeMs' ] ?? 0,
		];

		foreach ( $search_result[ 'hits' ] as $hit ) {
			$post_id = $hit[ 'id' ];
			$post    = get_post( $post_id );

			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$formatted_hit = [
				'id'              => $post_id,
				'title'           => get_the_title( $post_id ),
				'url'             => get_permalink( $post_id ),
				'post_type'       => $post_type,
				'post_type_label' => get_post_type_object( $post_type )->labels->singular_name,
				'excerpt'         => get_the_excerpt( $post_id ),
				'_score'          => $hit[ '_score' ] ?? 0,
			];

			// Add featured image if available
			if ( has_post_thumbnail( $post_id ) ) {
				$featured_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'thumbnail' );
				if ( is_array( $featured_image ) && isset( $featured_image[ 0 ] ) ) {
					$formatted_hit[ 'featured_image' ] = $featured_image[ 0 ];
				}
			}

			$formatted[ 'hits' ][] = $formatted_hit;
		}

		return $formatted; // Legacy method retained for backward compatibility (may be removed in future once REST uses unified enrichment exclusively).
	}

	/**
	 * Internal logger helper (only logs when WP_DEBUG enabled).
	 *
	 * @param string $message
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WP_Loupe_REST] ' . $message );
		}
	}
}
