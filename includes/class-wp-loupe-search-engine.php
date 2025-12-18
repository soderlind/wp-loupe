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
	private const CACHE_TTL = 3600; // 1 hour

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
					if ( ! empty( $settings['indexable'] ) ) {
						$indexable_fields[] = [
							'field'  => $field_name,
							'weight' => $settings['weight'] ?? 1.0,
						];
					}

					if ( ! empty( $settings['filterable'] ) ) {
						$filterable_fields[] = $field_name;
					}

					if ( ! empty( $settings['sortable'] ) ) {
						$sort_direction     = $settings['sort_direction'] ?? 'desc';
						$valid_sort_fields[] = "{$field_name}:{$sort_direction}";
					}
				}

				$retrievable_fields = array_unique( array_merge(
					$retrievable_fields,
					array_map( function ( $field ) {
						return $field['field'];
					}, $indexable_fields ),
					$filterable_fields
				) );

				$search_params = SearchParameters::create()
					->withQuery( (string) $query )
					->withAttributesToRetrieve( $retrievable_fields );

				if ( ! empty( $valid_sort_fields ) ) {
					try {
						$search_params = $search_params->withSort( $valid_sort_fields );
					} catch ( \Throwable $e ) {
						WP_Loupe_Utils::debug_log( "Sort error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
					}
				}

				$result = $this->loupe[ $post_type ]->search( $search_params );
				$arr    = $result->toArray();

				if ( isset( $arr['processingTimeMs'] ) ) {
					$processing_time_sum += (int) $arr['processingTimeMs'];
				}

				$tmp_hits = isset( $arr['hits'] ) && is_array( $arr['hits'] ) ? $arr['hits'] : [];
				foreach ( $tmp_hits as $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					$hit['post_type'] = $post_type;
					$hits[]           = $hit;
				}
			} catch ( \Throwable $e ) {
				WP_Loupe_Utils::debug_log( "Search error for {$post_type}: " . $e->getMessage(), 'WP Loupe' );
				continue;
			}
		}

		$this->log = sprintf( 'WP Loupe processing time: %s ms', (string) $processing_time_sum );
		set_transient( $transient_key, $hits, self::CACHE_TTL );
		return $hits;
	}

	/**
	 * @return string|null
	 */
	public function get_log() {
		return $this->log;
	}
}
