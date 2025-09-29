<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * WP Loupe MCP Server (Phase 1 Skeleton)
 *
 * Responsibilities:
 * - Register .well-known rewrite endpoints for discovery
 * - Provide manifest & protected resource metadata JSON
 * - Expose a /commands REST-like endpoint for MCP commands
 * - Provide auth stub (dev token) and WWW-Authenticate guidance
 *
 * NOTE: This is an initial scaffold; real OAuth validation, rate limiting, and
 * schema/command completeness will be implemented in subsequent steps.
 */
class WP_Loupe_MCP_Server {
	private static $instance = null;
	private const QUERY_VAR               = 'wp_loupe_mcp_wellknown';
	private const TRANSIENT_MANIFEST      = 'wp_loupe_mcp_manifest_v1';
	private const TRANSIENT_RESOURCE_META = 'wp_loupe_mcp_resource_meta_v1';
	/**
	 * Prefix used when persisting issued OAuth access tokens as transients.
	 * We hash the raw token when building the key to avoid leaking the raw value via options table.
	 */
	private const OAUTH_TOKEN_TRANSIENT_PREFIX = 'wp_loupe_mcp_oauth_tok_';
	/** Default lifetime (seconds) for access tokens (client_credentials). */
	private const OAUTH_ACCESS_TOKEN_TTL = 3600; // 1 hour
	/** Minimum length (bytes pre-encoding) for random token entropy. */
	private const OAUTH_TOKEN_BYTES = 32;
	/** Salt context for token HMAC hashing when storing. */
	private const OAUTH_TOKEN_HASH_CONTEXT = 'wp_loupe_mcp_oauth';

