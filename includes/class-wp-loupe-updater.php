<?php
namespace Soderlind\Plugin\WPLoupe;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

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
	// private $info_json = 'https://raw.githubusercontent.com/soderlind/wp-loupe/refs/heads/main/info.json';
	private $github_url = 'https://github.com/soderlind/wp-loupe';

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
		$update_checker = PucFactory::buildUpdateChecker(
			$this->github_url,
			$this->plugin_file,
			$this->plugin_slug
		);

		$update_checker->setBranch( 'main' );
		$update_checker->getVcsApi()->enableReleaseAssets( '/wp-loupe\.zip/' );
	}
}
