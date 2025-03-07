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

	private function get_available_fields($post_type) {
		$fields = [];
		
		// Get post type fields
		$post_type_fields = get_post_type_object($post_type);
		
		// Core fields
		$fields['post_title'] = __('Title', 'wp-loupe');
		$fields['post_content'] = __('Content', 'wp-loupe');
		$fields['post_excerpt'] = __('Excerpt', 'wp-loupe');
		
		// Get custom fields
		$meta_keys = $this->get_post_type_meta_keys($post_type);
		foreach ($meta_keys as $meta_key) {
			$fields[$meta_key] = $meta_key;
		}
		
		return $fields;
	}

	private function get_post_type_meta_keys($post_type) {
		global $wpdb;
		$query = "
			SELECT DISTINCT meta_key 
			FROM $wpdb->postmeta pm 
			JOIN $wpdb->posts p ON p.ID = pm.post_id 
			WHERE p.post_type = %s 
			AND meta_key NOT LIKE '\_%'
		";
		return $wpdb->get_col($wpdb->prepare($query, $post_type));
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
				$sanitized[$post_type][$field_key] = [
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
		if ('settings_page_wp-loupe' !== $hook) {
			return;
		}

		wp_enqueue_style('select2css');
		wp_enqueue_script('select2');
		
		// Enqueue admin CSS
		wp_enqueue_style(
			'wp-loupe-admin',
			WP_LOUPE_URL . '/lib/css/admin.css',
			[],
			WP_LOUPE_VERSION
		);
		
		wp_enqueue_script(
			'wp-loupe-admin',
			WP_LOUPE_URL . '/lib/js/admin.js',
			['jquery', 'select2', 'wp-api-fetch'],
			WP_LOUPE_VERSION,
			true
		);

		wp_localize_script('wp-loupe-admin', 'wpLoupeAdmin', [
			'restUrl' => rest_url('wp-loupe/v1'),
			'nonce' => wp_create_nonce('wp_rest'),
			'savedFields' => get_option('wp_loupe_fields', [])
		]);

		// Code to initialize the select2 dropdown. Connects the dropdown to the select2 library.
		$locale_script = <<<EOT
jQuery(document).ready(function($) {
    $('#wp_loupe_custom_post_types,#wp_loupe_network_sites,#wp_loupe_network_search_site').select2({
		placeholder: 'Select post types',
		width: '200px',
	});
});
EOT;

		\wp_add_inline_script( 'select2', $locale_script );

		// Add custom styles for the select2 dropdown. Ads a down arrow to the dropdown.
		$locale_style = <<<EOT
.select2-container--default .select2-selection--multiple {
    position: relative;
    padding-right: 20px;
}

.select2-container--default .select2-selection--multiple:after {
    content: '';
    border-color: #888 transparent transparent transparent;
    border-style: solid;
    border-width: 5px 4px 0 4px;
    position: absolute;
    top: 50%;
    right: 5px;
    transform: translateY(-50%);
    pointer-events: none;
}

.select2-container--default.select2-container--open .select2-selection--multiple:after {
    border-color: transparent transparent #888 transparent;
    border-width: 0 4px 5px 4px;
}
EOT;
		\wp_add_inline_style( 'select2css', $locale_style );
	}
}
new WPLoupe_Settings_Page();
