# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-01-01

### Added
- Batched reindexing to avoid admin request timeouts on large sites.
- Admin-only maintenance REST endpoint: `POST /wp-json/wp-loupe/v1/reindex-batch`.
- WP-CLI command for batched reindexing: `wp wp-loupe reindex`.
- Pest test runner (dev) alongside PHPUnit.

### Changed
- Reindexing is triggered via a separate admin UI button (not tied to saving settings).

### Documentation
- Updated reindexing and testing docs (admin UI, REST, WP-CLI).

## [0.7.0] - 2025-12-18

### Added
- Override hook for meta-field sortability: `wp_loupe_is_safely_sortable_meta_{$post_type}`.
- Search API guide with hook-based field preparation examples (facets, geo, sorting).

### Fixed
- Geo-point meta fields are treated as sortable-safe (enables geo distance sorting when a geo field is stored as post meta).

## [0.6.0] - 2025-12-18

### Added
- Split search into side-effect free engine plus front-end-only hooks.
- Advanced REST search API: `POST /wp-json/wp-loupe/v1/search` (JSON filters, facets, geo, explicit sorting).

### Removed
- Bundled UI integration (block/shortcode/search form override). Build your own UI using the REST API.

### Changed
- Upgraded `loupe/loupe` to 0.13.4 and added stricter runtime requirements checks.

### Fixed
- Reindexing now safely rebuilds/migrates indexes across Loupe schema upgrades.
- Guarded against empty `wp_loupe_db_path` filter values.

## [0.5.7] - 2025-11-11

### Added
- Always expose core WordPress fields (`post_title`, `post_content`, `post_excerpt`, `post_date`, `post_modified`, `post_author`, `permalink`) in REST post-type field discovery and settings UI regardless of indexing status.

### Changed
- Field discovery now seeds from core fields before merging schema-derived and meta keys to ensure consistent availability in admin UI.

### Fixed
- Typo in 0.5.6 changelog entry ("dependecies" -> "dependencies").

## [0.5.6] - 2025-11-11

### Changed
- Updated dependencies to latest versions


## [0.5.5] - 2025-11-10
### Added
- Settings toggle to enable/disable automatic plugin updates (defaults enabled)
- Schema manager unit test validating baseline `post_date` only
- Updated translation template (`languages/wp-loupe.pot`) with new settings strings
- Unit tests for token service (rate limit bounding & revoke-all) and settings sanitization (auto-update default, field filters)
- Upgrade note covering automatic `post_date` field + SQLite column migration (inline ≤2000 posts; scheduled otherwise) with opt-out (`WP_LOUPE_DISABLE_AUTO_REINDEX`) and manual rebuild fallback

### Changed
- Default schema construction simplified to mandatory baseline (`post_date` only); all other fields are post-type scoped
- Readme wording trimmed to reduce promotional language
- Extracted MCP token issuance & rate limit persistence from settings page into `WP_Loupe_Token_Service` (separation of concerns, improved maintainability & testability)
- Relocated auto-update checkbox to Advanced tab for clearer grouping of maintenance options

### Fixed
- Structural mismatch in `WP_Loupe_Schema_Manager::get_default_schema()` which previously mis-read saved settings globally
- Consistent hard-bounding of rate limit values during persistence (avoids accidental oversize windows/quotas)

### Documentation
- Clarified schema baseline behavior and auto-update control in README changelog
- Added internal developer note (in code comments) about token service extraction rationale
- Documented migration path & reindex strategy for newly enforced `post_date` column/field

### Notes
- Minor internal refactor ahead of future schema expansion; added test coverage for schema baseline
- Service extraction sets foundation for future MCP feature growth (scopes, audit logging)

## [0.5.4] - 2025-09-30
### Added
- Automatic plugin update infrastructure (filter-based) with constant opt-out
- Migration ensuring mandatory `post_date` field presence after Loupe upgrade; conditional reindex (immediate ≤2000 posts, scheduled otherwise)

### Fixed
- Publishing/indexing error from missing SQLite `post_date` column

