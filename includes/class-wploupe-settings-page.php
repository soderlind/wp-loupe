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
 * Admin class.
 *
 * @package  soderlind\plugin\WPLoupe
 */
class WPLoupe_Settings_Page {
	/**
	 * WPLoupe_Settings_Page constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'wp_loupe_create_settings' ] );
		add_action( 'admin_init', [ $this, 'wp_loupe_setup_sections' ] );
		add_action( 'admin_init', [ $this, 'wp_loupe_setup_fields' ] );
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
		add_settings_section( 'wp_loupe_section', 'WP Loupe Settings', [], 'wp-loupe',  );
	}

	/**
	 * Setup the settings fields.
	 *
	 * @return void
	 */
	public function wp_loupe_setup_fields() {
		register_setting( 'wp-loupe', 'wp_loupe_custom_post_types' );

		add_settings_field( 'wp_loupe_post_type_field', __( 'Select Custom 	Post Type', 'wp-loupe' ), [ $this, 'wp_loupe_post_type_field_callback' ], 'wp-loupe', 'wp_loupe_section' );
		add_settings_field( 'wp_loupe_reindex_field', __( 'Reindex Search Index', 'wp-loupe' ), [ $this, 'wp_loupe_reindex_field_callback' ], 'wp-loupe', 'wp_loupe_section' );
	}

	/**
	 * Callback for the post type field.
	 *
	 * @return void
	 */
	public function wp_loupe_post_type_field_callback() {
		$post_types   = get_post_types(
			[
				'public'   => true,
				'_builtin' => false,
			],
			'names',
			'and'
		);
		$options      = get_option( 'wp_loupe_custom_post_types', [] );
		$selected_ids = ! empty( $options ) && isset( $options['wp_loupe_post_type_field'] ) && ! empty( $options['wp_loupe_post_type_field'] ) ? (array) $options['wp_loupe_post_type_field'] : [];
		echo '<select name="wp_loupe_custom_post_types[wp_loupe_post_type_field][]" multiple>';
		foreach ( $post_types as $post_type ) {
			echo '<option value="' . esc_attr( $post_type ) . '" ' . selected( in_array( $post_type, $selected_ids, true ), true, false ) . '>' . esc_html( $post_type ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Callback for the reindex field.
	 *
	 * @return void
	 */
	public function wp_loupe_reindex_field_callback() {
		echo '<input type="checkbox" name="wp_loupe_reindex" id="wp_loupe_reindex">';
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
				<?php
				// add nonce field in the form.
				wp_nonce_field( 'wp_loupe_nonce_action', 'wp_loupe_nonce_field' );
				settings_fields( 'wp-loupe' );
				do_settings_sections( 'wp-loupe' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
new WPLoupe_Settings_Page();
