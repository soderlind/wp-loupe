<?php
// Lightweight PHPUnit bootstrap for WP Loupe.
// No full WP stack; only minimal shims/mocks for functions referenced in unit tests.

require_once __DIR__ . '/../vendor/autoload.php';

// Basic WP function shims (only those actually touched by tested units). If a test needs more, add here.
// Simple in-memory option store shared across calls.
global $wp_loupe_test_options, $wp_loupe_test_transients;
$wp_loupe_test_options    = [];
$wp_loupe_test_transients = [];
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
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) {
		return rtrim( $s, '/' );
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
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $v ) {
		return null;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) { /* no-op */
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
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

// Shut down Monkey after suite.
// (No shutdown handler needed in this lightweight bootstrap)