	private $search;
	private $indexer; // reserved for future use
	private $schema_manager;
	private $db;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Defer heavy dependencies; rely on loader having included them.
		$this->schema_manager = WP_Loupe_Schema_Manager::get_instance();
		$this->db             = WP_Loupe_DB::get_instance();
		$this->bootstrap();
	}

	private function bootstrap(): void {
		// Respect enable flag; allow CLI context to always load for issuance.
		$enabled = (bool) get_option( 'wp_loupe_mcp_enabled', false );
		if ( ! $enabled && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return; // Do not register any routes or rewrites if disabled.
		}
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_output_wellknown' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		// Ensure rules are present even if flush timing missed (multisite safety net).
		add_filter( 'rewrite_rules_array', [ $this, 'inject_wellknown_rules' ], 5 );
	}

	/**
	 * Inject .well-known rewrite rules at filter level so they persist without relying strictly on a flush.
	 */
	public function inject_wellknown_rules( array $rules ): array {
		$new = [
			'^\.well-known/oauth-protected-resource/?$' => 'index.php?' . self::QUERY_VAR . '=protected',
			'^\.well-known/mcp.json/?$'                 => 'index.php?' . self::QUERY_VAR . '=manifest',
		];
		// Prepend to ensure higher priority.
		return $new + $rules;
	}

	public function add_rewrite_rules(): void {
		// /.well-known/oauth-protected-resource
		add_rewrite_rule( '^\\.well-known/oauth-protected-resource/?$', 'index.php?' . self::QUERY_VAR . '=protected', 'top' );
		// /.well-known/mcp.json
		add_rewrite_rule( '^\\.well-known/mcp.json/?$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_output_wellknown(): void {
		if ( ! (bool) get_option( 'wp_loupe_mcp_enabled', false ) ) {
			return; // Disabled: pretend endpoints do not exist.
		}
		$type = null;
		// Primary path: query var from rewrite.
		if ( get_query_var( self::QUERY_VAR ) ) {
			$type = get_query_var( self::QUERY_VAR );
		} else {
			// Fallback: inspect raw request URI for /.well-known/* if rewrite rule missing.
			$uri = $_SERVER[ 'REQUEST_URI' ] ?? '';
			// Strip query string.
			if ( $uri && ( $qpos = strpos( $uri, '?' ) ) !== false ) {
				$uri = substr( $uri, 0, $qpos );
			}
			$uri = untrailingslashit( $uri );
			if ( preg_match( '#/\.well-known/mcp\.json$#', $uri ) ) {
				$type = 'manifest';
			} elseif ( preg_match( '#/\.well-known/oauth-protected-resource$#', $uri ) ) {
				$type = 'protected';
			}
			if ( $type ) {
				// Fire logging hook for raw fallback usage.
				do_action( 'wp_loupe_mcp_raw_wellknown_fallback', $type, $uri );
			}
		}
		if ( ! $type ) {
			return; // Not our endpoint.
		}
		if ( 'protected' === $type ) {
			// Ensure WP does not keep a 404 status.
			global $wp_query;
			if ( isset( $wp_query ) ) {
				$wp_query->is_404 = false;
			}
			status_header( 200 );
			$data = $this->get_resource_metadata();
			$this->send_json_with_etag( $data, 300, 'wp_loupe_mcp_resource' );
		} elseif ( 'manifest' === $type ) {
			global $wp_query;
			if ( isset( $wp_query ) ) {
				$wp_query->is_404 = false;
			}
			status_header( 200 );
			$data = $this->get_manifest();
			$this->send_json_with_etag( $data, 300, 'wp_loupe_mcp_manifest' );
		}
		exit; // Ensure no theme output appended
	}

	/**
	 * Send JSON with basic ETag / If-None-Match handling.
	 */
	private function send_json_with_etag( $data, int $max_age, string $etag_prefix ): void {
		$encoded = wp_json_encode( $data );
		$etag    = 'W/"' . $etag_prefix . '-' . md5( $encoded ) . '"';
		$client  = isset( $_SERVER[ 'HTTP_IF_NONE_MATCH' ] ) ? trim( wp_unslash( $_SERVER[ 'HTTP_IF_NONE_MATCH' ] ) ) : '';
		if ( $client && $client === $etag ) {
			headers_sent() || header( 'ETag: ' . $etag );
			headers_sent() || header( 'Cache-Control: public, max-age=' . intval( $max_age ) );
			http_response_code( 304 );
			return;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Cache-Control: public, max-age=' . intval( $max_age ) );
			header( 'ETag: ' . $etag );
		}
		echo $encoded;
	}

	private function send_json( $data, int $max_age = 60 ): void {
		// Use core JSON helper
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Cache-Control: public, max-age=' . intval( $max_age ) );
		}
		echo wp_json_encode( $data );
	}

	private function get_manifest(): array {
		$cached = get_transient( self::TRANSIENT_MANIFEST );
		if ( false !== $cached ) {
			// Invalidate older cached manifest lacking new commands.
			if ( empty( $cached[ 'commands' ] ) || ! in_array( 'listCommands', (array) $cached[ 'commands' ], true ) ) {
				delete_transient( self::TRANSIENT_MANIFEST );
			} else {
				return $cached;
			}
		}
		$base     = home_url( '/wp-json/wp-loupe-mcp/v1' );
		$scopes   = [
			'search.read'   => 'Perform search queries',
			'post.read'     => 'Retrieve published post metadata/content',
			'schema.read'   => 'View index schema',
			'health.read'   => 'Check server health',
			'commands.read' => 'List available commands',
		];
		$commands = [ 'searchPosts', 'getPost', 'getSchema', 'healthCheck', 'listCommands' ];
		$manifest = [
			'name'       => 'wp-loupe',
			'version'    => defined( 'WP_LOUPE_MCP_VERSION' ) ? WP_LOUPE_MCP_VERSION : 'dev',
			'canonical'  => untrailingslashit( $base ),
			'commands'   => $commands,
			'resources'  => [ 'post', 'searchResult', 'schema', 'health', 'commandList' ],
			'scopes'     => $scopes,
			'abilities'  => [
				'wp_loupe.search'        => [ 'scope' => 'search.read' ],
				'wp_loupe.post.read'     => [ 'scope' => 'post.read' ],
				'wp_loupe.schema.read'   => [ 'scope' => 'schema.read' ],
				'wp_loupe.health.read'   => [ 'scope' => 'health.read' ],
				'wp_loupe.commands.read' => [ 'scope' => 'commands.read' ],
			],
			'mcpVersion' => 'draft',
		];
		set_transient( self::TRANSIENT_MANIFEST, $manifest, 5 * MINUTE_IN_SECONDS );
		return $manifest;
	}

	/**
	 * Structured metadata for each command: brief description & parameter hints.
	 */
	private function get_commands_metadata(): array {
		return [
			'healthCheck'  => [
				'description' => 'Return plugin / environment health information',
				'params'      => (object) [],
			],
			'getSchema'    => [
				'description' => 'Retrieve index schema details for supported post types',
				'params'      => (object) [],
			],
			'searchPosts'  => [
				'description' => 'Full-text search posts/pages with pagination cursor',
				'params'      => [
					'query'     => 'string (required) search phrase',
					'limit'     => 'int (optional, 1-100, default 10)',
					'cursor'    => 'string (optional) pagination cursor',
					'fields'    => 'string[] optional whitelist of fields (id,title,excerpt,url,content,taxonomies,post_type)',
					'postTypes' => 'string[] optional post types to restrict',
				],
			],
			'getPost'      => [
				'description' => 'Retrieve a single published post by ID with optional field selection',
				'params'      => [
					'id'     => 'int (required) WordPress post ID',
					'fields' => 'string[] optional fields to include',
				],
			],
			'listCommands' => [
				'description' => 'List available MCP commands and their parameter hints',
				'params'      => (object) [],
			],
		];
	}

	private function get_resource_metadata(): array {
		$cached = get_transient( self::TRANSIENT_RESOURCE_META );
		if ( false !== $cached ) {
			return $cached;
		}
		$base     = home_url( '/' );
		$metadata = [
			// RFC9728 fields (subset)
			'resource'              => untrailingslashit( $base ),
			'authorization_servers' => [ home_url( '/wp-loupe-oauth' ) ], // Placeholder
			'scopes_supported'      => [ 'search.read', 'post.read', 'schema.read', 'health.read' ],
			'version'               => defined( 'WP_LOUPE_MCP_VERSION' ) ? WP_LOUPE_MCP_VERSION : 'dev',
		];
		set_transient( self::TRANSIENT_RESOURCE_META, $metadata, 5 * MINUTE_IN_SECONDS );
		return $metadata;
	}

	public function register_rest_routes(): void {
		register_rest_route( 'wp-loupe-mcp/v1', '/commands', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_commands_endpoint' ],
			'permission_callback' => '__return_true', // We'll enforce auth manually inside
		] );

		// OAuth token issuance (client_credentials only for now)
		register_rest_route( 'wp-loupe-mcp/v1', '/oauth/token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_oauth_token_endpoint' ],
			'permission_callback' => '__return_true', // Auth via client credentials in body
		] );

		// Fallback discovery endpoints in case rewrites fail.
		register_rest_route( 'wp-loupe-mcp/v1', '/discovery/manifest', [
			'methods'             => 'GET',
			'callback'            => function () {
				return $this->get_manifest();
			},
			'permission_callback' => '__return_true',
		] );
		register_rest_route( 'wp-loupe-mcp/v1', '/discovery/protected-resource', [
			'methods'             => 'GET',
			'callback'            => function () {
				return $this->get_resource_metadata();
			},
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_commands_endpoint( \WP_REST_Request $request ) {
		// Support internal (programmatic) REST requests where Authorization header is set on the request
		// object but not propagated to the PHP $_SERVER superglobal (e.g., wp eval / rest_do_request).
		$incoming_auth = $request->get_header( 'authorization' );
		if ( $incoming_auth && empty( $_SERVER[ 'HTTP_AUTHORIZATION' ] ) && empty( $_SERVER[ 'REDIRECT_HTTP_AUTHORIZATION' ] ) ) {
			$_SERVER[ 'HTTP_AUTHORIZATION' ] = $incoming_auth; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.OverrideProhibited
		}
		// Hybrid auth: obtain context (may be unauthenticated) or WP_Error.
		$auth_context = $this->maybe_authenticate();
		if ( is_wp_error( $auth_context ) ) {
			return $this->error_response_from_wp_error( $auth_context );
		}

		$body       = $request->get_json_params();
		$command    = $body[ 'command' ] ?? null;
		$params     = $body[ 'params' ] ?? [];
		$request_id = $body[ 'requestId' ] ?? null;

		if ( ! $command ) {
			return $this->envelope_error( 'missing_command', 'Missing command parameter', 400, $request_id );
		}

		// Determine required scopes (protected commands) under hybrid policy.
		$required_scopes_map  = $this->oauth_command_scopes_map();
		$required_for_command = $required_scopes_map[ $command ] ?? [];
		$authenticated        = ! empty( $auth_context[ 'authenticated' ] );
		$granted_scopes       = $auth_context[ 'scopes' ] ?? [];
		// Enforce rules:
		// - healthCheck: MUST be authenticated & have scope.
		// - searchPosts: if authenticated must possess search.read; if anonymous allowed.
		// - other commands in this hybrid model are public (listCommands, getPost, getSchema) but if authenticated and missing required scope we treat as insufficient_scope (future-proofing).
		if ( 'healthCheck' === $command ) {
			if ( ! $authenticated ) {
				return $this->envelope_error( 'missing_token', 'Authentication required for healthCheck', 401, $request_id );
			}
			$missing = array_diff( $required_for_command, $granted_scopes );
			if ( ! empty( $missing ) ) {
				return $this->envelope_error( 'insufficient_scope', 'Missing required scopes: ' . implode( ',', $missing ), 403, $request_id );
			}
		} elseif ( 'searchPosts' === $command && $authenticated ) {
			$missing = array_diff( [ 'search.read' ], $granted_scopes );
			if ( ! empty( $missing ) ) {
				return $this->envelope_error( 'insufficient_scope', 'Missing required scopes: search.read', 403, $request_id );
			}
		} elseif ( $authenticated && ! empty( $required_for_command ) ) {
			$missing = array_diff( $required_for_command, $granted_scopes );
			if ( ! empty( $missing ) ) {
				return $this->envelope_error( 'insufficient_scope', 'Missing required scopes: ' . implode( ',', $missing ), 403, $request_id );
			}
		}

		switch ( $command ) {
			case 'healthCheck':
				return $this->envelope_success( [ 'data' => $this->command_health_check(), 'requestId' => $request_id ] );
			case 'getSchema':
				return $this->envelope_success( [ 'data' => $this->command_get_schema(), 'requestId' => $request_id ] );
			case 'searchPosts':
				$result = $this->command_search_posts( $params, $auth_context );
				if ( is_wp_error( $result ) ) {
					// Add Retry-After header if present in error data.
					$retry = $result->get_error_data()[ 'retry_after' ] ?? 60;
					header( 'Retry-After: ' . intval( $retry ) );
					return $this->envelope_error( $result->get_error_code(), $result->get_error_message(), $result->get_error_data()[ 'status' ] ?? 429, $request_id );
				}
				return $this->envelope_success( [ 'data' => $result, 'requestId' => $request_id ] );
			case 'getPost':
				return $this->envelope_success( [ 'data' => $this->command_get_post( $params ), 'requestId' => $request_id ] );
			case 'listCommands':
				return $this->envelope_success( [ 'data' => $this->get_commands_metadata(), 'requestId' => $request_id ] );
			default:
				return $this->envelope_error( 'unknown_command', 'Unknown command: ' . sanitize_key( $command ), 400, $request_id );
		}
	}

	private function maybe_authenticate() {
		$header = $this->get_authorization_header();
		if ( ! $header ) {
			// Anonymous context (public commands allowed).
			return [ 'authenticated' => false, 'scopes' => [], 'dev' => false ];
		}
		if ( ! preg_match( '/Bearer\s+(.*)$/i', $header, $m ) ) {
			return $this->auth_error( 'invalid_header', 'Malformed Authorization header' );
		}
		$token = trim( $m[ 1 ] );
		// Dev token shortcut.
		if ( defined( 'WP_LOUPE_MCP_DEV_TOKEN' ) && WP_LOUPE_MCP_DEV_TOKEN && hash_equals( WP_LOUPE_MCP_DEV_TOKEN, $token ) ) {
			// Grant all known scopes.
			$scopes = array_keys( $this->get_manifest()[ 'scopes' ] );
			return [ 'authenticated' => true, 'scopes' => $scopes, 'dev' => true ];
		}
		// Validate OAuth token.
		$validation = $this->oauth_validate_bearer( $token );
		if ( is_wp_error( $validation ) ) {
			return $validation; // WP_Error bubbled up.
		}
		return [ 'authenticated' => true, 'scopes' => $validation[ 'scopes' ] ?? [], 'dev' => false ];
	}

	/**
	 * Retrieve the Authorization header in a server-agnostic way (Apache, Nginx, FastCGI, proxies).
	 */
	private function get_authorization_header(): string {
		$header = '';
		if ( isset( $_SERVER[ 'HTTP_AUTHORIZATION' ] ) ) {
			$header = $_SERVER[ 'HTTP_AUTHORIZATION' ];
		} elseif ( isset( $_SERVER[ 'REDIRECT_HTTP_AUTHORIZATION' ] ) ) { // Some Apache/FastCGI setups
			$header = $_SERVER[ 'REDIRECT_HTTP_AUTHORIZATION' ];
		} elseif ( function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			if ( is_array( $all ) ) {
				foreach ( $all as $k => $v ) {
					if ( strtolower( $k ) === 'authorization' ) {
						$header = $v;
						break;
					}
				}
			}
		}
		/**
		 * Allow last-resort override via filter (e.g., custom header translation layer).
		 */
		return apply_filters( 'wp_loupe_mcp_raw_authorization_header', $header );
	}

	private function auth_error( string $code, string $message ) {
		// Standardized OAuth-style header including error_description.
		$this->set_www_authenticate_header( $code, $message, 401 );
		return new \WP_Error( $code, $message, [ 'status' => 401 ] );
	}

	/**
	 * Set a RFC6750-style WWW-Authenticate header for Bearer token errors.
	 *
	 * @param string $error OAuth error code (invalid_token, insufficient_scope, etc.).
	 * @param string $description Human-readable description.
	 * @param int    $status Suggested HTTP status (401 or 403 typically).
	 * @param string $scope Optional required scope list for insufficient_scope.
	 */
	private function set_www_authenticate_header( string $error = '', string $description = '', int $status = 401, string $scope = '' ): void {
		$parts   = [ 'Bearer realm="wp-loupe"' ];
		$parts[] = 'resource_metadata="' . esc_url( home_url( '/.well-known/oauth-protected-resource' ) ) . '"';
		if ( $error ) {
			$parts[] = 'error="' . esc_attr( $error ) . '"';
		}
		if ( $description ) {
			$parts[] = 'error_description="' . esc_attr( $description ) . '"';
		}
		if ( $scope ) {
			$parts[] = 'scope="' . esc_attr( $scope ) . '"';
		}
		$header = implode( ', ', $parts );
		header( 'WWW-Authenticate: ' . $header );
	}

	private function envelope_success( array $payload ) {
		$response = [
			'success'   => true,
			'error'     => null,
			'requestId' => $payload[ 'requestId' ] ?? null,
			'data'      => $payload[ 'data' ] ?? null,
		];
		return rest_ensure_response( $response );
	}

	private function envelope_error( string $code, string $message, int $status = 400, $request_id = null ) {
		$response = [
			'success'   => false,
			'error'     => [ 'code' => $code, 'message' => $message ],
			'requestId' => $request_id,
			'data'      => null,
		];
		// Attach OAuth-style header for relevant auth errors.
		if ( in_array( $code, [ 'invalid_token', 'insufficient_scope', 'invalid_header', 'missing_token' ], true ) && in_array( $status, [ 401, 403 ], true ) ) {
			$scope = '';
			if ( 'insufficient_scope' === $code && preg_match( '/Missing required scopes: (.+)$/', $message, $m ) ) {
				$scope = trim( $m[ 1 ] );
			}
			$this->set_www_authenticate_header( $code, $message, $status, $scope );
		}
		return new \WP_REST_Response( $response, $status );
	}

	private function error_response_from_wp_error( \WP_Error $error ) {
		$status  = $error->get_error_data()[ 'status' ] ?? 400;
		$code    = $error->get_error_code();
		$message = $error->get_error_message();
		// Ensure OAuth auth errors also carry header.
		if ( in_array( $code, [ 'invalid_token', 'insufficient_scope', 'invalid_header', 'missing_token' ], true ) && in_array( $status, [ 401, 403 ], true ) ) {
			// If insufficient_scope, attempt to extract scopes from message.
			$scope = '';
			if ( 'insufficient_scope' === $code && preg_match( '/Missing required scopes: (.+)$/', $message, $m ) ) {
				$scope = trim( $m[ 1 ] );
			}
			$this->set_www_authenticate_header( $code, $message, $status, $scope );
		}
		return $this->envelope_error( $code, $message, $status );
	}

	/* ------------------ Commands ------------------ */

	private function command_health_check(): array {
		$version    = defined( 'WP_LOUPE_MCP_VERSION' ) ? WP_LOUPE_MCP_VERSION : 'dev';
		$has_sqlite = WP_Loupe_Utils::has_sqlite();
		return [
			'version'    => $version,
			'hasSqlite'  => $has_sqlite,
			'phpVersion' => PHP_VERSION,
			'wpVersion'  => get_bloginfo( 'version' ),
			'timestamp'  => gmdate( 'c' ),
		];
	}

	private function command_get_schema(): array {
		$post_types = apply_filters( 'wp_loupe_post_types', [ 'post', 'page' ] );
		$out        = [];
		foreach ( $post_types as $pt ) {
			$schema     = $this->schema_manager->get_schema_for_post_type( $pt );
			$out[ $pt ] = [
				'indexable'  => $this->schema_manager->get_indexable_fields( $schema ),
				'filterable' => $this->schema_manager->get_filterable_fields( $schema ),
				'sortable'   => $this->schema_manager->get_sortable_fields( $schema ),
			];
		}
		return $out;
	}

	private function command_search_posts( array $params, array $auth_context ) {
		$query = isset( $params[ 'query' ] ) ? sanitize_text_field( $params[ 'query' ] ) : '';
		if ( '' === $query ) {
			return [ 'hits' => [], 'tookMs' => 0, 'pageInfo' => [ 'nextCursor' => null ] ];
		}

		// Rate limiting (different buckets for anonymous vs authenticated)
		$rl = $this->enforce_rate_limit( $auth_context );
		if ( is_wp_error( $rl ) ) {
			return $rl; // Propagate to handler to wrap as envelope_error
		}
		$post_types = $params[ 'postTypes' ] ?? apply_filters( 'wp_loupe_post_types', [ 'post', 'page' ] );
		$post_types = array_map( 'sanitize_key', (array) $post_types );

		$requested_limit = isset( $params[ 'limit' ] ) ? intval( $params[ 'limit' ] ) : 10;
		$rl_cfg          = get_option( 'wp_loupe_mcp_rate_limits', [] );
		if ( ! is_array( $rl_cfg ) ) {
			$rl_cfg = [];
		}
		$opt_max_auth   = isset( $rl_cfg[ 'max_search_auth' ] ) ? (int) $rl_cfg[ 'max_search_auth' ] : 100;
		$opt_max_anon   = isset( $rl_cfg[ 'max_search_anon' ] ) ? (int) $rl_cfg[ 'max_search_anon' ] : 10;
		$max_auth_limit = apply_filters( 'wp_loupe_mcp_search_max_limit_auth', $opt_max_auth );
		$max_anon_limit = apply_filters( 'wp_loupe_mcp_search_max_limit_anon', $opt_max_anon );
		$limit_cap      = $auth_context[ 'authenticated' ] ? $max_auth_limit : $max_anon_limit;
		$limit          = min( $limit_cap, max( 1, $requested_limit ) );
		$cursor         = isset( $params[ 'cursor' ] ) ? sanitize_text_field( $params[ 'cursor' ] ) : null;
		$offset         = 0;
		if ( $cursor ) {
			$decoded = $this->decode_cursor( $cursor );
			if ( $decoded && $decoded[ 'q' ] === md5( $query ) ) {
				$offset = intval( $decoded[ 'o' ] );
			}
		}
		$start_time       = microtime( true );
		$search           = new WP_Loupe_Search( $post_types );
		$hits             = $search->search( $query );
		$total            = count( $hits );
		$window           = array_slice( $hits, $offset, $limit );
		$results          = [];
		$requested_fields = isset( $params[ 'fields' ] ) && is_array( $params[ 'fields' ] ) ? array_map( 'sanitize_key', $params[ 'fields' ] ) : [];
		$whitelist        = [ 'id', 'post_type', 'title', 'url', 'excerpt', 'taxonomies', 'content' ]; // extended with optional heavy fields
		$effective_fields = empty( $requested_fields ) ? $whitelist : array_values( array_intersect( $requested_fields, $whitelist ) );

		foreach ( $window as $hit ) {
			if ( empty( $hit[ 'id' ] ) ) {
				continue;
			}
			$post = get_post( $hit[ 'id' ] );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$row = [];
			foreach ( $effective_fields as $field ) {
				switch ( $field ) {
					case 'id':
						$row[ 'id' ] = (int) $post->ID;
						break;
					case 'post_type':
						$row[ 'post_type' ] = $post->post_type;
						break;
					case 'title':
						$row[ 'title' ] = get_the_title( $post );
						break;
					case 'url':
						$row[ 'url' ] = get_permalink( $post );
						break;
					case 'excerpt':
						$row[ 'excerpt' ] = get_the_excerpt( $post );
						break;
					case 'content':
						// Return sanitized content (basic) to avoid scripts.
						$row[ 'content' ] = wp_kses_post( apply_filters( 'the_content', $post->post_content ) );
						break;
					case 'taxonomies':
						$row[ 'taxonomies' ] = $this->collect_post_taxonomies( $post );
						break;
				}
			}
			$results[] = $row;
		}
		$next_offset = $offset + $limit;
		$next_cursor = ( $next_offset < $total ) ? $this->encode_cursor( [ 'o' => $next_offset, 'q' => md5( $query ) ] ) : null;
		$took_ms     = (int) ( ( microtime( true ) - $start_time ) * 1000 );
		return [
			'hits'     => $results,
			'tookMs'   => $took_ms,
			'pageInfo' => [ 'nextCursor' => $next_cursor ],
		];
	}

	private function command_get_post( array $params ): array {
		$id = isset( $params[ 'id' ] ) ? intval( $params[ 'id' ] ) : 0;
		if ( ! $id ) {
			return [];
		}
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return [];
		}
		$requested_fields = isset( $params[ 'fields' ] ) && is_array( $params[ 'fields' ] ) ? array_map( 'sanitize_key', $params[ 'fields' ] ) : [];
		$whitelist        = [ 'id', 'post_type', 'title', 'url', 'excerpt', 'date', 'content', 'taxonomies' ];
		$effective_fields = empty( $requested_fields ) ? $whitelist : array_values( array_intersect( $requested_fields, $whitelist ) );
		$out              = [];
		foreach ( $effective_fields as $field ) {
			switch ( $field ) {
				case 'id':
					$out[ 'id' ] = (int) $post->ID;
					break;
				case 'post_type':
					$out[ 'post_type' ] = $post->post_type;
					break;
				case 'title':
					$out[ 'title' ] = get_the_title( $post );
					break;
				case 'url':
					$out[ 'url' ] = get_permalink( $post );
					break;
				case 'excerpt':
					$out[ 'excerpt' ] = get_the_excerpt( $post );
					break;
				case 'date':
					$out[ 'date' ] = get_post_time( 'c', true, $post );
					break;
				case 'content':
					$out[ 'content' ] = wp_kses_post( apply_filters( 'the_content', $post->post_content ) );
					break;
				case 'taxonomies':
					$out[ 'taxonomies' ] = $this->collect_post_taxonomies( $post );
					break;
			}
		}
		return $out;
	}

	/**
	 * Collect taxonomy terms for a post in a safe, compact structure.
	 */
	private function collect_post_taxonomies( \WP_Post $post ): array {
		$taxes  = get_object_taxonomies( $post->post_type );
		$output = [];
		foreach ( $taxes as $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$output[ $tax ] = array_map( function ( $t ) {
				return [ 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name ];
			}, $terms );
		}
		return $output;
	}

	/**
	 * Enforce a simple rate limit for searchPosts.
	 */
	private function enforce_rate_limit( array $auth_context ) {
		$ip             = $_SERVER[ 'REMOTE_ADDR' ] ?? 'unknown';
		$token_fragment = $auth_context[ 'authenticated' ] ? substr( hash( 'sha256', implode( '-', $auth_context[ 'scopes' ] ) ), 0, 16 ) : 'anon';
		$key            = 'wp_loupe_mcp_rl_' . md5( $ip . '|' . $token_fragment );
		$data           = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = [ 'count' => 0, 'start' => time() ];
		}
		$data[ 'count' ]++;
		// Load saved rate limit configuration (option) with sane fallbacks.
		$cfg = get_option( 'wp_loupe_mcp_rate_limits', [] );
		if ( ! is_array( $cfg ) ) {
			$cfg = [];
		}
		$default_window_auth = isset( $cfg[ 'auth_window' ] ) ? (int) $cfg[ 'auth_window' ] : 60;
		$default_window_anon = isset( $cfg[ 'anon_window' ] ) ? (int) $cfg[ 'anon_window' ] : 60;
		$default_limit_auth  = isset( $cfg[ 'auth_limit' ] ) ? (int) $cfg[ 'auth_limit' ] : 60;
		$default_limit_anon  = isset( $cfg[ 'anon_limit' ] ) ? (int) $cfg[ 'anon_limit' ] : 15;
		$window              = (int) apply_filters( 'wp_loupe_mcp_rate_window_seconds', $auth_context[ 'authenticated' ] ? $default_window_auth : $default_window_anon );
		$limit               = $auth_context[ 'authenticated' ]
			? (int) apply_filters( 'wp_loupe_mcp_search_rate_limit_auth', $default_limit_auth )
			: (int) apply_filters( 'wp_loupe_mcp_search_rate_limit_anon', $default_limit_anon );
		if ( ( time() - $data[ 'start' ] ) > $window ) {
			$data = [ 'count' => 1, 'start' => time() ];
		}
		set_transient( $key, $data, $window );
		$remaining = max( 0, $limit - $data[ 'count' ] );
		header( 'X-RateLimit-Limit: ' . $limit );
		header( 'X-RateLimit-Remaining: ' . $remaining );
		if ( $data[ 'count' ] > $limit ) {
			return new \WP_Error( 'rate_limited', 'Rate limit exceeded', [ 'status' => 429, 'retry_after' => $window ] );
		}
		return true;
	}

	/* ------------------ Cursor Helpers ------------------ */

	private function encode_cursor( array $parts ): string {
		$payload = wp_json_encode( $parts );
		$hmac    = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rtrim( strtr( base64_encode( $payload . '|' . $hmac ), '+/', '-_' ), '=' );
	}

	private function decode_cursor( string $cursor ) {
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( ! $raw || ! str_contains( $raw, '|' ) ) {
			return null;
		}
		list( $payload, $hmac ) = explode( '|', $raw, 2 );
		$calc                   = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $calc, $hmac ) ) {
			return null;
		}
		$data = json_decode( $payload, true );
		return is_array( $data ) ? $data : null;
	}

	/* ------------------ OAuth Token Helpers (Scaffold) ------------------ */

	/**
	 * Issue a new access token for a given client (client_credentials).
	 * For scaffold purposes we accept any non-empty client_id/secret that matches configured constants if defined.
	 *
	 * @param string   $client_id Client identifier.
	 * @param string   $client_secret Client secret.
	 * @param string[] $scopes Requested scopes.
	 * @return array|\WP_Error { access_token, token_type, expires_in, scope }
	 */
	public function oauth_issue_access_token( string $client_id, string $client_secret, array $scopes, int $ttl_seconds = null ) {
		// Optional hard-coded credentials for now (can be replaced with settings page later).
		$conf_id     = defined( 'WP_LOUPE_OAUTH_CLIENT_ID' ) ? WP_LOUPE_OAUTH_CLIENT_ID : 'wp-loupe-local';
		$conf_secret = defined( 'WP_LOUPE_OAUTH_CLIENT_SECRET' ) ? WP_LOUPE_OAUTH_CLIENT_SECRET : '';
		if ( $conf_secret && ( $client_id !== $conf_id || ! hash_equals( $conf_secret, $client_secret ) ) ) {
			return new \WP_Error( 'invalid_client', 'Invalid client credentials', [ 'status' => 401 ] );
		}
		if ( ! $conf_secret && '' === $client_secret ) {
			// Allow empty secret only if no secret defined (dev convenience).
			if ( $client_id !== $conf_id ) {
				return new \WP_Error( 'invalid_client', 'Unknown client_id', [ 'status' => 401 ] );
			}
		}
		$available_scopes = array_keys( $this->get_manifest()[ 'scopes' ] );
		if ( empty( $scopes ) ) {
			$scopes = $available_scopes; // default to all if none specified (could tighten later)
		}
		$valid_requested = array_values( array_intersect( $available_scopes, $scopes ) );
		if ( empty( $valid_requested ) ) {
			return new \WP_Error( 'invalid_scope', 'No valid scopes requested', [ 'status' => 400 ] );
		}
		$token       = $this->oauth_generate_token();
		$hash        = $this->oauth_hash_token( $token );
		$indefinite  = ( isset( $ttl_seconds ) && 0 === $ttl_seconds );
		$ttl_seconds = $indefinite ? 0 : ( ( $ttl_seconds && $ttl_seconds > 0 ) ? min( $ttl_seconds, 168 * HOUR_IN_SECONDS ) : self::OAUTH_ACCESS_TOKEN_TTL ); // cap at 7 days unless 0
		$expires_at  = $indefinite ? 0 : time() + $ttl_seconds;
		$record      = [
			'hash'       => $hash,
			'scopes'     => $valid_requested,
			'client_id'  => $client_id,
			'expires_at' => $expires_at,
			'issued_at'  => time(),
		];
		$this->oauth_store_token_record( $record, $ttl_seconds );
		$this->registry_upsert_token( $record );
		return [
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => $ttl_seconds,
			'scope'        => implode( ' ', $valid_requested ),
		];
	}

	/**
	 * Mirror token metadata in persistent option used by settings UI so CLI-issued tokens appear.
	 */
	private function registry_upsert_token( array $record ): void {
		$registry = get_option( 'wp_loupe_mcp_tokens', [] );
		if ( ! is_array( $registry ) ) {
			$registry = [];
		}
		$hash = $record[ 'hash' ];
		if ( ! isset( $registry[ $hash ] ) ) {
			$registry[ $hash ] = [
				'label'      => '',
				'scopes'     => $record[ 'scopes' ],
				'issued_at'  => $record[ 'issued_at' ] ?? time(),
				'expires_at' => $record[ 'expires_at' ],
				'last_used'  => null,
			];
			update_option( 'wp_loupe_mcp_tokens', $registry );
		}
	}

	/** Generate cryptographically secure random token string. */
	private function oauth_generate_token(): string {
		$bytes = random_bytes( self::OAUTH_TOKEN_BYTES );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/** Stable salted hash of token for storage lookup. */
	private function oauth_hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( self::OAUTH_TOKEN_HASH_CONTEXT ) );
	}

	/** Persist token record in transient keyed by its hash. */
	private function oauth_store_token_record( array $record, int $ttl_seconds = null ): void {
		$key = self::OAUTH_TOKEN_TRANSIENT_PREFIX . $record[ 'hash' ];
		if ( 0 === (int) $record[ 'expires_at' ] ) {
			// Non-expiring token: cache for an extended period (1 year) and treat expires_at=0 logically as indefinite.
			set_transient( $key, $record, YEAR_IN_SECONDS );
			return;
		}
		$ttl_effect = ( $ttl_seconds && $ttl_seconds > 0 ) ? $ttl_seconds : ( (int) $record[ 'expires_at' ] - time() );
		if ( $ttl_effect <= 0 ) {
			$ttl_effect = 60; // fallback minimal cache window
		}
		set_transient( $key, $record, $ttl_effect );
	}

	/** Retrieve token record by raw token value. */
	private function oauth_get_token_record( string $token ) {
		$hash = $this->oauth_hash_token( $token );
		$key  = self::OAUTH_TOKEN_TRANSIENT_PREFIX . $hash;
		$rec  = get_transient( $key );
		return is_array( $rec ) ? $rec : null;
	}

	/** Validate a bearer token and (optionally) required scopes. */
	private function oauth_validate_bearer( string $token, array $required_scopes = [] ) {
		$rec = $this->oauth_get_token_record( $token );
		if ( ! $rec ) {
			return new \WP_Error( 'invalid_token', 'Access token not found', [ 'status' => 401 ] );
		}
		if ( (int) $rec[ 'expires_at' ] !== 0 && time() >= (int) $rec[ 'expires_at' ] ) {
			return new \WP_Error( 'invalid_token', 'Access token expired', [ 'status' => 401 ] );
		}
		if ( ! empty( $required_scopes ) ) {
			$missing = array_diff( $required_scopes, $rec[ 'scopes' ] );
			if ( ! empty( $missing ) ) {
				return new \WP_Error( 'insufficient_scope', 'Missing required scopes: ' . implode( ',', $missing ), [ 'status' => 403 ] );
			}
		}
		// Update last_used in registry (best-effort; do not block on failure).
		$registry = get_option( 'wp_loupe_mcp_tokens', [] );
		if ( is_array( $registry ) ) {
			$hash = $rec[ 'hash' ] ?? $this->oauth_hash_token( $token );
			if ( isset( $registry[ $hash ] ) ) {
				$registry[ $hash ][ 'last_used' ] = time();
				update_option( 'wp_loupe_mcp_tokens', $registry );
			}
		}
		return $rec;
	}

	/** Map command to required scope(s). */
	private function oauth_command_scopes_map(): array {
		return [
			'healthCheck'  => [ 'health.read' ],
			'getSchema'    => [ 'schema.read' ],
			'searchPosts'  => [ 'search.read' ],
			'getPost'      => [ 'post.read' ],
			'listCommands' => [ 'commands.read' ],
		];
	}

	/* ------------------ OAuth Token Endpoint Handler ------------------ */

	/**
	 * Handle /oauth/token (client_credentials grant only).
	 */
	public function handle_oauth_token_endpoint( \WP_REST_Request $request ) {
		// Accept JSON or x-www-form-urlencoded
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		$grant_type = $params[ 'grant_type' ] ?? '';
		if ( 'client_credentials' !== $grant_type ) {
			return $this->oauth_error_response( 'unsupported_grant_type', 'Only client_credentials grant_type is supported', 400 );
		}
		$client_id     = isset( $params[ 'client_id' ] ) ? sanitize_text_field( $params[ 'client_id' ] ) : '';
		$client_secret = isset( $params[ 'client_secret' ] ) ? (string) $params[ 'client_secret' ] : '';
		$scope_raw     = isset( $params[ 'scope' ] ) ? trim( (string) $params[ 'scope' ] ) : '';
		$scopes        = '' === $scope_raw ? [] : preg_split( '/\s+/', $scope_raw );
		if ( ! $client_id ) {
			return $this->oauth_error_response( 'invalid_request', 'client_id required', 400 );
		}
		$token_or_error = $this->oauth_issue_access_token( $client_id, $client_secret, $scopes );
		if ( is_wp_error( $token_or_error ) ) {
			$code   = $token_or_error->get_error_code();
			$desc   = $token_or_error->get_error_message();
			$status = $token_or_error->get_error_data()[ 'status' ] ?? 400;
			return $this->oauth_error_response( $code, $desc, $status );
		}
		return rest_ensure_response( $token_or_error );
	}

	/**
	 * Standard OAuth error response wrapper for token endpoint.
	 */
	private function oauth_error_response( string $error, string $description, int $status ) {
		$realm = 'wp-loupe';
		$www   = sprintf( 'Bearer realm="%s", error="%s", error_description="%s"', esc_attr( $realm ), esc_attr( $error ), esc_attr( $description ) );
		header( 'WWW-Authenticate: ' . $www );
		$body = [ 'error' => $error, 'error_description' => $description ];
		return new \WP_REST_Response( $body, $status );
	}
}
