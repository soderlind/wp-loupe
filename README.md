# WP Loupe - Enhanced WordPress Search

A powerful search enhancement plugin for WordPress that delivers fast, accurate, and typo-tolerant search results.

## Quick Links

[Features](#features) | [Installation](#installation) | [Usage](#usage) | [Configuration](#configuration) | [Filters](#filters) | [FAQ](#faq)

## Overview

WP Loupe transforms WordPress's search functionality by:

- Creating a dedicated search index for lightning-fast results
- Supporting typo-tolerant searches
- Offering phrase matching and advanced search operators
- Automatically maintaining the search index
- Providing customization options for developers

## Features

### Core Features

- ✨ Fast, accurate search results
- 🔄 Automatic index updates
- 🌍 Multi-language support
- 📑 Custom post type support
- 📊 Built-in search analytics

### Search Capabilities

- 🔍 Typo-tolerant searching
- "..." Phrase matching with quotation marks
- `-` Exclusion operator support
- 📱 Mobile-friendly interface
- 📖 Pagination support

### Developer Features

- 🛠️ Extensive filter system
- 📊 Performance monitoring
- 🔧 Customizable indexing
- 🎯 API for custom integrations

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

## Acknowledgements

WP Loupe is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.
