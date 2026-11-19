# Telco SQL Sync

**English** | [Shqip](README.sq.md)

A WooCommerce plugin that imports products from a local `.sql` dump file.

The SQL is **parsed, never executed** – only `INSERT` statements are read and turned into WooCommerce products.

## Features
- Upload a `.sql` file, or point to one on the server (good for large dumps)
- Auto-detects tables and columns, with a mapping screen to fix anything it guesses wrong
- Maps name, type, SKU, prices (handles `1,299.00`, `1.299,50`, `12,5`), sale dates, descriptions, stock, backorders, status, visibility, categories (`A > B` hierarchy), tags, attributes, images, tax status/class, shipping class, weight and dimensions, upsells, cross-sells, and more
- Product types: simple, variable (with variations as child rows via a parent SKU), grouped and external
- Optional: save every unmapped column as a custom field (`sql_<column>`)
- Create new products or update/skip existing ones (matched by SKU or a source ID)
- Imports images into the media library: a column with an image URL or file path becomes the product photo, and a column with a folder path puts every image in that folder into the product gallery
- Optionally upload a ZIP of your images, so the paths and folders in the SQL are resolved inside it (only image files are kept)
- Local image paths are restricted to the image folder you choose; nothing outside it is ever read
- Batched AJAX import with progress bar, error log and resume

## Install
Copy this folder to `wp-content/plugins/telco-sql-sync/` and activate it (WooCommerce required). Then go to **Products → Telco SQL Sync**.

Try it with `sample/sample-products.sql`.

## Limitations
- No product reviews, global (taxonomy) attributes, or downloadable-file products
- Foreign-key IDs (e.g. a category stored as `3`) are not resolved
- Variation, grouped, upsell and cross-sell rows can only reference products that appear earlier in the file
- INSERTs need column names, or the file needs a `CREATE TABLE`

## License
GPL-2.0-or-later. See [LICENSE](LICENSE) (official English text); an unofficial Albanian summary is in [LICENSE.sq.md](LICENSE.sq.md).
