<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Utility functions for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.1
 */
class WP_Loupe_Utils {
	private const REQUIRED_SQLITE_VERSION = '3.35.0';

	/**
	 * Debug logger helper.
	 *
	 * Logs only when WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 * @param string $prefix Optional prefix.
	 * @return void
	 */
	public static function debug_log( $message, $prefix = 'WP Loupe' ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[' . $prefix . '] ' . (string) $message );
	}

	/**
	 * Check if SQLite (PDO) is installed and meets version requirements.
	 *
	 * Loupe 0.13+ requires the PDO SQLite driver and SQLite >= 3.35.0.
	 *
	 * @since 0.0.1
	 * @return boolean True if SQLite is available and meets requirements
	 */
	public static function has_sqlite() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! class_exists( '\\PDO' ) || ! extension_loaded( 'pdo_sqlite' ) ) {
			self::display_error_and_deactivate_plugin(
				__( 'SQLite PDO driver not available', 'wp-loupe' ),
				sprintf(
					/* translators: %s: required sqlite version */
					__( 'WP Loupe requires the pdo_sqlite extension and SQLite version %s or newer.', 'wp-loupe' ),
					self::REQUIRED_SQLITE_VERSION
				)
			);
			return false;
		}

		$drivers = [];
		try {
			$drivers = \PDO::getAvailableDrivers();
		} catch ( \Throwable $e ) {
			$drivers = [];
		}
		if ( ! is_array( $drivers ) || ! in_array( 'sqlite', $drivers, true ) ) {
			self::display_error_and_deactivate_plugin(
				__( 'SQLite PDO driver not available', 'wp-loupe' ),
				sprintf(
					/* translators: %s: required sqlite version */
					__( 'WP Loupe requires the pdo_sqlite extension and SQLite version %s or newer.', 'wp-loupe' ),
					self::REQUIRED_SQLITE_VERSION
				)
			);
			return false;
		}

		$sqlite_version = self::get_sqlite_version_string();
		if ( ! $sqlite_version ) {
			self::display_error_and_deactivate_plugin(
				__( 'Unable to detect SQLite version', 'wp-loupe' ),
				sprintf(
					/* translators: %s: required sqlite version */
					__( 'WP Loupe requires SQLite version %s or newer.', 'wp-loupe' ),
					self::REQUIRED_SQLITE_VERSION
				)
			);
			return false;
		}

		if ( version_compare( $sqlite_version, self::REQUIRED_SQLITE_VERSION, '<' ) ) {
			self::display_error_and_deactivate_plugin(
				__( 'SQLite version too old', 'wp-loupe' ),
				sprintf(
					/* translators: %s: required sqlite version */
					__( 'WP Loupe requires SQLite version %s or newer.', 'wp-loupe' ),
					self::REQUIRED_SQLITE_VERSION
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Returns a short, admin-facing requirements diagnostic line.
	 *
	 * This is informational only and does not deactivate the plugin.
	 *
	 * @return string
	 */
	public static function get_requirements_diagnostic_line(): string {
		$has_pdo        = class_exists( '\\PDO' );
		$has_pdo_sqlite = extension_loaded( 'pdo_sqlite' );
		$has_intl       = extension_loaded( 'intl' );
		$has_mbstring   = extension_loaded( 'mbstring' );

		$sqlite_version = null;
		if ( $has_pdo && $has_pdo_sqlite ) {
			$sqlite_version = self::get_sqlite_version_string();
		} elseif ( class_exists( 'SQLite3' ) ) {
			try {
				$info = \SQLite3::version();
				if ( is_array( $info ) && ! empty( $info['versionString'] ) ) {
					$sqlite_version = (string) $info['versionString'];
				}
			} catch ( \Throwable $e ) {
				$sqlite_version = null;
			}
		}

		$sqlite_ok = ( is_string( $sqlite_version ) && $sqlite_version !== '' )
			? version_compare( $sqlite_version, self::REQUIRED_SQLITE_VERSION, '>=' )
			: false;

		return sprintf(
			/* translators: 1: pdo_sqlite yes/no, 2: sqlite version or ?, 3: required sqlite version, 4: ok/missing, 5: intl yes/no, 6: mbstring yes/no */
			__( 'Requirements: pdo_sqlite=%1$s; SQLite=%2$s (>= %3$s): %4$s; intl=%5$s; mbstring=%6$s', 'wp-loupe' ),
			$has_pdo_sqlite ? 'yes' : 'no',
			$sqlite_version ? $sqlite_version : '?',
			self::REQUIRED_SQLITE_VERSION,
			$sqlite_ok ? 'OK' : 'MISSING/OLD',
			$has_intl ? 'yes' : 'no',
			$has_mbstring ? 'yes' : 'no'
		);
	}

	/**
	 * Returns the SQLite library version.
	 *
	 * Prefers PDO + `SELECT sqlite_version()` to match Loupe's usage.
	 * Falls back to SQLite3::version() when available.
	 *
	 * @return string|null
	 */
	private static function get_sqlite_version_string(): ?string {
		try {
			$pdo = new \PDO( 'sqlite::memory:' );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$stmt = $pdo->query( 'select sqlite_version()' );
			$ver  = $stmt ? $stmt->fetchColumn() : null;
			if ( is_string( $ver ) && $ver !== '' ) {
				return $ver;
			}
		} catch ( \Throwable $e ) {
			// Ignore and fall back.
		}

		if ( class_exists( 'SQLite3' ) ) {
			try {
				$info = \SQLite3::version();
				if ( is_array( $info ) && ! empty( $info['versionString'] ) ) {
					return (string) $info['versionString'];
				}
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Display error message and deactivate plugin
	 *
	 * @since 0.0.1
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
	 * @since 0.0.1
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
	 * @since 0.0.1
	 * @param mixed $var Variable to dump.
	 * @return void
	 */
	public static function dump( $var ) {
		if ( function_exists( '\ray' ) ) {
			// add function name of calling function
			$backtrace = debug_backtrace();
			$caller    = $backtrace[ 1 ][ 'function' ];
			\ray( [ $caller, $var ] ); // phpcs:ignore
			// \ray( $var ); // phpcs:ignore
		}
	}

	// return version number based upon plugin file version
	/**
	 * Get version number based upon plugin version
	 *
	 * @since 0.0.1
	 * @return string Version number
	 */
	public static function get_version_number() {
		$plugin_data = get_plugin_data( WP_LOUPE_FILE );
		$version     = $plugin_data[ 'Version' ];
		return $version;
	}

	/**
	 * Remove transients from the database
	 *
	 * @since  1.0
	 * @param  string $prefix Transient prefix to remove
	 * @return void
	 */
	public static function remove_transient( $prefix ) {

		$matches = self::get_transients( array(
			'search' => $prefix,
			'count'  => false,
			'offset' => 0,
			'number' => 1000,
		) );
		
		foreach ( $matches as $match ) {
			$transient_name = str_replace( '_transient_', '', $match->option_name );
			
			delete_transient( $transient_name );
		}
	}


	/**
	 * Get transients from the database
	 *
	 * These queries are uncached, to prevent race conditions with persistent
	 * object cache setups and the way Transients use them.
	 *
	 * @copyright wpbeginner
	 * @see https://github.com/awesomemotive/Transients-Manager/blob/master/src/TransientsManager.php#L693
	 * @since  1.0
	 * @param  array $args
	 * @return array
	 */
	private static function get_transients( $args = array() ) {
		global $wpdb;

		// Parse arguments
		$r = wp_parse_args( $args, array(
			'offset' => 0,
			'number' => 30,
			'search' => '',
			'count'  => false,
		) );

		// Escape some LIKE parts
		$esc_name = '%' . $wpdb->esc_like( '_transient_' ) . '%';
		$esc_time = '%' . $wpdb->esc_like( '_transient_timeout_' ) . '%';

		// SELECT
		$sql = array( 'SELECT' );

		// COUNT
		if ( ! empty( $r[ 'count' ] ) ) {
			$sql[] = 'count(option_id)';
		} else {
			$sql[] = '*';
		}

		// FROM
		$sql[] = "FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s";

		// Search
		if ( ! empty( $r[ 'search' ] ) ) {
			$search = '%' . $wpdb->esc_like( $r[ 'search' ] ) . '%';
			$sql[]  = $wpdb->prepare( "AND option_name LIKE %s", $search );
		}

		// Limits
		if ( empty( $r[ 'count' ] ) ) {
			$offset = absint( $r[ 'offset' ] );
			$number = absint( $r[ 'number' ] );
			$sql[]  = $wpdb->prepare( "ORDER BY option_id DESC LIMIT %d, %d", $offset, $number );
		}

		// Combine the SQL parts
		$query = implode( ' ', $sql );

		// Prepare
		$prepared = $wpdb->prepare( $query, $esc_name, $esc_time );

		// Query
		$transients = empty( $r[ 'count' ] )
			? $wpdb->get_results( $prepared ) // Rows
			: $wpdb->get_var( $prepared );    // Count

		// Return transients
		return $transients;
	}
}
