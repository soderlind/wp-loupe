<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Auto-update opt-in for WP Loupe.
 * Enabled by default; define WP_LOUPE_DISABLE_AUTO_UPDATE to disable.
 *
 * @since 0.5.4
 */
class WP_Loupe_Auto_Update {
	public static function init(): void {
		add_filter( 'auto_update_plugin', [ __CLASS__, 'enable_auto_update' ], 10, 2 );
	}

	/**
	 * Enable auto updates for this plugin unless explicitly disabled.
	 */
	public static function enable_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && $item->plugin === WP_LOUPE_NAME ) {
			// Respect constant-based opt-out first
			if ( defined( 'WP_LOUPE_DISABLE_AUTO_UPDATE' ) && WP_LOUPE_DISABLE_AUTO_UPDATE ) {
				return $update;
			}
			// Then respect user setting (defaults to true if not saved yet)
			$enabled = (bool) get_option( 'wp_loupe_auto_update_enabled', true );
			if ( ! $enabled ) {
				return $update; // Leave core decision (likely false unless user globally enables plugin updates)
			}
			return true;
		}
		return $update;
	}
}

add_action( 'plugins_loaded', [ '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_Auto_Update', 'init' ], 9 );
