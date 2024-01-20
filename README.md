# WP Loupe

## Description

WP Loupe is a WordPress plugin that enhances the search functionality of your WordPress site. It uses a custom search index to provide fast and accurate search results.

WP Loupe uses the [Loupe library](https://github.com/loupe-php/loupe/blob/main/README.md) to create a search index for your posts and pages.

## Features

- [x] Search index is updated automatically when a post or page is updated.
- [x] Typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
- [x] Supports phrase search using `"` quotation marks
- [x] Supports stemming
- [x] Uses stop words from the WordPress translation, eg [Norwegian bokmål](https://translate.wordpress.org/projects/wp/dev/nb/default/?filters%5Bstatus%5D=either&filters%5Boriginal_id%5D=70980&filters%5Btranslation_id%5D=2917948). 
- [x] Auto-detects languages
- [ ] Supports filtering (and ordering) on any attribute with any SQL-inspired filter statement





## Installation

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

