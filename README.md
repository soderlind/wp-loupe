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
- [ ] \(Not sure if I'll add this) Supports filtering (and ordering) on any attribute with any SQL-inspired filter statement



## Installation

- [x] Install manually from the command line, using `git` and `composer`.
- [x] Install using `composer require soderlind/wp-loupe`
- [ ] Download the latest release zip file, unzip, and upload to your plugins folder.`
- [ ] Install from the WordPress admin interface (Plugins > Add New > Upload Plugin).
- [ ] Install from the WordPress admin interface (Plugins > Add New > Search for "WP Loupe").

> It's a early beta so for now you must install the plugin manually, from the command line, using `git` and `composer`.

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

## Credits

WP Loupe is based on [Loupe](https://github.com/loupe-php/loupe/). Loupe has a MIT license.

## Copyright and License

This plugin is copyright © 2023 [Per Soderlind](http://github.com/soderlind).

This plugin is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for more information.

