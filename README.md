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

> Want to write your own search plugin? Here's a guide to get started: [Create a WordPress custom search](https://gist.github.com/soderlind/cc7283db9290031455c5a79d40e3119b)

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

> As of version 0.1.4, plugin updates are handled automatically via GitHub. No need to manually download and install updates.

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


## Behind the Scenes

This document explains the architecture and technical implementation of the WP Loupe plugin, detailing how it provides fast search functionality for WordPress sites.

### Overview

WP Loupe is a search plugin that enhances WordPress's default search with a fast, SQLite-based search engine. The plugin creates and maintains search indexes for WordPress content and intercepts search queries to provide improved results.

### Core Components

#### 1. Search Engine

The plugin uses the Loupe search library, which provides:
- Full-text search capabilities
- Typo tolerance
- Fast queries via SQLite

Each post type has its own search index stored in separate SQLite databases:
```
/wp-content/wp-loupe-db/
  ├── post/
  ├── page/
  └── {custom-post-type}/
```

#### 3. Component Classes

##### Factory Pattern

`WP_Loupe_Factory` creates Loupe instances with appropriate configuration:
- Uses schema information to determine which fields to index, search, filter, and sort
- Processes field weights for relevance scoring
- Configures language and typo tolerance settings

##### Schema Management

`WP_Loupe_Schema_Manager` handles search schema configuration:
- Defines which fields are searchable and their weights
- Manages filterable and sortable fields
- Provides a default schema that can be customized via filters

##### Indexing

`WP_Loupe_Indexer`:
- Monitors post changes (create, update, delete) to keep the search index current
- Provides bulk indexing functionality
- Transforms WordPress posts into documents that can be indexed

##### Search

`WP_Loupe_Search`:
- Intercepts WordPress search queries
- Performs optimized search using Loupe
- Handles pagination and result formatting
- Implements query result caching for improved performance

### Key Technical Features

#### 1. Performance Optimizations

- **Single-Pass Processing**: All field types are processed in one loop for efficiency
- **Result Caching**: Search results are cached using WordPress transients
- **Batch Operations**: Bulk indexing uses optimized document batching

#### 2. Database Management

`WP_Loupe_DB` handles:
- Index file paths and creation
- Index deletion (when necessary)
- Directory structure management

#### 3. Integration Points

- Hooks into WordPress search via `posts_pre_query` filter
- Monitors post changes via `save_post_{post_type}` and `wp_trash_post` actions
- Settings integration via WordPress Settings API

### Search Flow

When a user performs a search:

1. WP Loupe intercepts the search query via `posts_pre_query`
2. The search term is sanitized and prepared
3. The plugin checks the cache for existing results
4. If not cached, the query is sent to the appropriate Loupe instance(s)
5. Results are combined and sorted by relevance
6. The plugin formats results as WordPress post objects
7. Results are cached for future queries
8. The WordPress query object is updated with results and pagination info

### Index Maintenance

The plugin automatically:
- Adds/updates documents when posts are published or modified
- Removes documents when posts are trashed
- Provides a manual reindex option in the settings

### Admin Interface

The plugin includes a settings page that allows:
- Selecting which post types to include in search
- Triggering a complete reindex of content
- Configuring search behavior (via filters)

### Technical Requirements

- PHP 8.2
- SQLite 3.16.0+ (required by the Loupe library)
- WordPress 6.3+

This architecture provides a balance between search quality, performance, and ease of integration with WordPress.


## Acknowledgements

- WP Loupe is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.
- The plugin uses [Select2](https://select2.org/) for the post type selection dropdown. Select2 is licensed under the MIT license.
- The plugin uses Yannick Lefebvre's [WP Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker?tab=readme-ov-file#github-integration) for updates. WP Plugin Update Checker is licensed under the MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.
