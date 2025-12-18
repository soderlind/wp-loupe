# WP Loupe Search API (Developer Guide)

WP Loupe exposes a REST API that lets you build your own search UI (theme template, JS widget, Gutenberg block, React app, etc.) on top of the same index WP Loupe uses internally.

**Endpoints**

- **GET** ` /wp-json/wp-loupe/v1/search?q=...` (legacy, kept for backward compatibility)
- **POST** ` /wp-json/wp-loupe/v1/search` (recommended: JSON filters, facets, geo, rich sorting)

## Concepts

### Index readiness

Search requires a ready index per post type.

- When `postTypes: "all"` is used, WP Loupe only searches post types that have a ready index.
- If **no** configured post type has a ready index, the API returns **HTTP 400**.

### Allowlisted fields

Filtering, sorting, facets, and geo operations are restricted to fields that are explicitly enabled in **Settings → WP Loupe**.

- Filter fields must be enabled as **Filterable**
- Sort fields must be enabled as **Sortable**
- Facet fields must be enabled as **Filterable** (terms facet)
- Geo requires a dedicated geo-point field:
  - Geo radius filtering requires the field to be **Filterable**
  - Geo distance sorting (`geo.sort`) requires the field to be **Sortable**

If you request an operation on a non-allowlisted field, the API returns **HTTP 400**.

#### Preparing fields (hooks + data shape)

WP Loupe’s REST API only lets clients **filter/sort/facet/geo** on fields that are enabled in the schema.

Most sites will configure this in **Settings → WP Loupe → Field Settings**.
If you’re building an integration or need to enforce fields programmatically, use the schema hooks below.

##### 1) Facets (terms)

Terms facets require the field to be:

- indexed (`indexable: true`)
- allowlisted as filterable (`filterable: true`)
- stored as a string or an array of strings

Example: add a facet field backed by post meta.

```php
// 1) Allowlist the field in the schema.
add_filter( 'wp_loupe_schema_post', function ( array $schema ): array {
  $schema['audience'] = [
    'weight'         => 1.0,
    'indexable'      => true,
    'filterable'     => true,  // enables filtering + terms facets
    'sortable'       => false,
    'sort_direction' => 'desc',
  ];
  return $schema;
} );

// 2) Store the value in post meta as string or array of strings.
//    (Arrays become multi-valued facets.)
add_action( 'save_post', function ( int $post_id ) {
  // Example: multi-valued facet.
  update_post_meta( $post_id, 'audience', [ 'beginner', 'developer' ] );
} );
```

##### 2) Geo (radius filtering + distance sorting)

Geo requires a dedicated geo-point field stored as an array:

```php
// Stored as post meta:
// [ 'lat' => 59.9139, 'lng' => 10.7522 ]
// (or use 'lon' instead of 'lng')
```

To enable geo features:

- Geo **radius filtering** requires the field to be **Filterable**.
- Geo **distance sorting** (`geo.sort`) requires the field to be **Sortable**.

Example:

```php
// 1) Allowlist the geo field.
add_filter( 'wp_loupe_schema_post', function ( array $schema ): array {
  $schema['location'] = [
    'weight'         => 1.0,
    'indexable'      => true,
    'filterable'     => true, // required for geo radius filtering
    'sortable'       => true, // required for geo.sort distance ordering
    'sort_direction' => 'asc',
  ];
  return $schema;
} );

// 2) Store the geo-point in post meta.
add_action( 'save_post', function ( int $post_id ) {
  update_post_meta( $post_id, 'location', [
    'lat' => 59.9139,
    'lng' => 10.7522,
  ] );
} );
```

If your geo field is stored as post meta and you need to override “meta sortability” decisions, you can use:

```php
add_filter( 'wp_loupe_is_safely_sortable_meta_post', function ( bool $is_sortable, string $field_name ): bool {
  if ( 'location' === $field_name ) {
    return true;
  }
  return $is_sortable;
}, 10, 2 );
```

##### 3) Sorting (non-geo)

Sorting requires the field to be:

- indexed (`indexable: true`)
- allowlisted as sortable (`sortable: true`)
- stored as a scalar (string/number) for post meta fields

Example:

```php
add_filter( 'wp_loupe_schema_post', function ( array $schema ): array {
  $schema['rating'] = [
    'weight'         => 1.0,
    'indexable'      => true,
    'filterable'     => false,
    'sortable'       => true,
    'sort_direction' => 'desc',
  ];
  return $schema;
} );

add_action( 'save_post', function ( int $post_id ) {
  update_post_meta( $post_id, 'rating', 4.7 );
} );
```

Note: if you already have data in meta, you typically only need the schema hook + a reindex.

## POST /search

### Request body

```json
{
  "q": "search text",
  "postTypes": "all",
  "page": { "number": 1, "size": 10 },
  "filter": {
    "type": "and",
    "items": [
      { "type": "pred", "field": "category", "op": "eq", "value": "news" },
      { "type": "pred", "field": "post_author", "op": "eq", "value": 123 }
    ]
  },
  "sort": [
    { "by": "_score", "order": "desc" },
    { "by": "post_date", "order": "desc" }
  ],
  "facets": [
    { "type": "terms", "field": "category", "size": 10, "minCount": 1 }
  ],
  "geo": {
    "field": "location",
    "near": { "lat": 59.9139, "lon": 10.7522 },
    "radiusMeters": 5000,
    "sort": { "order": "asc" },
    "includeDistance": true
  }
}
```

#### Top-level properties

