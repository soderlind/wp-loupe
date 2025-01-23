<?php
namespace Soderlind\Plugin\WPLoupe;

class WP_Loupe_DB {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function delete_index() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		$file_system = new \WP_Filesystem_Direct( false );
		$cache_path  = apply_filters( 'wp_loupe_db_path', WP_CONTENT_DIR . '/wp-loupe-db' );

		if ( $file_system->is_dir( $cache_path ) ) {
			$file_system->rmdir( $cache_path, true );
		}
	}

	public function get_db_path( $post_type ) {
		$base_path = apply_filters( 'wp_loupe_db_path', WP_CONTENT_DIR . '/wp-loupe-db' );
		return "{$base_path}/{$post_type}";
	}
}
