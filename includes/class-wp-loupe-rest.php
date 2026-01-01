<?php
namespace Soderlind\Plugin\WPLoupe;

// Namespaced compatibility shims: allow static analysis / tests without full WP while delegating to global functions when available.
if ( ! function_exists( __NAMESPACE__ . '\register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args ) {
		if ( function_exists( '\register_rest_route' ) ) {
			return \register_rest_route( $namespace, $route, $args );
		}
		return null;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\__' ) ) {
	function __( $text, $domain = null ) {
		if ( function_exists( '\__' ) ) {
			return \__( $text, $domain );
		}
		return $text;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post_id ) {
		return function_exists( '\has_post_thumbnail' ) ? \has_post_thumbnail( $post_id ) : false;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ) {
		return function_exists( '\get_post_thumbnail_id' ) ? \get_post_thumbnail_id( $post_id ) : 0;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) {
		return function_exists( '\wp_get_attachment_image_src' ) ? \wp_get_attachment_image_src( $id, $size ) : false;
	}
}

use Loupe\Loupe\SearchParameters;

/**
 * REST API handler for WP Loupe
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.0.11
 */
class WP_Loupe_REST {

	private $post_types;
	private $loupe = [];
	/** @var object|null Search service with a search($query) method (engine or test stub). */
	private $search_service = null;
	private $db;
	private $schema_manager;
	private $iso6391_lang;
	private const REINDEX_CURSOR_VERSION = 1;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->db             = WP_Loupe_DB::get_instance();
		$this->schema_manager = new WP_Loupe_Schema_Manager();
		$this->iso6391_lang   = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );

		$this->set_post_types();
		$this->init_loupe_instances();
		// Side-effect free engine for REST usage.
		$this->search_service = new WP_Loupe_Search_Engine( $this->post_types, $this->db );
		$this->register_rest_routes();
	}

	/**
	 * Set post types from settings
	 */
	private function set_post_types() {
		$options          = get_option( 'wp_loupe_custom_post_types', [] );
		$this->post_types = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
			? (array) $options[ 'wp_loupe_post_type_field' ]
			: [ 'post', 'page' ];
	}

	/**
	 * Initialize Loupe instances for selected post types
	 */
	private function init_loupe_instances() {
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = WP_Loupe_Factory::create_loupe_instance(
				$post_type,
				$this->iso6391_lang,
				$this->db
			);
		}
	}

	/**
	 * Register REST API routes
	 */
	private function register_rest_routes() {
		add_action( 'rest_api_init', [ $this, 'on_rest_api_init' ] );
		// Defensive: if this instance is created after rest_api_init already fired (edge cases), register immediately.
		if ( did_action( 'rest_api_init' ) ) {
			$this->log( 'rest_api_init already fired – registering routes immediately.' );
			$this->on_rest_api_init();
		} else {
			$this->log( 'Hooked into rest_api_init for route registration.' );
		}
	}

	/**
	 * Hook callback to actually register routes (avoids anonymous closure for easier introspection/testing).
	 */
	public function on_rest_api_init() {
		$this->log( 'Registering routes.' );
		register_rest_route( 'wp-loupe/v1', '/search', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_search_request' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'q'         => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_type' => [
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'per_page'  => [
						'default'           => 10,
						'sanitize_callback' => 'absint',
					],
					'page'      => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_search_request_post' ],
				'permission_callback' => '__return_true',
				// POST uses a JSON body and has a richer schema; validate in the handler.
				'args'                => [],
			],
		] );

		// Dynamic field discovery for settings UI.
		register_rest_route( 'wp-loupe/v1', '/post-type-fields/(?P<post_type>[a-zA-Z0-9_-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_post_type_fields_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Create database (directory + option update) for a post type.
		register_rest_route( 'wp-loupe/v1', '/create-database', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_create_database_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Delete database for a post type.
		register_rest_route( 'wp-loupe/v1', '/delete-database', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_delete_database_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Batched reindex runner (admin only).
		register_rest_route( 'wp-loupe/v1', '/reindex-batch', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_reindex_batch_request' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'cursor'     => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'reset'      => [
					'required'          => false,
					'sanitize_callback' => function ( $v ) {
						return (bool) $v;
					},
				],
				'batch_size' => [
					'required'          => false,
					'sanitize_callback' => 'absint',
				],
				'post_types' => [
					'required' => false,
				],
			],
		] );
		$this->log( 'Routes registered.' );
	}

	/**
	 * Run a single batched reindex step.
	 *
	 * POST /wp-json/wp-loupe/v1/reindex-batch
	 *
	 * @param mixed $request Request object implementing typical WP_REST_Request methods.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_reindex_batch_request( $request ) {
		$cursor     = '';
		$reset      = false;
		$batch_size = 500;
		$post_types = null;

		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$cursor = (string) $request->get_param( 'cursor' );
			$reset  = (bool) $request->get_param( 'reset' );
			$batch_size = (int) $request->get_param( 'batch_size' );
			$post_types = $request->get_param( 'post_types' );
		}
		if ( $batch_size < 10 || $batch_size > 2000 ) {
			$batch_size = 500;
		}

		if ( is_array( $post_types ) ) {
			$post_types = array_values( array_unique( array_filter( array_map( function ( $v ) {
				return is_string( $v ) ? sanitize_key( $v ) : '';
			}, $post_types ) ) ) );
			if ( empty( $post_types ) ) {
				$post_types = null;
			}
		} else {
			$post_types = null;
		}

		$state = null;
		if ( ! $reset && $cursor ) {
			$decoded = $this->decode_reindex_cursor( $cursor );
			if ( is_array( $decoded ) ) {
				$state = $decoded;
			}
		}

		$indexer = new WP_Loupe_Indexer( null, false );
		if ( ! is_array( $state ) ) {
			$state = $indexer->reindex_batch_init( $post_types );
			$state = $this->add_totals_to_reindex_state( $state );
		}

		try {
			$state = $indexer->reindex_batch_step( $state, $batch_size );
		} catch (\Throwable $e) {
			return new \WP_Error( 'wp_loupe_reindex_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		$done = ! empty( $state['done'] );
		$next_cursor = $done ? null : $this->encode_reindex_cursor( $state );

		$idx = isset( $state['idx'] ) ? (int) $state['idx'] : 0;
		$post_types_state = isset( $state['post_types'] ) && is_array( $state['post_types'] ) ? $state['post_types'] : [];
		$current_pt = ( $idx < count( $post_types_state ) ) ? (string) $post_types_state[ $idx ] : null;

		return rest_ensure_response( [
			'done'             => $done,
			'cursor'           => $next_cursor,
			'processed'        => isset( $state['processed'] ) ? (int) $state['processed'] : 0,
			'processedPostType'=> isset( $state['processed_pt'] ) ? (int) $state['processed_pt'] : 0,
			'currentPostType'  => $current_pt,
			'totals'           => isset( $state['totals'] ) ? $state['totals'] : null,
			'total'            => isset( $state['total'] ) ? (int) $state['total'] : null,
		] );
	}

	private function add_totals_to_reindex_state( array $state ): array {
		$post_types = isset( $state['post_types'] ) && is_array( $state['post_types'] ) ? $state['post_types'] : [];
		$totals = [];
		$total = 0;
		foreach ( $post_types as $pt ) {
			$pt = is_string( $pt ) ? sanitize_key( $pt ) : '';
			if ( '' === $pt ) {
				continue;
			}
			$counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( $pt ) : null;
			$publish = 0;
			if ( is_object( $counts ) && isset( $counts->publish ) ) {
				$publish = (int) $counts->publish;
			}
			$totals[ $pt ] = $publish;
			$total += $publish;
		}
		$state['totals'] = $totals;
		$state['total']  = $total;
		return $state;
	}

	/* ------------------ Reindex Cursor Helpers ------------------ */

	private function encode_reindex_cursor( array $state ): string {
		$payload = wp_json_encode( [ 'v' => self::REINDEX_CURSOR_VERSION, 's' => $state ] );
		$hmac    = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return rtrim( strtr( base64_encode( $payload . '|' . $hmac ), '+/', '-_' ), '=' );
	}

	private function decode_reindex_cursor( string $cursor ) {
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( ! $raw || ! str_contains( $raw, '|' ) ) {
			return null;
		}
		list( $payload, $hmac ) = explode( '|', $raw, 2 );
		$calc = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $calc, $hmac ) ) {
			return null;
		}
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || ! isset( $data['v'], $data['s'] ) ) {
			return null;
		}
		if ( (int) $data['v'] !== self::REINDEX_CURSOR_VERSION ) {
			return null;
		}
		return is_array( $data['s'] ) ? $data['s'] : null;
	}

	/**
	 * Advanced search handler (POST /search).
	 *
	 * Supports JSON filters, facets, geo distance and explicit sorting while keeping
	 * the existing GET endpoint stable.
	 *
	 * @param mixed $request Request object implementing typical WP_REST_Request methods.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_search_request_post( $request ) {
		$payload = $this->get_json_body( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'wp_loupe_invalid_payload', __( 'Invalid JSON body.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		$q = isset( $payload[ 'q' ] ) ? (string) $payload[ 'q' ] : '';
		$q = trim( $q );
		if ( '' === $q ) {
			return new \WP_Error( 'wp_loupe_missing_query', __( 'Missing or empty query parameter "q".', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		// Pagination: page.number + page.size
		$page_number = 1;
		$page_size   = 10;
		if ( isset( $payload[ 'page' ] ) && is_array( $payload[ 'page' ] ) ) {
			if ( isset( $payload[ 'page' ][ 'number' ] ) ) {
				$page_number = max( 1, (int) $payload[ 'page' ][ 'number' ] );
			}
			if ( isset( $payload[ 'page' ][ 'size' ] ) ) {
				$page_size = (int) $payload[ 'page' ][ 'size' ];
			}
		}
		if ( $page_size < 1 || $page_size > 100 ) {
			return new \WP_Error( 'wp_loupe_invalid_page_size', __( 'Invalid page.size. Must be between 1 and 100.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		$offset      = max( 0, ( $page_number - 1 ) * $page_size );
		$fetch_count = $offset + $page_size;
		// Loupe has an internal max limit; keep this endpoint predictable.
		if ( $fetch_count > 1000 ) {
			return new \WP_Error( 'wp_loupe_pagination_limit', __( 'Requested page is too deep. Reduce page.number/page.size.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		$post_types_raw       = $payload[ 'postTypes' ] ?? 'all';
		$post_types_to_search = [];

		// Resolve and validate post types against configured ones and index readiness.
		// Do not rely on an injected search service (tests may stub it).
		$base_engine = new WP_Loupe_Search_Engine( $this->post_types, $this->db );

		if ( 'all' === $post_types_raw ) {
			foreach ( $this->post_types as $pt ) {
				$status = $base_engine->is_index_ready( $pt );
				if ( ! empty( $status[ 'ready' ] ) ) {
					$post_types_to_search[] = $pt;
				}
			}
			if ( empty( $post_types_to_search ) ) {
				return new \WP_Error( 'wp_loupe_no_indexed_post_types', __( 'No indexed post types are available for search.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
		} elseif ( is_array( $post_types_raw ) ) {
			$post_types_raw = array_values( array_unique( array_filter( array_map( function ( $v ) {
				return is_string( $v ) ? sanitize_key( $v ) : '';
			}, $post_types_raw ) ) ) );
			if ( empty( $post_types_raw ) ) {
				return new \WP_Error( 'wp_loupe_invalid_post_types', __( 'postTypes must be "all" or a non-empty array of post type slugs.', 'wp-loupe' ), [ 'status' => 400 ] );
			}

			$unknown = array_values( array_diff( $post_types_raw, $this->post_types ) );
			if ( ! empty( $unknown ) ) {
				return new \WP_Error( 'wp_loupe_invalid_post_type', __( 'Invalid post type in postTypes.', 'wp-loupe' ), [ 'status' => 400, 'details' => [ 'unknown' => $unknown ] ] );
			}

			foreach ( $post_types_raw as $pt ) {
				$status = $base_engine->is_index_ready( $pt );
				if ( empty( $status[ 'ready' ] ) ) {
					$reason = $status[ 'reason' ] ?? 'index_missing';
					if ( 'index_needs_reindex' === $reason ) {
						return new \WP_Error( 'wp_loupe_index_needs_reindex', sprintf( __( 'Search index schema is out of date for post type "%s". Rebuild required.', 'wp-loupe' ), $pt ), [ 'status' => 400, 'details' => [ 'postType' => $pt, 'reason' => $reason ] ] );
					}
					return new \WP_Error( 'wp_loupe_index_missing', sprintf( __( 'Search index not found for post type "%s".', 'wp-loupe' ), $pt ), [ 'status' => 400, 'details' => [ 'postType' => $pt, 'reason' => $reason ] ] );
				}
				$post_types_to_search[] = $pt;
			}
		} else {
			return new \WP_Error( 'wp_loupe_invalid_post_types', __( 'postTypes must be "all" or an array of post type slugs.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		// Build common allowlists across all requested post types.
		$allow      = $this->get_common_allows( $post_types_to_search );
		$filterable = $allow[ 'filterable' ];
		$sortable   = $allow[ 'sortable' ];

		// Facets
		$facet_specs      = isset( $payload[ 'facets' ] ) ? $payload[ 'facets' ] : [];
		$requested_facets = [];
		$facet_meta       = [];
		if ( ! empty( $facet_specs ) ) {
			if ( ! is_array( $facet_specs ) ) {
				return new \WP_Error( 'wp_loupe_invalid_facets', __( 'facets must be an array.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			foreach ( $facet_specs as $spec ) {
				if ( ! is_array( $spec ) || ( $spec[ 'type' ] ?? null ) !== 'terms' ) {
					return new \WP_Error( 'wp_loupe_invalid_facets', __( 'Only terms facets are supported.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				$field = isset( $spec[ 'field' ] ) ? (string) $spec[ 'field' ] : '';
				if ( ! WP_Loupe_Search_Engine::is_valid_loupe_attribute_name( $field ) ) {
					return new \WP_Error( 'wp_loupe_invalid_facet_field', __( 'Invalid facet field name.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				if ( ! in_array( $field, $filterable, true ) ) {
					return new \WP_Error( 'wp_loupe_unallowlisted_field', __( 'Facet field is not allowlisted as filterable for the requested post types.', 'wp-loupe' ), [ 'status' => 400, 'details' => [ 'field' => $field ] ] );
				}
				$size                 = isset( $spec[ 'size' ] ) ? (int) $spec[ 'size' ] : 10;
				$min_count            = isset( $spec[ 'minCount' ] ) ? (int) $spec[ 'minCount' ] : 1;
				$size                 = max( 1, min( 50, $size ) );
				$min_count            = max( 1, $min_count );
				$requested_facets[]   = $field;
				$facet_meta[ $field ] = [ 'size' => $size, 'minCount' => $min_count ];
			}
			$requested_facets = array_values( array_unique( $requested_facets ) );
		}

		// Geo
		$geo                  = isset( $payload[ 'geo' ] ) ? $payload[ 'geo' ] : null;
		$geo_field            = null;
		$geo_lat              = null;
		$geo_lon              = null;
		$geo_radius           = null;
		$geo_sort_order       = null;
		$geo_include_distance = false;
		if ( null !== $geo ) {
			if ( ! is_array( $geo ) ) {
				return new \WP_Error( 'wp_loupe_invalid_geo', __( 'geo must be an object.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			$geo_field = isset( $geo[ 'field' ] ) ? (string) $geo[ 'field' ] : '';
			if ( ! WP_Loupe_Search_Engine::is_valid_loupe_attribute_name( $geo_field ) ) {
				return new \WP_Error( 'wp_loupe_invalid_geo_field', __( 'Invalid geo field name.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			// Geo filter uses Loupe filter parser, which requires the geo attribute to be filterable.
			if ( ! in_array( $geo_field, $filterable, true ) ) {
				return new \WP_Error( 'wp_loupe_unallowlisted_field', __( 'Geo field must be allowlisted as filterable for the requested post types.', 'wp-loupe' ), [ 'status' => 400, 'details' => [ 'field' => $geo_field ] ] );
			}
			if ( empty( $geo[ 'near' ] ) || ! is_array( $geo[ 'near' ] ) ) {
				return new \WP_Error( 'wp_loupe_invalid_geo', __( 'geo.near is required and must be an object.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			$geo_lat = isset( $geo[ 'near' ][ 'lat' ] ) ? (float) $geo[ 'near' ][ 'lat' ] : null;
			$geo_lon = isset( $geo[ 'near' ][ 'lon' ] ) ? (float) $geo[ 'near' ][ 'lon' ] : null;
			if ( ! is_numeric( $geo_lat ) || ! is_numeric( $geo_lon ) || $geo_lat < -90 || $geo_lat > 90 || $geo_lon < -180 || $geo_lon > 180 ) {
				return new \WP_Error( 'wp_loupe_invalid_geo', __( 'Invalid geo.near coordinates.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			if ( isset( $geo[ 'radiusMeters' ] ) ) {
				$geo_radius = (float) $geo[ 'radiusMeters' ];
				if ( $geo_radius <= 0 ) {
					return new \WP_Error( 'wp_loupe_invalid_geo', __( 'geo.radiusMeters must be > 0.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
			}
			if ( isset( $geo[ 'sort' ] ) ) {
				if ( ! is_array( $geo[ 'sort' ] ) ) {
					return new \WP_Error( 'wp_loupe_invalid_geo', __( 'geo.sort must be an object.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				$geo_sort_order = isset( $geo[ 'sort' ][ 'order' ] ) ? strtolower( (string) $geo[ 'sort' ][ 'order' ] ) : 'asc';
				if ( ! in_array( $geo_sort_order, [ 'asc', 'desc' ], true ) ) {
					return new \WP_Error( 'wp_loupe_invalid_geo', __( 'geo.sort.order must be asc or desc.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				// Geo point sorting in Loupe requires the attribute to be sortable.
				if ( ! in_array( $geo_field, $sortable, true ) ) {
					return new \WP_Error( 'wp_loupe_unallowlisted_field', __( 'Geo field must be allowlisted as sortable to sort by distance.', 'wp-loupe' ), [ 'status' => 400, 'details' => [ 'field' => $geo_field ] ] );
				}
			}
			$geo_include_distance = ! empty( $geo[ 'includeDistance' ] );
		}

		// Filter AST
		$filter_node = $payload[ 'filter' ] ?? null;
		$filter_str  = '';
		if ( null !== $filter_node ) {
			$filter_str = $this->build_filter_string( $filter_node, $filterable );
			if ( is_wp_error( $filter_str ) ) {
				return $filter_str;
			}
		}

		// Geo radius filter is appended via AND.
		if ( null !== $geo && null !== $geo_radius ) {
			$geo_filter = sprintf( '_geoRadius(%s, %s, %s, %s)', $geo_field, (string) $geo_lat, (string) $geo_lon, (string) $geo_radius );
			$filter_str = $filter_str ? ( '(' . $filter_str . ') AND ' . $geo_filter ) : $geo_filter;
		}

		// Sort
		$sort_specs   = $payload[ 'sort' ] ?? [];
		$sort_strings = [];
		if ( ! empty( $sort_specs ) ) {
			if ( ! is_array( $sort_specs ) ) {
				return new \WP_Error( 'wp_loupe_invalid_sort', __( 'sort must be an array.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			foreach ( $sort_specs as $spec ) {
				if ( ! is_array( $spec ) ) {
					return new \WP_Error( 'wp_loupe_invalid_sort', __( 'Invalid sort spec.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				$by    = isset( $spec[ 'by' ] ) ? (string) $spec[ 'by' ] : '';
				$order = isset( $spec[ 'order' ] ) ? strtolower( (string) $spec[ 'order' ] ) : 'desc';
				if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
					return new \WP_Error( 'wp_loupe_invalid_sort', __( 'Invalid sort order.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				if ( '_score' === $by ) {
					$sort_strings[] = "_relevance:{$order}";
					continue;
				}
				if ( ! WP_Loupe_Search_Engine::is_valid_loupe_attribute_name( $by ) ) {
					return new \WP_Error( 'wp_loupe_invalid_sort', __( 'Invalid sort field name.', 'wp-loupe' ), [ 'status' => 400 ] );
				}
				if ( ! in_array( $by, $sortable, true ) ) {
					return new \WP_Error( 'wp_loupe_unallowlisted_field', __( 'Sort field is not allowlisted as sortable for the requested post types.', 'wp-loupe' ), [ 'status' => 400, 'details' => [ 'field' => $by ] ] );
				}
				$sort_strings[] = "{$by}:{$order}";
			}
		}

		// Apply geo distance sorting as highest priority if requested.
		if ( null !== $geo && null !== $geo_sort_order ) {
			array_unshift( $sort_strings, sprintf( '_geoPoint(%s, %s, %s):%s', $geo_field, (string) $geo_lat, (string) $geo_lon, $geo_sort_order ) );
		}

		// Always include score.
		$attrs_to_retrieve = [ 'id' ];
		// Ensure sort fields are retrievable so we can merge/sort across post types if needed.
		foreach ( $sort_specs as $spec ) {
			if ( is_array( $spec ) && isset( $spec[ 'by' ] ) && is_string( $spec[ 'by' ] ) ) {
				$by = (string) $spec[ 'by' ];
				if ( '_score' !== $by && WP_Loupe_Search_Engine::is_valid_loupe_attribute_name( $by ) ) {
					$attrs_to_retrieve[] = $by;
				}
			}
		}
		if ( null !== $geo && $geo_include_distance ) {
			$attrs_to_retrieve[] = sprintf( '_geoDistance(%s)', $geo_field );
		}
		$attrs_to_retrieve = array_values( array_unique( $attrs_to_retrieve ) );

		$engine = ( count( $post_types_to_search ) === count( $this->post_types ) )
			? $this->search_service
			: new WP_Loupe_Search_Engine( $post_types_to_search, $this->db );

		$raw = $engine->search_advanced( $q, [
			'filter'               => $filter_str,
			'sort'                 => $sort_strings,
			'facets'               => $requested_facets,
			'limit'                => $fetch_count,
			'attributesToRetrieve' => $attrs_to_retrieve,
		] );

		$raw_hits = isset( $raw[ 'hits' ] ) && is_array( $raw[ 'hits' ] ) ? $raw[ 'hits' ] : [];
		// Enforce allowed post types.
		$allowed  = array_flip( $post_types_to_search );
		$raw_hits = array_values( array_filter( $raw_hits, function ( $hit ) use ( $allowed ) {
			return is_array( $hit ) && isset( $hit[ 'post_type' ] ) && isset( $allowed[ $hit[ 'post_type' ] ] );
		} ) );

		// Extract distance field if requested.
		if ( null !== $geo && $geo_include_distance ) {
			$distance_key = sprintf( '_geoDistance(%s)', $geo_field );
			foreach ( $raw_hits as &$hit ) {
				if ( is_array( $hit ) && isset( $hit[ $distance_key ] ) ) {
					$hit[ '_distanceMeters' ] = (int) $hit[ $distance_key ];
				}
			}
			unset( $hit );
		}

		// Merge-level sorting across post types.
		$raw_hits = $this->sort_raw_hits( $raw_hits, $payload, $geo_field );

		$total_hits = isset( $raw[ 'totalHits' ] ) ? (int) $raw[ 'totalHits' ] : count( $raw_hits );
		$paged_raw  = array_slice( $raw_hits, $offset, $page_size );

		$enriched = [];
		foreach ( $paged_raw as $hit ) {
			if ( empty( $hit[ 'id' ] ) || empty( $hit[ 'post_type' ] ) ) {
				continue;
			}
			$post_id  = (int) $hit[ 'id' ];
			$ptype    = (string) $hit[ 'post_type' ];
			$post_obj = get_post( $post_id );
			if ( ! $post_obj || 'publish' !== $post_obj->post_status ) {
				continue;
			}
			$ptype_obj = get_post_type_object( $ptype );
			$row       = [
				'id'              => $post_id,
				'title'           => get_the_title( $post_id ),
				'url'             => get_permalink( $post_id ),
				'post_type'       => $ptype,
				'post_type_label' => $ptype_obj && isset( $ptype_obj->labels->singular_name ) ? $ptype_obj->labels->singular_name : $ptype,
				'excerpt'         => get_the_excerpt( $post_id ),
				'_score'          => isset( $hit[ '_score' ] ) ? (float) $hit[ '_score' ] : 0.0,
			];
			if ( isset( $hit[ '_distanceMeters' ] ) ) {
				$row[ '_distanceMeters' ] = (int) $hit[ '_distanceMeters' ];
			}
			$enriched[] = $row;
		}

		$response = [
			'hits'       => $enriched,
			'pagination' => [
				'total'        => $total_hits,
				'per_page'     => $page_size,
				'current_page' => $page_number,
				'total_pages'  => ( $page_size > 0 ) ? (int) ceil( $total_hits / $page_size ) : 0,
			],
			'tookMs'     => isset( $raw[ 'processingTimeMs' ] ) ? (int) $raw[ 'processingTimeMs' ] : 0,
		];

		// Facet formatting
		if ( ! empty( $requested_facets ) && isset( $raw[ 'facetDistribution' ] ) && is_array( $raw[ 'facetDistribution' ] ) ) {
			$response[ 'facets' ] = $this->format_facet_distribution( $raw[ 'facetDistribution' ], $facet_meta );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Extract JSON body for POST requests.
	 *
	 * @param mixed $request
	 * @return array|null
	 */
	private function get_json_body( $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$params = $request->get_json_params();
			if ( is_array( $params ) ) {
				return $params;
			}
		}
		if ( is_object( $request ) && method_exists( $request, 'get_body_params' ) ) {
			$params = $request->get_body_params();
			if ( is_array( $params ) ) {
				return $params;
			}
		}
		// Fallback for tests using a stub that only supports get_param().
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$q = $request->get_param( 'q' );
			return is_string( $q ) ? [ 'q' => $q ] : [];
		}
		return null;
	}

	/**
	 * Compute the intersection of filterable/sortable fields across post types.
	 *
	 * @param array<int,string> $post_types
	 * @return array{filterable:array<int,string>, sortable:array<int,string>}
	 */
	private function get_common_allows( array $post_types ) {
		$filterable_sets = [];
		$sortable_sets   = [];
		foreach ( $post_types as $pt ) {
			$schema            = $this->schema_manager->get_schema_for_post_type( $pt );
			$filterable_sets[] = $this->schema_manager->get_filterable_fields( $schema );
			$sortable_fields   = $this->schema_manager->get_sortable_fields( $schema );
			$sortable_sets[]   = array_map( function ( $row ) {
				return is_array( $row ) && isset( $row[ 'field' ] ) ? $row[ 'field' ] : null;
			}, $sortable_fields );
		}

		$filterable = $this->intersect_string_lists( $filterable_sets );
		$sortable   = $this->intersect_string_lists( $sortable_sets );

		// Only keep Loupe-compatible attribute names.
		$filterable = array_values( array_filter( $filterable, [ WP_Loupe_Search_Engine::class, 'is_valid_loupe_attribute_name' ] ) );
		$sortable   = array_values( array_filter( $sortable, [ WP_Loupe_Search_Engine::class, 'is_valid_loupe_attribute_name' ] ) );

		return [
			'filterable' => $filterable,
			'sortable'   => $sortable,
		];
	}

	/**
	 * @param array<int,array<int|string,mixed>> $sets
	 * @return array<int,string>
	 */
	private function intersect_string_lists( array $sets ) {
		$lists = [];
		foreach ( $sets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}
			$lists[] = array_values( array_filter( array_map( function ( $v ) {
				return is_string( $v ) ? $v : null;
			}, $set ) ) );
		}
		if ( empty( $lists ) ) {
			return [];
		}
		$acc = array_shift( $lists );
		foreach ( $lists as $l ) {
			$acc = array_values( array_intersect( $acc, $l ) );
		}
		return array_values( array_unique( $acc ) );
	}

	/**
	 * Build a Loupe filter string from the canonical JSON filter AST.
	 *
	 * @param mixed $node
	 * @param array<int,string> $allowlisted_fields
	 * @return string|\WP_Error
	 */
	private function build_filter_string( $node, array $allowlisted_fields ) {
		if ( ! is_array( $node ) || empty( $node[ 'type' ] ) || ! is_string( $node[ 'type' ] ) ) {
			return new \WP_Error( 'wp_loupe_invalid_filter', __( 'Invalid filter node.', 'wp-loupe' ), [ 'status' => 400 ] );
		}
		$type = $node[ 'type' ];
		if ( 'and' === $type || 'or' === $type ) {
			if ( ! isset( $node[ 'items' ] ) || ! is_array( $node[ 'items' ] ) || empty( $node[ 'items' ] ) ) {
				return new \WP_Error( 'wp_loupe_invalid_filter', __( 'Filter group items must be a non-empty array.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			$parts = [];
			foreach ( $node[ 'items' ] as $child ) {
				$child_str = $this->build_filter_string( $child, $allowlisted_fields );
				if ( is_wp_error( $child_str ) ) {
					return $child_str;
				}
				$parts[] = '(' . $child_str . ')';
			}
			$glue = ( 'and' === $type ) ? ' AND ' : ' OR ';
			return implode( $glue, $parts );
		}
		if ( 'not' === $type ) {
			if ( ! isset( $node[ 'item' ] ) ) {
				return new \WP_Error( 'wp_loupe_invalid_filter', __( 'not filter node requires item.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			$child_str = $this->build_filter_string( $node[ 'item' ], $allowlisted_fields );
			if ( is_wp_error( $child_str ) ) {
				return $child_str;
			}
			return 'NOT (' . $child_str . ')';
		}
		if ( 'pred' !== $type ) {
			return new \WP_Error( 'wp_loupe_invalid_filter', __( 'Unknown filter node type.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		$field = isset( $node[ 'field' ] ) ? (string) $node[ 'field' ] : '';
		$op    = isset( $node[ 'op' ] ) ? (string) $node[ 'op' ] : '';
		if ( ! WP_Loupe_Search_Engine::is_valid_loupe_attribute_name( $field ) ) {
			return new \WP_Error( 'wp_loupe_invalid_filter', __( 'Invalid filter field name.', 'wp-loupe' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $field, $allowlisted_fields, true ) ) {
			return new \WP_Error( 'wp_loupe_unallowlisted_field', __( 'Filter field is not allowlisted as filterable for the requested post types.', 'wp-loupe' ), [ 'status' => 400, 'details' => [ 'field' => $field ] ] );
		}

		$allowed_ops = [ 'eq', 'ne', 'in', 'nin', 'lt', 'lte', 'gt', 'gte', 'between', 'exists' ];
		if ( ! in_array( $op, $allowed_ops, true ) ) {
			return new \WP_Error( 'wp_loupe_invalid_filter', __( 'Invalid filter operator.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		if ( 'exists' === $op ) {
			$val = $node[ 'value' ] ?? null;
			if ( ! is_bool( $val ) ) {
				return new \WP_Error( 'wp_loupe_invalid_filter', __( 'exists operator requires boolean value.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			return $val ? ( $field . ' IS NOT NULL' ) : ( $field . ' IS NULL' );
		}

		if ( ! array_key_exists( 'value', $node ) ) {
			return new \WP_Error( 'wp_loupe_invalid_filter', __( 'Predicate filter requires value.', 'wp-loupe' ), [ 'status' => 400 ] );
		}

		$value = $node[ 'value' ];
		if ( 'in' === $op || 'nin' === $op ) {
			if ( ! is_array( $value ) || empty( $value ) ) {
				return new \WP_Error( 'wp_loupe_invalid_filter', __( 'in/nin operators require a non-empty array value.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			$vals = [];
			foreach ( $value as $v ) {
				$vals[] = $this->filter_value_literal( $v );
			}
			$kw = ( 'in' === $op ) ? 'IN' : 'NOT IN';
			return sprintf( '%s %s (%s)', $field, $kw, implode( ', ', $vals ) );
		}

		if ( 'between' === $op ) {
			$min = null;
			$max = null;
			if ( is_array( $value ) && isset( $value[ 0 ], $value[ 1 ] ) ) {
				$min = $value[ 0 ];
				$max = $value[ 1 ];
			} elseif ( is_array( $value ) && isset( $value[ 'min' ], $value[ 'max' ] ) ) {
				$min = $value[ 'min' ];
				$max = $value[ 'max' ];
			}
			if ( null === $min || null === $max ) {
				return new \WP_Error( 'wp_loupe_invalid_filter', __( 'between operator requires [min,max] or {min,max}.', 'wp-loupe' ), [ 'status' => 400 ] );
			}
			return sprintf( '%s BETWEEN %s AND %s', $field, $this->filter_value_literal( $min ), $this->filter_value_literal( $max ) );
		}

		$map = [
			'eq'  => '=',
			'ne'  => '!=',
			'lt'  => '<',
			'lte' => '<=',
			'gt'  => '>',
			'gte' => '>=',
		];
		return sprintf( '%s %s %s', $field, $map[ $op ], $this->filter_value_literal( $value ) );
	}

	/**
	 * Convert a scalar into a Loupe filter literal.
	 *
	 * Strings are single-quoted and escaped by doubling quotes.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function filter_value_literal( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_numeric( $value ) && ! is_string( $value ) ) {
			return (string) $value;
		}
		// Dates are ISO-8601 strings and are treated as strings in filters.
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/**
	 * Sort merged hits across post types according to the POST payload.
	 *
	 * @param array<int,array<string,mixed>> $hits
	 * @param array $payload
	 * @param string|null $geo_field
	 * @return array<int,array<string,mixed>>
	 */
	private function sort_raw_hits( array $hits, array $payload, $geo_field ) {
		$sort_specs = isset( $payload[ 'sort' ] ) && is_array( $payload[ 'sort' ] ) ? $payload[ 'sort' ] : [];
		$geo_sort   = ( isset( $payload[ 'geo' ] ) && is_array( $payload[ 'geo' ] ) && isset( $payload[ 'geo' ][ 'sort' ] ) ) ? $payload[ 'geo' ][ 'sort' ] : null;

		usort( $hits, function ( $a, $b ) use ( $sort_specs, $geo_sort, $geo_field ) {
			// Geo distance sort is highest priority when requested.
			if ( is_array( $geo_sort ) && $geo_field ) {
				$order = isset( $geo_sort[ 'order' ] ) ? strtolower( (string) $geo_sort[ 'order' ] ) : 'asc';
				$da    = isset( $a[ '_distanceMeters' ] ) ? (int) $a[ '_distanceMeters' ] : PHP_INT_MAX;
				$db    = isset( $b[ '_distanceMeters' ] ) ? (int) $b[ '_distanceMeters' ] : PHP_INT_MAX;
				if ( $da !== $db ) {
					return ( 'desc' === $order ) ? ( $db <=> $da ) : ( $da <=> $db );
				}
			}

			foreach ( $sort_specs as $spec ) {
				if ( ! is_array( $spec ) ) {
					continue;
				}
				$by    = isset( $spec[ 'by' ] ) ? (string) $spec[ 'by' ] : '';
				$order = isset( $spec[ 'order' ] ) ? strtolower( (string) $spec[ 'order' ] ) : 'desc';
				$dir   = ( 'asc' === $order ) ? 1 : -1;
				if ( '_score' === $by ) {
					$va = isset( $a[ '_score' ] ) ? (float) $a[ '_score' ] : 0.0;
					$vb = isset( $b[ '_score' ] ) ? (float) $b[ '_score' ] : 0.0;
					if ( $va !== $vb ) {
						return ( $va <=> $vb ) * $dir;
					}
					continue;
				}
				if ( '' === $by ) {
					continue;
				}
				$va = $a[ $by ] ?? null;
				$vb = $b[ $by ] ?? null;
				if ( $va === $vb ) {
					continue;
				}
				// Treat missing values as last.
				if ( null === $va ) {
					return 1;
				}
				if ( null === $vb ) {
					return -1;
				}
				if ( is_numeric( $va ) && is_numeric( $vb ) ) {
					return ( (float) $va <=> (float) $vb ) * $dir;
				}
				return strcmp( (string) $va, (string) $vb ) * $dir;
			}

			// Default: score desc.
			$va = isset( $a[ '_score' ] ) ? (float) $a[ '_score' ] : 0.0;
			$vb = isset( $b[ '_score' ] ) ? (float) $b[ '_score' ] : 0.0;
			if ( $va !== $vb ) {
				return $vb <=> $va;
			}
			return (int) ( $b[ 'id' ] ?? 0 ) <=> (int) ( $a[ 'id' ] ?? 0 );
		} );

		return $hits;
	}

	/**
	 * Convert Loupe facetDistribution into the public facet bucket format.
	 *
	 * @param array<string,array<string,int>> $facet_distribution
	 * @param array<string,array{size:int,minCount:int}> $facet_meta
	 * @return array<string,array{type:string,buckets:array<int,array{value:string,count:int}>}>
	 */
	private function format_facet_distribution( array $facet_distribution, array $facet_meta ) {
		$out = [];
		foreach ( $facet_meta as $field => $meta ) {
			$dist = $facet_distribution[ $field ] ?? [];
			if ( ! is_array( $dist ) ) {
				$dist = [];
			}
			// Filter + sort buckets by count desc.
			$buckets = [];
			foreach ( $dist as $val => $cnt ) {
				$cnt = (int) $cnt;
				if ( $cnt < (int) $meta[ 'minCount' ] ) {
					continue;
				}
				$buckets[] = [ 'value' => (string) $val, 'count' => $cnt ];
			}
			usort( $buckets, function ( $a, $b ) {
				return (int) $b[ 'count' ] <=> (int) $a[ 'count' ];
			} );
			$buckets       = array_slice( $buckets, 0, (int) $meta[ 'size' ] );
			$out[ $field ] = [
				'type'    => 'terms',
				'buckets' => $buckets,
			];
		}
		return $out;
	}

	/**
	 * Return available fields for a post type (schema + meta keys) with labels.
	 * Used by admin.js for dynamic Field Settings table.
	 *
	 * @param mixed $request Request object implementing get_param().
	 */
	public function handle_post_type_fields_request( $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'wp_loupe_invalid_post_type', __( 'Invalid post type.', 'wp-loupe' ), [ 'status' => 404 ] );
		}

		// Start with core WordPress fields that should always be available
		$fields = [];
		foreach ( array_keys( $this->get_core_fields() ) as $core_field ) {
			$fields[ $core_field ] = [ 'label' => $this->prettify_field_label( $core_field ) ];
		}

		// Add schema-based fields (saved indexable configuration)
		$schema_manager = WP_Loupe_Schema_Manager::get_instance();
		$schema         = $schema_manager->get_schema_for_post_type( $post_type );
		foreach ( $schema as $field_key => $_settings ) {
			if ( ! isset( $fields[ $field_key ] ) ) {
				$fields[ $field_key ] = [ 'label' => $this->prettify_field_label( $field_key ) ];
			}
		}

		// Add discovered meta keys (non-protected, existing values)
		$meta_keys = $this->get_post_type_meta_keys_with_values( $post_type );
		foreach ( array_keys( $meta_keys ) as $meta_key ) {
			if ( ! isset( $fields[ $meta_key ] ) ) {
				$fields[ $meta_key ] = [ 'label' => $this->prettify_field_label( $meta_key ) ];
			}
		}

		return rest_ensure_response( $fields );
	}

	/**
	 * Simple label prettifier (shared logic local copy).
	 */
	private function prettify_field_label( $key ) {
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Get core WordPress fields that should always be available
	 * 
	 * @return array Associative array of field_key => true
	 */
	private function get_core_fields() {
		return [
			'post_title'    => true,
			'post_content'  => true,
			'post_excerpt'  => true,
			'post_date'     => true,
			'post_modified' => true,
			'post_author'   => true,
			'permalink'     => true,
		];
	}

	/**
	 * Create database (ensure directory, update post types option) for a post type.
	 *
	 * @param mixed $request REST request implementing get_param().
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create_database_request( $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'wp_loupe_invalid_post_type', __( 'Invalid post type.', 'wp-loupe' ), [ 'status' => 404 ] );
		}

		// Update option list if not already present.
		$options = get_option( 'wp_loupe_custom_post_types', [] );
		if ( ! isset( $options[ 'wp_loupe_post_type_field' ] ) || ! is_array( $options[ 'wp_loupe_post_type_field' ] ) ) {
			$options[ 'wp_loupe_post_type_field' ] = [];
		}
		if ( ! in_array( $post_type, $options[ 'wp_loupe_post_type_field' ], true ) ) {
			$options[ 'wp_loupe_post_type_field' ][] = $post_type;
			update_option( 'wp_loupe_custom_post_types', $options );
		}

		// Ensure directory exists.
		$db      = WP_Loupe_DB::get_instance();
		$dir     = $db->get_db_path( $post_type );
		$base    = $db->get_base_path();
		$created = false;
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		if ( ! file_exists( $dir ) ) {
			$created = wp_mkdir_p( $dir );
		}

		// Clear factory & schema caches so subsequent indexing uses updated post type list.
		WP_Loupe_Factory::clear_instance_cache();
		WP_Loupe_Schema_Manager::get_instance()->clear_cache();

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf( __( 'Database structure ready for %s.', 'wp-loupe' ), $post_type ),
			'created' => $created,
		] );
	}

	/**
	 * Delete database (directory removal and option update) for a post type.
	 *
	 * @param mixed $request REST request implementing get_param().
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_database_request( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$options   = get_option( 'wp_loupe_custom_post_types', [] );

		if ( isset( $options[ 'wp_loupe_post_type_field' ] ) && is_array( $options[ 'wp_loupe_post_type_field' ] ) ) {
			$options[ 'wp_loupe_post_type_field' ] = array_values( array_filter( $options[ 'wp_loupe_post_type_field' ], function ( $pt ) use ( $post_type ) {
				return $pt !== $post_type;
			} ) );
			update_option( 'wp_loupe_custom_post_types', $options );
		}

		$db      = WP_Loupe_DB::get_instance();
		$dir     = $db->get_db_path( $post_type );
		$deleted = false;
		if ( file_exists( $dir ) && is_dir( $dir ) ) {
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$fs      = new \WP_Filesystem_Direct( false );
			$deleted = $fs->rmdir( $dir, true );
		}

		WP_Loupe_Factory::clear_instance_cache();
		WP_Loupe_Schema_Manager::get_instance()->clear_cache();

		return rest_ensure_response( [
			'success' => true,
			'message' => sprintf( __( 'Database removed for %s.', 'wp-loupe' ), $post_type ),
			'deleted' => $deleted,
		] );
	}

	/**
	 * Discover meta keys with non-empty values for the post type.
	 */
	private function get_post_type_meta_keys_with_values( $post_type ) {
		global $wpdb;
		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}
		$sql  = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key NOT LIKE '\_%'
               AND pm.meta_value <> ''
             LIMIT 500",
			$post_type
		);
		$keys = $wpdb->get_col( $sql );
		$out  = [];
		if ( is_array( $keys ) ) {
			foreach ( $keys as $k ) {
				if ( is_string( $k ) && strlen( $k ) < 128 ) {
					$out[ $k ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Handle search REST request
	 *
	 * @param mixed $request Request object implementing get_param().
	 * @return array Response array (later wrapped by rest_ensure_response).
	 */
	public function handle_search_request( $request ) {
		$query     = $request->get_param( 'q' );
		$post_type = $request->get_param( 'post_type' );
		$per_page  = $request->get_param( 'per_page' );
		$page      = $request->get_param( 'page' );

		// Generate a cache key based on the search parameters unless a custom injected search service (tests) is detected.
		$cache_key      = 'wp_loupe_search_' . md5( $query . $post_type . $per_page . $page );
		$use_cache      = is_object( $this->search_service ) && ( $this->search_service instanceof WP_Loupe_Search_Engine );
		$cached_results = $use_cache ? get_transient( $cache_key ) : false;
		if ( $use_cache && false !== $cached_results ) {
			return rest_ensure_response( $cached_results );
		}

		$search_results = $this->perform_search( $query, $post_type, $per_page, $page );

		// Cache the results for 12 hours if using the real search service.
		if ( $use_cache ) {
			set_transient( $cache_key, $search_results, 12 * HOUR_IN_SECONDS );
		}

		return rest_ensure_response( $search_results );
	}

	/**
	 * Perform search against Loupe index
	 *
	 * @param string $query Search query
	 * @param string $post_type Post type to search in or 'all'
	 * @param int $per_page Items per page
	 * @param int $page Current page number
	 * @return array Search results
	 */
	private function perform_search( $query, $post_type, $per_page, $page ) {
		// Determine scope.
		$post_types_to_search = ( 'all' === $post_type ) ? $this->post_types : [ $post_type ];

		// Instantiate a narrowed search service if filtering to a single type (saves internal loops).
		$service = ( count( $post_types_to_search ) === count( $this->post_types ) )
			? $this->search_service
			: new WP_Loupe_Search_Engine( $post_types_to_search, $this->db );

		$raw_hits = $service->search( $query ); // Returns combined hit arrays w/ post_type & id.

		// Safety filter: ensure only hits from requested post types (important when test stubs or custom services return broader sets).
		$allowed  = array_flip( $post_types_to_search );
		$raw_hits = array_values( array_filter( $raw_hits, function ( $hit ) use ( $allowed ) {
			return isset( $hit[ 'post_type' ] ) && isset( $allowed[ $hit[ 'post_type' ] ] );
		} ) );

		// Enrich hits into formatted structure (mirrors prior shape) before slicing.
		$enriched = [];
		foreach ( $raw_hits as $hit ) {
			if ( empty( $hit[ 'id' ] ) || empty( $hit[ 'post_type' ] ) ) {
				continue;
			}
			$post_id  = (int) $hit[ 'id' ];
			$ptype    = $hit[ 'post_type' ];
			$post_obj = get_post( $post_id );
			if ( ! $post_obj || 'publish' !== $post_obj->post_status ) {
				continue;
			}
			$ptype_obj  = get_post_type_object( $ptype );
			$enriched[] = [
				'id'              => $post_id,
				'title'           => get_the_title( $post_id ),
				'url'             => get_permalink( $post_id ),
				'post_type'       => $ptype,
				'post_type_label' => $ptype_obj && isset( $ptype_obj->labels->singular_name ) ? $ptype_obj->labels->singular_name : $ptype,
				'excerpt'         => get_the_excerpt( $post_id ),
				'_score'          => $hit[ '_score' ] ?? 0,
			];
		}

		// Relevance sort & paginate.
		usort( $enriched, function ( $a, $b ) {
			return $b[ '_score' ] <=> $a[ '_score' ];
		} );
		$total_hits = count( $enriched );
		$offset     = max( 0, ( $page - 1 ) * $per_page );
		$paged_hits = array_slice( $enriched, $offset, $per_page );

		return [
			'hits'       => $paged_hits,
			'pagination' => [
				'total'        => $total_hits,
				'per_page'     => $per_page,
				'current_page' => $page,
				'total_pages'  => ( $per_page > 0 ) ? (int) ceil( $total_hits / $per_page ) : 0,
			],
		];
	}

	/**
	 * Format search results into a standardized structure
	 *
	 * @param array $search_result Raw search result from Loupe
	 * @param string $post_type Current post type
	 * @return array Formatted search results
	 */
	private function format_search_results( $search_result, $post_type ) {
		$formatted = [
			'hits' => [],
			'took' => $search_result[ 'processingTimeMs' ] ?? 0,
		];

		foreach ( $search_result[ 'hits' ] as $hit ) {
			$post_id = $hit[ 'id' ];
			$post    = get_post( $post_id );

			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$formatted_hit = [
				'id'              => $post_id,
				'title'           => get_the_title( $post_id ),
				'url'             => get_permalink( $post_id ),
				'post_type'       => $post_type,
				'post_type_label' => get_post_type_object( $post_type )->labels->singular_name,
				'excerpt'         => get_the_excerpt( $post_id ),
				'_score'          => $hit[ '_score' ] ?? 0,
			];

			// Add featured image if available
			if ( has_post_thumbnail( $post_id ) ) {
				$featured_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'thumbnail' );
				if ( is_array( $featured_image ) && isset( $featured_image[ 0 ] ) ) {
					$formatted_hit[ 'featured_image' ] = $featured_image[ 0 ];
				}
			}

			$formatted[ 'hits' ][] = $formatted_hit;
		}

		return $formatted; // Legacy method retained for backward compatibility (may be removed in future once REST uses unified enrichment exclusively).
	}

	/**
	 * Internal logger helper (only logs when WP_DEBUG enabled).
	 *
	 * @param string $message
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WP_Loupe_REST] ' . $message );
		}
	}
}
