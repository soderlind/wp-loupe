<?php
/**
 * Admin class.
 *
 * @package  soderlind\plugin\WPLoupe
 */
class WPLoupe_Settings_Page {
	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'create_plugin_settings_page' ] );
	}

	/**
	 * Adds a new settings page under Settings.
	 */
	public function create_plugin_settings_page() {
		// Add the menu item and page.
		$page_title = 'WP Loupe';
		$menu_title = 'WP Loupe';
		$capability = 'manage_options';
		$slug       = 'wp_loupe';
		$callback   = [ $this, 'plugin_settings_page_content' ];
		$position   = 100;


		\add_options_page( $page_title, $menu_title, $capability, $slug, $callback, $position );
	}

	/**
	 * Provides the content for the plugin settings page.
	 */
	public function plugin_settings_page_content() {
		// Check if user is allowed access.
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
			<div>
				<p>
				<?php
				echo \esc_html_x( 'Delete the idexes and reindex data.', 'wp-loupe' );
				?>
				</p>
			</div>
			<form action="" method="POST">
				<?php wp_nonce_field( 'wp_loupe_reindex', 'wp_loupe_reindex_nonce' ); ?>
				<input type="hidden" name="action" value="reindex">
				<input type="submit" name="submit" id="submit" class="button button-primary" value="Reindex">
			</form>
		</div>
		<?php
	}
}

new WPLoupe_Settings_Page();
