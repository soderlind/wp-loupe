# WP Loupe

## Description

WP Loupe is a WordPress plugin that enhances the search functionality of your WordPress site. It uses a custom search index to provide fast and accurate search results.

WP Loupe uses the [Loupe search engine](https://github.com/loupe-php/loupe/blob/main/README.md) to create a search index for your posts and pages.

## Features

- [x] Search index is updated automatically when a post or page is created or updated.
- [x] Typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
- [x] Supports phrase search using `"` quotation marks
- [x] Supports stemming
- [x] Uses stop words from the WordPress translation, eg [Norwegian bokmål](https://translate.wordpress.org/projects/wp/dev/nb/default/?filters%5Bstatus%5D=either&filters%5Boriginal_id%5D=70980&filters%5Btranslation_id%5D=2917948).
- [x] Auto-detects languages
- [x] Reindex all posts and pages from the admin interface (Settings > WP Loupe).
- [x] Uses the theme's search.php template. Tested with [Twenty Twenty-Four](https://wordpress.org/themes/twentytwentyfour/).
- [x] Supports custom post types.
- [x] Add prosessing time, as a comment, to the footer.
- [ ] \(Not sure if I'll add this) Supports filtering (and ordering) on any attribute with any SQL-inspired filter statement

## Installation

- [x] Install manually from the command line, using `git` and `composer`.
- [x] Install using `composer require soderlind/wp-loupe`
- [ ] Download the latest release zip file, unzip, and upload to your plugins folder.`
- [ ] Download the latest release zip file and install from the WordPress admin interface (Plugins > Add New > Upload Plugin).
- [ ] Install from the WordPress admin interface (Plugins > Add New > Search for "WP Loupe").

> **NOTE**: It's an early beta so installing the zip, manually or from the WordPress admin interface, is not supported.

To install WP Loupe, you need to clone the repository into your plugins folder and run composer install. Here are the steps:

```bash
# Navigate to your plugins directory
cd wp-content/plugins

# Clone the WP Loupe repository
git clone https://github.com/soderlind/wp-loupe

# Navigate to the WP Loupe directory
cd wp-loupe

# Install the necessary dependencies
composer install

# Activate the plugin from the command line, or from the WordPress admin
wp plugin activate wp-loupe
```

## Usage

- The index is updated automatically when a post or page is created or updated.
- If you need to add older posts or pages to the search index, go to `Settings > WP Loupe` and click the "Reindex" button to index all posts and pages.
- Add custom post types to the search index by selecting the post type in the `Settings > WP Loupe` admin page, or by adding the post type to the `wp_loupe_post_types` filter (see below).

## Filters

1. `wp_loupe_db_path`: This filter is used to modify the path where the Loupe database files are stored. By default, it's in the 'wp-content/wp-loupe-db' directory.

```php
add_filter( 'wp_loupe_db_path', WP_CONTENT_DIR . '/my-path' );
```

2. `wp_loupe_post_types`: This filter is used to modify the array of post types that the WP Loupe plugin works with. By default, it includes 'post' and 'page'.

```php
add_filter( 'wp_loupe_post_types', [ 'post', 'page', 'book' ] );
```

3. `wp_loupe_filterable_attribute_{$post_type}`: This dynamic filter is used to modify the array of filterable attributes for each post type. By default, it includes 'title' and 'content'.

```php
add_filter( "wp_loupe_filterable_attribute_book", [ 'title', 'author', 'isbn' ] );
```

## Credits

WP Loupe is based on [Loupe](https://github.com/loupe-php/loupe/). Loupe has a MIT license.

## Copyright and License

WP Loupe is copyright © 2024 [Per Søderlind](http://github.com/soderlind).

WP Loupe is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

WP Loupe is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.
