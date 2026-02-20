<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://github.com/soderlind/wp-loupe
 * @since             0.0.1
 * @package           WP_Loupe
 *
 * @wordpress-plugin
 * Plugin Name:       WP Loupe
 * Plugin URI:        https://github.com/soderlind/wp-loupe
 * Description:       Enhance the search functionality of your WordPress site with WP Loupe.
 * Version:           0.8.1
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-loupe
 * Domain Path:       /languages
 */

declare(strict_types=1);
namespace Soderlind\Plugin\WPLoupe;

use Soderlind\Plugin\WPLoupe\WP_Loupe_Utils;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WP_LOUPE_FILE', __FILE__ );
define( 'WP_LOUPE_NAME', plugin_basename( WP_LOUPE_FILE ) );
define( 'WP_LOUPE_PATH', plugin_dir_path( WP_LOUPE_FILE ) );
define( 'WP_LOUPE_URL', plugin_dir_url( WP_LOUPE_FILE ) );
// MCP related constants (development token & version marker)
if ( ! defined( 'WP_LOUPE_MCP_VERSION' ) ) {
	define( 'WP_LOUPE_MCP_VERSION', '0.8.1' );
}
// Optional development bearer token for initial implementation (SHOULD be disabled in production)
if ( ! defined( 'WP_LOUPE_MCP_DEV_TOKEN' ) ) {
	define( 'WP_LOUPE_MCP_DEV_TOKEN', '' ); // Site owner may set in wp-config.php
}

require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-loader.php';
// Load CLI commands if in WP-CLI context
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-mcp-cli.php';
}

/**
 * Initialize plugin
 */
function init() {
	// Don't run on autosave, WP CLI, Heartbeat or cron requests, and REST API requests (except our own)
	if (
		( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			// Allow WP_CLI to proceed so CLI subcommands can access server components.
		( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST[ 'action' ] ) && 'heartbeat' === $_REQUEST[ 'action' ] ) ||
		( defined( 'REST_REQUEST' ) && REST_REQUEST && ! str_starts_with( $_SERVER[ 'REQUEST_URI' ] ?? '', '/wp-json/wp-loupe' ) ) ||
		( defined( 'DOING_CRON' ) && DOING_CRON )
	) {
		return;
	}

	WP_Loupe_Loader::get_instance();
	if ( ! WP_Loupe_Utils::has_sqlite() ) {
		return;
	}

	// new WP_Loupe_Updater( WP_LOUPE_FILE );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Activation hook: flush rewrite rules to register .well-known endpoints once they are added.
 */
function activate( $network_wide = false ) {
	// When network activated, iterate over all sites to ensure rewrite rules include .well-known endpoints.
	if ( is_multisite() && $network_wide ) {
		global $wpdb;
		$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} WHERE public = 1" );
		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			flush_rewrite_rules();
			restore_current_blog();
		}
	} else {
		flush_rewrite_rules();
	}
}

/**
 * Deactivation hook: flush rewrite rules to remove custom endpoints.
 */
function deactivate() {
	flush_rewrite_rules();
}

register_activation_hook( WP_LOUPE_FILE, __NAMESPACE__ . '\\activate' );

// Ensure new subsites get rewrite rules for .well-known endpoints.
function on_new_blog( $blog_id ) {
	if ( ! is_multisite() ) {
		return;
	}
	switch_to_blog( (int) $blog_id );
	// Trigger init hooks that add rewrite rules.
	do_action( 'init' );
	flush_rewrite_rules();
	restore_current_blog();
}
add_action( 'wpmu_new_blog', __NAMESPACE__ . '\\on_new_blog', 20 );
register_deactivation_hook( WP_LOUPE_FILE, __NAMESPACE__ . '\\deactivate' );
