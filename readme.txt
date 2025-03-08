=== WP Loupe ===
Contributors: PerS
Tags: search, loupe, posts, pages, custom post types, typo-tolerant, fast search, advanced search
Requires at least: 6.3
Requires PHP: 8.1
Tested up to: 6.7
Stable tag: 0.2.3
Donate link: https://paypal.me/PerSoderlind
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform your WordPress search with lightning-fast, typo-tolerant results and advanced search capabilities.

== Description ==

WP Loupe enhances WordPress search functionality by providing a powerful, fast, and accurate search experience. Built on the Loupe search engine, it creates and maintains a dedicated search index for optimal performance.

= Core Features =

* 🚀 Enhanced search engine replacing WordPress default
* ⚡ Lightning-fast, precise result delivery
* 🔄 Real-time index synchronization
* 🌐 Support for multiple languages
* 📦 Full custom post type integration
* 📈 Integrated search performance metrics
* ✅ Seamless compatibility with WordPress default themes

= Advanced Search Capabilities =

* Phrase matching using quotation marks: `"exact phrase"`
* Exclusion operators: `term -excluded`
* OR search: `term1 term2`
* Customizable via filters
* Stemming support
* Stop words recognition

= Developer-Friendly =

* Extensive filter system for customization
* Performance monitoring and diagnostics
* Customizable indexing options

= Administration =

* Simple settings interface
* Post type selection
* One-click reindexing
* Processing time monitoring

== Installation ==

1. Upload 'wp-loupe' to the '/wp-content/plugins/' directory
2. Activate through the 'Plugins' menu in WordPress
3. Visit Settings > WP Loupe to configure
4. Click 'Reindex' to build the initial search index
5. Plugin updates are handled automatically via GitHub. No need to manually download and install updates.

== Frequently Asked Questions ==

= How does it handle updates to posts? =

The search index automatically updates when content is created, modified, or deleted.

= Will it slow down my site? =

No. WP Loupe uses a separate, optimized search index and doesn't impact your main database performance.

= Can I customize what content is searchable? =

Yes, using filters you can control exactly what content gets indexed and how it's searched.

= Does it work with custom post types? =

Yes, you can select which post types to include in the Settings page or via filters.

== Filters ==

These filters allow developers to customize WP Loupe's behavior:

= wp_loupe_db_path =
Controls where the search index is stored.
Default: WP_CONTENT_DIR . '/wp-loupe-db'

= wp_loupe_post_types =
Modifies which post types are included in search.
Default: ['post', 'page']

= wp_loupe_posts_per_page =
Controls search results per page.
Default: WordPress "Blog pages show at most" setting

= wp_loupe_index_protected =
Controls indexing of password-protected posts.
Default: false

= wp_loupe_field_{$field_name} =
Modifies a field before indexing.
Default: Removes HTML comments from `post_content`

= wp_loupe_schema_{$post_type} =
Customizes the schema for a post type.

For usage examples, see the [filter documentation at GitHub](https://github.com/soderlind/wp-loupe?tab=readme-ov-file#filters).

== Changelog ==

= 0.2.3 =
* Enhanced field indexing to strictly respect settings configuration
* Improved schema manager to only include explicitly selected fields
* Refined factory class to ensure proper field filtering from settings
* Added filter `wp_loupe_field_{$field_name}` to allow field modification.

= 0.2.2 =
* Changed: Modified field indexing to only include explicitly selected fields in settings
* Changed: Updated schema manager to respect indexable field settings
* Changed: Improved field selection behavior in admin interface

= 0.2.1 =
* Added translation support for admin interface
* Updated translation files with new strings

= 0.2.0 =
* Added new field settings management interface in the settings page
	* Added ability to configure Weight, Filterable, and Sortable options per field
* Added help tabs to explain field configuration options
	* Added detailed explanations for Weight, Filterable, and Sortable fields
	* Added help sidebar with documentation link

= 0.1.7 =
* Refactored code: Replaced WP_Loupe_Shared trait with WP_Loupe_Factory class
* Improved code organization and maintainability
* Enhanced code structure for better testability

= 0.1.6 =
* Housekeeping

= 0.1.5 =
* Fixed: GitHub API authentication errors in updater class
* Fixed: Added proper token-based authentication for GitHub API requests
* Fixed: Resolved 403 errors when checking for plugin updates

= 0.1.4 =
* Fixed issue with plugin update notification not showing in some cases
* Fixed GitHub release asset detection for automatic updates

= 0.1.3 =
* Security: Improved GitHub integration with proper API token handling
* Security: Updated GitHub actions workflow for better release asset management
* Added: Plugin update success notification
* Added: Improved GitHub release asset detection with regex pattern
* Added: Updated installation instructions for automatic updates
* Changed: Enhanced updater with better error handling
* Changed: Updated dependencies to latest versions

= 0.1.2 =
* Added "Behind the scenes" documentation section explaining plugin's internal dataflow
* Added detailed step-by-step documentation on indexing and search processes
* Implemented automatic GitHub updates using YahnisElsts/plugin-update-checker library
* Added acknowledgement for third-party libraries used
* Improved README documentation with more thorough explanations of architecture
* Enhanced code organization and comments for better developer understanding
* Simplified plugin update process with direct GitHub integration

= 0.1.1 =
* Clear search results cache when reindexing, saving, updating or deleting posts

= 0.1.0 =
* Added new WP_Loupe_Schema_Manager class for schema configurations
* Added methods for indexable, filterable, and sortable fields
* Added prepare_document method in WP_Loupe_Indexer
* Enhanced reindex_all method with prepare_document
* Improved search method in WP_Loupe_Search with schema-based fields
* Added search results caching for better performance
* Updated create_post_objects method for efficient post fetching
* Added schema customization documentation in README.md

= 0.0.31 =
* Update readme.txt

= 0.0.30 =
* Added post type selection in settings page
* Added support for all public post types in search
* Added default selection of 'post' and 'page' for new installations
* Improved settings UI with Select2 dropdown
* Updated post type handling in search index

= 0.0.20 =
* Fix Typo in class-wp-loupe-loader.php

= 0.0.19 =
* Changed: Update dependencies

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