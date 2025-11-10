<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * WP Loupe migration routines.
 *
 * Handles schema-related upgrades and field configuration normalization so that
 * the Loupe engine can evolve without fatal indexing errors.
 *
 * Current migrations (0.5.4):
 * - Ensure core field `post_date` exists for all configured post types (prevents
 *   "no such column: post_date" SQLite errors on older installs).
 * - Trigger a reindex to populate the new column (inline for small sites, scheduled
 *   for large ones). Opt-out via WP_LOUPE_DISABLE_AUTO_REINDEX.
 *
 * @since 0.5.4
 */
class WP_Loupe_Migration {

	/**
	 * Entry point for migrations.
	 */
	public static function run(): void {
		// Skip in cron to avoid unexpected long-running operations.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		if ( ! WP_Loupe_Utils::has_sqlite() ) {
			return; // Nothing to migrate without SQLite support.
		}

		self::ensure_post_date_field();
	}

	/**
	 * Adds `post_date` field settings to every post type if missing.
	 */
	private static function ensure_post_date_field(): void {
		$fields = get_option( 'wp_loupe_fields', [] );

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return; // Fresh install; factory will create defaults.
		}

		$changed = false;
		foreach ( $fields as $post_type => &$post_type_fields ) {
			if ( ! is_array( $post_type_fields ) ) {
				continue;
			}
			if ( ! isset( $post_type_fields[ 'post_date' ] ) ) {
				$post_type_fields[ 'post_date' ] = [
					'indexable'      => true,
					'weight'         => 1.0,
					'filterable'     => true,
					'sortable'       => true,
					'sort_direction' => 'desc',
				];
				$changed                         = true;
			}
		}
		unset( $post_type_fields );

		if ( ! $changed ) {
			return; // Migration already applied.
		}

		update_option( 'wp_loupe_fields', $fields );

		// Clear caches so new configuration is used immediately.
		WP_Loupe_Factory::clear_instance_cache();
		WP_Loupe_Schema_Manager::get_instance()->clear_cache();

		if ( defined( 'WP_LOUPE_DISABLE_AUTO_REINDEX' ) && WP_LOUPE_DISABLE_AUTO_REINDEX ) {
			return; // Admin opted out; manual reindex still available.
		}

		// Decide inline vs scheduled reindex.
		$total = 0;
		foreach ( array_keys( $fields ) as $pt ) {
			if ( function_exists( 'wp_count_posts' ) ) {
				$count = wp_count_posts( $pt );
				if ( isset( $count->publish ) ) {
					$total += (int) $count->publish;
				}
			}
		}

		if ( $total <= 2000 ) {
			self::reindex_now( array_keys( $fields ) );
		} else {
			if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
				if ( ! wp_next_scheduled( 'wp_loupe_migration_reindex' ) ) {
					wp_schedule_single_event( time() + 30, 'wp_loupe_migration_reindex', [ array_keys( $fields ) ] );
				}
			}
		}
	}

	/**
	 * Immediate reindex for smaller sites.
	 *
	 * @param array $post_types Post types to reindex.
	 */
	private static function reindex_now( array $post_types ): void {
		try {
			$indexer = new WP_Loupe_Indexer( $post_types );
			$indexer->reindex_all();
		} catch (\Throwable $e) {
			error_log( '[WP Loupe] Reindex failed after post_date migration: ' . $e->getMessage() );
		}
	}

	/**
	 * Ensure new loupe schema columns exist (post_date) after library upgrade.
	 * If missing, alter and schedule reindex.
	 */
	private function ensure_post_date_column() {
		// Only run once per request.
		if ( get_transient( 'wp_loupe_checked_post_date_column' ) ) {
			return;
		}
		set_transient( 'wp_loupe_checked_post_date_column', 1, 300 );

		$service_class = __NAMESPACE__ . '\\WP_Loupe_Index_Service';
		if ( ! class_exists( $service_class ) ) {
			return;
		}
		$svc = $service_class::instance();
		$db  = $svc->get_connection(); // Must return \PDO or similar, adjust if different.

		if ( ! $db instanceof \PDO ) {
			return;
		}

		try {
			$cols  = $db->query( 'PRAGMA table_info(documents)' )->fetchAll( \PDO::FETCH_ASSOC );
			$found = false;
			foreach ( $cols as $c ) {
				if ( isset( $c[ 'name' ] ) && $c[ 'name' ] === 'post_date' ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				// Add column; INTEGER (unix timestamp) nullable.
				$db->exec( 'ALTER TABLE documents ADD COLUMN post_date INTEGER' );
				// Schedule reindex (or immediate for small sites).
				if ( ! defined( 'WP_LOUPE_DISABLE_AUTO_REINDEX' ) ) {
					if ( $this->is_small_site() ) {
						$svc->drop_index();
						$svc->build_index();
					} else {
						wp_schedule_single_event( time() + 30, 'wp_loupe_reindex_async' );
					}
				}
			}
		} catch (\Throwable $e) {
			// Silently ignore; admin can manually rebuild.
		}
	}

	/**
	 * Hook entry.
	 */
	public function maybe_migrate() {
		$this->ensure_post_date_column();
		// existing migration logic ...
	}
}

// Scheduled reindex handler (large sites).
add_action( 'wp_loupe_migration_reindex', function ( $post_types ) {
	if ( ! is_array( $post_types ) ) {
		return;
	}
	try {
		$indexer = new WP_Loupe_Indexer( $post_types );
		$indexer->reindex_all();
	} catch (\Throwable $e) {
		error_log( '[WP Loupe] Scheduled reindex failed after post_date migration: ' . $e->getMessage() );
	}
}, 10, 1 );

// Hook migrations early on plugins_loaded (priority 5 so it runs before other indexing logic at default priority).
add_action( 'plugins_loaded', [ '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_Migration', 'run' ], 5 );