### Notes
- Introduces migration path setting groundwork for future schema evolution

## [0.5.3] - 2025-09-30
### Added
- Copy buttons (with accessibility & status feedback) for manifest and protected resource endpoints in MCP settings
- Screen-reader friendly live region announcing copy success/failure

### Changed
- Further refined MCP settings layout; ensured no inline JavaScript remains for endpoint copying
- Minor wording tweaks in endpoint descriptions for clarity

### Fixed
- Defensive clipboard fallback path using hidden textarea to support insecure contexts / older browsers

### Notes
- Small UX iteration; next releases will focus on token issuance improvements & dynamic manifest enrichment

## [0.5.2] - 2025-09-29
### Added
- Rate limiting settings UI polish (clear separation of anonymous vs authenticated buckets)
- Discovery endpoints surfaced in settings (manifest & protected resource) for easier client setup

### Changed
- Removed POST `/commands` endpoint from the public endpoint list to avoid method confusion
- Centralized endpoint copy behavior into `admin.js` (removed inline script)
- Improved accessibility: added aria-live region for copy feedback

### Fixed
- Ensured `/.well-known/mcp.json` manifest reliably returns JSON once MCP enabled (rewrite + fallback logic)
- Clipboard copy fallback for browsers or contexts without `navigator.clipboard`

### Notes
- Prepares groundwork for broader rate-limit configurability and future manifest expansion

## [0.5.1] - 2025-09-29
### Added
- MCP token management UI (scope selection, TTL presets including indefinite, revoke-all, last-used tracking, copy-once display)
- Hybrid anonymous vs authenticated (scoped token) access model
- Secure HMAC-signed pagination cursors for `searchPosts`
- WP-CLI token issuance mirrored in admin registry

### Changed
- Wrapped MCP token table in panel container with unified max-width (840px)
- Reordered admin headings and standardized Save button placement

### Notes
- This is an incremental UI/UX refinement over 0.5.0 preview

## [0.5.0] - 2025-09-29
### Added
- Optional MCP (Model Context Protocol) server with discovery manifest and command routing
- Token-based authentication with scoped access (search.read, post.read, schema.read, health.read, commands.read)
- Hybrid anonymous + authenticated search access model
- Secure HMAC-signed cursor pagination for `searchPosts`
- Configurable rate limiting (separate anonymous vs authenticated windows & quotas)
- Admin UI for token issuance (TTL with indefinite option), revocation, last-used tracking
- WP-CLI commands for issuing tokens mirrored into admin registry

### Security
- Hashed token storage (raw value displayed once on creation)
- Pagination cursor tamper detection

### Documentation
- Comprehensive MCP documentation (`docs/mcp.md`) and design notes
- README and admin help text updates for MCP usage and configuration

### Changed
- Bumped minimum PHP requirement to 8.3
- Upgraded `loupe/loupe` dependency to 0.12.13

### Internal
- Added health check command and schema exposure utilities
- Refined factory and settings integration to surface MCP options

### Notes
- MCP feature is disabled by default and must be explicitly enabled in settings

