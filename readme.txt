=== WP Loupe ===
Contributors: PerS
Tags: search, loupe, posts, pages, custom post types
Requires at least: 6.3
Requires PHP: 8.1
Tested up to: 6.7
Stable tag: 0.0.17
Donate link: https://paypal.me/PerSoderlind
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhance the search functionality of your WordPress site with WP Loupe.

== Description ==

WP Loupe is a WordPress plugin that enhances the search functionality of your WordPress site. It uses a custom search index to provide fast and accurate search results.

WP Loupe uses the [Loupe search engine](https://github.com/loupe-php/loupe/blob/main/README.md) to create a search index for your posts and pages.

= Features =

* Search index is updated automatically when a post or page is created or updated.
* Typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
* Supports phrase search using `"` quotation marks
* Supports stemming
* Uses stop words from the WordPress translation,
* Auto-detects languages
* Reindex all posts and pages from the admin interface (Settings > WP Loupe).
* Uses the theme's search.php template. Tested with [Twenty Twenty-Four](https://wordpress.org/themes/twentytwentyfour/).
* Supports custom post types.
* Add prosessing time, as a comment, to the footer.
* Add translation. .pot file is included in the `languages` folder.
* Delete posts and pages from the search index when they are deleted.
* Pagination.

= Usage =

* The search index is updated automatically when a post or page is created or updated.
* If you need to add older posts or pages to the search index, go to `Settings > WP Loupe`, and click the "Reindex search index" button.
* Add custom post types to the search index by selecting the post type in the `Settings > WP Loupe` admin page, or by adding the post type to the `wp_loupe_post_types` filter (see below).

= Searching =

* `Hello World` will search for posts containing `Hello` or `World`.
* `"Hello World"` will search for posts containing the phrase `Hello World`.
* `Hello -World` will search for posts containing `Hello` but not `World`.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-loupe` directory, or install the plugin zip file through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->WP Loupe screen to configure the plugin


== Changelog ==

= 0.0.18 =
* Fixed return value in posts_pre_query to return null instead of posts for better WP Core integration


= 0.0.17 =
* Added wp_loupe_posts_per_page filter hook for customizing posts per page
* Added PHPDoc blocks for all class properties in search class
* Improved code documentation for all method parameters
* Enhanced error handling in database operations


= 0.0.16 =
* Added proper documentation to WP_Loupe_Search class
* Added missing PHPDoc blocks for class properties
* Fixed PHPCS warnings related to comment formatting
* Fixed inline documentation for better code readability

= 0.0.15 =
* Added pagination support for search results
* Added total found posts and max pages calculation
* Added proper handling of posts per page setting
* Improved search query interception logic
* Enhanced performance for large result sets

= 0.0.14 =
* Fixed problem with reindexing all posts and pages from the admin interface

= 0.0.13 =

* Improved search results handling for custom post types
* Enhanced post object creation with proper post type support

= 0.0.12 =
* Added comprehensive documentation to all classes and methods
* Added proper DocBlocks following WordPress coding standards
* Improved code documentation across all files

= 0.0.11 =
* Added trait for sharing Loupe instance creation between classes
* Updated field names to match WordPress post field names
* Fixed search results handling and post object creation

= 0.0.10 =
* Performance: Reduced search attributes to only retrieve essential fields
* Performance: Removed content from sortable attributes
* Performance: Removed highlighting feature
* Fixed: Typo in search query variable
* Fixed: Code style improvements for better maintainability

= 0.0.1 - 0.0.5 =
Development version, do not use in production.