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

	// Register top-level command if WP_CLI is available.
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
		call_user_func( [ '\\WP_CLI', 'add_command' ], 'wp-loupe mcp', '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_MCP_CLI_Command' );
	}
}
