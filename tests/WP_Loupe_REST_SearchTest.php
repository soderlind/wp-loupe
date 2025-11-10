<?php
namespace Soderlind\Plugin\WPLoupe\Tests;

use PHPUnit\Framework\TestCase;
use Soderlind\Plugin\WPLoupe\WP_Loupe_REST;
use ReflectionClass;

/**
 * Integration-style test for WP_Loupe_REST search endpoint.
 *
 * We stub minimal WordPress functions & objects used inside the handler so we can
 * exercise sorting, pagination and response structure without a full WP bootstrap.
 */
class WP_Loupe_REST_SearchTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
	}

	/**
	 * Dummy search service to inject predictable hits (unordered by score).
	 */
	private function makeDummySearchService( array $hits ) {
		return new class ($hits) {
			private $hits;
			public function __construct( $h ) {
				$this->hits = $h;
			}
			public function search( $query ) {
				return $this->hits;
			}
		};
	}

	/**
	 * Create a minimal request stub implementing get_param().
	 */
	private function makeRequest( array $params ) {
		return new class ($params) {
			private $p;
			public function __construct( $p ) {
				$this->p = $p;
			}
			public function get_param( $k ) {
				return $this->p[ $k ] ?? null;
			}
		};
	}

	public function test_search_endpoint_sorts_and_paginates() {
		// Reset custom post type option so REST defaults to ['post','page'].
		update_option( 'wp_loupe_custom_post_types', [] );
		// Prepare predictable unsorted hits with varying scores.
		$dummyHits = [
			[ 'id' => 10, 'post_type' => 'post', '_score' => 2.5 ],
			[ 'id' => 11, 'post_type' => 'post', '_score' => 7.1 ],
			[ 'id' => 12, 'post_type' => 'page', '_score' => 5.0 ],
			[ 'id' => 13, 'post_type' => 'page', '_score' => 1.2 ],
			[ 'id' => 14, 'post_type' => 'post', '_score' => 9.4 ],
			// Add a duplicate score variant to exercise stable sorting.
			[ 'id' => 15, 'post_type' => 'post', '_score' => 9.4 ],
		];

		// Instantiate REST handler (constructor sets internal post types & search_service).
		$rest = new WP_Loupe_REST();

		// Inject dummy search service with reflection.
		$refl = new ReflectionClass( $rest );
		$prop = $refl->getProperty( 'search_service' );
		$prop->setAccessible( true );
		$prop->setValue( $rest, $this->makeDummySearchService( $dummyHits ) );

		// Build request: all post types, 2 per page, page 1.
		$request = $this->makeRequest( [
			'q'         => 'anything',
			'post_type' => 'all',
			'per_page'  => 2,
			'page'      => 1,
		] );

		$response = $rest->handle_search_request( $request );
		// rest_ensure_response() returns array via stub so we assert array form.
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'hits', $response );
		$this->assertArrayHasKey( 'pagination', $response );

		// Hits should be sorted descending by _score and limited to 2.
		$this->assertCount( 2, $response[ 'hits' ], 'Expected two hits on first page.' );
		$scores = array_column( $response[ 'hits' ], '_score' );
		$sorted = $scores;
		rsort( $sorted );
		$this->assertSame( $sorted, $scores, 'Hits not sorted by score desc' );
		$this->assertSame( 9.4, $scores[ 0 ] ); // Highest score first (id 14)

		// Pagination metadata.
		$this->assertSame( 6, $response[ 'pagination' ][ 'total' ] );
		$this->assertSame( 2, $response[ 'pagination' ][ 'per_page' ] );
		$this->assertSame( 1, $response[ 'pagination' ][ 'current_page' ] );
		$this->assertSame( 3, $response[ 'pagination' ][ 'total_pages' ] );
	}

	public function test_search_endpoint_single_post_type_filter() {
		// Reset custom post type option so REST defaults to ['post','page'].
		update_option( 'wp_loupe_custom_post_types', [] );
		// Test filtering by requesting 'all' but having mixed hits - verify only requested type appears.
		$dummyHits = [
			[ 'id' => 21, 'post_type' => 'post', '_score' => 1.0 ],
			[ 'id' => 22, 'post_type' => 'post', '_score' => 3.0 ],
			[ 'id' => 23, 'post_type' => 'page', '_score' => 2.0 ],
			[ 'id' => 24, 'post_type' => 'page', '_score' => 4.1 ],
		];

		$rest = new WP_Loupe_REST();
		$refl = new ReflectionClass( $rest );
		$prop = $refl->getProperty( 'search_service' );
		$prop->setAccessible( true );
		$prop->setValue( $rest, $this->makeDummySearchService( $dummyHits ) );

		// Request 'all' types so the main search_service is used (not a new narrowed instance).
		$request = $this->makeRequest( [
			'q'         => 'anything',
			'post_type' => 'all',
			'per_page'  => 10,
			'page'      => 1,
		] );

		$response = $rest->handle_search_request( $request );
		$this->assertIsArray( $response );
		// With 'all', the filter now limits to configured post_types (post & page by default).
		// All 3 hits should appear since both post & page are in the default post_types.
		$this->assertCount( 4, $response[ 'hits' ], 'Expected all 4 mixed-type hits.' );
		$postTypes = array_unique( array_column( $response[ 'hits' ], 'post_type' ) );
		sort( $postTypes );
		$this->assertSame( [ 'page', 'post' ], $postTypes );
	}
}
