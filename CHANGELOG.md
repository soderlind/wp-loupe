# Changelog

All notable changes to this project will be documented in this file.

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
