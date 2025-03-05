# Changelog

All notable changes to this project will be documented in this file.

## [0.1.3] - 2025-03-30

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

## [0.1.2] - 2025-03-15

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

[0.0.10]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.10
[0.0.11]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.11
[0.0.12]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.12
[0.0.13]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.13
[0.0.14]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.14
[0.0.15]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.15
[0.0.16]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.16
[0.0.17]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.17
[0.0.18]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.18
[0.0.19]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.19
[0.0.20]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.20
[0.0.30]: https://github.com/soderlind/wp-loupe/releases/tag/0.0.30
[0.1.0]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.0
[0.1.1]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.1
[0.1.2]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.2
[0.1.3]: https://github.com/soderlind/wp-loupe/releases/tag/0.1.3
