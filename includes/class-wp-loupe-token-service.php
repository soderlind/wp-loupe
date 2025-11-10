<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Service class handling MCP token & rate limit operations.
 * Extracted from settings page for separation of concerns.
 */
class WP_Loupe_Token_Service {
	/**
	 * Create a new token via MCP server.
	 * @param string $label Optional label
	 * @param array $scopes Array of scope strings
	 * @param int $ttl_hours Hours until expiry (0 = never)
	 * @return array|\WP_Error { access_token, hash, record }
	 */
	public function create_token( $label, array $scopes, $ttl_hours ) {
		$ttl_hours  = (int) $ttl_hours;
		$indefinite = ( 0 === $ttl_hours );
		if ( $ttl_hours > 168 ) {
			$ttl_hours = 168;
		}
		$ttl_seconds = $indefinite ? 0 : ( $ttl_hours * HOUR_IN_SECONDS );

		if ( ! class_exists( '\\Soderlind\\Plugin\\WPLoupe\\WP_Loupe_MCP_Server' ) ) {
			return new \WP_Error( 'no_server', 'MCP server not available' );
		}
		$server = WP_Loupe_MCP_Server::get_instance();
		$result = $server->oauth_issue_access_token( defined( 'WP_LOUPE_OAUTH_CLIENT_ID' ) ? WP_LOUPE_OAUTH_CLIENT_ID : 'wp-loupe-local', defined( 'WP_LOUPE_OAUTH_CLIENT_SECRET' ) ? WP_LOUPE_OAUTH_CLIENT_SECRET : '', $scopes, $ttl_seconds );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$raw_token = $result[ 'access_token' ];
		$hash      = hash_hmac( 'sha256', $raw_token, wp_salt( 'wp_loupe_mcp_oauth' ) );
		$registry  = get_option( 'wp_loupe_mcp_tokens', [] );
		if ( ! is_array( $registry ) ) {
			$registry = [];
		}
		$registry[ $hash ] = [
			'label'      => ( is_string( $label ) ? $label : '' ),
			'scopes'     => $scopes,
			'issued_at'  => time(),
			'expires_at' => $indefinite ? 0 : time() + $ttl_seconds,
			'last_used'  => null,
		];
		update_option( 'wp_loupe_mcp_tokens', $registry );
		update_option( 'wp_loupe_mcp_last_created_token', $raw_token );
		return [ 'access_token' => $raw_token, 'hash' => $hash, 'record' => $registry[ $hash ] ];
	}

	/** Revoke a single token by hash */
	public function revoke_token( $hash ) {
		$registry = get_option( 'wp_loupe_mcp_tokens', [] );
		if ( isset( $registry[ $hash ] ) ) {
			delete_transient( 'wp_loupe_mcp_oauth_tok_' . $hash );
			unset( $registry[ $hash ] );
			update_option( 'wp_loupe_mcp_tokens', $registry );
			return true;
		}
		return false;
	}

	/** Revoke all tokens */
	public function revoke_all_tokens() {
		$registry = get_option( 'wp_loupe_mcp_tokens', [] );
		if ( is_array( $registry ) ) {
			foreach ( array_keys( $registry ) as $hash ) {
				delete_transient( 'wp_loupe_mcp_oauth_tok_' . $hash );
			}
		}
		update_option( 'wp_loupe_mcp_tokens', [] );
	}

	/** Save rate limits with bounds */
	public function save_rate_limits( array $incoming ) {
		$existing                     = get_option( 'wp_loupe_mcp_rate_limits', [] );
		$sanitized                    = [];
		$sanitized[ 'anon_window' ]     = max( 10, min( 3600, intval( $incoming[ 'anon_window' ] ?? ( $existing[ 'anon_window' ] ?? 60 ) ) ) );
		$sanitized[ 'anon_limit' ]      = max( 1, min( 1000, intval( $incoming[ 'anon_limit' ] ?? ( $existing[ 'anon_limit' ] ?? 15 ) ) ) );
		$sanitized[ 'auth_window' ]     = max( 10, min( 3600, intval( $incoming[ 'auth_window' ] ?? ( $existing[ 'auth_window' ] ?? 60 ) ) ) );
		$sanitized[ 'auth_limit' ]      = max( 1, min( 5000, intval( $incoming[ 'auth_limit' ] ?? ( $existing[ 'auth_limit' ] ?? 60 ) ) ) );
		$sanitized[ 'max_search_auth' ] = max( 1, min( 500, intval( $incoming[ 'max_search_auth' ] ?? ( $existing[ 'max_search_auth' ] ?? 100 ) ) ) );
		$sanitized[ 'max_search_anon' ] = max( 1, min( 100, intval( $incoming[ 'max_search_anon' ] ?? ( $existing[ 'max_search_anon' ] ?? 10 ) ) ) );
		update_option( 'wp_loupe_mcp_rate_limits', $sanitized );
		return $sanitized;
	}

	/** Get registry */
	public function get_registry() {
		$registry = get_option( 'wp_loupe_mcp_tokens', [] );
		return is_array( $registry ) ? $registry : [];
	}

	/** Get last created token (and optionally clear) */
	public function pop_last_created_token() {
		$token = get_option( 'wp_loupe_mcp_last_created_token', '' );
		if ( $token ) {
			delete_option( 'wp_loupe_mcp_last_created_token' );
		}
		return $token;
	}
}
