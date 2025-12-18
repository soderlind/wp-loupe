<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Database management class for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.1
 */
class WP_Loupe_DB {
	/**
	 * Instance of this class
	 *
	 * @since 0.0.1
	 * @var WP_Loupe_DB
	 */
	private static $instance = null;

	/**
	 * Get instance of this class
	 *
	 * @since 0.0.1
	 * @return WP_Loupe_DB Instance of this class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Delete the search index
	 *
	 * @since 0.0.1
	 * @return bool True if index was deleted, false otherwise
	 */
	public function delete_index() {
		// Only load the filesystem classes if needed
		if (!class_exists('WP_Filesystem_Direct')) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$file_system = new \WP_Filesystem_Direct(false);
		$cache_path = $this->get_base_path();

		if ($file_system->is_dir($cache_path)) {
			return $file_system->rmdir($cache_path, true);
		}
		
		return true;
	}

	/**
	 * Get database path for a post type
	 *
	 * @since 0.0.1
	 * @param string $post_type Post type.
	 * @return string Path to database file
	 */
	public function get_db_path($post_type) {
		$base_path = $this->get_base_path();
		$path      = rtrim( $base_path, '/' ) . '/' . ltrim( (string) $post_type, '/' );
		$this->ensure_directory_exists( $path );
		return $path;
	}
	
	/**
	 * Get base path for all Loupe databases
	 *
	 * @since 0.0.1
	 * @return string Base path
	 */
	public function get_base_path() {
		$default = defined( 'WP_CONTENT_DIR' ) ? ( WP_CONTENT_DIR . '/wp-loupe-db' ) : '';
		$path    = apply_filters( 'wp_loupe_db_path', $default );
		$path    = is_string( $path ) ? trim( $path ) : '';

		// Guard against misbehaving filters returning empty/false.
		if ( '' === $path ) {
			$path = $default;
		}

		$path = is_string( $path ) ? rtrim( $path, '/' ) : '';
		$this->ensure_directory_exists( $path );
		return $path;
	}

	/**
	 * Best-effort ensure that the directory exists.
	 *
	 * @param string $path
	 */
	private function ensure_directory_exists( string $path ): void {
		if ( '' === $path ) {
			return;
		}

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $path );
			return;
		}

		// Fallback for very early contexts.
		if ( ! is_dir( $path ) ) {
			@mkdir( $path, 0755, true );
		}
	}
}
