<?php
namespace Soderlind\Plugin\WPLoupe;

/**
 * Legacy combined search class.
 *
 * This class used to both intercept WordPress search queries (via hooks) and
 * implement the Loupe search engine. It is kept for backward compatibility.
 *
 * @deprecated 0.6.0 Use WP_Loupe_Search_Engine (side-effect free) and
 *                   WP_Loupe_Search_Hooks (front-end only integration).
 */
class WP_Loupe_Search {
	/** @var WP_Loupe_Search_Engine */
	private $engine;
	/** @var WP_Loupe_Search_Hooks|null */
	private $hooks;
	/** @var string|null */
	private $log;

	/**
	 * @param array $post_types
	 */
	public function __construct( $post_types ) {
		if ( function_exists( '_deprecated_function' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			_deprecated_function( __CLASS__, '0.6.0', __NAMESPACE__ . '\\WP_Loupe_Search_Engine' );
		}

		$this->engine = new WP_Loupe_Search_Engine( (array) $post_types );

		// Backward-compat: still intercept front-end queries if instantiated.
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->hooks = new WP_Loupe_Search_Hooks( $this->engine );
			$this->hooks->register();
		}
	}

	/**
	 * Execute a search.
	 *
	 * @param string $query
	 * @return array
	 */
	public function search( $query ) {
		$hits      = $this->engine->search( $query );
		$this->log = $this->engine->get_log();
		return $hits;
	}

	/**
	 * Backward compatible hook callback.
	 */
	public function posts_pre_query( $posts, \WP_Query $query ) {
		if ( ! $this->hooks ) {
			return null;
		}
		return $this->hooks->posts_pre_query( $posts, $query );
	}

	/**
	 * Backward compatible footer timing callback.
	 */
	public function action_wp_footer(): void {
		if ( ! $this->hooks ) {
			return;
		}
		$this->hooks->action_wp_footer();
	}

	/**
	 * @return string|null
	 */
	public function get_log() {
		return $this->log;
	}
}