## [0.4.3] - 2023-08-11
### Fixed
- Inline JavaScript using `wp_print_inline_script_tag`.
- Plugin [updates are handled automatically](https://github.com/soderlind/wordpress-plugin-github-updater?tab=readme-ov-file).

## [0.4.2] - 2023-03-17
### Fixed
- Customizer settings not being saved


## [0.4.1] - 2023-03-13
### Changed
- Update advanced settings documentation in README.md
- Update translations for new strings

## [0.4.0] - 2023-03-13
### Added
- Improved caching mechanisms for better performance
- Enhanced field configuration management
- Dedicated methods for taxonomy field handling

### Changed
- Refactored WP_Loupe_Factory class for better code organization
- Improved attribute extraction and configuration building
- Optimized sortable field checking with static caching

### Fixed
- More efficient handling of typo tolerance configuration

## [0.3.2] - 2023-03-12

### Fixed
- In readme.txt, update the `Tested up to` value to 6.7

## [0.3.1] - 2023-03-12

### Fixed
- Bug fix: Non-scalar fields no longer get selected by default for sorting when adding a new post type
- Improved field configuration UI to properly handle non-sortable fields

### Changed
- Updated translations for new strings

## [0.3.0] - 2025-03-12

### Added

- Added proper handling of database files when selecting/deselecting post types
- Improved custom field management with better support for sortable fields
- Enhanced error handling for sortable custom fields like date, text, and number fields
- Fixed issue with field display when creating/removing post type databases

### Changed

- Refactored JavaScript admin code for better maintainability
- Improved user feedback during database operations
- Separated database creation from content indexing process

## [0.2.3] - 2025-03-09

### Changed

- Enhanced field indexing to strictly respect settings configuration
- Improved schema manager to only include explicitly selected fields
- Refined factory class to ensure proper field filtering from settings
- Added filter `wp_loupe_field_{$field_name}` to allow field modification.

## [0.2.2] - 2025-03-08

### Changed

- Modified field indexing to only include explicitly selected fields in settings
- Updated schema manager to respect indexable field settings
- Improved field selection behavior in admin interface

## [0.2.1] - 2025-03-07

### Added

- Added translation support for admin interface
- Updated translation files with new strings

## [0.2.0] - 2025-03-07

### Added

- Added new field settings management interface in the settings page
	- Added ability to configure Weight, Filterable, and Sortable options per field
- Added help tabs to explain field configuration options
	- Added detailed explanations for Weight, Filterable, and Sortable fields
	- Added help sidebar with documentation link


## [0.1.7] - 2025-03-06

### Changed

- Refactored code: Replaced WP_Loupe_Shared trait with WP_Loupe_Factory class
- Improved code organization and maintainability
- Enhanced code structure for better testability

## [0.1.6] - 2025-03-06

### Changed

- Housekeeping


## [0.1.5] - 2025-03-06

### Fixed

- Fixed GitHub API authentication errors in updater class
- Resolved 403 errors when checking for plugin updates

## [0.1.4] - 2025-03-05

### Fixed

- Fixed issue with plugin update notification not showing in some cases
- Fixed GitHub release asset detection for automatic updates

## [0.1.3] - 2025-03-05

### Security

- Improved GitHub integration with proper API token handling
- Updated GitHub actions workflow for better release asset management

### Added

- Added plugin update success notification
- Improved GitHub release asset detection with regex pattern
- Updated installation instructions for automatic updates

### Changed

- Enhanced updater with better error handling
- Updated dependencies to latest versions

## [0.1.2] - 2025-03-04

### Added

- Added "Behind the scenes" documentation section explaining plugin's internal dataflow
- Added detailed step-by-step documentation on indexing and search processes
- Implemented automatic GitHub updates using YahnisElsts/plugin-update-checker library
- Added acknowledgement for third-party libraries used

### Changed

- Improved README documentation with more thorough explanations of architecture
- Enhanced code organization and comments for better developer understanding
- Simplified plugin update process with direct GitHub integration

## [0.1.1] - 2025-03-01

### Fixed

- Clear search results cache when reindexing, saving, updating or deleting posts.

## [0.1.0] - 2025-02-28

### Schema Management:

- Added a new class `WP_Loupe_Schema_Manager` to handle schema configurations for different post types, including methods to get indexable, filterable, and sortable fields.

### Indexing Enhancements:

- Introduced a `prepare_document` method in `WP_Loupe_Indexer` to create documents based on schema configurations.
- Modified the `reindex_all` method to use the `prepare_document` method for consistency and efficiency.

### Search Improvements:

- Enhanced the `search` method in `WP_Loupe_Search` to use schema-based fields for indexing and sorting, and added caching for search results to improve performance.
- Updated the `create_post_objects` method to fetch all posts in a single query, maintaining the search result order.

### Documentation:

- Added detailed documentation on how to customize the search schema for specific post types in `README.md`.

## [0.0.31] - 2025-02-27

- Update README.md

### Added

- Added post type selection in settings page
- Added support for all public post types in search
- Added default selection of 'post' and 'page' for new installations

### Changed

- Improved settings UI with Select2 dropdown for post type selection
- Updated post type handling in search index

## [0.0.20] - 2025-02-05

- Fix Typo in class-wp-loupe-loader.php

## [0.0.19] - 2025-02-04

### Changed

- Update dependencies

## [0.0.18] - 2025-02-03

### Changed

- Fixed return value in posts_pre_query to return null instead of posts for better WP Core integration

## [0.0.17] - 2025-02-02

### Added

- Added wp_loupe_posts_per_page filter hook for customizing posts per page
- Added PHPDoc blocks for all class properties in search class

### Changed

- Improved code documentation for all method parameters
- Enhanced error handling in database operations

## [0.0.16] - 2025-02-01

### Added

- Added proper documentation to WP_Loupe_Search class
- Added missing PHPDoc blocks for class properties

### Fixed

- Fixed PHPCS warnings related to comment formatting
- Fixed inline documentation for better code readability

## [0.0.15] - 2025-01-31

### Added

- Added pagination support for search results
- Added total found posts and max pages calculation
- Added proper handling of posts per page setting

### Changed

- Improved search query interception logic
- Enhanced performance for large result sets

## [0.0.14] - 2025-01-30

### Fixed

- Fixed problem with reindexing all posts and pages from the admin interface

## [0.0.13] - 2025-01-29

### Changed

- Improved search results handling for custom post types
- Enhanced post object creation with proper post type support

## [0.0.12] - 2025-01-28

### Added

- Added comprehensive documentation to all classes and methods
- Added proper DocBlocks following WordPress coding standards

### Changed

- Improved code documentation across all files
- Updated version number in package.json and plugin file

## [0.0.11] - 2025-01-28

### Changed

- Added trait for sharing Loupe instance creation between classes
- Updated field names to match WordPress post field names (title -> post_title, etc)
- Fixed search results handling and post object creation

## [0.0.10] - 2025-01-22

### Changed

- Reduced search attributes to only retrieve 'id', 'post_title', and 'date' for better performance
- Removed 'post_content' from sortable attributes for better performance
- Removed highlighting feature for better performance
- Fixed typo in search query variable (removed extra $ from $$query)

### Fixed

- Code style improvements for better maintainability

[0.8.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.8.0
[0.7.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.7.0
[0.6.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.6.0
[0.5.7]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.7
[0.5.6]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.6
[0.5.5]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.5
[0.5.4]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.4
[0.5.3]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.3
[0.5.2]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.2
[0.5.1]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.1
[0.5.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.5.0
[0.4.3]: https://github.com/soderlind/wp-loupe/releases/tag/0.4.3
[0.4.2]: https://github.com/soderlind/wp-loupe/releases/tag/0.4.2
[0.4.1]: https://github.com/soderlind/wp-loupe/releases/tag/0.4.1
[0.4.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.4.0
[0.3.2]: https://github.com/soderlind/wp-loupe/releases/tag/0.3.2
[0.3.1]: https://github.com/soderlind/wp-loupe/releases/tag/0.3.1
[0.3.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.3.0
[0.2.3]: https://github.com/soderlind/wp-loupe/releases/tag/0.2.3
[0.2.2]: https://github.com/soderlind/wp-loupe/releases/tag/0.2.2
[0.2.1]: https://github.com/soderlind/wp-loupe/releases/tag/0.2.1
[0.2.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.2.0
[0.1.7]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.7
[0.1.6]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.6
[0.1.5]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.5
[0.1.4]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.4
[0.1.3]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.3
[0.1.2]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.2
[0.1.1]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.1
[0.1.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.0
[0.0.30]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.30
[0.0.20]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.20
[0.0.19]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.19
[0.0.18]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.18
[0.0.17]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.17
[0.0.16]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.16
[0.0.15]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.15
[0.0.14]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.14
[0.0.13]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.13
[0.0.12]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.12
[0.0.11]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.11
[0.0.10]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.10