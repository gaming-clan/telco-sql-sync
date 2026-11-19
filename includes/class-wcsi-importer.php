<?php
/**
 * Turns rows parsed from a SQL dump into WooCommerce products.
 *
 * @package Telco_SQL_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCSI_Importer {

	/**
	 * Importable product fields: key => [ label, column-name aliases used for auto mapping ].
	 */
	public static function fields() {
		return array(
			'name'              => array( __( 'Product name (required)', 'telco-sql-sync' ), array( 'name', 'product_name', 'title', 'post_title', 'product_title' ) ),
			'sku'               => array( __( 'SKU', 'telco-sql-sync' ), array( 'sku', 'product_sku', 'code', 'product_code', 'reference', 'ref' ) ),
			'source_id'         => array( __( 'Source ID (stored as meta, used for matching)', 'telco-sql-sync' ), array( 'id', 'product_id', 'ID', 'pid' ) ),
			'regular_price'     => array( __( 'Regular price', 'telco-sql-sync' ), array( 'regular_price', 'price', 'product_price', 'list_price', 'msrp' ) ),
			'sale_price'        => array( __( 'Sale price', 'telco-sql-sync' ), array( 'sale_price', 'special_price', 'discount_price' ) ),
			'description'       => array( __( 'Description', 'telco-sql-sync' ), array( 'description', 'long_description', 'post_content', 'content', 'details' ) ),
			'short_description' => array( __( 'Short description', 'telco-sql-sync' ), array( 'short_description', 'excerpt', 'post_excerpt', 'summary' ) ),
			'stock_quantity'    => array( __( 'Stock quantity', 'telco-sql-sync' ), array( 'stock_quantity', 'stock', 'quantity', 'qty', 'inventory' ) ),
			'stock_status'      => array( __( 'Stock status', 'telco-sql-sync' ), array( 'stock_status', 'availability' ) ),
			'status'            => array( __( 'Post status (publish/draft/…)', 'telco-sql-sync' ), array( 'status', 'post_status', 'active', 'enabled', 'published' ) ),
			'slug'              => array( __( 'Slug', 'telco-sql-sync' ), array( 'slug', 'post_name', 'url_key' ) ),
			'categories'        => array( __( 'Categories (use ">" for hierarchy)', 'telco-sql-sync' ), array( 'categories', 'category', 'category_name', 'product_cat' ) ),
			'tags'              => array( __( 'Tags', 'telco-sql-sync' ), array( 'tags', 'tag', 'keywords' ) ),
			'image'             => array( __( 'Main image (URL or file name)', 'telco-sql-sync' ), array( 'image', 'image_url', 'img', 'thumbnail', 'picture', 'photo' ) ),
			'gallery'           => array( __( 'Gallery images (list)', 'telco-sql-sync' ), array( 'gallery', 'images', 'gallery_images', 'additional_images' ) ),
			'weight'            => array( __( 'Weight', 'telco-sql-sync' ), array( 'weight' ) ),
			'length'            => array( __( 'Length', 'telco-sql-sync' ), array( 'length' ) ),
			'width'             => array( __( 'Width', 'telco-sql-sync' ), array( 'width' ) ),
			'height'            => array( __( 'Height', 'telco-sql-sync' ), array( 'height' ) ),
		);
	}

	/** Guess field => column mapping from column names. */
	public static function guess_mapping( array $columns ) {
		$lower = array();
		foreach ( $columns as $c ) {
			$lower[ strtolower( $c ) ] = $c;
		}
		$map = array();
		foreach ( self::fields() as $key => $def ) {
			foreach ( $def[1] as $alias ) {
				if ( isset( $lower[ strtolower( $alias ) ] ) && ! in_array( $lower[ strtolower( $alias ) ], $map, true ) ) {
					$map[ $key ] = $lower[ strtolower( $alias ) ];
					break;
				}
			}
		}
		return $map;
	}

	/**
	 * Process rows of the job's table for up to $budget seconds. Updates $job in place.
	 */
	public static function run_batch( array &$job, $budget = 10 ) {
		$deadline       = microtime( true ) + $budget;
		$job['new_log'] = array();
		$parser         = new WCSI_SQL_Parser( $job['file'] );
		$importer       = new self( $job );

		wp_defer_term_counting( true );
		try {
			foreach ( $parser->statements( (int) $job['offset'] ) as $s ) {
				list( $stmt, $start, $end ) = $s;

				$table = WCSI_SQL_Parser::sniff_insert_table( $stmt );
				if ( $table === $job['table'] ) {
					$ins = WCSI_SQL_Parser::parse_insert( $stmt );
					if ( $ins ) {
						$cols = $ins['columns'] ? $ins['columns'] : $job['columns'];
						$idx  = 0;
						foreach ( $ins['rows'] as $row ) {
							if ( $idx++ < (int) $job['skip_rows'] ) {
								continue;
							}
							$importer->handle_row( $cols, $row, $job );
							$job['skip_rows'] = $idx;
							if ( microtime( true ) > $deadline ) {
								$job['offset'] = $start; // Resume inside this statement.
								return;
							}
						}
					}
				}

				$job['offset']    = $end;
				$job['skip_rows'] = 0;
				if ( microtime( true ) > $deadline ) {
					return;
				}
			}
			$job['done']   = true;
			$job['offset'] = $job['size'];
		} finally {
			wp_defer_term_counting( false );
		}
	}

	/** @var array */
	private $opts;

	/** @var array */
	private $map;

	/** @var string[] */
	private $warnings = array();

	public function __construct( array $job ) {
		$this->opts = $job['options'];
		$this->map  = $job['mapping'];
	}

	private function handle_row( $cols, $row, array &$job ) {
		$job['rows_seen'] = (int) $job['rows_seen'] + 1;
		try {
			if ( ! is_array( $cols ) || count( $cols ) !== count( $row ) ) {
				throw new Exception( 'column count does not match value count (is there a CREATE TABLE or column list in the file?)' );
			}
			$this->warnings = array();
			$result         = $this->import_row( array_combine( $cols, $row ) );
			$job[ $result ] = (int) $job[ $result ] + 1;
			foreach ( $this->warnings as $w ) {
				$msg              = sprintf( 'Row %d (warning): %s', $job['rows_seen'], $w );
				$job['new_log'][] = $msg;
				$job['log'][]     = $msg;
			}
			$job['log'] = array_slice( (array) $job['log'], -200 );
		} catch ( Throwable $e ) {
			$job['failed']    = (int) $job['failed'] + 1;
			$job['new_log'][] = sprintf( 'Row %d: %s', $job['rows_seen'], $e->getMessage() );
			$job['log']       = array_slice( array_merge( (array) $job['log'], array( end( $job['new_log'] ) ) ), -200 );
		}
	}

	private function val( array $data, $field ) {
		if ( empty( $this->map[ $field ] ) || ! array_key_exists( $this->map[ $field ], $data ) ) {
			return null;
		}
		return $data[ $this->map[ $field ] ];
	}

	private function has( array $data, $field ) {
		return ! empty( $this->map[ $field ] ) && array_key_exists( $this->map[ $field ], $data );
	}

	/** @return string created|updated|skipped */
	private function import_row( array $data ) {
		$name      = trim( (string) $this->val( $data, 'name' ) );
		$sku       = trim( (string) $this->val( $data, 'sku' ) );
		$source_id = trim( (string) $this->val( $data, 'source_id' ) );

		$existing_id = 0;
		if ( 'source_id' === $this->opts['match'] ) {
			if ( '' !== $source_id ) {
				$found = get_posts(
					array(
						'post_type'   => 'product',
						'post_status' => 'any',
						'meta_key'    => '_wcsi_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'  => $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery
						'fields'      => 'ids',
						'numberposts' => 1,
					)
				);
				$existing_id = $found ? (int) $found[0] : 0;
			}
		} elseif ( '' !== $sku ) {
			$existing_id = (int) wc_get_product_id_by_sku( $sku );
		}

		if ( $existing_id && 'skip' === $this->opts['existing'] ) {
			return 'skipped';
		}

		if ( $existing_id ) {
			$product = wc_get_product( $existing_id );
			if ( ! $product ) {
				throw new Exception( 'could not load existing product #' . $existing_id );
			}
		} else {
			if ( '' === $name ) {
				throw new Exception( 'missing product name' );
			}
			$product = new WC_Product_Simple();
			$product->set_status( $this->opts['status'] );
		}

		if ( '' !== $name ) {
			$product->set_name( $name );
		}
		if ( $this->has( $data, 'slug' ) && '' !== trim( (string) $this->val( $data, 'slug' ) ) ) {
			$product->set_slug( sanitize_title( $this->val( $data, 'slug' ) ) );
		}
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( $this->has( $data, 'description' ) ) {
			$product->set_description( wp_kses_post( (string) $this->val( $data, 'description' ) ) );
		}
		if ( $this->has( $data, 'short_description' ) ) {
			$product->set_short_description( wp_kses_post( (string) $this->val( $data, 'short_description' ) ) );
		}
		if ( $this->has( $data, 'regular_price' ) ) {
			$product->set_regular_price( $this->price( $this->val( $data, 'regular_price' ) ) );
		}
		if ( $this->has( $data, 'sale_price' ) ) {
			$product->set_sale_price( $this->price( $this->val( $data, 'sale_price' ) ) );
		}
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $dim ) {
			if ( $this->has( $data, $dim ) ) {
				$v = $this->val( $data, $dim );
				$product->{"set_$dim"}( ( null === $v || '' === trim( (string) $v ) ) ? '' : wc_format_decimal( $v ) );
			}
		}
		if ( $this->has( $data, 'stock_quantity' ) ) {
			$q = $this->val( $data, 'stock_quantity' );
			if ( null !== $q && '' !== trim( (string) $q ) && is_numeric( $q ) ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( wc_stock_amount( $q ) );
			} else {
				$product->set_manage_stock( false );
			}
		}
		if ( $this->has( $data, 'stock_status' ) && ! $product->get_manage_stock() ) {
			$product->set_stock_status( $this->stock_status( $this->val( $data, 'stock_status' ) ) );
		}
		if ( $this->has( $data, 'status' ) && null !== $this->val( $data, 'status' ) ) {
			$product->set_status( $this->post_status( $this->val( $data, 'status' ) ) );
		}
		if ( $this->has( $data, 'categories' ) ) {
			$product->set_category_ids( $this->term_ids( $this->val( $data, 'categories' ), 'product_cat', true ) );
		}
		if ( $this->has( $data, 'tags' ) ) {
			$product->set_tag_ids( $this->term_ids( $this->val( $data, 'tags' ), 'product_tag', false ) );
		}

		$warnings = array();
		if ( ! empty( $this->opts['images'] ) ) {
			if ( $this->has( $data, 'image' ) && '' !== trim( (string) $this->val( $data, 'image' ) ) ) {
				try {
					$product->set_image_id( $this->image_id( $this->val( $data, 'image' ) ) );
				} catch ( Throwable $e ) {
					$warnings[] = 'main image: ' . $e->getMessage();
				}
			}
			if ( $this->has( $data, 'gallery' ) && '' !== trim( (string) $this->val( $data, 'gallery' ) ) ) {
				$ids = array();
				foreach ( $this->split_list( $this->val( $data, 'gallery' ) ) as $src ) {
					try {
						$ids[] = $this->image_id( $src );
					} catch ( Throwable $e ) {
						$warnings[] = 'gallery image: ' . $e->getMessage();
					}
				}
				if ( $ids ) {
					$product->set_gallery_image_ids( $ids );
				}
			}
		}

		$this->warnings = $warnings;
		$id             = $product->save();
		if ( '' !== $source_id ) {
			update_post_meta( $id, '_wcsi_source_id', $source_id );
		}

		return $existing_id ? 'updated' : 'created';
	}

	private function price( $v ) {
		if ( null === $v ) {
			return '';
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$v = preg_replace( '/[^\d.,\-]/', '', $v );
		return '' === $v ? '' : wc_format_decimal( $v );
	}

	private function stock_status( $v ) {
		$v = strtolower( trim( (string) $v ) );
		if ( in_array( $v, array( 'outofstock', 'out of stock', 'out_of_stock', '0', 'no', 'false' ), true ) ) {
			return 'outofstock';
		}
		if ( in_array( $v, array( 'onbackorder', 'on backorder', 'backorder' ), true ) ) {
			return 'onbackorder';
		}
		return 'instock';
	}

	private function post_status( $v ) {
		$v = strtolower( trim( (string) $v ) );
		if ( in_array( $v, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			return $v;
		}
		if ( in_array( $v, array( '0', 'no', 'false', 'inactive', 'disabled', 'unpublished' ), true ) ) {
			return 'draft';
		}
		return 'publish';
	}

	private function split_list( $v ) {
		$sep   = '' !== $this->opts['list_sep'] ? $this->opts['list_sep'] : ',';
		$items = array_map( 'trim', explode( $sep, (string) $v ) );
		return array_values( array_filter( $items, 'strlen' ) );
	}

	private function term_ids( $value, $taxonomy, $hierarchical ) {
		$ids = array();
		if ( null === $value ) {
			return $ids;
		}
		foreach ( $this->split_list( $value ) as $item ) {
			$parts  = $hierarchical ? array_map( 'trim', explode( '>', $item ) ) : array( $item );
			$parent = 0;
			$id     = 0;
			foreach ( $parts as $part ) {
				if ( '' === $part ) {
					continue;
				}
				$term = term_exists( $part, $taxonomy, $hierarchical ? $parent : null );
				if ( ! $term ) {
					$args = $hierarchical ? array( 'parent' => $parent ) : array();
					$term = wp_insert_term( $part, $taxonomy, $args );
					if ( is_wp_error( $term ) ) {
						$existing = $term->get_error_data( 'term_exists' );
						if ( ! $existing ) {
							throw new Exception( $taxonomy . ' "' . $part . '": ' . $term->get_error_message() );
						}
						$term = array( 'term_id' => (int) $existing );
					}
				}
				$id     = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				$parent = $id;
			}
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Sideload an image (URL, or file relative to the configured base folder). Returns attachment ID. */
	private function image_id( $src ) {
		$src = trim( (string) $src );

		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => '_wcsi_source', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $src, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( preg_match( '#^https?://#i', $src ) ) {
			$tmp = download_url( $src, 30 );
			if ( is_wp_error( $tmp ) ) {
				throw new Exception( $src . ' – ' . $tmp->get_error_message() );
			}
			$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		} else {
			$base = $this->opts['image_base'];
			if ( '' === $base || ! is_dir( $base ) ) {
				throw new Exception( $src . ' – not a URL and no valid local image folder configured' );
			}
			$real_base = realpath( $base );
			$real      = realpath( $real_base . DIRECTORY_SEPARATOR . ltrim( $src, '/\\' ) );
			if ( ! $real || 0 !== strpos( $real, $real_base ) || ! is_file( $real ) ) {
				throw new Exception( $src . ' – file not found in image folder' );
			}
			$tmp = wp_tempnam( $real );
			if ( ! copy( $real, $tmp ) ) {
				throw new Exception( $src . ' – could not copy file' );
			}
			$name = basename( $real );
		}

		$id = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $name ),
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			throw new Exception( $src . ' – ' . $id->get_error_message() );
		}
		update_post_meta( $id, '_wcsi_source', $src );
		return (int) $id;
	}
}
