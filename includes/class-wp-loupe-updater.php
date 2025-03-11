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
	private $github_url = 'https://github.com/soderlind/wp-loupe';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'setup_updater' ) );
	}

	/**
	 * Set up the update checker using GitHub integration
	 */
	public function setup_updater() {
		$update_checker = PucFactory::buildUpdateChecker(
			$this->github_url,
			WP_LOUPE_FILE,
			'wp-loupe'
		);

		$update_checker->setBranch( 'main' );
		$update_checker->getVcsApi()->enableReleaseAssets( '/wp-loupe\.zip/' );
	}
}