- `q` (string, required): the search query.
- `postTypes` (`"all"` | string[], optional, default `"all"`): which post types to search.
  - `"all"` resolves to the subset of configured post types that have a ready index.
- `page.number` (int, optional, default 1): 1-based page.
- `page.size` (int, optional, default 10): page size (1–100).
- `filter` (object, optional): JSON filter AST (see below).
- `sort` (array, optional): sorting instructions.
- `facets` (array, optional): terms facets.
- `geo` (object, optional): geo radius + geo sorting.

Notes:

- Fields used in `filter`, `sort`, `facets`, and `geo` must be allowlisted in Settings (see **Allowlisted fields** above).
- For geo, `geo.near.lon` is supported; `geo.near.lng` is also accepted for convenience.

### Response

```json
{
  "hits": [
    {
      "id": 123,
      "post_type": "post",
      "post_type_label": "Post",
      "title": "Example title",
      "excerpt": "…",
      "url": "https://example.test/example",
      "_score": 12.345,
      "_distanceMeters": 3210
    }
  ],
  "facets": {
    "category": {
      "type": "terms",
      "buckets": [
        { "value": "news", "count": 12 },
        { "value": "events", "count": 4 }
      ]
    }
  },
  "pagination": {
    "total": 42,
    "per_page": 10,
    "current_page": 1,
    "total_pages": 5
  },
  "tookMs": 8
}
```

Notes:

- `_score` is always included.
- `_distanceMeters` is included only when `geo.includeDistance` is `true`.

## Filter AST (JSON)

WP Loupe accepts a structured JSON filter. The server translates it into the underlying Loupe filter syntax.

### Groups

- `{ "type": "and", "items": [ <expr>, <expr>, ... ] }`
- `{ "type": "or",  "items": [ <expr>, <expr>, ... ] }`
- `{ "type": "not", "item": <expr> }`

### Predicates

Predicates use a single shape:

```json
{ "type": "pred", "field": "fieldName", "op": "eq", "value": "example" }
```

Supported `op` values:

- `eq`, `ne`
- `in`, `nin` (value must be a non-empty array)
- `lt`, `lte`, `gt`, `gte`
- `between` (value must be `[min, max]` or `{ "min": ..., "max": ... }`)
- `exists` (value must be boolean)

### Literal values

The API accepts:

- strings
- numbers
- booleans
- `null`
- dates as either:
  - date-only `YYYY-MM-DD`
  - ISO-8601 timestamp (e.g. `2025-12-18T10:11:12Z`)

## Facets

Only **terms** facets are supported.

```json
{
  "facets": [
    { "type": "terms", "field": "category", "size": 10, "minCount": 1 }
  ]
}
```

## Geo search

```json
{
  "geo": {
    "field": "location",
    "near": { "lat": 59.9139, "lon": 10.7522 },
    "radiusMeters": 5000,
    "sort": { "order": "asc" },
    "includeDistance": true
  }
}
```

- `field` must be a geo-point field.
- `near` is required.
- `radiusMeters` is optional; when present, the server filters to that radius.
- `sort.order` can be `"asc"` or `"desc"`.

## Errors

Errors are returned as WordPress REST errors with **HTTP 400**.

Example (missing `q`):

```json
{
  "code": "wp_loupe_missing_query",
  "message": "Missing or empty query parameter \"q\".",
  "data": { "status": 400 }
}
```

## Gutenberg block example (client-side search)

This is a minimal example showing how a custom block could call the POST endpoint.

### 1) Install dependencies

If you build blocks with `@wordpress/scripts`, you’ll typically already have these:

- `@wordpress/api-fetch`
- `@wordpress/element`
- `@wordpress/components`

### 2) Example `edit` implementation

```js
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { TextControl, Spinner } from '@wordpress/components';

export default function Edit() {
	const [query, setQuery] = useState('');
	const [isLoading, setIsLoading] = useState(false);
	const [hits, setHits] = useState([]);

	const body = useMemo(() => ({
		q: query,
		postTypes: 'all',
		page: { number: 1, size: 10 },
    sort: [ { by: '_score', order: 'desc' } ],
	}), [query]);

	useEffect(() => {
		let cancelled = false;
		if (!query.trim()) {
			setHits([]);
			return () => { cancelled = true; };
		}

		setIsLoading(true);
		apiFetch({
			path: '/wp-loupe/v1/search',
			method: 'POST',
			data: body,
		}).then((res) => {
			if (cancelled) return;
			setHits(res?.hits || []);
		}).catch(() => {
			if (cancelled) return;
			setHits([]);
		}).finally(() => {
			if (cancelled) return;
			setIsLoading(false);
		});

		return () => { cancelled = true; };
	}, [body, query]);

	return (
		<div className="wp-loupe-search-block">
			<TextControl
				label="Search"
				value={ query }
				onChange={ setQuery }
				placeholder="Type to search…"
			/>
			{ isLoading ? <Spinner /> : null }
			<ul>
        { hits.map((h) => (
					<li key={ h.id }>
            <a href={ h.url }>{ h.title }</a>
					</li>
				)) }
			</ul>
		</div>
	);
}
```

## PHP example (server-side call)

```php
$response = wp_remote_post(
	rest_url( 'wp-loupe/v1/search' ),
	[
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode(
			[
				'q'        => 'wordpress',
				'postTypes' => 'all',
				'page'     => [ 'number' => 1, 'size' => 10 ],
			]
		),
	]
);

$body = json_decode( wp_remote_retrieve_body( $response ), true );
```
