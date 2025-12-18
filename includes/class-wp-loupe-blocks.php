<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Block variations & editor integration for WP Loupe.
 *
 * Provides a block variation of core/search that can display configured
 * filterable fields (as chosen in WP Loupe settings). This initial version
 * focuses on surfacing the filter inputs; wiring them to an AJAX-powered
 * Loupe search can be layered on later.
 */
class WP_Loupe_Blocks {
	/**
	 * Initialize editor-only integration.
	 *
	 * Loads the block variation + inspector controls only when editing.
	 */
	public static function init_editor() {
		add_action( 'current_screen', [ __CLASS__, 'on_current_screen' ] );
	}

	/**
	 * current_screen callback.
	 *
	 * @param mixed $screen
	 */
	public static function on_current_screen( $screen ) {
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'is_block_editor' ) ) {
			return;
		}
		if ( ! $screen->is_block_editor() ) {
			return;
		}
		self::register_scripts();
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_scripts' ] );
		add_filter( 'render_block', [ __CLASS__, 'inject_filters_into_search_block' ], 10, 2 );
		add_filter( 'get_search_form', [ __CLASS__, 'override_default_search_form' ], 20 );
		add_shortcode( 'loupe_search', [ __CLASS__, 'shortcode_loupe_search_form' ] );
	}

	/**
	 * Register editor / front-end script for block variation.
	 */
	public static function register_scripts() {
		$version = WP_Loupe_Utils::get_version_number();

		// Collect filterable fields per post type.
		$filterable = self::collect_filterable_fields();

		// Editor script: registers variation + inspector controls.
		wp_register_script(
			'wp-loupe-blocks',
			WP_LOUPE_URL . 'lib/js/block-search-variation.js',
			[ 'wp-blocks', 'wp-hooks', 'wp-i18n', 'wp-element', 'wp-editor', 'wp-components', 'wp-compose', 'wp-data' ],
			$version,
			true
		);
		wp_localize_script( 'wp-loupe-blocks', 'wpLoupeBlockData', [
			'restUrl'    => rest_url( 'wp-loupe/v1' ),
			'filterable' => $filterable,
		] );

		// Only enqueue in block editor.
		add_action( 'enqueue_block_editor_assets', function () {
			wp_enqueue_script( 'wp-loupe-blocks' );
		} );
	}

	/**
	 * Gather filterable field labels keyed by post type.
	 * Structure: [ post_type => [ field_key => [ 'label' => 'Readable Label' ] ] ]
	 */
	private static function collect_filterable_fields() {
		$stored = get_option( 'wp_loupe_fields', [] );
		$out    = [];
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $post_type => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field_key => $settings ) {
				if ( ! empty( $settings[ 'filterable' ] ) ) {
					$out[ $post_type ][ $field_key ] = [ 'label' => self::prettify_label( $field_key ) ];
				}
			}
		}
		return $out;
	}

	/**
	 * Inject filter inputs into the core/search block form when variation active.
	 *
	 * We look for the variation attributes added by our script (loupeFilters & loupeFilterFields)
	 * and then append a fieldset of filter inputs before the closing </form> tag.
	 */
	public static function inject_filters_into_search_block( $block_content, $block ) {
		if ( empty( $block[ 'blockName' ] ) || 'core/search' !== $block[ 'blockName' ] ) {
			return $block_content;
		}
		$attrs = isset( $block[ 'attrs' ] ) ? $block[ 'attrs' ] : [];
		if ( empty( $attrs[ 'loupeFilters' ] ) || empty( $attrs[ 'loupeFilterFields' ] ) || ! is_array( $attrs[ 'loupeFilterFields' ] ) ) {
			return $block_content; // Variation not active or no fields selected.
		}

		// Build filters HTML.
		$post_type   = isset( $attrs[ 'loupePostType' ] ) ? sanitize_key( $attrs[ 'loupePostType' ] ) : 'post';
		$filterable  = self::collect_filterable_fields();
		$markup_rows = [];
		foreach ( $attrs[ 'loupeFilterFields' ] as $field_key ) {
			$field_key     = sanitize_key( $field_key );
			$label         = isset( $filterable[ $post_type ][ $field_key ][ 'label' ] ) ? esc_html( $filterable[ $post_type ][ $field_key ][ 'label' ] ) : esc_html( $field_key );
			$markup_rows[] = '<label class="wp-loupe-filter-field" style="display:block;margin:4px 0;">' . $label . ' <input type="text" name="loupe_filter[' . esc_attr( $field_key ) . ']" /></label>';
		}
		if ( empty( $markup_rows ) ) {
			return $block_content; // Nothing to append.
		}
		$fieldset = '<fieldset class="wp-loupe-filters" style="margin-top:8px;padding:8px;border:1px solid #ddd;">'
			. '<legend style="font-weight:600;">' . esc_html__( 'Filters', 'wp-loupe' ) . '</legend>'
			. implode( '', $markup_rows )
			. '<p class="description" style="margin:6px 0 0;font-size:12px;">' . esc_html__( 'Values entered here will be submitted along with the search query. (Further integration needed for server-side filtering.)', 'wp-loupe' ) . '</p>'
			. '</fieldset>';

		// Append before closing form tag.
		if ( false !== strpos( $block_content, '</form>' ) ) {
			$block_content = str_replace( '</form>', $fieldset . '</form>', $block_content );
		}
		return $block_content;
	}

	/**
	 * Pretty label for a field key.
	 */
	private static function prettify_label( $key ) {
		return ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
	}

	/**
	 * Override the default theme search form to include Loupe filters if enabled.
	 *
	 * Reads first public post type with filterable fields and displays them.
	 * Users can disable by removing the filter (add_filter with remove_filter in theme).
	 *
	 * @param string $form Existing form HTML
	 * @return string Modified form HTML
	 */
	public static function override_default_search_form( $form ) {
		// Debug log always to confirm hook invocation.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WP_Loupe_Blocks] get_search_form filter invoked.' );
		}
		// Avoid overriding in admin or if a block form is already used (heuristic: presence of wp-block-search class).
		if ( function_exists( 'is_admin' ) && is_admin() || strpos( $form, 'wp-block-search' ) !== false ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[WP_Loupe_Blocks] Skipping override (admin or block search detected).' );
			}
			return $form;
		}

		$filterable = self::collect_filterable_fields();
		if ( empty( $filterable ) ) {
			WP_Loupe_Utils::debug_log( 'No filterable fields found for default search form override.', 'WP Loupe' );
			return $form; // Nothing to add.
		}
		// Pick first post type with at least one filterable field.
		$post_type = null;
		$fields    = [];
		foreach ( $filterable as $pt => $list ) {
			if ( ! empty( $list ) ) {
				$post_type = $pt;
				$fields    = $list;
				break;
			}
		}
		if ( ! $post_type || empty( $fields ) ) {
			WP_Loupe_Utils::debug_log( 'No valid post type or fields found for default search form override.', 'WP Loupe' );
			return $form;
		}

		// Inject hidden post_type marker & filter inputs just before closing form tag.
		$filters_markup = '<div class="wp-loupe-default-filters" style="margin-top:8px;">'
			. '<input type="hidden" name="loupe_post_type" value="' . esc_attr( $post_type ) . '" />'
			. '<details style="margin:6px 0;">'
			. '<summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Refine search', 'wp-loupe' ) . '</summary>';
		foreach ( $fields as $field_key => $meta ) {
			$label           = isset( $meta[ 'label' ] ) ? $meta[ 'label' ] : $field_key;
			$filters_markup .= '<label style="display:block;margin:4px 0 0;">' . esc_html( $label ) . ' '
				. '<input type="text" name="loupe_filter[' . esc_attr( $field_key ) . ']" /></label>';
		}
		$filters_markup .= '<p class="description" style="margin:6px 0 0;font-size:12px;">' . esc_html__( 'Filters are optional; leave blank for broader matches.', 'wp-loupe' ) . '</p>';
		$filters_markup .= '</details></div>';

		if ( false !== strpos( $form, '</form>' ) ) {
			$form = str_replace( '</form>', $filters_markup . '</form>', $form );
		}
		return $form;
	}

	/**
	 * Shortcode: [loupe_search post_type="post" fields="post_author,post_date"]
	 * Renders a search form with optional Loupe filter inputs irrespective of theme implementation.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_loupe_search_form( $atts ) {
		$atts = shortcode_atts( [
			'post_type' => 'post',
			'fields'    => '', // comma list or empty for all filterable of post_type.
		], $atts, 'loupe_search' );

		$post_type = sanitize_key( $atts[ 'post_type' ] );
		$available = self::collect_filterable_fields();
		$fields    = isset( $available[ $post_type ] ) ? $available[ $post_type ] : [];

		// Narrow list if fields attr provided.
		if ( ! empty( $atts[ 'fields' ] ) ) {
			$requested = array_filter( array_map( 'trim', explode( ',', $atts[ 'fields' ] ) ) );
			$filtered  = [];
			foreach ( $requested as $rk ) {
				$rk = sanitize_key( $rk );
				if ( isset( $fields[ $rk ] ) ) {
					$filtered[ $rk ] = $fields[ $rk ];
				}
			}
			if ( ! empty( $filtered ) ) {
				$fields = $filtered;
			}
		}

		$action  = esc_url( home_url( '/' ) );
		$html    = '<form role="search" method="get" class="search-form wp-loupe-shortcode-form" action="' . $action . '">';
		$html   .= '<label class="screen-reader-text" for="loupe-s">' . esc_html__( 'Search for:', 'wp-loupe' ) . '</label>';
		$html   .= '<input type="search" id="loupe-s" class="search-field" placeholder="' . esc_attr__( 'Search …', 'wp-loupe' ) . '" value="" name="s" />';
		$html   .= '<input type="hidden" name="loupe_post_type" value="' . esc_attr( $post_type ) . '" />';

		if ( ! empty( $fields ) ) {
			$html .= '<fieldset class="wp-loupe-shortcode-filters" style="margin-top:8px;padding:8px;border:1px solid #ddd;">';
			$html .= '<legend style="font-weight:600;">' . esc_html__( 'Filters', 'wp-loupe' ) . '</legend>';
			foreach ( $fields as $field_key => $data ) {
				$label  = isset( $data[ 'label' ] ) ? $data[ 'label' ] : $field_key;
				$html  .= '<label style="display:block;margin:4px 0 0;">' . esc_html( $label ) . ' <input type="text" name="loupe_filter[' . esc_attr( $field_key ) . ']" /></label>';
			}
			$html .= '<p class="description" style="margin:6px 0 0;font-size:12px;">' . esc_html__( 'Add filter values or leave blank for broader matches.', 'wp-loupe' ) . '</p>';
			$html .= '</fieldset>';
		}

		$html .= '<button type="submit" class="search-submit">' . esc_html__( 'Search', 'wp-loupe' ) . '</button>';
		$html .= '</form>';
		return $html;
	}
}
