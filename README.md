# Telco SQL Sync

**English** | [Shqip](README.sq.md)

A WooCommerce plugin that imports products from a local `.sql` dump file.

The SQL is **parsed, never executed** – only `INSERT` statements are read and turned into WooCommerce products.

## Features
- Upload a `.sql` file, or point to one on the server (good for large dumps)
- Auto-detects tables and columns, with a mapping screen to fix anything it guesses wrong
- Maps name, SKU, prices, descriptions, stock, status, categories (`A > B` hierarchy), tags, images, weight and dimensions
- Create new products or update/skip existing ones (matched by SKU or a source ID)
- Imports images into the media library: a column with an image URL or file path becomes the product photo, and a column with a folder path puts every image in that folder into the product gallery
- Optionally upload a ZIP of your images, so the paths and folders in the SQL are resolved inside it (only image files are kept)
- Local image paths are restricted to the image folder you choose; nothing outside it is ever read
- Batched AJAX import with progress bar, error log and resume

## Install
Copy this folder to `wp-content/plugins/telco-sql-sync/` and activate it (WooCommerce required). Then go to **Products → Telco SQL Sync**.

Try it with `sample/sample-products.sql`.

## Limitations
- Simple products only (no variable products yet)
- Foreign-key IDs (e.g. a category stored as `3`) are not resolved
- INSERTs need column names, or the file needs a `CREATE TABLE`

## License
GPL-2.0-or-later. See [LICENSE](LICENSE) (official English text); an unofficial Albanian summary is in [LICENSE.sq.md](LICENSE.sq.md).
