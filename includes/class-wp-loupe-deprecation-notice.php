<?php
/**
 * Deprecation notice pointing users to Loupe Search.
 *
 * @package Soderlind\Plugin\WPLoupe
 * @since 0.8.6
 */

declare(strict_types=1);
namespace Soderlind\Plugin\WPLoupe;

/**
 * Renders a dismissible admin notice announcing that WP Loupe is superseded by
 * Loupe Search, and handles persisting the dismissal per user.
 *
 * @since 0.8.6
 */
class WP_Loupe_Deprecation_Notice {

	/**
	 * User meta key storing the dismissal flag.
	 *
	 * @var string
	 */
	private const META_KEY = 'wp_loupe_deprecation_notice_dismissed';

	/**
	 * Query argument used to trigger a dismissal.
	 *
	 * @var string
	 */
	private const DISMISS_ARG = 'wp_loupe_dismiss_deprecation';

	/**
	 * Nonce action for the dismissal link.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'wp_loupe_dismiss_deprecation';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_dismiss' ] );
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Handle the dismissal request.
	 *
	 * @return void
	 */
	public function maybe_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$nonce = isset( $_GET[ '_wpnonce' ] ) ? sanitize_text_field( wp_unslash( $_GET[ '_wpnonce' ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::META_KEY, '1' );

		wp_safe_redirect( remove_query_arg( [ self::DISMISS_ARG, '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Output the notice.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::META_KEY, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ARG, '1' ),
			self::NONCE_ACTION
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p><p><a href="%4$s" class="button button-primary">%5$s</a> <a href="%6$s" class="button">%7$s</a></p></div>',
			esc_html__( 'WP Loupe is now Loupe Search', 'wp-loupe' ),
			esc_html__( 'WP Loupe has been renamed to Loupe Search and is no longer maintained under this name. This is the final WP Loupe release; all further development, fixes, and security updates happen in Loupe Search.', 'wp-loupe' ),
			esc_html__( 'To migrate: install Loupe Search, deactivate WP Loupe, then activate Loupe Search. Both plugins hook WordPress search and index content, so running them at the same time will cause conflicts. Your existing index is reused, so no reindexing is required.', 'wp-loupe' ),
			esc_url( 'https://wordpress.org/plugins/loupe-search/' ),
			esc_html__( 'Get Loupe Search', 'wp-loupe' ),
			esc_url( 'https://github.com/soderlind/loupe-search/blob/main/docs/renamed-from-wp-loupe.md' ),
			esc_html__( 'Migration guide', 'wp-loupe' )
		);
	}
}
