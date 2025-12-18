> **0.5.2 and later, require PHP 8.3**

# WP Loupe - Enhanced WordPress Search

A search enhancement plugin for WordPress that builds a fast, typo-tolerant index and exposes a developer-friendly API so you can build your own search UI.

## Quick Links

[Installation](#installation) | [REST API](#rest-api) | [Search API Docs](docs/search-api.md) | [Settings](#settings) | [Filters](#filters) | [MCP Docs](docs/mcp.md) | [Changelog](CHANGELOG.md)


## Overview

WP Loupe transforms WordPress's search functionality by:

- Creating a dedicated search index for lightning-fast results
- Supporting typo-tolerant searches
- Automatically maintaining the search index
- Providing a stable REST API for custom search experiences

> Integrating with external agents or automation? See **[docs/mcp.md](docs/mcp.md)**.

## REST API

WP Loupe exposes search via REST endpoints:

- **POST** `/wp-json/wp-loupe/v1/search` (recommended; supports JSON filters, facets, geo, and explicit sorting)
- **GET** `/wp-json/wp-loupe/v1/search?q=...` (legacy; kept for backward compatibility)

Developer documentation (schema + examples + Gutenberg block example): **[docs/search-api.md](docs/search-api.md)**

## MCP (Model Context Protocol) Integration (Summary)

WP Loupe ships with an optional MCP server enabling external AI agents or automation tools to discover commands and query your site.

Key points:
- Discovery endpoints: `/.well-known/mcp.json` & `/.well-known/oauth-protected-resource` (enable in Settings → WP Loupe → MCP)
- Hybrid access: anonymous users can run limited `searchPosts`; tokens increase limits & unlock `healthCheck`
- Token UI: create, scope-limit, set TTL (1–168h) or indefinite (0), revoke individually or all
- Last-used tracking for tokens; copy raw token once on creation
- Configurable rate limits (window, per-window quotas, max hits) via admin UI + filter overrides
- WP-CLI command for issuing tokens (mirrors into UI registry)
- Secure pagination cursors (HMAC) and standardized envelope responses

Full details, filter references, and examples: see [docs/mcp.md](docs/mcp.md).

## Features

- Fast index-backed search for configured post types
- Typo-tolerance (Loupe)
- Per-field weighting, filterable fields, sortable fields (configured in Settings)
- Developer-facing REST API for building custom UIs
- Optional MCP server for external agent/automation access

## Installation

1. **Quick Install**

   - Download [`wp-loupe.zip`](https://github.com/soderlind/wp-loupe/releases/latest/download/wp-loupe.zip)
   - Upload via WordPress Plugins > Add New > Upload Plugin

2. **Composer Install**

   ```bash
   composer require soderlind/wp-loupe
   ```

3. **Post-Installation**
   - Activate the plugin
   - Go to Settings > WP Loupe
   - Click "Reindex" to build the initial search index

* Plugin [updates are handled automatically](https://github.com/soderlind/wordpress-plugin-github-updater#readme) via GitHub. No need to manually download and install updates.

## Building Your Own Search UI

WP Loupe works out of the box with WordPress’s standard search.
If your theme uses the normal search flow (e.g. a search form that routes to the built-in search results page), WP Loupe will power the results automatically — no custom UI required.

WP Loupe intentionally does **not** ship a front-end search block/shortcode UI.
If you want a custom search experience (autocomplete, filters/facets, geo, custom sorting, etc.), build the UI you want and query WP Loupe via the REST API.

Start here: **[docs/search-api.md](docs/search-api.md)**

## Settings

You can configure WP Loupe's search behavior and performance via the WordPress admin: Settings > WP Loupe.


### General Settings

#### Post Types
Select which post types to include in the search index.

#### Field Weight
Weight determines how important a field is in search results:

- Higher weight (e.g., 2.0) makes matches in this field more important in results ranking.
- Default weight is 1.0.
- Lower weight (e.g., 0.5) makes matches less important but still searchable.

#### Filterable Fields
Filterable fields can be used to refine search results:

- Enable this option to allow filtering search results by this field's values.
- Best for fields with consistent, categorized values like taxonomies, status fields, or controlled metadata.
- Examples: categories, tags, post type, author, or custom taxonomies.

Note: Fields with highly variable or unique values (like content) make poor filters as each post would have its own filter value.


#### Sortable Fields
Sortable fields can be used to order search results:

- Enable this option to allow sorting search results by this field's values
- Works best with numerical fields, dates, or short text values
- Examples: date, price, rating, title

### Advanced Settings

WP Loupe provides advanced configuration options to fine-tune your search experience:

#### Prefix Search

- Configure prefix search behavior. Prefix search allows finding terms by typing only the beginning (e.g., "huck" finds "huckleberry").
- Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get accurate results. E.g. `my friend huck` would find documents containing huckleberry - `huck is my friend`, however, would not.

#### Typo Tolerance

- **Enable Typo Tolerance**: When enabled, searches will match terms with minor spelling errors.
- **First Character Double Counting**: When enabled, typos in the first character of a word will count as two errors instead of one.
- **Typo Tolerance for Prefix Search**: Allows typo tolerance in partial word searches.
- **Alphabet Size**: Define the size of the alphabet for typo calculations.
- **Index Length**: Configure the maximum length of indexed terms.
- **Typo Thresholds**: Set the minimum word length required for allowing different numbers of typos.

#### Query Parameters

- **Maximum Query Tokens**: Limits the number of words processed in a search query (default: 12).
- **Minimum Prefix Length**: Sets the minimum character length before prefix search activates (default: 3).

#### Languages

- Configure which languages the search index should optimize for. Default is English ('en').

These advanced settings can be accessed in the WordPress admin under Settings > WP Loupe > Advanced tab.

## FAQ

### How does it handle updates to posts?

The search index automatically updates when content is created, modified, or deleted.

### Will it slow down my site?

No. WP Loupe uses a separate, optimized search index and doesn't impact your main database performance.

### Can I customize what content is searchable?

Yes, using filters you can control exactly what content gets indexed and how it's searched.

### Does it work with custom post types?

Yes, you can select which post types to include in the Settings page or via filters.

## Filters

### `wp_loupe_db_path`

This filter allows you to change the path where the Loupe database files are stored. By default, it's in the `WP_CONTENT_DIR .'/wp-loupe-db'` directory.

```php
add_filter( 'wp_loupe_db_path', function ( $path ) {
	return WP_CONTENT_DIR . '/my-path';
} );
```

### `wp_loupe_post_types`

This filter allows you to modify the array of post types that the WP Loupe plugin works with. By default, it includes 'post' and 'page'.

```php
add_filter( 'wp_loupe_post_types', [ 'post', 'page', 'book' ] );
```

### `wp_loupe_posts_per_page`

This filter allows you to modify the number of search results per page. By default it's 10, set in `WPAdmin->Settings->Reading->"Blog pages show at most"`.

```php
add_filter( 'wp_loupe_posts_per_page', 20 );
```

### `wp_loupe_index_protected`

This filter allows you to index posts and pages that are protected by a password. By default, it's set to `false`.

```php
add_filter( 'wp_loupe_index_protected','__return_true' );
```

### `wp_loupe_field_{$field_name}`

This filter allows you to change the field content before it is indexed.

By default, the following is used to remove HTML tags and comments from `post_content`. Among others, it removes the WordPress block comments.

```php
add_filter( 'wp_loupe_field_post_content', 'wp_strip_all_tags' );
```

### `wp_loupe_schema_{$post_type}`

Modify the search schema for a specific post type. The filter name is dynamically generated based on the post type.

```php
// Customize the schema for 'book' post type
add_filter( 'wp_loupe_schema_book', function( $schema ) {
	$schema['book_isbn'] = [		// Add a new field
		'weight'     => 2.0,		// Higher weight means higher relevance in search results
		'filterable' => true,		// Allow filtering by this field
		'sortable'   => [			// Allow sorting by this field
			'direction' => 'asc'	// Default sort direction
		],
	];

	// Modify existing field settings
	$schema['post_title']['weight'] = 3.0; // Increase title weight for books

	// Remove a field
	unset( $schema['post_excerpt'] );


	$schema['book_author'] = [
		'weight'     => 1.5,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'asc' ],
	];

	return $schema;
});
```

The schema configuration supports the following options for each field:

- `weight` (float): The relevance weight in search results. Default: 1.0
- `filterable` (bool): Whether the field can be used for filtering. Default: false
- `sortable` (array): Sorting configuration with `direction` key ('asc' or 'desc'). Default: null

Default schema fields:

```php
[
	'post_title'   => [
		'weight'     => 2,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'asc' ],
	],
	'post_content' => [ 'weight' => 1.0],
	'post_excerpt' => [ 'weight' => 1.5 ],
	'post_date'    => [
		'weight'     => 1.0,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'desc' ],
	],
	'post_author'  => [
		'weight'     => 1.0,
		'filterable' => true,
		'sortable'   => [ 'direction' => 'asc' ],
	],
	'permalink'    => [ 'weight' => 1.0 ],
]
```


## Technical Requirements

- PHP 8.3
- SQLite 3.35+ (required by Loupe 0.13.x)
- PHP extensions: `pdo_sqlite`, `intl`, `mbstring`
- WordPress 6.7+

This architecture provides a balance between search quality, performance, and ease of integration with WordPress.


## Acknowledgements

- WP Loupe is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.
- The plugin uses [Select2](https://select2.org/) for the post type selection dropdown. Select2 is licensed under the MIT license.
- The plugin uses Yannick Lefebvre's [WP Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker?tab=readme-ov-file#github-integration) for updates. WP Plugin Update Checker is licensed under the MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.

<!--
TOC MAINTENANCE
The Table of Contents near the top of this file is maintained manually (no automated script in build pipeline).
Update procedure when headings change:
1. Identify new/renamed/removed headings at levels ## and important ### subsections.
2. Derive anchors (GitHub algorithm: lowercase, spaces -> dashes, remove most punctuation).
3. Insert/update list items inside the <!-- TOC BEGIN --> / <!-- TOC END --> block.
4. Keep indentation with tabs (current style) or convert uniformly if you restyle the list.
5. Avoid adding very small, single-sentence subsections to keep TOC scannable.
-->
