<?php
namespace Soderlind\Plugin\LoupeWP;

class Loupe_WP_Loader {
	private static $instance = null;
	private $search;
	private $indexer;
	private $post_types;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->setup_post_types();
		$this->init_components();
		$this->register_hooks();
	}

	private function load_dependencies() {
		require_once LOUPE_WP_PATH . 'vendor/autoload.php';
		require_once LOUPE_WP_PATH . 'includes/class-loupe-wp-search.php';
		require_once LOUPE_WP_PATH . 'includes/class-loupe-wp-indexer.php';
		require_once LOUPE_WP_PATH . 'includes/class-loupe-wp-db.php';
		require_once LOUPE_WP_PATH . 'includes/class-loupe-wp-utils.php';
		require_once LOUPE_WP_PATH . 'includes/class-loupe-wp-settings.php';
	}

	private function setup_post_types() {
		add_filter( 'loupe_wp_post_types', array( $this, 'filter_post_types' ) );
		$this->post_types = apply_filters( 'loupe_wp_post_types', array( 'post', 'page' ) );
	}

	private function init_components() {
		$this->search  = new Loupe_WP_Search( $this->post_types );
		$this->indexer = new Loupe_WP_Indexer( $this->post_types );
	}

	private function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		// Register other global hooks
	}

	public function filter_post_types( $post_types ) {
		$options           = get_option( 'wp_loupe_custom_post_types', array() );
		$custom_post_types = ! empty( $options ) && isset( $options[ 'wp_loupe_post_type_field' ] )
			? (array) $options[ 'wp_loupe_post_type_field' ]
			: array();
		return array_merge( $post_types, $custom_post_types );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wp-loupe', false, dirname( plugin_basename( LOUPE_WP_FILE ) ) . '/languages' );
	}
}
