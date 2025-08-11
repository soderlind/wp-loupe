<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Settings page.
 *
 * @package  soderlind\plugin\WPLoupe
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Settings page.
 * 
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WPLoupe_Settings_Page {

	/**
	 * Custom post types.
	 *
	 * @var array
	 */
	private $cpt = [];

	/**
	 * WPLoupe_Settings_Page constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'wp_loupe_create_settings' ] );
		add_action( 'admin_init', [ $this, 'wp_loupe_setup_sections' ] );
		add_action( 'admin_init', [ $this, 'wp_loupe_setup_fields' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'load-settings_page_wp-loupe', [ $this, 'add_help_tabs' ] );
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes() {
		register_rest_route( 'wp-loupe/v1', '/post-type-fields/(?P<post_type>[a-zA-Z0-9_-]+)', [ 
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_post_type_fields' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			}
		] );

		// Add new endpoints for database management
		register_rest_route( 'wp-loupe/v1', '/create-database', [ 
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_database_for_post_type' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [ 
				'post_type' => [ 
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'wp-loupe/v1', '/delete-database', [ 
			'methods'             => 'POST',
			'callback'            => [ $this, 'delete_database_for_post_type' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [ 
				'post_type' => [ 
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'wp-loupe/v1', '/update-database', [ 
			'methods'             => 'POST',
			'callback'            => [ $this, 'update_database_for_post_type' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [ 
				'post_type' => [ 
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Create database for a post type via REST API
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function create_database_for_post_type( $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', 'Invalid post type', [ 'status' => 404 ] );
		}

		try {
			// Get DB instance
			$db           = WP_Loupe_DB::get_instance();
			$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );

			// Create Loupe instance - this creates the database structure but doesn't add documents
			$loupe = WP_Loupe_Factory::create_loupe_instance( $post_type, $iso6391_lang, $db );

			// Force update the post type settings to include this post type
			$this->force_update_post_type_settings( $post_type, true );

			return rest_ensure_response( [ 
				'success' => true,
				'message' => sprintf( __( 'Created database structure for post type: %s', 'wp-loupe' ), $post_type ),
				'count'   => 0 // No documents indexed
			] );
		} catch (\Exception $e) {
			return new \WP_Error( 'database_creation_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Delete database for a post type via REST API
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function delete_database_for_post_type( $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', 'Invalid post type', [ 'status' => 404 ] );
		}

		try {
			// Get the database path for the post type
			$db      = WP_Loupe_DB::get_instance();
			$db_path = $db->get_db_path( $post_type );

			// Clear factory cache for this post type
			WP_Loupe_Factory::clear_instance_cache( $post_type );

			// Force update post type settings to remove this post type
			$this->force_update_post_type_settings( $post_type, false );

			// Delete the database directory if it exists
			if ( file_exists( $db_path ) ) {
				// Include the filesystem class if needed
				if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
					require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				}

				$file_system_direct = new \WP_Filesystem_Direct( false );

				if ( $file_system_direct->is_dir( $db_path ) ) {
					// Attempt to delete the directory forcefully
					$success = $file_system_direct->rmdir( $db_path, true );

					// If failed, try direct PHP functions as a fallback
					if ( ! $success ) {
						WP_Loupe_Utils::dump( "Filesystem deletion failed, trying PHP functions" );
						$this->delete_directory_recursive( $db_path );
					}
				}
			}

			// Clear cache for search results regardless of whether directory existed
			WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );

			// Also remove this post type from the fields configuration
			$saved_fields = get_option( 'wp_loupe_fields', [] );
			if ( isset( $saved_fields[ $post_type ] ) ) {
				unset( $saved_fields[ $post_type ] );
				update_option( 'wp_loupe_fields', $saved_fields );
			}

			// Clear schema cache after removing fields
			$schema_manager = new WP_Loupe_Schema_Manager();
			$schema_manager->clear_cache();

			return rest_ensure_response( [ 
				'success' => true,
				'message' => sprintf( __( 'Deleted database for post type: %s', 'wp-loupe' ), $post_type ),
			] );
		} catch (\Exception $e) {
			return new \WP_Error( 'database_deletion_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Force update the post type settings
	 * 
	 * @param string $post_type The post type to update
	 * @param bool $include Whether to include or exclude the post type
	 */
	private function force_update_post_type_settings( $post_type, $include = true ) {
		$options             = get_option( 'wp_loupe_custom_post_types', [] );
		$selected_post_types = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
			? (array) $options[ 'wp_loupe_post_type_field' ]
			: [ 'post', 'page' ];

		if ( $include && ! in_array( $post_type, $selected_post_types ) ) {
			// Add the post type to selected types
			$selected_post_types[] = $post_type;
		} else if ( ! $include ) {
			// Remove the post type from selected types
			$selected_post_types = array_diff( $selected_post_types, [ $post_type ] );
		}

		// Update the option
		$options[ 'wp_loupe_post_type_field' ] = $selected_post_types;
		update_option( 'wp_loupe_custom_post_types', $options );

		return true;
	}

	/**
	 * Delete directory recursively using PHP functions
	 * Fallback when WP_Filesystem_Direct fails
	 * 
	 * @param string $dir Directory path
	 * @return bool Success
	 */
	private function delete_directory_recursive( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return true;
		}

		if ( ! is_dir( $dir ) ) {
			return unlink( $dir );
		}

		$files = scandir( $dir );
		foreach ( $files as $file ) {
			if ( $file !== '.' && $file !== '..' ) {
				$path = $dir . '/' . $file;

				if ( is_dir( $path ) ) {
					$this->delete_directory_recursive( $path );
				} else {
					unlink( $path );
				}
			}
		}

		return rmdir( $dir );
	}

	/**
	 * Update database for a post type via REST API
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function update_database_for_post_type( $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', 'Invalid post type', [ 'status' => 404 ] );
		}

		try {
			// Get DB instance
			$db           = WP_Loupe_DB::get_instance();
			$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );

			// Clear schema cache to ensure new settings are used
			$schema_manager = new WP_Loupe_Schema_Manager();
			$schema_manager->clear_cache();

			// Clear factory cache for this post type 
			WP_Loupe_Factory::clear_instance_cache( $post_type );

			// Create new Loupe instance with updated configuration
			$loupe = WP_Loupe_Factory::create_loupe_instance( $post_type, $iso6391_lang, $db );

			// Get posts of selected type
			$posts = get_posts( [ 
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			] );

			// Create indexer to prepare documents
			$indexer = new WP_Loupe_Indexer( [ $post_type ] );

			// Map posts to documents and update the index
			$documents = array_map(
				[ $indexer, 'prepare_document' ],
				$posts
			);

			// Clear existing documents first
			$post_ids = array_map( function ($post) {
				return $post->ID;
			}, $posts );

			if ( ! empty( $post_ids ) ) {
				$loupe->deleteDocuments( $post_ids );
			}

			// Add documents to the index
			if ( ! empty( $documents ) ) {
				$loupe->addDocuments( $documents );
			}

			// Clear cache for search results
			WP_Loupe_Utils::remove_transient( 'wp_loupe_search_' );

			return rest_ensure_response( [ 
				'success' => true,
				'message' => sprintf( __( 'Updated database for post type: %s', 'wp-loupe' ), $post_type ),
				'count'   => count( $documents ),
			] );
		} catch (\Exception $e) {
			return new \WP_Error( 'database_update_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Get fields for a post type via REST API
	 * 
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_post_type_fields( $request ) {
		$post_type        = $request->get_param( 'post_type' );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object ) {
			return new \WP_Error( 'invalid_post_type', 'Invalid post type', [ 'status' => 404 ] );
		}

		$fields = $this->get_available_fields( $post_type );
		return rest_ensure_response( $fields );
	}

	/**
	 * Get available fields for a post type
	 * 
	 * @param string $post_type
	 * @return array
	 */
	public function get_available_fields( $post_type ) {
		// Always include core fields
		$fields = [ 
			'post_title'   => __( 'Title', 'wp-loupe' ),
			'post_content' => __( 'Content', 'wp-loupe' ),
			'post_excerpt' => __( 'Excerpt', 'wp-loupe' ),
			'post_date'    => __( 'Date', 'wp-loupe' ),
			'post_author'  => __( 'Author', 'wp-loupe' ),
		];

		// Add taxonomy fields
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $tax_name => $tax_object ) {
			if ( $tax_object->show_ui ) {
				$fields[ 'taxonomy_' . $tax_name ] = $tax_object->label;
			}
		}

		// Get custom fields with values
		$meta_keys = $this->get_post_type_meta_keys_with_values( $post_type );
		foreach ( $meta_keys as $meta_key => $has_value ) {
			$registered_meta = get_registered_meta_keys( 'post', $post_type );
			$meta_label      = isset( $registered_meta[ $meta_key ][ 'description' ] ) ?
				$registered_meta[ $meta_key ][ 'description' ] :
				$this->prettify_meta_key( $meta_key );

			$fields[ $meta_key ] = [ 
				'label'    => $meta_label,
				'hasValue' => $has_value,
			];
		}

		return apply_filters( 'wp_loupe_available_fields', $fields, $post_type );
	}

	/**
	 * Get meta keys with values for a post type
	 * 
	 * @param string $post_type
	 * @return array
	 */
	private function get_post_type_meta_keys_with_values( $post_type ) {
		$meta_keys = $this->get_filtered_meta_keys( $post_type );
		return array_fill_keys( $meta_keys, true );
	}

	/**
	 * Get filtered meta keys for a post type, removing system and protected meta
	 *
	 * @param string $post_type Post type to get meta keys for
	 * @return array Filtered meta keys
	 */
	private function get_filtered_meta_keys( $post_type ) {
		global $wpdb;
		static $cache = array();

		// Check cache first
		$cache_key = 'wp_loupe_meta_keys_' . $post_type;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		// Get all meta keys in a single query
		$query = $wpdb->prepare(
			"SELECT DISTINCT meta_key 
            FROM $wpdb->postmeta pm 
            JOIN $wpdb->posts p ON p.ID = pm.post_id 
            WHERE p.post_type = %s 
            AND pm.meta_key NOT LIKE '\_%'",
			$post_type
		);

		$meta_keys = $wpdb->get_col( $query );

		// Remove system meta keys and make unique
		$filtered_keys = array_unique( array_filter( $meta_keys, function ($key) {
			return ! is_protected_meta( $key, 'post' )
				&& ! preg_match( '/^_oembed|^_wp/', $key )
				&& ! in_array( $key, [ 
					'_edit_last',
					'_edit_lock',
					'_thumbnail_id',
					'_wp_old_slug',
					'_wp_page_template',
				] );
		} ) );

		sort( $filtered_keys );
		$cache[ $cache_key ] = $filtered_keys;

		return $filtered_keys;
	}

	/**
	 * Convert meta key to readable label
	 * 
	 * @param string $key
	 * @return string
	 */
	private function prettify_meta_key( $key ) {
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Create the settings page.
	 *
	 * @return void
	 */
	public function wp_loupe_create_settings() {
		add_options_page( 'WP Loupe', 'WP Loupe', 'manage_options', 'wp-loupe', [ $this, 'plugin_settings_page_content' ] );
	}

	/**
	 * Setup the settings sections.
	 *
	 * @return void
	 */
	public function wp_loupe_setup_sections() {
		// General tab sections
		add_settings_section( 'wp_loupe_section', 'WP Loupe Settings', [ $this, 'general_section_callback' ], 'wp-loupe' );
		add_settings_section( 'wp_loupe_fields_section', 'Field Settings', [ $this, 'fields_section_callback' ], 'wp-loupe' );

		// Advanced tab sections
		add_settings_section( 'wp_loupe_tokenization_section', __( 'Tokenization', 'wp-loupe' ),
			[ $this, 'tokenization_section_callback' ], 'wp-loupe-advanced' );
		add_settings_section( 'wp_loupe_prefix_section', __( 'Prefix Search', 'wp-loupe' ),
			[ $this, 'prefix_section_callback' ], 'wp-loupe-advanced' );
		add_settings_section( 'wp_loupe_typo_section', __( 'Typo Tolerance', 'wp-loupe' ),
			[ $this, 'typo_section_callback' ], 'wp-loupe-advanced' );
	}

	/**
	 * General settings section description
	 */
	public function general_section_callback() {
		echo '<p>' . __( 'Select which post types and fields to include in the search index.', 'wp-loupe' ) . '</p>';
	}

	/**
	 * Tokenization section description
	 */
	public function tokenization_section_callback() {
		echo '<p>' . __( 'Configure how search terms are tokenized.', 'wp-loupe' ) . '</p>';
	}

	/**
	 * Prefix search section description
	 */
	public function prefix_section_callback() {
		echo '<p>' . __( 'Configure prefix search behavior. Prefix search allows finding terms by typing only the beginning (e.g., "huck" finds "huckleberry"). Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. my friend huck would find documents containing huckleberry - huck is my friend, however, would not.', 'wp-loupe' ) . '</p>';
	}

	/**
	 * Typo tolerance section description
	 */
	public function typo_section_callback() {
		echo '<p>' . __( 'Configure typo tolerance for search queries. Typo tolerance allows finding results even when users make typing mistakes.', 'wp-loupe' ) . '</p>';
		echo '<p><small>' . sprintf(
			__( 'Based on the algorithm from "Efficient Similarity Search in Very Large String Sets" %s.', 'wp-loupe' ),
			'<a href="https://hpi.de/fileadmin/user_upload/fachgebiete/naumann/publications/PDFs/2012_ICDE_p1586-fenz.pdf" target="_blank">' . __( '(read the paper)', 'wp-loupe' ) . '</a>'
		) . '</small></p>';
	}

	/**
	 * Fields configuration section description
	 */
	public function fields_section_callback() {
		echo '<div id="wp-loupe-fields-config"></div>';
	}

	/**
	 * Setup the settings fields.
	 *
	 * @return void
	 */
	public function wp_loupe_setup_fields() {
		register_setting( 'wp-loupe', 'wp_loupe_custom_post_types' );

		$this->cpt = array_diff( get_post_types(
			[ 
				'public' => true,
			],
			'names',
			'and'
		), [ 'attachment' ] );

		add_settings_field(
			'wp_loupe_post_type_field',
			__( 'Select Post Types', 'wp-loupe' ),
			[ $this, 'wp_loupe_post_type_field_callback' ],
			'wp-loupe',
			'wp_loupe_section'
		);

		// Tokenization settings
		add_settings_field(
			'wp_loupe_max_query_tokens',
			__( 'Max Query Tokens', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[max_query_tokens]',
				'value'       => $this->get_advanced_option( 'max_query_tokens', 12 ),
				'description' => __( 'Maximum number of tokens in a search query.', 'wp-loupe' ),
			]
		);

		// Prefix search settings
		add_settings_field(
			'wp_loupe_min_prefix_length',
			__( 'Minimum Prefix Length', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[min_prefix_length]',
				'value'       => $this->get_advanced_option( 'min_prefix_length', 3 ),
				'description' => __( 'Minimum number of characters before prefix search is enabled.', 'wp-loupe' ),
			]
		);

		// Typo tolerance settings
		add_settings_field(
			'wp_loupe_typo_enabled',
			__( 'Enable Typo Tolerance', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[typo_enabled]',
				'value'       => $this->get_advanced_option( 'typo_enabled', true ),
				'description' => __( 'Enable or disable typo tolerance in search.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_alphabet_size',
			__( 'Alphabet Size', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[alphabet_size]',
				'value'       => $this->get_advanced_option( 'alphabet_size', 4 ),
				'description' => __( 'Size of the alphabet for typo tolerance (default: 4).', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_index_length',
			__( 'Index Length', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[index_length]',
				'value'       => $this->get_advanced_option( 'index_length', 14 ),
				'description' => __( 'Length of the index for typo tolerance (default: 14).', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_typo_prefix_search',
			__( 'Typo Tolerance for Prefix Search', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[typo_prefix_search]',
				'value'       => $this->get_advanced_option( 'typo_prefix_search', false ),
				'description' => __( 'Enable typo tolerance in prefix search (may impact performance).', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_first_char_typo_double',
			__( 'Double Count First Character Typo', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe',
			'wp_loupe_advanced_section',
			[ 
				'name'        => 'wp_loupe_advanced[first_char_typo_double]',
				'value'       => $this->get_advanced_option( 'first_char_typo_double', true ),
				'description' => __( 'Count a typo at the beginning of a word as two mistakes.', 'wp-loupe' ),
			]
		);
	}

	/**
	 * Get advanced option with default
	 */
	private function get_advanced_option( $key, $default ) {
		$options = get_option( 'wp_loupe_advanced', [] );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Callback for number input fields
	 */
	public function number_field_callback( $args ) {
		printf(
			'<input type="number" name="%s" value="%s" class="regular-text">
			<p class="description">%s</p>',
			esc_attr( $args[ 'name' ] ),
			esc_attr( $args[ 'value' ] ),
			esc_html( $args[ 'description' ] )
		);
	}

	/**
	 * Callback for checkbox fields
	 */
	public function checkbox_field_callback( $args ) {
		printf(
			'<input type="checkbox" name="%s" %s>
			<p class="description">%s</p>',
			esc_attr( $args[ 'name' ] ),
			checked( $args[ 'value' ], true, false ),
			esc_html( $args[ 'description' ] )
		);
	}

	/**
	 * Sanitize advanced settings
	 */
	public function sanitize_advanced_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];

		// Sanitize numeric fields
		$numeric_fields = [ 'max_query_tokens', 'min_prefix_length', 'alphabet_size', 'index_length' ];
		foreach ( $numeric_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = absint( $input[ $field ] );
			}
		}

		// Sanitize boolean fields
		$boolean_fields = [ 'typo_enabled', 'typo_prefix_search', 'first_char_typo_double' ];
		foreach ( $boolean_fields as $field ) {
			$sanitized[ $field ] = ! empty( $input[ $field ] );
		}

		return $sanitized;
	}

	/**
	 * Callback for the post type field.
	 *
	 * @return void
	 */
	public function wp_loupe_post_type_field_callback() {
		$options      = get_option( 'wp_loupe_custom_post_types', [] );
		$selected_ids = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
			? (array) $options[ 'wp_loupe_post_type_field' ]
			: [ 'post', 'page' ]; // Default selection

		echo '<select id="wp_loupe_custom_post_types" name="wp_loupe_custom_post_types[wp_loupe_post_type_field][]" multiple>';
		foreach ( $this->cpt as $post_type ) {
			echo sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $post_type ),
				selected( in_array( $post_type, $selected_ids, true ), true, false ),
				esc_html( $post_type )
			);
		}
		echo '</select>';
	}

	/**
	 * Settings page content.
	 *
	 * @return void
	 */
	public function plugin_settings_page_content() {
		// Check if user is allowed access.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = isset( $_GET[ 'tab' ] ) ? sanitize_key( $_GET[ 'tab' ] ) : 'general';
		?>
		<div class="wrap">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>

			<nav class="nav-tab-wrapper">
				<a href="?page=wp-loupe" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
					<?php _e( 'General', 'wp-loupe' ); ?>
				</a>
				<a href="?page=wp-loupe&tab=advanced"
					class="nav-tab <?php echo $current_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
					<?php _e( 'Advanced', 'wp-loupe' ); ?>
				</a>
			</nav>

			<form action="options.php" method="POST">
				<?php
				wp_nonce_field( 'wp_loupe_nonce_action', 'wp_loupe_nonce_field' );

				if ( $current_tab === 'advanced' ) {
					settings_fields( 'wp-loupe-advanced' );
					do_settings_sections( 'wp-loupe-advanced' );
				} else {
					echo '<input type="hidden" name="wp_loupe_reindex" id="wp_loupe_reindex" value="on">';
					settings_fields( 'wp-loupe' );
					do_settings_sections( 'wp-loupe' );
				}

				submit_button( $current_tab === 'general' ? __( 'Reindex', 'wp-loupe' ) : __( 'Save Settings', 'wp-loupe' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register all settings
	 */
	public function register_settings() {
		// General tab settings
		register_setting( 'wp-loupe', 'wp_loupe_custom_post_types' );
		register_setting( 'wp-loupe', 'wp_loupe_fields', [ 
			'type'              => 'array',
			'description'       => 'Field configuration for each post type',
			'sanitize_callback' => [ $this, 'sanitize_fields_settings' ],
		] );

		// Advanced tab settings
		register_setting( 'wp-loupe-advanced', 'wp_loupe_advanced', [ 
			'type'              => 'array',
			'description'       => 'Advanced search configuration options',
			'sanitize_callback' => [ $this, 'sanitize_advanced_settings' ],
		] );

		// Setup fields
		$this->wp_loupe_setup_general_fields();
		$this->wp_loupe_setup_advanced_fields();
	}

	/**
	 * Setup basic fields (moved from wp_loupe_setup_fields)
	 */
	public function wp_loupe_setup_general_fields() {
		$this->cpt = array_diff( get_post_types(
			[ 
				'public' => true,
			],
			'names',
			'and'
		), [ 'attachment' ] );

		add_settings_field(
			'wp_loupe_post_type_field',
			__( 'Select Post Types', 'wp-loupe' ),
			[ $this, 'wp_loupe_post_type_field_callback' ],
			'wp-loupe',
			'wp_loupe_section'
		);
	}

	/**
	 * Setup advanced fields
	 */
	public function wp_loupe_setup_advanced_fields() {
		// Tokenization section fields
		add_settings_field(
			'wp_loupe_max_query_tokens',
			__( 'Max Query Tokens', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_tokenization_section',
			[ 
				'name'        => 'wp_loupe_advanced[max_query_tokens]',
				'value'       => $this->get_advanced_option( 'max_query_tokens', 12 ),
				'min'         => 1,
				'max'         => 50,
				'description' => __( 'Maximum number of tokens in a search query (default: 12).', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_languages',
			__( 'Languages', 'wp-loupe' ),
			[ $this, 'languages_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_tokenization_section',
			[ 
				'name'        => 'wp_loupe_advanced[languages]',
				'value'       => $this->get_advanced_option( 'languages', [ 'en' ] ),
				'description' => __( 'Select languages for tokenization. Uses site language by default.', 'wp-loupe' ),
			]
		);

		// Prefix search section fields
		add_settings_field(
			'wp_loupe_min_prefix_length',
			__( 'Minimum Prefix Length', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_prefix_section',
			[ 
				'name'        => 'wp_loupe_advanced[min_prefix_length]',
				'value'       => $this->get_advanced_option( 'min_prefix_length', 3 ),
				'min'         => 1,
				'max'         => 10,
				'description' => __( 'Minimum number of characters before prefix search is enabled (default: 3).', 'wp-loupe' ),
			]
		);

		// Typo tolerance section fields
		add_settings_field(
			'wp_loupe_typo_enabled',
			__( 'Enable Typo Tolerance', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[ 
				'name'        => 'wp_loupe_advanced[typo_enabled]',
				'value'       => $this->get_advanced_option( 'typo_enabled', true ),
				'description' => __( 'Allow search to find results with typos.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_alphabet_size',
			__( 'Alphabet Size', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[ 
				'name'        => 'wp_loupe_advanced[alphabet_size]',
				'value'       => $this->get_advanced_option( 'alphabet_size', 4 ),
				'min'         => 1,
				'max'         => 10,
				'description' => __( 'Size of the alphabet for typo tolerance (default: 4). Higher values reduce false positives but increase index size.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_index_length',
			__( 'Index Length', 'wp-loupe' ),
			[ $this, 'number_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[ 
				'name'        => 'wp_loupe_advanced[index_length]',
				'value'       => $this->get_advanced_option( 'index_length', 14 ),
				'min'         => 5,
				'max'         => 25,
				'description' => __( 'Length of the index for typo tolerance (default: 14). Higher values improve accuracy but increase index size.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_first_char_typo_double',
			__( 'First Character Typo Weight', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[ 
				'name'        => 'wp_loupe_advanced[first_char_typo_double]',
				'value'       => $this->get_advanced_option( 'first_char_typo_double', true ),
				'description' => __( 'Count a typo at the beginning of a word as two mistakes (recommended).', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_typo_prefix_search',
			__( 'Typo Tolerance for Prefix Search', 'wp-loupe' ),
			[ $this, 'checkbox_field_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[ 
				'name'        => 'wp_loupe_advanced[typo_prefix_search]',
				'value'       => $this->get_advanced_option( 'typo_prefix_search', false ),
				'description' => __( 'Enable typo tolerance in prefix search. Not recommended for large datasets.', 'wp-loupe' ),
			]
		);

		add_settings_field(
			'wp_loupe_typo_thresholds',
			__( 'Typo Thresholds', 'wp-loupe' ),
			[ $this, 'typo_thresholds_callback' ],
			'wp-loupe-advanced',
			'wp_loupe_typo_section',
			[ 
				'name'        => 'wp_loupe_advanced[typo_thresholds]',
				'value'       => $this->get_advanced_option( 'typo_thresholds', [ 
					'9' => 2, // 9 or more characters = 2 typos
					'5' => 1  // 5-8 characters = 1 typo
				] ),
				'description' => __( 'Configure how many typos are allowed based on word length.', 'wp-loupe' ),
			]
		);
	}

	/**
	 * Sanitize and validate field settings
	 * 
	 * @param array $input
	 * @return array
	 */
	public function sanitize_fields_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $input as $post_type => $fields ) {
			if ( ! is_array( $fields ) )
				continue;

			foreach ( $fields as $field_key => $settings ) {
				// Only include the field if it's explicitly marked as indexable
				if ( ! empty( $settings[ 'indexable' ] ) ) {
					$sanitized[ $post_type ][ $field_key ] = [ 
						'indexable'      => true,
						'weight'         => isset( $settings[ 'weight' ] ) ?
							floatval( $settings[ 'weight' ] ) : 1.0,
						'filterable'     => ! empty( $settings[ 'filterable' ] ),
						'sortable'       => ! empty( $settings[ 'sortable' ] ),
						'sort_direction' => isset( $settings[ 'sort_direction' ] ) &&
							in_array( $settings[ 'sort_direction' ], [ 'asc', 'desc' ] ) ?
							$settings[ 'sort_direction' ] : 'desc'
					];
				}
			}
		}

		// Clear schema cache when settings are updated
		$schema_manager = new WP_Loupe_Schema_Manager();
		$schema_manager->clear_cache();

		return $sanitized;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Check if we're on the WP Loupe settings page
		if ( ! in_array( $hook, [ 'settings_page_wp-loupe', 'tools_page_wp-loupe' ] ) ) {
			return;
		}

		$version = WP_Loupe_Utils::get_version_number();

		// Register and enqueue Select2
		wp_register_style(
			'select2css',
			WP_LOUPE_URL . 'lib/css/select2.min.css',
			[],
			$version
		);

		wp_register_script(
			'select2',
			WP_LOUPE_URL . 'lib/js/select2.min.js',
			[ 'jquery' ],
			$version,
			true
		);

		// Register and enqueue admin assets
		wp_register_style(
			'wp-loupe-admin',
			WP_LOUPE_URL . 'lib/css/admin.css',
			[ 'select2css' ],
			$version
		);

		wp_register_script(
			'wp-loupe-admin',
			WP_LOUPE_URL . 'lib/js/admin.js',
			[ 'jquery', 'select2', 'wp-api-fetch' ],
			$version,
			true
		);

		// Enqueue all assets
		wp_enqueue_style( 'select2css' );
		wp_enqueue_style( 'wp-loupe-admin' );
		wp_enqueue_script( 'select2' );
		wp_enqueue_script( 'wp-loupe-admin' );

		// Add some custom styles for the typo thresholds
		wp_add_inline_style( 'wp-loupe-admin', '
			.wp-loupe-typo-thresholds {
				margin-bottom: 10px;
			}
			.wp-loupe-threshold-row {
				margin-bottom: 8px;
			}
			.wp-loupe-threshold-row input[type="number"] {
				width: 60px;
			}
			.nav-tab-wrapper {
				margin-bottom: 20px;
			}
		' );

		// Add Select2 initialization
		wp_add_inline_script( 'select2', '
			jQuery(document).ready(function($) {
				$("#wp_loupe_custom_post_types").select2({
					placeholder: "Select post types",
					width: "400px"
				});
			});
		' );

		// Localize script with enhanced field data
		wp_localize_script( 'wp-loupe-admin', 'wpLoupeAdmin', [ 
			'restUrl'     => rest_url( 'wp-loupe/v1' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'savedFields' => $this->prepare_fields_for_js(),
		] );
	}

	/**
	 * Prepare field data for JavaScript
	 * 
	 * @return array
	 */
	private function prepare_fields_for_js() {
		$saved_fields    = get_option( 'wp_loupe_fields', [] );
		$enhanced_fields = [];

		foreach ( $saved_fields as $post_type => $fields ) {
			$available_fields = $this->get_available_fields( $post_type );

			$enhanced_fields[ $post_type ] = [];
			foreach ( $fields as $field_key => $settings ) {
				if ( isset( $available_fields[ $field_key ] ) ) {
					$enhanced_fields[ $post_type ][ $field_key ] = $settings;
				}
			}
		}

		return $enhanced_fields;
	}

	/**
	 * Add help tabs to explain field configuration options
	 */
	public function add_help_tabs() {
		$screen = get_current_screen();

		// Add overview help tab that explains the structure
		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_help_overview',
			'title'   => __( 'Overview', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><div class="wp-loupe-help-sections"><div class="wp-loupe-help-section basic"><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul></div><div class="wp-loupe-help-section advanced"><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul></div></div>',
				__( 'WP Loupe Help', 'wp-loupe' ),
				__( 'WP Loupe provides powerful search functionality with both basic and advanced configuration options.', 'wp-loupe' ),
				__( 'Basic Settings', 'wp-loupe' ),
				__( 'Configure which content is searchable and how:', 'wp-loupe' ),
				__( 'Select post types to include in search', 'wp-loupe' ),
				__( 'Configure field weights for relevance', 'wp-loupe' ),
				__( 'Set filterable and sortable fields', 'wp-loupe' ),
				__( 'Advanced Settings', 'wp-loupe' ),
				__( 'Fine-tune search behavior with advanced options:', 'wp-loupe' ),
				__( 'Tokenization and language settings', 'wp-loupe' ),
				__( 'Prefix search configuration', 'wp-loupe' ),
				__( 'Typo tolerance customization', 'wp-loupe' )
			),
		] );

		// Basic settings help tabs - remove "BASIC:" prefix
		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_weight',
			'title'   => __( 'Weight', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul>',
				__( 'Field Weight', 'wp-loupe' ),
				__( 'Weight determines how important a field is in search results:', 'wp-loupe' ),
				__( 'Higher weight (e.g., 2.0) makes matches in this field more important in results ranking', 'wp-loupe' ),
				__( 'Default weight is 1.0', 'wp-loupe' ),
				__( 'Lower weight (e.g., 0.5) makes matches less important but still searchable', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_filterable',
			'title'   => __( 'Filterable', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><p>%s</p>',
				__( 'Filterable Fields', 'wp-loupe' ),
				__( 'Filterable fields can be used to refine search results:', 'wp-loupe' ),
				__( 'Enable this option to allow filtering search results by this field\'s values', 'wp-loupe' ),
				__( 'Best for fields with consistent, categorized values like taxonomies, status fields, or controlled metadata', 'wp-loupe' ),
				__( 'Examples: categories, tags, post type, author, or custom taxonomies', 'wp-loupe' ),
				__( 'Note: Fields with highly variable or unique values (like content) make poor filters as each post would have its own filter value.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_sortable',
			'title'   => __( 'Sortable', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul><h3>%s</h3><p>%s</p><ul><li>%s</li><li>%s</li></ul>',
				__( 'Sortable Fields', 'wp-loupe' ),
				__( 'Sortable fields can be used to order search results:', 'wp-loupe' ),
				__( 'Enable this option to allow sorting search results by this field\'s values', 'wp-loupe' ),
				__( 'Works best with numerical fields, dates, or short text values', 'wp-loupe' ),
				__( 'Examples: date, price, rating, title', 'wp-loupe' ),
				__( 'Why some fields are not sortable', 'wp-loupe' ),
				__( 'Not all fields make good candidates for sorting:', 'wp-loupe' ),
				__( 'Long text fields (like content) don\'t provide meaningful sort order', 'wp-loupe' ),
				__( 'Fields with complex values (like arrays or objects) cannot be directly sorted', 'wp-loupe' )
			),
		] );

		// Advanced settings help tabs - remove "ADVANCED:" prefix
		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_tokenization',
			'title'   => __( 'Tokenization', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Tokenization Settings', 'wp-loupe' ),
				__( 'Tokenization controls how search queries are split into searchable pieces.', 'wp-loupe' ),
				__( 'Max Query Tokens', 'wp-loupe' ),
				__( 'Limits the number of words processed in a search query. Higher values allow longer queries but may impact performance.', 'wp-loupe' ),
				__( 'Languages', 'wp-loupe' ),
				__( 'Select languages to properly handle word splitting, stemming, and special characters. Include all languages your content uses.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_prefix_search',
			'title'   => __( 'Prefix Search', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><h3>%s</h3><p>%s</p><p>%s</p>',
				__( 'Prefix Search', 'wp-loupe' ),
				__( 'Prefix search allows users to find words by typing just the beginning of the term. For example, "huck" will match "huckleberry. Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. my friend huck would find documents containing huckleberry - huck is my friend, however, would not.', 'wp-loupe' ),
				__( 'Only the last word in a query is treated as a prefix. Earlier words must be typed fully.', 'wp-loupe' ),
				__( 'Minimum Prefix Length', 'wp-loupe' ),
				__( 'Sets the minimum number of characters before prefix search activates. Default is 3.', 'wp-loupe' ),
				__( 'Lower values (1-2) provide more immediate results but may slow searches on large sites. Higher values (4+) improve performance but require more typing.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_typo_tolerance',
			'title'   => __( 'Typo Tolerance', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Typo Tolerance', 'wp-loupe' ),
				__( 'Typo tolerance allows users to find results even when they make spelling mistakes in their search queries.', 'wp-loupe' ),
				__( 'For example, searching for "potatos" would still find "potatoes".', 'wp-loupe' ),
				__( 'Enable Typo Tolerance', 'wp-loupe' ),
				__( 'Turn typo tolerance on or off. Disabling may increase search speed but reduces forgiveness for spelling errors.', 'wp-loupe' )
			),
		] );

		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_typo_advanced',
			'title'   => __( 'Typo Details', 'wp-loupe' ),
			'content' => sprintf(
				'<h2>%s</h2><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p>',
				__( 'Advanced Typo Settings', 'wp-loupe' ),
				__( 'Alphabet Size & Index Length', 'wp-loupe' ),
				__( 'These settings affect index size and search performance. Higher values improve accuracy but increase index size. Default values work well for most sites.', 'wp-loupe' ),
				__( 'Typo Thresholds', 'wp-loupe' ),
				__( 'Control how many typos are allowed based on word length. Longer words typically allow more typos than shorter words.', 'wp-loupe' ),
				__( 'First Character Typo Weight', 'wp-loupe' ),
				__( 'When enabled, typos at the beginning of a word count as two mistakes. This helps prioritize more relevant results, as most typos occur in the middle of words.', 'wp-loupe' ),
				__( 'Typo Tolerance for Prefix Search', 'wp-loupe' ),
				__( 'Allows typos in prefix searches. Not recommended for large sites as it can significantly impact performance.', 'wp-loupe' )
			),
		] );

		// Add some custom styling to the help tabs
		$screen->add_help_tab( [ 
			'id'      => 'wp_loupe_help_styles',
			'title'   => __( '', 'wp-loupe' ),
			'content' => '<style>
				.wp-loupe-help-sections {
					display: flex;
					gap: 20px;
					margin-top: 15px;
				}
				.wp-loupe-help-section {
					flex: 1;
					padding: 15px;
					border-radius: 5px;
				}
				.wp-loupe-help-section.basic {
					background-color: #e7f5fa;
					border-left: 4px solid #2271b1;
				}
				.wp-loupe-help-section.advanced {
					background-color: #faf5e7;
					border-left: 4px solid #b17a22;
				}
				.wp-loupe-help-section h3 {
					margin-top: 0;
				}
			</style>',
		] );
	}

	/**
	 * Callback for language selection
	 */
	public function languages_field_callback( $args ) {
		$available_languages = [ 
			'ar' => __( 'Arabic', 'wp-loupe' ),
			'hy' => __( 'Armenian', 'wp-loupe' ),
			'eu' => __( 'Basque', 'wp-loupe' ),
			'ca' => __( 'Catalan', 'wp-loupe' ),
			'zh' => __( 'Chinese', 'wp-loupe' ),
			'cs' => __( 'Czech', 'wp-loupe' ),
			'da' => __( 'Danish', 'wp-loupe' ),
			'nl' => __( 'Dutch', 'wp-loupe' ),
			'en' => __( 'English', 'wp-loupe' ),
			'fi' => __( 'Finnish', 'wp-loupe' ),
			'fr' => __( 'French', 'wp-loupe' ),
			'gl' => __( 'Galician', 'wp-loupe' ),
			'de' => __( 'German', 'wp-loupe' ),
			'el' => __( 'Greek', 'wp-loupe' ),
			'hi' => __( 'Hindi', 'wp-loupe' ),
			'hu' => __( 'Hungarian', 'wp-loupe' ),
			'id' => __( 'Indonesian', 'wp-loupe' ),
			'ga' => __( 'Irish', 'wp-loupe' ),
			'it' => __( 'Italian', 'wp-loupe' ),
			'ja' => __( 'Japanese', 'wp-loupe' ),
			'ko' => __( 'Korean', 'wp-loupe' ),
			'no' => __( 'Norwegian', 'wp-loupe' ),
			'fa' => __( 'Persian', 'wp-loupe' ),
			'pt' => __( 'Portuguese', 'wp-loupe' ),
			'ro' => __( 'Romanian', 'wp-loupe' ),
			'ru' => __( 'Russian', 'wp-loupe' ),
			'sr' => __( 'Serbian', 'wp-loupe' ),
			'es' => __( 'Spanish', 'wp-loupe' ),
			'sv' => __( 'Swedish', 'wp-loupe' ),
			'ta' => __( 'Tamil', 'wp-loupe' ),
			'th' => __( 'Thai', 'wp-loupe' ),
			'tr' => __( 'Turkish', 'wp-loupe' ),
			'uk' => __( 'Ukrainian', 'wp-loupe' ),
			'ur' => __( 'Urdu', 'wp-loupe' ),
		];

		echo '<select multiple name="' . esc_attr( $args[ 'name' ] ) . '[]" class="wp-loupe-select2" style="width: 400px;">';
		foreach ( $available_languages as $code => $name ) {
			$selected = in_array( $code, $args[ 'value' ] ) ? 'selected="selected"' : '';
			echo '<option value="' . esc_attr( $code ) . '" ' . $selected . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html( $args[ 'description' ] ) . '</p>';

		// Add inline script to initialize Select2 on this field
		wp_print_inline_script_tag( '
		jQuery(document).ready(function($) {
			$(".wp-loupe-select2").select2({
				placeholder: "' . esc_js( __( "Select languages", "wp-loupe" ) ) . '"
			});
		});
		' );

	}

	/**
	 * Callback for typo thresholds
	 */
	public function typo_thresholds_callback( $args ) {
		$thresholds = $args[ 'value' ];

		echo '<div class="wp-loupe-typo-thresholds">';

		// First threshold
		echo '<div class="wp-loupe-threshold-row">';
		echo '<label>' . __( 'Word length ≥', 'wp-loupe' ) . ' </label>';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold1][length]" value="' . ( isset( $thresholds[ '9' ] ) ? '9' : ( isset( $thresholds[ 'threshold1' ][ 'length' ] ) ? esc_attr( $thresholds[ 'threshold1' ][ 'length' ] ) : '9' ) ) . '" min="3" max="20" step="1">';
		echo ' ' . __( 'characters: Allow', 'wp-loupe' ) . ' ';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold1][typos]" value="' . ( isset( $thresholds[ '9' ] ) ? esc_attr( $thresholds[ '9' ] ) : ( isset( $thresholds[ 'threshold1' ][ 'typos' ] ) ? esc_attr( $thresholds[ 'threshold1' ][ 'typos' ] ) : '2' ) ) . '" min="1" max="3" step="1">';
		echo ' ' . __( 'typos', 'wp-loupe' );
		echo '</div>';

		// Second threshold
		echo '<div class="wp-loupe-threshold-row">';
		echo '<label>' . __( 'Word length ≥', 'wp-loupe' ) . ' </label>';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold2][length]" value="' . ( isset( $thresholds[ '5' ] ) ? '5' : ( isset( $thresholds[ 'threshold2' ][ 'length' ] ) ? esc_attr( $thresholds[ 'threshold2' ][ 'length' ] ) : '5' ) ) . '" min="2" max="8" step="1">';
		echo ' ' . __( 'characters: Allow', 'wp-loupe' ) . ' ';
		echo '<input type="number" name="' . esc_attr( $args[ 'name' ] ) . '[threshold2][typos]" value="' . ( isset( $thresholds[ '5' ] ) ? esc_attr( $thresholds[ '5' ] ) : ( isset( $thresholds[ 'threshold2' ][ 'typos' ] ) ? esc_attr( $thresholds[ 'threshold2' ][ 'typos' ] ) : '1' ) ) . '" min="1" max="2" step="1">';
		echo ' ' . __( 'typos', 'wp-loupe' );
		echo '</div>';

		echo '</div>';
		echo '<p class="description">' . esc_html( $args[ 'description' ] ) . '</p>';
	}
}