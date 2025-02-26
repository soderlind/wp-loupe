<?php
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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_select2_jquery' ] );
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
		add_settings_section( 'wp_loupe_section', 'WP Loupe Settings', [], 'wp-loupe', );
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


	/**
	 * Enqueue Select2.
	 *
	 * @return void
	 */
	public static function enqueue_select2_jquery() {

		\wp_register_style( 'select2css', WP_LOUPE_URL . '/lib/css/select2.min.css', [], '4.0.13', 'all' );
		\wp_enqueue_style( 'select2css' );

		\wp_register_script( 'select2', WP_LOUPE_URL . '/lib/js/select2.min.js', [ 'jquery' ], '4.0.13', true );
		\wp_enqueue_script( 'select2' );

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
