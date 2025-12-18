<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\SearchParameters;

/**
 * Side-effect free search engine for WP Loupe.
 *
 * Performs Loupe searches and returns raw hit arrays.
 */
class WP_Loupe_Search_Engine {
	private $post_types;
	private $loupe = [];
	private $log;
	private $db;
	private $saved_fields;
	private const CACHE_TTL                 = 3600; // 1 hour
	private const LOUPE_DB_FILENAME         = 'loupe.db';
	private const LOUPE_ATTRIBUTE_NAME_RGXP = '/^[a-zA-Z\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/';

	/**
	 * @param array $post_types
	 * @param WP_Loupe_DB|null $db
	 */
	public function __construct( $post_types, $db = null ) {
		$this->post_types   = (array) $post_types;
		$this->db           = $db ?: WP_Loupe_DB::get_instance();
		$this->saved_fields = get_option( 'wp_loupe_fields', [] );

		$iso6391_lang = ( '' === get_locale() ) ? 'en' : strtolower( substr( get_locale(), 0, 2 ) );
		foreach ( $this->post_types as $post_type ) {
			$this->loupe[ $post_type ] = WP_Loupe_Factory::create_loupe_instance( $post_type, $iso6391_lang, $this->db );
		}
	}

	/**
	 * @return array
	 */
	public function get_post_types() {
		return $this->post_types;
	}

	/**
	 * Execute a search.
	 *
	 * @param string $query
	 * @return array Raw hit arrays with at least id, _score, post_type.
	 */
	public function search( $query ) {
		$cache_key     = md5( (string) $query . serialize( $this->post_types ) );
		$transient_key = "wp_loupe_search_{$cache_key}";
		$cached_result = get_transient( $transient_key );
		if ( false !== $cached_result ) {
			$this->log = sprintf( 'WP Loupe cache hit: %s ms', 0 );
			return $cached_result;
		}

		$hits                = [];
		$processing_time_sum = 0;

		foreach ( $this->post_types as $post_type ) {
			$post_type_fields = $this->saved_fields[ $post_type ] ?? [];
			if ( empty( $post_type_fields ) ) {
				continue;
			}

			try {
				$indexable_fields   = [];
				$filterable_fields  = [];
				$valid_sort_fields  = [];
				$retrievable_fields = [ 'id' ];

				foreach ( $post_type_fields as $field_name => $settings ) {
					if ( ! empty( $settings[ 'indexable' ] ) ) {
						$indexable_fields[] = [
							'field'  => $field_name,
							'weight' => $settings[ 'weight' ] ?? 1.0,
						];
					}

					if ( ! empty( $settings[ 'filterable' ] ) ) {
						$filterable_fields[] = $field_name;
					}

					if ( ! empty( $settings[ 'sortable' ] ) ) {
						$sort_direction      = $settings[ 'sort_direction' ] ?? 'desc';
						$valid_sort_fields[] = "{$field_name}:{$sort_direction}";
					}
				}

				$retrievable_fields = array_unique( array_merge(
					$retrievable_fields,
					array_map( function ( $field ) {
						return $field[ 'field' ];
					}, $indexable_fields ),
					$filterable_fields
				) );

				$search_params = SearchParameters::create()
					->withQuery( (string) $query )
					->withAttributesToRetrieve( $retrievable_fields )
					->withShowRankingScore( true );

				if ( ! empty( $valid_sort_fields ) ) {
					try {
						$search_params = $search_params->withSort( $valid_sort_fields );
					} catch (\Throwable $e) {
						WP_Loupe_Utils::debug_log( "Sort error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
					}
				}

				$result = $this->loupe[ $post_type ]->search( $search_params );
				$arr    = $result->toArray();

				if ( isset( $arr[ 'processingTimeMs' ] ) ) {
					$processing_time_sum += (int) $arr[ 'processingTimeMs' ];
				}

				$tmp_hits = isset( $arr[ 'hits' ] ) && is_array( $arr[ 'hits' ] ) ? $arr[ 'hits' ] : [];
				foreach ( $tmp_hits as $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					// Normalize Loupe's ranking score into the plugin's historic `_score` field.
					if ( isset( $hit[ '_rankingScore' ] ) && ! isset( $hit[ '_score' ] ) ) {
						$hit[ '_score' ] = $hit[ '_rankingScore' ];
					}
					$hit[ 'post_type' ] = $post_type;
					$hits[]           = $hit;
				}
			} catch (\Throwable $e) {
				WP_Loupe_Utils::debug_log( "Search error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
				continue;
			}
		}

		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) $processing_time_sum );
		set_transient( $transient_key, $hits, self::CACHE_TTL );
		return $hits;
	}

	/**
	 * Determine whether a post type index is ready to be queried.
	 *
	 * Definition (per docs): ready = DB file exists + schema OK.
	 * Schema OK is derived from Loupe's needsReindex() signal.
	 *
	 * @param string $post_type
	 * @return array{ready:bool, reason?:string}
	 */
	public function is_index_ready( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		if ( empty( $post_type ) ) {
			return [ 'ready' => false, 'reason' => 'invalid_post_type' ];
		}
		if ( ! isset( $this->loupe[ $post_type ] ) ) {
			return [ 'ready' => false, 'reason' => 'unknown_post_type' ];
		}

		try {
			$db_dir  = $this->db->get_db_path( $post_type );
			$db_file = trailingslashit( $db_dir ) . self::LOUPE_DB_FILENAME;
			if ( ! file_exists( $db_file ) ) {
				return [ 'ready' => false, 'reason' => 'index_missing' ];
			}
		} catch (\Throwable $e) {
			return [ 'ready' => false, 'reason' => 'index_unreadable' ];
		}

		try {
			if ( method_exists( $this->loupe[ $post_type ], 'needsReindex' ) ) {
				$needs_reindex = (bool) $this->loupe[ $post_type ]->needsReindex();
				if ( $needs_reindex ) {
					return [ 'ready' => false, 'reason' => 'index_needs_reindex' ];
				}
			}
		} catch (\Throwable $e) {
			return [ 'ready' => false, 'reason' => 'index_unreadable' ];
		}

		return [ 'ready' => true ];
	}

