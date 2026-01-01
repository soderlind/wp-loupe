<?php
namespace Soderlind\Plugin\WPLoupe; // Reuse plugin namespace

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
	/**
	 * WP-CLI commands for WP Loupe MCP server.
	 */
	class WP_Loupe_MCP_CLI_Command {
		/**
		 * Issue an OAuth access token (client_credentials) for MCP usage.
		 *
		 * ## OPTIONS
		 *
		 * [--client_id=<client_id>]
		 * : Client ID to use. Defaults to value of WP_LOUPE_OAUTH_CLIENT_ID or 'wp-loupe-local'.
		 *
		 * [--client_secret=<client_secret>]
		 * : Client secret (if configured). If omitted and secret is required, command will error.
		 *
		 * [--scopes=<scopes>]
		 * : Space or comma separated list of scopes. Defaults to all available scopes.
		 *
		 * [--format=<format>]
		 * : Output format. One of: json, table, csv. Default: json
		 *
		 * ## EXAMPLES
		 *
		 *   # Issue a token with search + health scopes (JSON output)
		 *   wp wp-loupe mcp issue-token --scopes="search.read health.read"
		 *
		 *   # Issue a token with all scopes in table form
		 *   wp wp-loupe mcp issue-token --format=table
		 */
		public function issue_token( $args, $assoc_args ) {
			$client_id     = $assoc_args[ 'client_id' ] ?? ( defined( 'WP_LOUPE_OAUTH_CLIENT_ID' ) ? WP_LOUPE_OAUTH_CLIENT_ID : 'wp-loupe-local' );
			$client_secret = $assoc_args[ 'client_secret' ] ?? ( defined( 'WP_LOUPE_OAUTH_CLIENT_SECRET' ) ? WP_LOUPE_OAUTH_CLIENT_SECRET : '' );
			$scopes_raw    = $assoc_args[ 'scopes' ] ?? '';
			if ( $scopes_raw ) {
				// Allow comma or space separation
				$scopes = preg_split( '/[\s,]+/', trim( $scopes_raw ) );
			} else {
				$scopes = [];
			}

			$server = WP_Loupe_MCP_Server::get_instance();
			$result = $server->oauth_issue_access_token( $client_id, $client_secret, $scopes );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
				\fwrite( STDERR, 'Error: ' . $result->get_error_message() . "\n" );
				return;
			}

			$format = $assoc_args[ 'format' ] ?? 'json';
			$record = $result; // single record
			// Ensure deterministic key order for JSON (optional)
			$ordered = [
				'access_token' => $record[ 'access_token' ],
				'token_type'   => $record[ 'token_type' ],
				'expires_in'   => $record[ 'expires_in' ],
				'scope'        => $record[ 'scope' ],
			];
			if ( in_array( $format, [ 'table', 'csv' ], true ) ) {
				if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'format_items' ) ) {
					call_user_func( [ '\\WP_CLI\\Utils', 'format_items' ], $format, [ $ordered ], array_keys( $ordered ) );
					return;
				}
				// Fallback: emit JSON one-liner so shell users still parse it.
				echo ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $ordered ) : json_encode( $ordered ) ) . "\n";
				return;
			}
			// Default JSON (machine readable, single line)
			echo ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $ordered ) : json_encode( $ordered ) ) . "\n";
		}
	}

	/**
	 * WP-CLI commands for WP Loupe indexing.
	 */
	class WP_Loupe_CLI_Command {
		/**
		 * Reindex all configured post types in batches.
		 *
		 * ## OPTIONS
		 *
		 * [--post-types=<slugs>]
		 * : Comma or space separated list of post types. Defaults to the plugin setting.
		 *
		 * [--batch-size=<n>]
		 * : Number of posts per batch. Default: 500. Range: 10..2000
		 *
		 * ## EXAMPLES
		 *
		 *   # Reindex all configured post types
		 *   wp wp-loupe reindex
		 *
		 *   # Reindex only posts in bigger batches
		 *   wp wp-loupe reindex --post-types=post --batch-size=1000
		 */
		public function reindex( $args, $assoc_args ) {
			$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 500;
			if ( $batch_size < 10 || $batch_size > 2000 ) {
				$batch_size = 500;
			}

			$post_types = null;
			if ( isset( $assoc_args['post-types'] ) && is_string( $assoc_args['post-types'] ) && trim( $assoc_args['post-types'] ) !== '' ) {
				$post_types = preg_split( '/[\s,]+/', trim( (string) $assoc_args['post-types'] ) );
				$post_types = array_values( array_unique( array_filter( array_map( function ( $v ) {
					return is_string( $v ) ? sanitize_key( $v ) : '';
				}, $post_types ) ) ) );
				if ( empty( $post_types ) ) {
					$post_types = null;
				}
			}

			$indexer = new WP_Loupe_Indexer( null, false );
			$state   = $indexer->reindex_batch_init( $post_types );

			$totals = [];
			$total  = 0;
			if ( isset( $state['post_types'] ) && is_array( $state['post_types'] ) ) {
				foreach ( $state['post_types'] as $pt ) {
					$counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( (string) $pt ) : null;
					$publish = ( is_object( $counts ) && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
					$totals[ (string) $pt ] = $publish;
					$total += $publish;
				}
			}

			\WP_CLI::log( 'WP Loupe: starting batched reindex…' );
			if ( $total > 0 ) {
				\WP_CLI::log( 'Total published posts: ' . $total );
			}

			$last_logged = -1;
			while ( empty( $state['done'] ) ) {
				$state = $indexer->reindex_batch_step( $state, $batch_size );
				$processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
				$idx = isset( $state['idx'] ) ? (int) $state['idx'] : 0;
				$post_types_state = isset( $state['post_types'] ) && is_array( $state['post_types'] ) ? $state['post_types'] : [];
				$current_pt = ( $idx < count( $post_types_state ) ) ? (string) $post_types_state[ $idx ] : null;
				$pt_processed = isset( $state['processed_pt'] ) ? (int) $state['processed_pt'] : 0;

				if ( $processed !== $last_logged ) {
					$last_logged = $processed;
					if ( $total > 0 ) {
						$pct = min( 100, (int) round( ( $processed / $total ) * 100 ) );
						$line = sprintf( 'Progress: %d%% (%d/%d)', $pct, $processed, $total );
					} else {
						$line = sprintf( 'Progress: %d', $processed );
					}
					if ( $current_pt ) {
						$pt_total = isset( $totals[ $current_pt ] ) ? (int) $totals[ $current_pt ] : 0;
						if ( $pt_total > 0 ) {
							$line .= sprintf( ' — %s: %d/%d', $current_pt, $pt_processed, $pt_total );
						} else {
							$line .= sprintf( ' — %s: %d', $current_pt, $pt_processed );
						}
					}
					\WP_CLI::log( $line );
				}
			}

			\WP_CLI::success( 'Reindex completed.' );
		}
	}

	// Register top-level command if WP_CLI is available.
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
		call_user_func( [ '\\WP_CLI', 'add_command' ], 'wp-loupe mcp', '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_MCP_CLI_Command' );
		call_user_func( [ '\WP_CLI', 'add_command' ], 'wp-loupe', '\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_CLI_Command' );
	}
}
