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
		// Auth check
		$auth_error = $this->maybe_authenticate();
		if ( is_wp_error( $auth_error ) ) {
			return $this->error_response_from_wp_error( $auth_error );
		}

		$body       = $request->get_json_params();
		$command    = $body[ 'command' ] ?? null;
		$params     = $body[ 'params' ] ?? [];
		$request_id = $body[ 'requestId' ] ?? null;

		if ( ! $command ) {
			return $this->envelope_error( 'missing_command', 'Missing command parameter', 400, $request_id );
		}

		switch ( $command ) {
			case 'healthCheck':
				return $this->envelope_success( [ 'data' => $this->command_health_check(), 'requestId' => $request_id ] );
			case 'getSchema':
				return $this->envelope_success( [ 'data' => $this->command_get_schema(), 'requestId' => $request_id ] );
			case 'searchPosts':
				$result = $this->command_search_posts( $params );
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
		$header = $_SERVER[ 'HTTP_AUTHORIZATION' ] ?? '';
		if ( ! $header ) {
			return $this->auth_error( 'missing_token', 'Authorization header required' );
		}
		if ( ! preg_match( '/Bearer\s+(.*)$/i', $header, $m ) ) {
			return $this->auth_error( 'invalid_header', 'Malformed Authorization header' );
		}
		$token = trim( $m[ 1 ] );
		if ( defined( 'WP_LOUPE_MCP_DEV_TOKEN' ) && WP_LOUPE_MCP_DEV_TOKEN ) {
			if ( hash_equals( WP_LOUPE_MCP_DEV_TOKEN, $token ) ) {
				return true; // Dev token accepted.
			}
		}
		// Placeholder: real OAuth validation to be added.
		return $this->auth_error( 'invalid_token', 'Token not recognized (dev mode). Set WP_LOUPE_MCP_DEV_TOKEN in wp-config.php for testing.' );
	}

	private function auth_error( string $code, string $message ) {
		$www = 'Bearer realm="wp-loupe", error="' . esc_attr( $code ) . '", resource_metadata="' . esc_url( home_url( '/.well-known/oauth-protected-resource' ) ) . '"';
		header( 'WWW-Authenticate: ' . $www );
		return new \WP_Error( $code, $message, [ 'status' => 401 ] );
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
		return new \WP_REST_Response( $response, $status );
	}

	private function error_response_from_wp_error( \WP_Error $error ) {
		$status = $error->get_error_data()[ 'status' ] ?? 400;
		return $this->envelope_error( $error->get_error_code(), $error->get_error_message(), $status );
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

	private function command_search_posts( array $params ) {
		$query = isset( $params[ 'query' ] ) ? sanitize_text_field( $params[ 'query' ] ) : '';
		if ( '' === $query ) {
			return [ 'hits' => [], 'tookMs' => 0, 'pageInfo' => [ 'nextCursor' => null ] ];
		}

		// Basic rate limiting: 60 requests per 60 seconds per token/IP tuple
		$rl = $this->enforce_rate_limit();
		if ( is_wp_error( $rl ) ) {
			return $rl; // Propagate to handler to wrap as envelope_error
		}
		$post_types = $params[ 'postTypes' ] ?? apply_filters( 'wp_loupe_post_types', [ 'post', 'page' ] );
		$post_types = array_map( 'sanitize_key', (array) $post_types );

		$limit  = isset( $params[ 'limit' ] ) ? min( 100, max( 1, intval( $params[ 'limit' ] ) ) ) : 10;
		$cursor = isset( $params[ 'cursor' ] ) ? sanitize_text_field( $params[ 'cursor' ] ) : null;
		$offset = 0;
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
	private function enforce_rate_limit() {
		$ip     = $_SERVER[ 'REMOTE_ADDR' ] ?? 'unknown';
		$token  = '';
		$header = $_SERVER[ 'HTTP_AUTHORIZATION' ] ?? '';
		if ( preg_match( '/Bearer\s+(.*)$/i', $header, $m ) ) {
			$token = substr( hash( 'sha256', $m[ 1 ] ), 0, 16 );
		}
		$key  = 'wp_loupe_mcp_rl_' . md5( $ip . '|' . $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = [ 'count' => 0, 'start' => time() ];
		}
		$data[ 'count' ]++;
		$window = 60; // seconds
		$limit  = apply_filters( 'wp_loupe_mcp_search_rate_limit', 60 );
		if ( ( time() - $data[ 'start' ] ) > $window ) {
			$data = [ 'count' => 1, 'start' => time() ];
		}
		set_transient( $key, $data, $window );
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
}
