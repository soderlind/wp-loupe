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
		add_action('rest_api_init', [$this, 'register_rest_routes']);
		add_action('load-settings_page_wp-loupe', [$this, 'add_help_tabs']);
	}

	public function register_rest_routes() {
		register_rest_route('wp-loupe/v1', '/post-type-fields/(?P<post_type>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_post_type_fields'],
			'permission_callback' => function() {
				return current_user_can('manage_options');
			}
		]);
	}

	public function get_post_type_fields($request) {
		$post_type = $request->get_param('post_type');
		$post_type_object = get_post_type_object($post_type);
		
		if (!$post_type_object) {
			return new WP_Error('invalid_post_type', 'Invalid post type', ['status' => 404]);
		}

		$fields = $this->get_available_fields($post_type);
		return rest_ensure_response($fields);
	}

	public function get_available_fields($post_type) {
        // Always include core fields
        $fields = [
            'post_title' => __('Title', 'wp-loupe'),
            'post_content' => __('Content', 'wp-loupe'),
            'post_excerpt' => __('Excerpt', 'wp-loupe'),
            'post_date' => __('Date', 'wp-loupe'),
            'post_author' => __('Author', 'wp-loupe')
        ];
        
        // Add taxonomy fields
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        foreach ($taxonomies as $tax_name => $tax_object) {
            if ($tax_object->show_ui) {
                $fields['taxonomy_' . $tax_name] = $tax_object->label;
            }
        }
        
        // Get custom fields with values
        $meta_keys = $this->get_post_type_meta_keys_with_values($post_type);
        foreach ($meta_keys as $meta_key => $has_value) {
            $registered_meta = get_registered_meta_keys('post', $post_type);
            $meta_label = isset($registered_meta[$meta_key]['description']) ? 
                $registered_meta[$meta_key]['description'] : 
                $this->prettify_meta_key($meta_key);
            
            $fields[$meta_key] = [
                'label' => $meta_label,
                'hasValue' => $has_value
            ];
        }
        
        return apply_filters('wp_loupe_available_fields', $fields, $post_type);
    }

    private function get_post_type_meta_keys_with_values($post_type) {
        $meta_keys = $this->get_filtered_meta_keys($post_type);
        return array_fill_keys($meta_keys, true);
    }

    private function get_post_type_meta_keys($post_type) {
        return $this->get_filtered_meta_keys($post_type);
    }

    /**
     * Get filtered meta keys for a post type, removing system and protected meta
     *
     * @param string $post_type Post type to get meta keys for
     * @return array Filtered meta keys
     */
    private function get_filtered_meta_keys($post_type) {
        global $wpdb;
        static $cache = array();
        
        // Check cache first
        $cache_key = 'wp_loupe_meta_keys_' . $post_type;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
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

        $meta_keys = $wpdb->get_col($query);
        
        // Remove system meta keys and make unique
        $filtered_keys = array_unique(array_filter($meta_keys, function($key) {
            return !is_protected_meta($key, 'post') 
                && !preg_match('/^_oembed|^_wp/', $key)
                && !in_array($key, [
                    '_edit_last',
                    '_edit_lock',
                    '_thumbnail_id',
                    '_wp_old_slug',
                    '_wp_page_template'
                ]);
        }));
        
        sort($filtered_keys);
        $cache[$cache_key] = $filtered_keys;
        
        return $filtered_keys;
    }

	private function prettify_meta_key($key) {
        return ucwords(str_replace(['_', '-'], ' ', $key));
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
		add_settings_section( 'wp_loupe_section', 'WP Loupe Settings', [ $this, 'section_callback' ], 'wp-loupe' );
		add_settings_section('wp_loupe_fields_section', 'Field Settings', [$this, 'fields_section_callback'], 'wp-loupe');
	}

	public function section_callback() {
		echo '<p>' . __('Select which post types and fields to include in the search index.', 'wp-loupe') . '</p>';
	}

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
	 * Callback for the reindex field.
	 *
	 * @return void
	 */
	public function wp_loupe_reindex_field_callback() {
		echo '<input type="hidden" name="wp_loupe_reindex" id="wp_loupe_reindex" value="1">';
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

		// Get custom post types.
		$args       = [ 
			'public'   => true,
			'_builtin' => false,
		];
		$post_types = get_post_types( $args, 'names', 'and' );

		?>
		<div class="wrap">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
			<form action="options.php" method="POST">
				<input
				type="hidden" name="wp_loupe_reindex" id="wp_loupe_reindex" value="on">
			<?php
			// add nonce field in the form.
			wp_nonce_field( 'wp_loupe_nonce_action', 'wp_loupe_nonce_field' );
			settings_fields( 'wp-loupe' );
			do_settings_sections( 'wp-loupe' );
			submit_button( __( 'Reindex', 'wp-loupe' ) );
			?>
			</form>
		</div><?php
	}

	public function register_settings() {
		register_setting('wp-loupe', 'wp_loupe_custom_post_types');
		register_setting('wp-loupe', 'wp_loupe_fields', [
			'type' => 'array',
			'description' => 'Field configuration for each post type',
			'sanitize_callback' => [$this, 'sanitize_fields_settings']
		]);
	}

	public function sanitize_fields_settings($input) {
        if (!is_array($input)) {
            return [];
        }

        $sanitized = [];
        foreach ($input as $post_type => $fields) {
            if (!is_array($fields)) continue;

            foreach ($fields as $field_key => $settings) {
                // Only include the field if it's explicitly marked as indexable
                if (!empty($settings['indexable'])) {
                    $sanitized[$post_type][$field_key] = [
                        'indexable' => true,
                        'weight' => isset($settings['weight']) ? 
                            floatval($settings['weight']) : 1.0,
                        'filterable' => !empty($settings['filterable']),
                        'sortable' => !empty($settings['sortable']),
                        'sort_direction' => isset($settings['sort_direction']) && 
                            in_array($settings['sort_direction'], ['asc', 'desc']) ? 
                            $settings['sort_direction'] : 'desc'
                    ];
                }
            }
        }

        // Clear schema cache when settings are updated
        WP_Loupe_Schema_Manager::get_instance()->clear_cache();
        
        return $sanitized;
    }

	/**
	 * Enqueue Select2.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets($hook) {
		// Check if we're on the WP Loupe settings page
		if (!in_array($hook, ['settings_page_wp-loupe', 'tools_page_wp-loupe'])) {
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
			['jquery'],
			$version,
			true
		);
		// Register and enqueue admin assets
		wp_register_style(
			'wp-loupe-admin',
			WP_LOUPE_URL . 'lib/css/admin.css',
			['select2css'],
			$version
		);

		wp_register_script(
			'wp-loupe-admin',
			WP_LOUPE_URL . 'lib/js/admin.js',
			['jquery', 'select2', 'wp-api-fetch'],
			$version,
			true
		);

		// Enqueue all assets
		wp_enqueue_style('select2css');
		wp_enqueue_style('wp-loupe-admin');
		wp_enqueue_script('select2');
		wp_enqueue_script('wp-loupe-admin');

		// Add Select2 initialization
		wp_add_inline_script('select2', '
			jQuery(document).ready(function($) {
				$("#wp_loupe_custom_post_types").select2({
					placeholder: "Select post types",
					width: "400px"
				});
			});
		');

		// Localize script with enhanced field data
        wp_localize_script('wp-loupe-admin', 'wpLoupeAdmin', [
            'restUrl' => rest_url('wp-loupe/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'savedFields' => $this->prepare_fields_for_js()
        ]);
	}

	private function prepare_fields_for_js() {
        $saved_fields = get_option('wp_loupe_fields', []);
        $enhanced_fields = [];

        foreach ($saved_fields as $post_type => $fields) {
            $available_fields = $this->get_available_fields($post_type);
            
            $enhanced_fields[$post_type] = [];
            foreach ($fields as $field_key => $settings) {
                if (isset($available_fields[$field_key])) {
                    $enhanced_fields[$post_type][$field_key] = $settings;
                }
            }
        }
        WP_Loupe_Utils::dump($enhanced_fields);
        return $enhanced_fields;
    }

	/**
	 * Add help tabs to explain field configuration options
	 */
	public function add_help_tabs() {
		$screen = get_current_screen();
		
		$screen->add_help_tab([
			'id'      => 'wp_loupe_weight',
			'title'   => __('Weight', 'wp-loupe'),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul>',
				__('Field Weight', 'wp-loupe'),
				__('Weight determines how important a field is in search results:', 'wp-loupe'),
				__('Higher weight (e.g., 2.0) makes matches in this field more important', 'wp-loupe'),
				__('Default weight is 1.0', 'wp-loupe'),
				__('Lower weight (e.g., 0.5) makes matches less important', 'wp-loupe')
			)
		]);

		$screen->add_help_tab([
			'id'      => 'wp_loupe_filterable',
			'title'   => __('Filterable', 'wp-loupe'),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li></ul>',
				__('Filterable Fields', 'wp-loupe'),
				__('Filterable fields can be used to refine search results:', 'wp-loupe'),
				__('Enable this option to allow filtering search results by this field\'s values', 'wp-loupe'),
				__('Useful for categories, tags, and other taxonomies or metadata that you want users to filter by', 'wp-loupe')
			)
		]);

		$screen->add_help_tab([
			'id'      => 'wp_loupe_sortable',
			'title'   => __('Sortable', 'wp-loupe'),
			'content' => sprintf(
				'<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li></ul>',
				__('Sortable Fields', 'wp-loupe'),
				__('Sortable fields can be used to order search results:', 'wp-loupe'),
				__('Enable this option to allow sorting search results by this field\'s values', 'wp-loupe'),
				__('Useful for dates, prices, or other numerical values that make sense to sort by', 'wp-loupe')
			)
		]);

		$screen->set_help_sidebar(
			sprintf(
				'<p><strong>%s</strong></p><p>%s</p>',
				__('For more information:', 'wp-loupe'),
				sprintf(
					'<a href="%s" target="_blank">%s</a>',
					'https://github.com/soderlind/wp-loupe',
					__('WP Loupe Documentation', 'wp-loupe')
				)
			)
		);
	}
}