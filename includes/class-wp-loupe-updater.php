<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Plugin updater class for WP Loupe
 * 
 * Handles plugin updates directly from GitHub using the plugin-update-checker library
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.1.2
 */
class WP_Loupe_Updater {
	/**
	 * @var string GitHub repository URL
	 */
	private $github_repo_url = 'https://github.com/soderlind/wp-loupe';

	/**
	 * @var string Main plugin file path
	 */
	private $plugin_file;

	/**
	 * @var string Plugin slug
	 */
	private $plugin_slug = 'wp-loupe';

	/**
	 * Constructor
	 * 
	 * @param string $plugin_file Main plugin file path
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->init();
	}

	/**
	 * Initialize the updater
	 */
	public function init() {
		add_action( 'init', array( $this, 'setup_updater' ) );
	}

	/**
	 * Set up the update checker using GitHub integration
	 */
	public function setup_updater() {
		// Path to the plugin-update-checker library
		$update_checker_path = WP_LOUPE_PATH . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
		// Only proceed if the library is available
		if ( file_exists( $update_checker_path ) ) {
			require_once $update_checker_path;

			$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$this->github_repo_url,
				$this->plugin_file,
				$this->plugin_slug
			);

			// Set to use GitHub releases as the update source
			$update_checker->getVcsApi()->enableReleaseAssets( '/wp-loupe\.zip/' );

			// Optional: Set branch name if not using 'master'
			$update_checker->setBranch( 'main' );
		}
	}
}
