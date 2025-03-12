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
 * Version:           0.3.2
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

require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-loader.php';

/**
 * Initialize plugin
 */
function init() {
	// Don't run on autosave, WP CLI, Heartbeat or cron requests, and REST API requests (except our own)
	if ( 
		( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
		( defined( 'WP_CLI' ) && WP_CLI ) ||
		( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) && 'heartbeat' === $_REQUEST['action'] ) ||
		( defined( 'REST_REQUEST' ) && REST_REQUEST && ! str_starts_with( $_SERVER['REQUEST_URI'] ?? '', '/wp-json/wp-loupe' ) ) ||
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