	/**
	 * Execute an advanced search for REST / API usage.
	 *
	 * This method is intentionally low-level: it accepts an already validated set of
	 * Loupe-compatible parameters (filter string, sort strings, facet fields, etc.).
	 *
	 * @param string $query
	 * @param array{
	 *   filter?: string,
	 *   sort?: array<string>,
	 *   facets?: array<string>,
	 *   limit?: int,
	 *   attributesToRetrieve?: array<string>,
	 * } $options
	 * @return array{hits:array<int,array<string,mixed>>, totalHits:int, processingTimeMs:int, facetDistribution:array<string,array<string,int>>}
	 */
	public function search_advanced( string $query, array $options ): array {
		$query  = (string) $query;
		$filter = isset( $options[ 'filter' ] ) ? (string) $options[ 'filter' ] : '';
		$sort   = isset( $options[ 'sort' ] ) && is_array( $options[ 'sort' ] ) ? array_values( $options[ 'sort' ] ) : [];
		$facets = isset( $options[ 'facets' ] ) && is_array( $options[ 'facets' ] ) ? array_values( $options[ 'facets' ] ) : [];
		$limit  = isset( $options[ 'limit' ] ) ? (int) $options[ 'limit' ] : 0;
		$attrs  = isset( $options[ 'attributesToRetrieve' ] ) && is_array( $options[ 'attributesToRetrieve' ] ) ? array_values( $options[ 'attributesToRetrieve' ] ) : [];

		$cache_key     = md5( wp_json_encode( [
			'q'          => $query,
			'post_types' => $this->post_types,
			'filter'     => $filter,
			'sort'       => $sort,
			'facets'     => $facets,
			'limit'      => $limit,
			'attrs'      => $attrs,
		] ) );
		$transient_key = "wp_loupe_search_adv_{$cache_key}";
		$cached        = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$total_hits          = 0;
		$processing_time_sum = 0;
		$facet_distribution  = [];
		$hits                = [];

		foreach ( $this->post_types as $post_type ) {
			try {
				$search_params = SearchParameters::create()
					->withQuery( $query )
					->withShowRankingScore( true );

				if ( ! empty( $attrs ) ) {
					$search_params = $search_params->withAttributesToRetrieve( array_values( array_unique( $attrs ) ) );
				}
				if ( ! empty( $filter ) ) {
					$search_params = $search_params->withFilter( $filter );
				}
				if ( ! empty( $facets ) ) {
					$search_params = $search_params->withFacets( $facets );
				}
				if ( ! empty( $sort ) ) {
					$search_params = $search_params->withSort( $sort );
				}
				if ( $limit > 0 ) {
					$search_params = $search_params->withLimit( $limit );
				}

				$result = $this->loupe[ $post_type ]->search( $search_params );
				$arr    = $result->toArray();

				$total_hits          += isset( $arr[ 'totalHits' ] ) ? (int) $arr[ 'totalHits' ] : 0;
				$processing_time_sum += isset( $arr[ 'processingTimeMs' ] ) ? (int) $arr[ 'processingTimeMs' ] : 0;

				if ( isset( $arr[ 'facetDistribution' ] ) && is_array( $arr[ 'facetDistribution' ] ) ) {
					foreach ( $arr[ 'facetDistribution' ] as $facet_field => $dist ) {
						if ( ! is_array( $dist ) ) {
							continue;
						}
						if ( ! isset( $facet_distribution[ $facet_field ] ) ) {
							$facet_distribution[ $facet_field ] = [];
						}
						foreach ( $dist as $val => $cnt ) {
							$facet_distribution[ $facet_field ][ (string) $val ] = ( $facet_distribution[ $facet_field ][ (string) $val ] ?? 0 ) + (int) $cnt;
						}
					}
				}

				$tmp_hits = isset( $arr[ 'hits' ] ) && is_array( $arr[ 'hits' ] ) ? $arr[ 'hits' ] : [];
				foreach ( $tmp_hits as $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					if ( isset( $hit[ '_rankingScore' ] ) && ! isset( $hit[ '_score' ] ) ) {
						$hit[ '_score' ] = $hit[ '_rankingScore' ];
					}
					$hit[ 'post_type' ] = $post_type;
					$hits[]           = $hit;
				}
			} catch (\Throwable $e) {
				WP_Loupe_Utils::debug_log( "Advanced search error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
				continue;
			}
		}

		$out = [
			'hits'              => $hits,
			'totalHits'         => $total_hits,
			'processingTimeMs'  => $processing_time_sum,
			'facetDistribution' => $facet_distribution,
		];
		set_transient( $transient_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Helper: validate that a field name is a Loupe-compatible attribute name.
	 *
	 * @param mixed $field
	 */
	public static function is_valid_loupe_attribute_name( $field ): bool {
		return is_string( $field ) && preg_match( self::LOUPE_ATTRIBUTE_NAME_RGXP, $field ) === 1;
	}

	/**
	 * @return string|null
	 */
	public function get_log() {
		return $this->log;
	}
}
