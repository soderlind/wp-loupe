=== WP Loupe – WordPress Search Enhancement ===
Contributors: persoderlind
Tags: search, full-text search, relevance, typo-tolerant, fast search, search engine
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 0.6.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://paypal.me/PerSoderlind

A search enhancement plugin for WordPress that delivers fast, accurate, and typo-tolerant results.

== Description ==

WP Loupe improves WordPress core search by maintaining its own index for fast lookups, supporting typo tolerance, phrase matching, basic exclusion operators, and per–post-type customization.

= MCP (Model Context Protocol) Integration =
WP Loupe includes an optional MCP server (disabled by default) for external agents / automation.

Key points:
* Discovery endpoints: `/.well-known/mcp.json` and `/.well-known/oauth-protected-resource` (toggle in Settings → WP Loupe → MCP)
* Commands include `searchPosts`, `getPost`, `getSchema`, and `healthCheck`
* Anonymous access can be enabled with lower rate limits; tokens raise limits and can unlock health checks
* Tokens are scoped (`search.read`, `health.read`), have configurable TTL (1–168 hours, or 0 = indefinite), and can be revoked
* Tokens are stored hashed (raw value shown once); pagination cursors are HMAC-protected
* Rate limits are configurable (anonymous vs authenticated), with filter hooks and a WP-CLI token command available

Full documentation: https://github.com/soderlind/wp-loupe/blob/main/docs/mcp.md

= Core Features =

* 🚀 Enhanced search engine replacing WordPress default
* ⚡ Lightning-fast, precise result delivery
* 🔄 Real-time index synchronization
* 🌐 Support for multiple languages
* 📦 Full custom post type integration
* 📈 Integrated search performance metrics
* ✅ Seamless compatibility with WordPress default themes

= Search Capabilities =

* 🔍 Typo-tolerant searching - find results even with misspellings
* "..." Phrase matching with quotation marks
* `-` Exclusion operator support (e.g., `term -excluded`)
* OR search: `term1 term2` finds content with either term
* 📖 Pagination support
* Stemming support
* Stop words recognition

= Developer Features =

* 🛠️ Extensive filter system for customization
* 📊 Performance monitoring and diagnostics
* 🔧 Customizable indexing
* Field weighting control

= Administration =

* Simple settings interface
* Post type selection
* Field configuration options
* One-click reindexing
* Processing time monitoring

= Filters =

These filters allow developers to customize WP Loupe's behavior:

`wp_loupe_db_path`

Controls where the search index is stored.
Default: WP_CONTENT_DIR . '/wp-loupe-db'

`wp_loupe_post_types`

Modifies which post types are included in search.
Default: ['post', 'page']

`wp_loupe_posts_per_page`

Controls search results per page.
Default: WordPress "Blog pages show at most" setting

`wp_loupe_index_protected`

Controls indexing of password-protected posts.
Default: false

`wp_loupe_field_{$field_name}`

Modifies a field before indexing.
Example: The plugin uses `wp_loupe_field_post_content` to strip HTML tags from content

`wp_loupe_schema_{$post_type}`

Customizes the schema for a post type.

