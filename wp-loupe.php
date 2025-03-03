<?php
/**
 * WP Loupe
 *
 * @package     soderlind\plugin\WPLoupe
 * @author      Per Soderlind
 * @copyright   2021 Per Soderlind
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: WP Loupe
 * Plugin URI: https://github.com/soderlind/wp-loupe
 * GitHub Plugin URI: https://github.com/soderlind/wp-loupe
 * Description: Search engine for WordPress. It uses the Loupe search engine to create a search index for your posts and pages and to search the index.
 * Version:     0.1.1
 * Author:      Per Soderlind
 * Author URI:  https://soderlind.no
 * Text Domain: wp-loupe
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

declare(strict_types=1);
namespace Soderlind\Plugin\WPLoupe;

use Soderlind\Plugin\WPLoupe\WP_Loupe_Utils;

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

define( 'WP_LOUPE_VERSION', '0.0.10' );
define( 'WP_LOUPE_FILE', __FILE__ );
define( 'WP_LOUPE_NAME', plugin_basename( WP_LOUPE_FILE ) );
define( 'WP_LOUPE_PATH', plugin_dir_path( WP_LOUPE_FILE ) );
define( 'WP_LOUPE_URL', plugin_dir_url( WP_LOUPE_FILE ) );

require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-loader.php';

/**
 * Initialize plugin
 */
function init() {
	WP_Loupe_Loader::get_instance();
	if ( ! WP_Loupe_Utils::has_sqlite() ) {
		return;
	}

}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );
