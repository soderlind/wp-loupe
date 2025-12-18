<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Soderlind\Plugin\WPLoupe\WP_Loupe_DB;
use Soderlind\Plugin\WPLoupe\WP_Loupe_Factory;
use Soderlind\Plugin\WPLoupe\WP_Loupe_REST;

class WP_Loupe_REST_SearchPostTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Ensure REST defaults to ['post','page'] regardless of other tests.
		update_option( 'wp_loupe_custom_post_types', [] );
	}

	private function seedIndex( string $post_type, array $docs ): void {
		$db    = WP_Loupe_DB::get_instance();
		$loupe = WP_Loupe_Factory::create_loupe_instance( $post_type, 'en', $db );
		// Ensure a fresh index for deterministic totals.
		$loupe->deleteAllDocuments();
		if ( ! empty( $docs ) ) {
			$loupe->addDocuments( $docs );
		}
	}

	private function makeJsonRequest( array $payload ) {
		return new class ($payload) {
			private $payload;
			public function __construct( $payload ) {
				$this->payload = $payload;
			}
			public function get_json_params() {
				return $this->payload;
			}
		};
	}

	private function setSchemaManagerStub( WP_Loupe_REST $rest, array $filterable, array $sortableFields ): void {
		$stub = new class ($filterable, $sortableFields) {
			private $filterable;
			private $sortable;
			public function __construct( $filterable, $sortable ) {
				$this->filterable = $filterable;
				$this->sortable   = $sortable;
			}
			public function get_schema_for_post_type( $pt ) {
				return [];
			}
			public function get_filterable_fields( $schema ) {
				return $this->filterable;
			}
			public function get_sortable_fields( $schema ) {
				return array_map( function ( $f ) {
					return [ 'field' => $f, 'direction' => 'desc' ];
				}, $this->sortable );
			}
		};

		$refl = new ReflectionClass( $rest );
		$prop = $refl->getProperty( 'schema_manager' );
		$prop->setAccessible( true );
		$prop->setValue( $rest, $stub );
	}

	public function test_post_search_invalid_payload_returns_error() {
		$rest    = new WP_Loupe_REST();
		$request = new class () {
			public function get_json_params() {
				return null;
			}
		};

		$result = $rest->handle_search_request_post( $request );
		$this->assertInstanceOf( '\\WP_Error', $result );
		$this->assertSame( 'wp_loupe_invalid_payload', $result->get_error_code() );
	}

	public function test_post_search_missing_query_returns_error() {
		$rest   = new WP_Loupe_REST();
		$result = $rest->handle_search_request_post( $this->makeJsonRequest( [ 'q' => '   ' ] ) );
		$this->assertInstanceOf( '\\WP_Error', $result );
		$this->assertSame( 'wp_loupe_missing_query', $result->get_error_code() );
	}

	public function test_post_search_invalid_page_size_returns_error() {
		$rest   = new WP_Loupe_REST();
		$result = $rest->handle_search_request_post(
			$this->makeJsonRequest( [
				'q'    => 'hello',
				'page' => [ 'number' => 1, 'size' => 0 ],
			] )
		);
		$this->assertInstanceOf( '\\WP_Error', $result );
		$this->assertSame( 'wp_loupe_invalid_page_size', $result->get_error_code() );
	}

	public function test_post_search_invalid_post_types_type_returns_error() {
		$rest   = new WP_Loupe_REST();
		$result = $rest->handle_search_request_post(
			$this->makeJsonRequest( [
				'q'         => 'hello',
				'postTypes' => 'post',
			] )
		);
		$this->assertInstanceOf( '\\WP_Error', $result );
		$this->assertSame( 'wp_loupe_invalid_post_types', $result->get_error_code() );
	}

	public function test_post_search_invalid_filter_node_returns_error() {
		// Ensure the index exists so we get to filter validation.
		$this->seedIndex( 'post', [
			[ 'id' => 1, 'post_title' => 'Hello' ],
		] );

		$rest = new WP_Loupe_REST();
		$this->setSchemaManagerStub( $rest, [ 'category' ], [ 'post_date' ] );

		$result = $rest->handle_search_request_post(
			$this->makeJsonRequest( [
				'q'         => 'hello',
				'postTypes' => [ 'post' ],
				'filter'    => [ 'foo' => 'bar' ],
			] )
		);

		$this->assertInstanceOf( '\\WP_Error', $result );
		$this->assertSame( 'wp_loupe_invalid_filter', $result->get_error_code() );
	}

	public function test_post_search_unallowlisted_facet_field_errors() {
		$this->seedIndex( 'post', [
			[ 'id' => 1, 'post_title' => 'Hello' ],
		] );

		$rest = new WP_Loupe_REST();
		$this->setSchemaManagerStub( $rest, [ 'category' ], [ 'post_date' ] );

		$result = $rest->handle_search_request_post(
			$this->makeJsonRequest( [
				'q'         => 'hello',
				'postTypes' => [ 'post' ],
				'facets'    => [
					[ 'type' => 'terms', 'field' => 'not_allowed', 'size' => 10 ],
				],
			] )
		);

		$this->assertInstanceOf( '\\WP_Error', $result );
		$this->assertSame( 'wp_loupe_unallowlisted_field', $result->get_error_code() );
	}

	public function test_post_search_happy_path_sorts_by_score_and_paginates() {
		$this->seedIndex( 'post', [
			[ 'id' => 1, 'post_title' => 'Hello world' ],
			[ 'id' => 2, 'post_title' => 'Hello hello hello' ],
		] );

		$rest = new WP_Loupe_REST();
		$this->setSchemaManagerStub( $rest, [ 'category' ], [ 'post_date' ] );

		$response = $rest->handle_search_request_post(
			$this->makeJsonRequest( [
				'q'         => 'hello',
				'postTypes' => [ 'post' ],
				'page'      => [ 'number' => 1, 'size' => 1 ],
				'sort'      => [ [ 'by' => '_score', 'order' => 'desc' ] ],
			] )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'hits', $response );
		$this->assertArrayHasKey( 'pagination', $response );
		$this->assertCount( 1, $response[ 'hits' ] );

		$hit = $response[ 'hits' ][ 0 ];
		$this->assertArrayHasKey( '_score', $hit );
		$this->assertIsFloat( (float) $hit[ '_score' ] );
		$this->assertArrayHasKey( 'url', $hit );

		$this->assertSame( 2, $response[ 'pagination' ][ 'total' ] );
		$this->assertSame( 1, $response[ 'pagination' ][ 'per_page' ] );
		$this->assertSame( 1, $response[ 'pagination' ][ 'current_page' ] );
		$this->assertSame( 2, $response[ 'pagination' ][ 'total_pages' ] );
	}
}
