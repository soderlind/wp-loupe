<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Main plugin loader
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_Loader {
	private static $instance = null;
	private $search;
	private $indexer;
	private $post_types;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->setup_post_types();
		$this->init_components();
		$this->register_hooks();
	}

	/**
	 * Load dependencies
	 * 
	 * @return void
	 */
	private function load_dependencies() {
		require_once WP_LOUPE_PATH . 'vendor/autoload.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-updater.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-schema-manager.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-factory.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-search.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-indexer.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-db.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-utils.php';
		require_once WP_LOUPE_PATH . 'includes/class-wp-loupe-settings.php';
	}

	/**
	 * Setup post types
	 * 
	 * @return void
	 */
	private function setup_post_types() {
		$options = get_option('wp_loupe_custom_post_types', []);
		
		if (!empty($options) && isset($options['wp_loupe_post_type_field'])) {
			$this->post_types = (array)$options['wp_loupe_post_type_field'];
		} else {
			$this->post_types = ['post', 'page'];
		}
		
		$this->post_types = apply_filters('wp_loupe_post_types', $this->post_types);
	}

	/**
	 * Initialize components
	 * 
	 * @return void
	 */
	private function init_components() {
		new WP_Loupe_Updater();
		new WPLoupe_Settings_Page();

		$this->search  = new WP_Loupe_Search($this->post_types);
		$this->indexer = new WP_Loupe_Indexer($this->post_types);
	}

	/**
	 * Register hooks
	 * 
	 * @return void
	 */
	private function register_hooks() {
		add_action('init', [$this, 'load_textdomain']);
	}

	/**
	 * Load textdomain
	 * 
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain('wp-loupe', false, dirname(plugin_basename(WP_LOUPE_FILE)) . '/languages');
	}
}
