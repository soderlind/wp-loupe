# Changelog

All notable changes to this project will be documented in this file.

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