For usage examples, see the [filter documentation at GitHub](https://github.com/soderlind/wp-loupe?tab=readme-ov-file#filters).


== Installation ==

1. **Quick Install**
   * Upload the plugin files to the `/wp-content/plugins/wp-loupe` directory, or install the plugin through the WordPress plugins screen directly
   * Activate through the 'Plugins' menu in WordPress

2. **Post-Installation**
   * Visit Settings > WP Loupe to configure
   * Click 'Reindex' to build the initial search index

3. **Updates**
   * Plugin updates are handled automatically via GitHub. No need to manually download and install updates.

== Frequently Asked Questions ==

= How does it handle updates to posts? =

The search index automatically updates when content is created, modified, or deleted.

= Will it slow down my site? =

No. WP Loupe uses a separate, optimized search index and doesn't impact your main database performance.

= Can I customize what content is searchable? =

Yes, using filters you can control exactly what content gets indexed and how it's searched.

= Does it work with custom post types? =

Yes, you can select which post types to include in the Settings page or via filters.

= How do I use advanced search operators? =

* `Hello World` will search for posts containing `Hello` **or** `World`.
* `"Hello World"` will search for posts containing the exact phrase `Hello World`.
* `Hello -World` will search for posts containing `Hello` but not `World`.

= What are the technical requirements? =

* PHP 8.3 or higher
* SQLite 3.35+ (required by Loupe 0.13.x)
* PHP extensions: pdo_sqlite, intl, mbstring
* WordPress 6.7+


== Changelog ==

= 0.6.0 =
* Added: Split search engine (side-effect free) from front-end hooks to avoid REST/MCP side effects.
* Added: Editor-only integration for the block editor search experience.
* Changed: Upgraded `loupe/loupe` to 0.13.4 and tightened runtime requirements checks.
* Fixed: Reindexing now safely rebuilds/migrates indexes across Loupe schema upgrades.
* Fixed: Guarded against empty `wp_loupe_db_path` filter values.

= 0.5.7 =
* Added: Always expose core WordPress fields (`post_title`, `post_content`, `post_excerpt`, `post_date`, `post_modified`, `post_author`, `permalink`) in REST field discovery and settings UI even if unchecked for indexing.
* Changed: Field discovery flow now starts with mandatory core fields then merges schema & meta keys for stable UI state.
* Fixed: Previous changelog typo ("dependecies" -> "dependencies").

= 0.5.6 =
* Changed: Updated dependencies to latest versions

= 0.5.5 =
* Added: Settings toggle to enable or disable automatic plugin updates (defaults enabled).
* Added: Schema manager unit test validating baseline `post_date` only.
* Added: Updated translation template with new settings strings.
* Changed: Simplified baseline schema to only include mandatory `post_date`; per-post-type field settings now applied cleanly.
* Changed: Readme wording trimmed to reduce promotional language.
* Fixed: Structural mismatch in default schema logic preventing accurate field inheritance.

= 0.5.4 =
* Added: Automatic plugin update infrastructure (filter-based) with constant opt-out.
* Added: Migration ensuring mandatory `post_date` field exists after Loupe upgrade; conditional reindex strategy (immediate for small sites, scheduled for large).
* Fixed: Publishing/indexing error caused by missing SQLite `post_date` column.
* Internal: Post-date migration triggers safe reindex path based on site size.

= 0.5.3 =
* Added: Copy buttons (with accessible live feedback) for MCP manifest and protected resource endpoints.
* Added: Aria-live region and translatable status messages for copy success/failure.
* Changed: Removed inline JavaScript for endpoint copying; logic centralized in `admin.js`.
* Fixed: Clipboard fallback for browsers without `navigator.clipboard` support.
* Changed: Minor wording clarity improvements in endpoint descriptions.
* Note: Small UX iteration paving the way for richer manifest metadata.

= 0.5.2 =
* Added: Settings surfacing discovery endpoints (manifest & protected resource) for MCP clients.
* Added: Accessibility improvements groundwork (live region placeholder) before 0.5.3 enhancements.
* Changed: Removed POST `/commands` from visible endpoint list (method clarity).
* Fixed: Ensured reliable JSON output for `/.well-known/mcp.json` (rewrite + fallback path).
* Fixed: Clipboard copy resilience improvements (initial implementation) preparing for 0.5.3.
* I18n: Regenerated `wp-loupe.pot` with new MCP strings and translator comments.
* Note: Internal rate-limit option polish and manifest stability adjustments.

= 0.5.1 =
* UI: Wrapped MCP token table in panel and standardized max-width (840px)
* UI: Reordered headings and moved Save button for consistency
* MCP: Token management interface (scopes, TTL presets, revoke all, last-used tracking, copy-once)
* MCP: Hybrid anonymous/authenticated search access with scoped tokens
* MCP: Secure HMAC-signed pagination cursors for `searchPosts`
* MCP: WP-CLI token issuance mirrored in admin interface

= 0.5.0 =
* Initial MCP integration (preview): discovery manifest, commands, rate limiting, scoped tokens, pagination security
* Requires PHP 8.3+ and Loupe 0.12.13

= 0.5.0 =
Introduces optional MCP (Model Context Protocol) server (disabled by default). After upgrading:
1. Go to Settings → WP Loupe → MCP tab and enable the server.
2. Create a scoped access token (copy it once) for higher search limits or health checks.
3. (Optional) Adjust rate limits (anonymous vs authenticated) before exposing to external agents.
This is a developer/automation feature; sites not using MCP can ignore these new settings.

= 0.4.3 =
* Fixed: Inline JavaScript using `wp_print_inline_script_tag`.
* Plugin updates are handled automatically via GitHub. No need to manually download and install updates.

= 0.4.2 =
* Customizer settings not being saved

= 0.4.1 =
* Update settings documentation in README.md
* Update translations for new strings

= 0.4.0 =
* Added improved caching mechanisms for better performance
* Enhanced field configuration management and organization
* Refactored code structure for better maintainability
* Optimized sortable field checking with static caching
* Improved attribute extraction and configuration building
* More efficient handling of typo tolerance configuration

= 0.3.2 =
* Fixed: In readme.txt, update the `Tested up to` value to 6.7

= 0.3.1 =
* Bug fix: Non-scalar fields no longer get selected by default for sorting when adding a new post type
* Improved field configuration UI to properly handle non-sortable fields
* Updated translations for new strings

= 0.3.0 =
* Added support for custom post types
* Added field configuration interface for indexing, filtering, and sorting
* Improved search algorithm
* Performance optimizations

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