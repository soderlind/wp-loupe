<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Utility functions for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_Utils {
	/**
	 * Check if SQLite3 is installed and meets version requirements
	 *
	 * @since 0.0.11
	 * @return boolean True if SQLite3 is available and meets requirements
	 */
	public static function has_sqlite() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! class_exists( 'SQLite3' ) ) {
			self::display_error_and_deactivate_plugin(
				__( 'SQLite3 not installed', 'wp-loupe' ),
				__( 'WP Loupe requires SQLite3 version 3.16.0 or newer.', 'wp-loupe' )
			);
			return false;
		}

		$version = \SQLite3::version();
		if ( version_compare( $version[ 'versionString' ], '3.16.0', '<' ) ) {
			self::display_error_and_deactivate_plugin(
				__( 'SQLite3 version too old', 'wp-loupe' ),
				__( 'WP Loupe requires SQLite3 version 3.16.0 or newer.', 'wp-loupe' )
			);
			return false;
		}

		return true;
	}

	/**
	 * Display error message and deactivate plugin
	 *
	 * @since 0.0.11
	 * @param string $error_title   The error title.
	 * @param string $error_message The error message.
	 * @return void
	 */
	private static function display_error_and_deactivate_plugin( $error_title, $error_message ) {
		add_action( 'all_admin_notices', function () use ($error_title, $error_message) {
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
				esc_html( $error_title ),
				esc_html( $error_message )
			);
			deactivate_plugins( WP_LOUPE_FILE );
			if ( is_multisite() ) {
				deactivate_plugins( WP_LOUPE_FILE, false, true );
			}
		} );
	}

	/**
	 * Check if a post should be indexed
	 *
	 * @since 0.0.11
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return boolean True if post should be indexed
	 */
	public static function is_post_indexable( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		return 'publish' === $post->post_status &&
			apply_filters( 'wp_loupe_index_protected', empty( $post->post_password ) );
	}

	/**
	 * Debug function that uses Ray if available
	 *
	 * @since 0.0.11
	 * @param mixed $var Variable to dump.
	 * @return void
	 */
	public static function dump( $var ) {
		if ( function_exists( '\ray' ) ) {
			\ray( $var ); // phpcs:ignore
		}
	}
}
