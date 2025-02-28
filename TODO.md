## TO DO

This is my to-do list, not all features are implemented yet. Some features below are under consideration.

- [x] Automatic update of search index upon creation or modification of a post or page.
- [x] Tolerant to typos (based on the State Set Index Algorithm and Levenshtein)
- [x] Supports phrase search using `"` quotation marks
- [x] Supports stemming
- [x] Utilizes stop words from the WordPress translation, e.g., [Norwegian bokmål](https://translate.wordpress.org/projects/wp/dev/nb/default/?filters%5Bstatus%5D=either&filters%5Boriginal_id%5D=70980&filters%5Btranslation_id%5D=2917948).
- [x] Auto-detects languages
- [x] Option to reindex all posts and pages from the admin interface (Settings > WP Loupe).
- [x] Compatible with the theme's search.php template. Tested with [Twenty Twenty-Four](https://wordpress.org/themes/twentytwentyfour/) and [Twenty Twenty-Five](https://wordpress.org/themes/twentytwentyfive/).
- [x] Custom post types.
- [x] Adds processing time, as a comment, to the footer.
- [x] Supports translation. .pot file is included in the `languages` folder.
- [x] Delete posts and pages from the search index when they are deleted.
- [x] Pagination.
- [ ] Categories, tags, and custom taxonomies.
- [ ] Custom fields.
- [ ] Filter search results (AND, OR, IN, NOT IN, etc.)
- [ ] Multisite support, including the option to index all sites in a network.
- [ ] Multisite support. Select which sites to index.
- [ ] Multisite support. Select which site to do search from.
- [ ] Supports filtering (and ordering) on any attribute with any SQL-inspired filter statement
