# WP Loupe Plugin

[Features](#key-features) | [Installation](#installation-methods) | [Usage](#how-to-use) | [Filters](#filters) | [Acknowledgements](#acknowledgements) | [License](#copyright-and-license)

## Overview

WP Loupe is a plugin for WordPress that significantly improves the search capabilities of your website. It leverages a custom search index to deliver quick and precise search results.

WP Loupe employs the [Loupe search engine](https://github.com/loupe-php/loupe/blob/main/README.md) to construct a search index for your posts and pages. The search index is stored in a SQLite database, which is automatically updated when a post or page is created or updated.

## Key Features

(This is my to-do list, not all features are implemented yet)

- [x] Automatic update of search index upon creation or modification of a post or page.
- [x] Tolerant to typos (based on the State Set Index Algorithm and Levenshtein)
- [x] Supports phrase search using `"` quotation marks
- [x] Supports stemming
- [x] Utilizes stop words from the WordPress translation, e.g., [Norwegian bokmål](https://translate.wordpress.org/projects/wp/dev/nb/default/?filters%5Bstatus%5D=either&filters%5Boriginal_id%5D=70980&filters%5Btranslation_id%5D=2917948).
- [x] Auto-detects languages
- [x] Option to reindex all posts and pages from the admin interface (Settings > WP Loupe).
- [x] Compatible with the theme's search.php template. Tested with [Twenty Twenty-Four](https://wordpress.org/themes/twentytwentyfour/) and [Twenty Twenty-Five](https://wordpress.org/themes/twentytwentyfive/).
- [x] Supports custom post types.
- [x] Adds processing time, as a comment, to the footer.
- [x] Supports translation. .pot file is included in the `languages` folder.
- [x] Delete posts and pages from the search index when they are deleted.
- [x] Pagination.
- [ ] Multisite support, including the option to index all sites in a network.
- [ ] Multisite support. Select which sites to index.
- [ ] Multisite support. Select which site to do search from.
- [ ] (Under consideration) Supports filtering (and ordering) on any attribute with any SQL-inspired filter statement

## Installation Methods

- [x] Manual installation via command line using `git` and `composer`.
- [x] Installation using `composer require soderlind/wp-loupe`
- [x] Download the latest release `wp-loupe.zip` file, unzip, and upload to your plugins folder.
- [x] Download the latest release `wp-loupe.zip` file and install from the WordPress admin interface (Plugins > Add New > Upload Plugin).
- [ ] Install directly from the WordPress admin interface (Plugins > Add New > Search for "WP Loupe").

The `wp-loupe.zip` file can be found in the "Assets" section of the [latest release](https://github.com/soderlind/wp-loupe/releases/latest).

After installation, activate the plugin and navigate to `Settings > WP Loupe` to reindex all posts and pages.

## How to Use

- The search index is automatically updated when a post or page is created or updated.
- To add older posts or pages to the search index, navigate to `Settings > WP Loupe`, and click the "Reindex search index" button.
- Add custom post types to the search index by selecting the post type in the `Settings > WP Loupe` admin page, or by adding the post type to the `wp_loupe_post_types` filter (see below).

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

## Acknowledgements

WP Loupe is built upon [Loupe](https://github.com/loupe-php/loupe/). Loupe is licensed under the MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is open-source software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.
