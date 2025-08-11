<?php
namespace Soderlind\WordPress;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Generic WordPress Plugin GitHub Updater
 * 
 * A reusable class for handling WordPress plugin updates from GitHub repositories
 * using the plugin-update-checker library.
 * 
 * @package Soderlind\WordPress
 * @link https://github.com/soderlind/wordpress-plugin-github-updater
 * @version 1.0.0
 * @author Per Soderlind
 * @license GPL-2.0+
 */
class GitHub_Plugin_Updater {
	/**
	 * @var string GitHub repository URL
	 */
	private $github_url;

	/**
	 * @var string Branch to check for updates
	 */
	private $branch;

	/**
	 * @var string Regex pattern to match the plugin zip file name
	 */
	private $name_regex;

	/**
	 * @var string The plugin slug
	 */
	private $plugin_slug;

	/**
	 * @var string The main plugin file path
	 */
	private $plugin_file;

	/**
	 * @var bool Whether to enable release assets
	 */
	private $enable_release_assets;

	/**
	 * Constructor
	 * 
	 * @param array $config Configuration array with the following keys:
	 *                     - github_url: GitHub repository URL (required)
	 *                     - plugin_file: Main plugin file path (required)
	 *                     - plugin_slug: Plugin slug for updates (required)
	 *                     - branch: Branch to check for updates (default: 'main')
	 *                     - name_regex: Regex pattern for zip file name (optional)
	 *                     - enable_release_assets: Whether to enable release assets (default: true if name_regex provided)
	 */
	public function __construct( $config = array() ) {
		// Validate required parameters
		$required = array( 'github_url', 'plugin_file', 'plugin_slug' );
		foreach ( $required as $key ) {
			if ( empty( $config[ $key ] ) ) {
				throw new \InvalidArgumentException( "Required parameter '{$key}' is missing or empty." );
			}
		}

		$this->github_url            = $config[ 'github_url' ];
		$this->plugin_file           = $config[ 'plugin_file' ];
		$this->plugin_slug           = $config[ 'plugin_slug' ];
		$this->branch                = isset( $config[ 'branch' ] ) ? $config[ 'branch' ] : 'main';
		$this->name_regex            = isset( $config[ 'name_regex' ] ) ? $config[ 'name_regex' ] : '';
		$this->enable_release_assets = isset( $config[ 'enable_release_assets' ] )
			? $config[ 'enable_release_assets' ]
			: ! empty( $this->name_regex );

		// Initialize the updater
		add_action( 'init', array( $this, 'setup_updater' ) );
	}

	/**
	 * Set up the update checker using GitHub integration
	 */
	public function setup_updater() {
		try {
			$update_checker = PucFactory::buildUpdateChecker(
				$this->github_url,
				$this->plugin_file,
				$this->plugin_slug
			);

			$update_checker->setBranch( $this->branch );

			// Enable release assets if configured
			if ( $this->enable_release_assets && ! empty( $this->name_regex ) ) {
				$update_checker->getVcsApi()->enableReleaseAssets( $this->name_regex );
			}

		} catch (\Exception $e) {
			// Log error if WordPress debug is enabled
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GitHub Plugin Updater Error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Create updater instance with minimal configuration
	 * 
	 * @param string $github_url GitHub repository URL
	 * @param string $plugin_file Main plugin file path
	 * @param string $plugin_slug Plugin slug
	 * @param string $branch Branch name (default: 'main')
	 * 
	 * @return GitHub_Plugin_Updater
	 */
	public static function create( $github_url, $plugin_file, $plugin_slug, $branch = 'main' ) {
		return new self( array(
			'github_url'  => $github_url,
			'plugin_file' => $plugin_file,
			'plugin_slug' => $plugin_slug,
			'branch'      => $branch,
		) );
	}

	/**
	 * Create updater instance for plugins with release assets
	 * 
	 * @param string $github_url GitHub repository URL
	 * @param string $plugin_file Main plugin file path
	 * @param string $plugin_slug Plugin slug
	 * @param string $name_regex Regex pattern for release assets
	 * @param string $branch Branch name (default: 'main')
	 * 
	 * @return GitHub_Plugin_Updater
	 */
	public static function create_with_assets( $github_url, $plugin_file, $plugin_slug, $name_regex, $branch = 'main' ) {
		return new self( array(
			'github_url'  => $github_url,
			'plugin_file' => $plugin_file,
			'plugin_slug' => $plugin_slug,
			'branch'      => $branch,
			'name_regex'  => $name_regex,
		) );
	}
}