=== WP Loupe ===
Contributors: PerS
Tags: search, loupe, posts, pages, custom post types
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 6.4
Stable tag: 0.0.5
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

= Usage =

* The search index is updated automatically when a post or page is created or updated.
* If you need to add older posts or pages to the search index, go to `Settings > WP Loupe`, mark the reindex checkbox and and click the "Save changes" button.
* Add custom post types to the search index by selecting the post type in the `Settings > WP Loupe` admin page, or by adding the post type to the `wp_loupe_post_types` filter (see below).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-loupe` directory, or install the plugin zip file through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->WP Loupe screen to configure the plugin


== Changelog ==

= 0.0.1 - 0.0.5 =
Development version, do not use in production.