# WP Loupe - Enhanced WordPress Search

A powerful search enhancement plugin for WordPress that delivers fast, accurate, and typo-tolerant search results.

## Quick Links

[Features](#features) | [Installation](#installation) | [Usage](#usage) | [FAQ](#faq) | [Filters](#filters) | [Changelog](CHANGELOG.md) | [TODO](TODO.md)

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

1. `wp_loupe_db_path`: This filter allows you to change the path where the Loupe database files are stored. By default, it's in the `WP_CONTENT_DIR .'/wp-loupe-db'` directory.

```php
add_filter( 'wp_loupe_db_path', WP_CONTENT_DIR . '/my-path' );
```

2. `wp_loupe_post_types`: This filter allows you to modify the array of post types that the WP Loupe plugin works with. By default, it includes 'post' and 'page'.

```php
add_filter( 'wp_loupe_post_types', [ 'post', 'page', 'book' ] );
```

3. `wp_loupe_filterable_attribute_{$post_type}`: This dynamic filter allows you to modify the array of filterable attributes for each post type. By default, it includes 'post_title' and 'post_content'.

```php
add_filter( "wp_loupe_filterable_attribute_book", [ 'post_title', 'author', 'isbn' ] );
```

4. `wp_loupe_posts_per_page`: This filter allows you to modify the number of search results per page. By default it's 10, set in `WPAdmin->Settings->Reading->"Blog pages show at most"`.

```php
add_filter( 'wp_loupe_posts_per_page', 20 );
```

5. `wp_loupe_index_protected`: This filter allows you to index posts and pages that are protected by a password. By default, it's set to `false`.

```php
add_filter( 'wp_loupe_index_protected','__return_true' );
```

6. `wp_loupe_schema_content`: This filter allows you to modify the content before it's indexed. By default, removes HTML comments from content (i.e. remove WordPress block comments).

```php
add_filter('wp_loupe_schema_content', function($content) {
	// Modify content before indexing
	$content = preg_replace('/<!--(.*?)-->/s', '', $content);
	return $content;
});
```

### wp*loupe_schema*{$post_type}

Modify the search schema for a specific post type. The filter name is dynamically generated based on the post type.

**Parameters:**

- `$schema` (array) The default schema for indexing and searching posts.

**Example:**

```php
// Customize the schema for 'book' post type
add_filter( 'wp_loupe_schema_book', function( $schema ) {
    $schema['book_isbn'] = [
        'weight'     => 2.0,      // Higher weight means higher relevance in search results
        'filterable' => true,     // Allow filtering by this field
        'sortable'   => [         // Allow sorting by this field
            'direction' => 'asc'  // Default sort direction
        ],
    ];

    // Modify existing field settings
    $schema['post_title']['weight'] = 3.0; // Increase title weight for books

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

## Acknowledgements

WP Loupe is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.
