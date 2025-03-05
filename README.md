# WP Loupe - Enhanced WordPress Search

A powerful search enhancement plugin for WordPress that delivers fast, accurate, and typo-tolerant search results.

## Quick Links

[Features](#features) | [Installation](#installation) | [Usage](#usage) | [FAQ](#faq) | [Filters](#filters) | [Behind the scenes](#behind-the-scenes) | [Changelog](CHANGELOG.md) | [TODO](TODO.md)

## Overview

WP Loupe transforms WordPress's search functionality by:

- Creating a dedicated search index for lightning-fast results
- Supporting typo-tolerant searches
- Offering phrase matching and advanced search operators
- Automatically maintaining the search index
- Providing customization options for developers

## Features

### Core Features

- 🚀 Enhanced search engine replacing WordPress default
- ⚡ Lightning-fast, precise result delivery
- 🔄 Real-time index synchronization
- 🌐 Support for multiple languages
- 📦 Full custom post type integration
- 📈 Integrated search performance metrics
- ✅ Seamless compatibility with WordPress default themes

### Search Capabilities

- 🔍 Typo-tolerant searching
- "..." Phrase matching with quotation marks
- `-` Exclusion operator support
- 📖 Pagination support

### Developer Features

- 🛠️ Extensive filter system
- 📊 Performance monitoring
- 🔧 Customizable indexing

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

As of version 0.1.4, plugin updates are handled automatically via GitHub. No need to manually download and install updates.

## Usage

### Basic Search

- Type normally in your search box
- Results are instant and typo-tolerant
- Matches are highlighted in results

### Advanced Search Operators

- `Hello World` will search for posts containing `Hello` **or** `World`.
- `"Hello World"` will search for posts containing the phrase `Hello World`.
- `Hello -World` will search for posts containing `Hello` but not `World`.

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
add_filter( 'wp_loupe_db_path', WP_CONTENT_DIR . '/my-path' );
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

### `wp_loupe_schema_content`

This filter allows you to modify the content before it's indexed. By default, removes HTML comments from content (i.e. remove WordPress block comments).

```php
add_filter('wp_loupe_schema_content', function($content) {
	// Modify content before indexing
	$content = preg_replace('/<!--(.*?)-->/s', '', $content);
	return $content;
});
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

## Behind the scenes

### Content Indexing Dataflow in WP Loupe

WP Loupe follows a structured approach to indexing and searching content in WordPress. Here's how it works:

#### Initial Setup and Configuration

1. **Plugin Initialization**: The `WP_Loupe_Indexer` class is instantiated with an array of post types to be indexed
2. **Database Connection**: It retrieves a `WP_Loupe_DB` singleton instance
3. **Schema Management**: Creates a `WP_Loupe_Schema_Manager` for handling data structures
4. **Search Engine Setup**: Initializes Loupe search engine instances (one per post type)
5. **WordPress Integration**: Registers WordPress hooks for content lifecycle events

#### Post Indexing Flow (When Content is Created/Updated)

1. **Content Creation Event**: WordPress triggers the `save_post` hook when a post is published or updated
2. **Validation**: The indexer validates if the post should be indexed:
   - Confirms the post is published
   - Verifies it's not password-protected (configurable via filter)
   - Checks if it belongs to a registered post type
3. **Document Preparation**: The indexer calls `prepare_document()` which:
   - Retrieves the schema for the post's type via `get_schema_for_post_type()`
   - Extracts indexable fields via `get_indexable_fields()`
   - Creates a document array with the post ID
   - For each field in the schema:
     - If it's a standard post property (title, content), gets it directly from the post object
     - For custom fields, retrieves values from post meta
   - Applies content filters (like removing HTML comments)
4. **Index Update**:
   - Removes any existing document with the same ID (for updates)
   - Adds the document to the appropriate post type's index

#### Content Removal Flow

1. **Deletion Event**: WordPress triggers trash/delete hooks when posts are removed
2. **Status Check**: The indexer verifies the previous status was "published"
3. **Deletion Process**: Handles both single and bulk deletion operations
4. **Index Cleanup**: Removes the document from the appropriate post type's index

#### Manual Reindexing Flow

1. **Admin Request**: Admin initiates reindexing through the settings interface
2. **Index Reset**:
   - Displays a status message
   - Deletes the entire index directory using WordPress filesystem API
   - Re-initializes all Loupe instances
3. **Bulk Indexing**: For each post type:
   - Fetches all published posts
   - Processes each post using the same document preparation flow
   - Adds all documents in a batch to the index

#### Schema Management Flow

1. **Field Definition**: For each indexed field, the Schema Manager:
   - Retrieves the default schema or a filtered schema for the specific post type
   - Processes field definitions with types (indexable, filterable, sortable)
   - Caches processed schemas for performance
2. **Document Processing**: When preparing documents, the Schema Manager:
   - Gets the schema for the post type
   - Extracts fields based on the requested type (indexable/filterable/sortable)
   - Returns field settings including weights, directions, etc.
3. **Custom Fields**: For custom post fields:
   - Retrieves field definitions from the settings
   - Adds them to the schema with appropriate weighting
   - Extracts values using WordPress meta functions

#### Search Query Flow

1. **Query Construction**: When a search is initiated:
   - The search parameters are gathered from the request
   - Filters and sorting are applied as specified
   - Results are limited based on pagination settings
2. **Result Processing**: Search results are processed to:
   - Format according to the requested output structure
   - Include post-specific data like permalinks
   - Apply WordPress-specific filters for display

The architecture leverages WordPress hooks extensively and provides multiple filter points to customize indexing behavior, schema definitions, and search results.

## Acknowledgements

- WP Loupe is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.
- The plugin uses [Select2](https://select2.org/) for the post type selection dropdown. Select2 is licensed under the MIT license.
- The plugin uses Yannick Lefebvre's [WP Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker?tab=readme-ov-file#github-integration) for updates. WP Plugin Update Checker is licensed under the MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.
