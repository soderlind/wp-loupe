<?php
namespace Soderlind\Plugin\WPLoupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

/**
 * Factory class for creating Loupe instances
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.1.6
 */
class WP_Loupe_Factory {
	/**
	 * Fields that are safe to use as sortable attributes in Loupe
	 * Only simple scalar data types like strings and numbers can be sortable
	 */
	private static $sortable_field_types = [
		'post_date',
		'post_modified',
		'post_title',
		'post_name',
		'post_author',
	];

	/**
	 * Fields that are known to be non-scalar and cannot be sortable
	 */
	private static $non_scalar_field_types = [
		'post_content', // Often contains complex HTML
		'post_excerpt', // May contain HTML
	];

	/**
	 * Cache of Loupe instances by post type
	 */
	private static $instance_cache = [];

	/**
	 * Create a Loupe search instance
	 *
	 * @since 0.1.6
	 * @param string $post_type Post type to create instance for.
	 * @param string $lang Language code.
	 * @param WP_Loupe_DB $db Database instance.
	 * @param bool $force_new Whether to force creation of a new instance regardless of cache
	 * @return \Loupe\Loupe\Loupe Loupe instance
	 */
	public static function create_loupe_instance( string $post_type, string $lang, WP_Loupe_DB $db ): \Loupe\Loupe\Loupe {
		// Generate cache key
		$cache_key = "{$post_type}:{$lang}";

		// Return cached instance if available
		if ( isset( self::$instance_cache[ $cache_key ] ) ) {
			return self::$instance_cache[ $cache_key ];
		}

		// Get field configuration for this post type
		$field_config = self::get_field_configuration( $post_type );

		// Extract attributes from field configuration
		$attributes = self::extract_attributes_from_fields( $field_config );

		// Get advanced configuration
		$configuration = self::build_configuration( $attributes );

		// Create and cache the Loupe instance
		$loupe_factory = new LoupeFactory();
		$instance      = $loupe_factory->create(
			$db->get_db_path( $post_type ),
			$configuration
		);

		// Cache the instance
		self::$instance_cache[ $cache_key ] = $instance;

		return $instance;
	}

	/**
	 * Get field configuration for a post type, creating defaults if needed
	 * 
	 * @param string $post_type Post type to get configuration for
	 * @return array Field configuration
	 */
	private static function get_field_configuration( string $post_type ): array {
		// Get all saved fields
		$all_saved_fields = get_option( 'wp_loupe_fields', [] );
		$needs_save       = false;

		// If settings don't exist for this post type, create defaults
		if ( ! is_array( $all_saved_fields ) ) {
			$all_saved_fields = [];
			$needs_save       = true;
		}

		if ( ! isset( $all_saved_fields[ $post_type ] ) ) {
			$needs_save = true;

			// Set default fields for this post type
			$all_saved_fields[ $post_type ] = [
				'post_title'   => [
					'indexable'      => true,
					'weight'         => 2.0,
					'filterable'     => true,
					'sortable'       => true,
					'sort_direction' => 'desc',
				],
				'post_content' => [
					'indexable'  => true,
					'weight'     => 1.0,
					'filterable' => true,
					'sortable'   => false,
				],
				'post_date'    => [
					'indexable'      => true,
					'weight'         => 1.0,
					'filterable'     => true,
					'sortable'       => true,
					'sort_direction' => 'desc',
				],
			];

			// Add taxonomy fields
			self::add_taxonomy_fields( $post_type, $all_saved_fields[ $post_type ] );
		}

		// Save if needed
		if ( $needs_save ) {
			update_option( 'wp_loupe_fields', $all_saved_fields );
		}

		return $all_saved_fields[ $post_type ] ?? [];
	}

