<?php
// Lightweight PHPUnit bootstrap for WP Loupe.
// No full WP stack; only minimal shims/mocks for functions referenced in unit tests.

require_once __DIR__ . '/../vendor/autoload.php';

// Ensure Patchwork is loaded before any shim functions are defined.
// This allows Brain Monkey to redefine functions like add_action/add_filter in tests.
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';

require_once __DIR__ . '/wp-shims-hooks.php';

// Explicitly require new classes added in this branch.
// Composer's classmap autoloader in vendor/ is not regenerated automatically here.
require_once __DIR__ . '/../includes/class-wp-loupe-search-engine.php';
require_once __DIR__ . '/../includes/class-wp-loupe-search-hooks.php';

// Basic WP function shims (only those actually touched by tested units). If a test needs more, add here.
// Simple in-memory option store shared across calls.
global $wp_loupe_test_options, $wp_loupe_test_transients;
$wp_loupe_test_options    = [];
$wp_loupe_test_transients = [];

// Simple post/meta store for tests that need post meta and WP_Query.
global $wp_loupe_test_posts, $wp_loupe_test_post_meta;
$wp_loupe_test_posts     = []; // [ post_id => [ 'post_type' => 'post' ] ]
$wp_loupe_test_post_meta = []; // [ post_id => [ meta_key => meta_value ] ]
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $wp_loupe_test_options;
		return $wp_loupe_test_options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		global $wp_loupe_test_options;
		$wp_loupe_test_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		global $wp_loupe_test_options;
		unset( $wp_loupe_test_options[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		global $wp_loupe_test_post_meta;
		if ( ! isset( $wp_loupe_test_post_meta[ $post_id ] ) ) {
			$wp_loupe_test_post_meta[ $post_id ] = [];
		}
		$wp_loupe_test_post_meta[ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $meta_key, $single = true ) {
		global $wp_loupe_test_post_meta;
		if ( isset( $wp_loupe_test_post_meta[ $post_id ] ) && array_key_exists( $meta_key, $wp_loupe_test_post_meta[ $post_id ] ) ) {
			return $wp_loupe_test_post_meta[ $post_id ][ $meta_key ];
		}
		return $single ? '' : [];
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = [];
		private $have_posts = false;

		public function __construct( $args = [] ) {
			global $wp_loupe_test_posts, $wp_loupe_test_post_meta;
			$post_type = $args[ 'post_type' ] ?? null;
			$meta_key  = $args[ 'meta_key' ] ?? null;
			$limit     = isset( $args[ 'posts_per_page' ] ) ? (int) $args[ 'posts_per_page' ] : 5;

			$ids = [];
			foreach ( $wp_loupe_test_posts as $id => $row ) {
				if ( $post_type && ( $row[ 'post_type' ] ?? null ) !== $post_type ) {
					continue;
				}
				if ( $meta_key ) {
					$val = $wp_loupe_test_post_meta[ $id ][ $meta_key ] ?? null;
					if ( null === $val || '' === $val ) {
						continue;
					}
				}
				$ids[] = $id;
				if ( count( $ids ) >= $limit ) {
					break;
				}
			}

			$this->posts      = $ids;
			$this->have_posts = ! empty( $ids );
		}

		public function have_posts() {
			return $this->have_posts;
		}
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) {
		return rtrim( $s, '/' );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) {
		return rtrim( (string) $s, '/' ) . '/';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $wp_loupe_test_transients;
		if ( isset( $wp_loupe_test_transients[ $key ] ) ) {
			$row = $wp_loupe_test_transients[ $key ];
			if ( 0 !== $row[ 'expires' ] && time() > $row[ 'expires' ] ) {
				unset( $wp_loupe_test_transients[ $key ] );
				return false;
			}
			return $row[ 'value' ];
		}
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $val, $exp ) {
		global $wp_loupe_test_transients;
		if ( ! is_array( $wp_loupe_test_transients ) ) {
			$wp_loupe_test_transients = [];
		}
		$wp_loupe_test_transients[ $key ] = [ 'value' => $val, 'expires' => $exp ? time() + (int) $exp : 0 ];
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		global $wp_loupe_test_transients;
		unset( $wp_loupe_test_transients[ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'testsalt';
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		return $u;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return $t;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return $v;
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( $c ) {}
}
// Minimal WP_Error shim for tests (subset implementation)
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = (array) $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $v ) {
		return null;
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die() { /* no-op for tests */
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( intval( $maybeint ) );
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-root/' );
}

// Minimal $wpdb shim for REST meta key discovery queries.
if ( ! isset( $GLOBALS[ 'wpdb' ] ) ) {
	class WP_Loupe_Test_WPDB {
		public function prepare( $query, $arg ) {
			// very naive replacement for single %s
			return str_replace( '%s', addslashes( (string) $arg ), $query );
		}
		public function get_col( $sql ) {
			// Return empty list; tests only assert structure, not specific meta keys.
			return [];
		}
		public $postmeta = 'wp_postmeta';
		public $posts = 'wp_posts';
	}
	$GLOBALS[ 'wpdb' ] = new WP_Loupe_Test_WPDB();
}

// Additional shims required for REST search test.
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		// Provide richer WP_Post-like stub so REST enrichment passes.
		return (object) [
			'ID'           => (int) $id,
			'post_status'  => 'publish',
			'post_type'    => ( (int) $id % 2 === 0 ) ? 'post' : 'page', // alternate types deterministically
			'post_title'   => 'Title ' . $id,
			'post_content' => 'Content for ' . $id,
		];
	}
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $pt ) {
		return (object) [ 'labels' => (object) [ 'singular_name' => ucfirst( $pt ) ] ];
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) {
		return 'Title ' . $id;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		return 'https://example.test/post/' . $id;
	}
}
if ( ! function_exists( 'get_the_excerpt' ) ) {
	function get_the_excerpt( $id ) {
		return 'Excerpt ' . $id;
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $pt ) {
		// Include custom post types referenced in tests.
		return in_array( $pt, [ 'post', 'page', 'hlz_movie' ], true );
	}
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $pt ) {
		return [];
	}
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $tax ) {
		return false;
	}
}

// Shut down Monkey after suite.
// (No shutdown handler needed in this lightweight bootstrap)