	/**
	 * Add taxonomy fields to the field configuration
	 * 
	 * @param string $post_type Post type
	 * @param array &$fields Fields array to modify
	 */
	private static function add_taxonomy_fields( string $post_type, array &$fields ): void {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $tax_name => $tax_obj ) {
			if ( $tax_obj->show_ui ) {
				$fields[ 'taxonomy_' . $tax_name ] = [
					'indexable'  => true,
					'weight'     => 1.5,
					'filterable' => true,
					'sortable'   => false // Taxonomies are arrays, not scalar
				];
			}
		}
	}

	/**
	 * Extract searchable, filterable, and sortable attributes from field configuration
	 * 
	 * @param array $fields Field configuration
	 * @return array Attributes for indexable, filterable, and sortable fields
	 */
	private static function extract_attributes_from_fields( array $fields ): array {
		$attributes = [
			'indexable'  => [],
			'filterable' => [],
			'sortable'   => [],
		];

		foreach ( $fields as $field_name => $settings ) {
			if ( ! empty( $settings[ 'indexable' ] ) ) {
				$attributes[ 'indexable' ][] = $field_name;
			}

			if ( ! empty( $settings[ 'filterable' ] ) ) {
				$attributes[ 'filterable' ][] = $field_name;
			}

			if ( ! empty( $settings[ 'sortable' ] ) ) {
				$attributes[ 'sortable' ][] = $field_name;
			}
		}

		return $attributes;
	}

	/**
	 * Build the Loupe configuration object
	 * 
	 * @param array $attributes Attributes for configuration
	 * @return Configuration Loupe configuration object
	 */
	private static function build_configuration( array $attributes ): Configuration {
		$advanced_settings = get_option( 'wp_loupe_advanced', [] );

		// Create the configuration
		$configuration = Configuration::create()
			->withPrimaryKey( 'id' )
			->withSearchableAttributes( $attributes[ 'indexable' ] )
			->withFilterableAttributes( $attributes[ 'filterable' ] )
			->withSortableAttributes( $attributes[ 'sortable' ] )
			->withMaxQueryTokens( $advanced_settings[ 'max_query_tokens' ] ?? 12 )
			->withMinTokenLengthForPrefixSearch( $advanced_settings[ 'min_prefix_length' ] ?? 3 )
			->withLanguages( $advanced_settings[ 'languages' ] ?? [ 'en' ] )
			->withTypoTolerance( self::configure_typo_tolerance( $advanced_settings ) );

		return $configuration;
	}

	/**
	 * Configure typo tolerance settings
	 * 
	 * @param array $settings Advanced settings
	 * @return TypoTolerance Configured typo tolerance object
	 */
	private static function configure_typo_tolerance( array $settings ): TypoTolerance {
		if ( empty( $settings[ 'typo_enabled' ] ) ) {
			return TypoTolerance::disabled();
		}

		$typo = TypoTolerance::create();

		// Apply settings if they exist
		if ( ! empty( $settings[ 'alphabet_size' ] ) ) {
			$typo->withAlphabetSize( $settings[ 'alphabet_size' ] );
		}

		if ( ! empty( $settings[ 'index_length' ] ) ) {
			$typo->withIndexLength( $settings[ 'index_length' ] );
		}

		// Configure boolean settings
		$typo->withFirstCharTypoCountsDouble( ! empty( $settings[ 'first_char_typo_double' ] ) );
		$typo->withEnabledForPrefixSearch( ! empty( $settings[ 'typo_prefix_search' ] ) );

		// Configure thresholds
		if ( ! empty( $settings[ 'typo_thresholds' ] ) && is_array( $settings[ 'typo_thresholds' ] ) ) {
			$typo->withTypoThresholds( $settings[ 'typo_thresholds' ] );
		}

		return $typo;
	}

	/**
	 * Clear the instance cache for all post types or a specific one
	 * 
	 * @param string|null $post_type Optional post type to clear cache for
	 */
	public static function clear_instance_cache( ?string $post_type = null ): void {
		if ( $post_type === null ) {
			self::$instance_cache = [];
		} else {
			foreach ( array_keys( self::$instance_cache ) as $key ) {
				if ( strpos( $key, "{$post_type}:" ) === 0 ) {
					unset( self::$instance_cache[ $key ] );
				}
			}
		}
	}

	/**
	 * Determine if a field is safe to use as a sortable attribute
	 * 
	 * @param string $field_name The field name to check
	 * @param string $post_type The post type being processed
	 * @return bool Whether the field can be safely sorted
	 */
	private static function is_safely_sortable( string $field_name, string $post_type ): bool {
		// Core WP fields we know are safely sortable
		if ( in_array( $field_name, self::$sortable_field_types ) ) {
			return true;
		}

		// Fields we know are not safely sortable
		if ( in_array( $field_name, self::$non_scalar_field_types ) ) {
			return false;
		}

		// Check if it's a taxonomy field (these are arrays and not safely sortable)
		if ( strpos( $field_name, 'taxonomy_' ) === 0 ) {
			return false;
		}

		// Core post fields list
		$core_fields = [
			'ID', 'post_author', 'post_date', 'post_date_gmt',
			'post_content', 'post_title', 'post_excerpt',
			'post_status', 'comment_status', 'ping_status',
			'post_password', 'post_name', 'to_ping', 'pinged',
			'post_modified', 'post_modified_gmt', 'post_content_filtered',
			'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count',
		];

		// If not a core field, treat as a meta field
		if ( ! in_array( $field_name, $core_fields ) ) {
			return self::check_meta_field_sortability( $field_name, $post_type );
		}

		// Allow plugins to override for custom field types
		return apply_filters( "wp_loupe_is_safely_sortable_{$post_type}", false, $field_name );
	}

	/**
	 * Check if a meta field contains only scalar values
	 * 
	 * @param string $field_name Meta field name
	 * @param string $post_type Post type
	 * @return bool Whether the field contains only scalar values
	 */
	private static function check_meta_field_sortability( string $field_name, string $post_type ): bool {
		// Check a sample value
		$args = [
			'post_type'         => $post_type,
			'posts_per_page'    => 5, // Check a few posts for better accuracy
			'meta_key'          => $field_name,
			'meta_value_exists' => true,
			'fields'            => 'ids', // We only need the IDs
		];

		$result = false;

		$query = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			$result = true;
			// Check the meta values from these posts
			foreach ( $query->posts as $post_id ) {
				$value = get_post_meta( $post_id, $field_name, true );

				// Loupe supports geo-point sorting via _geoPoint(field,...).
				// A geo-point meta value is stored as an array: { lat: float, lng: float }.
				if ( self::is_geo_point_meta_value( $value ) ) {
					continue;
				}

				// If we find a non-scalar value, mark as not sortable.
				if ( ! is_scalar( $value ) && $value !== null ) {
					$result = false;
					break;
				}
			}
		}

		// Allow plugins/themes to override meta-field sortability.
		return (bool) apply_filters( "wp_loupe_is_safely_sortable_meta_{$post_type}", $result, $field_name );
	}

	/**
	 * Determine whether a meta value is a Loupe geo-point.
	 *
	 * Accepts both lng and lon as input keys.
	 */
	private static function is_geo_point_meta_value( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( ! isset( $value[ 'lat' ] ) ) {
			return false;
		}
		if ( ! isset( $value[ 'lng' ] ) && ! isset( $value[ 'lon' ] ) ) {
			return false;
		}

		$lat = $value[ 'lat' ];
		$lng = isset( $value[ 'lng' ] ) ? $value[ 'lng' ] : $value[ 'lon' ];
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return false;
		}

		$lat = (float) $lat;
		$lng = (float) $lng;
		return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
	}

	/**
	 * Validate sortable fields in the settings
	 *
	 * @param array $fields All field settings
	 * @return array Validated field settings
	 */
	public static function validate_sortable_fields( array $fields ): array {
		$updated = false;

		foreach ( $fields as $post_type => &$post_type_fields ) {
			foreach ( $post_type_fields as $field_name => &$settings ) {
				// If marked as sortable but not safely sortable, correct it
				if ( ! empty( $settings[ 'sortable' ] ) && ! self::is_safely_sortable( $field_name, $post_type ) ) {
					$settings[ 'sortable' ] = false;
					$updated              = true;
				}
			}
		}

		// Save the corrected settings if needed
		if ( $updated ) {
			update_option( 'wp_loupe_fields', $fields );
		}

		return $fields;
	}

	/**
	 * Public method to check if a field is safely sortable
	 * 
	 * @param string $field_name The field name to check
	 * @param string $post_type The post type being processed
	 * @return bool True if field can be safely sorted
	 */
	public static function check_sortable_field( string $field_name, string $post_type ): bool {
		// Use static cache for frequently checked fields
		static $field_cache = [];
		$cache_key = "{$post_type}:{$field_name}";

		if ( ! isset( $field_cache[ $cache_key ] ) ) {
			$field_cache[ $cache_key ] = self::is_safely_sortable( $field_name, $post_type );
		}

		return $field_cache[ $cache_key ];
	}
}