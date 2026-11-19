<?php
/**
 * Turns rows parsed from a SQL dump into WooCommerce products.
 *
 * Products are created through WooCommerce's own CRUD classes, never by writing
 * to the database tables directly.
 *
 * @package Telco_SQL_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCSI_Importer {

	/**
	 * Importable fields: key => [ label, column-name aliases used for auto mapping ].
	 */
	public static function fields() {
		$t = 'telco-sql-sync';
		return array(
			'name'               => array( __( 'Product name (required)', $t ), array( 'name', 'product_name', 'title', 'post_title', 'product_title' ) ),
			'type'               => array( __( 'Product type (simple / variable / variation / grouped / external)', $t ), array( 'type', 'product_type' ) ),
			'parent'             => array( __( 'Parent product for variations (SKU or Source ID)', $t ), array( 'parent', 'parent_sku', 'parent_id' ) ),
			'sku'                => array( __( 'SKU', $t ), array( 'sku', 'product_sku', 'code', 'product_code', 'reference', 'ref' ) ),
			'source_id'          => array( __( 'Source ID (stored as meta, used for matching)', $t ), array( 'id', 'product_id', 'pid' ) ),
			'regular_price'      => array( __( 'Regular price', $t ), array( 'regular_price', 'price', 'product_price', 'list_price', 'msrp' ) ),
			'sale_price'         => array( __( 'Sale price', $t ), array( 'sale_price', 'special_price', 'discount_price' ) ),
			'sale_from'          => array( __( 'Sale start date', $t ), array( 'sale_from', 'date_on_sale_from', 'sale_start' ) ),
			'sale_to'            => array( __( 'Sale end date', $t ), array( 'sale_to', 'date_on_sale_to', 'sale_end' ) ),
			'description'        => array( __( 'Description', $t ), array( 'description', 'long_description', 'post_content', 'content', 'details' ) ),
			'short_description'  => array( __( 'Short description', $t ), array( 'short_description', 'excerpt', 'post_excerpt', 'summary' ) ),
			'stock_quantity'     => array( __( 'Stock quantity', $t ), array( 'stock_quantity', 'stock', 'quantity', 'qty', 'inventory' ) ),
			'stock_status'       => array( __( 'Stock status', $t ), array( 'stock_status', 'availability' ) ),
			'backorders'         => array( __( 'Backorders (no / notify / yes)', $t ), array( 'backorders', 'allow_backorders' ) ),
			'sold_individually'  => array( __( 'Sold individually (yes/no)', $t ), array( 'sold_individually' ) ),
			'status'             => array( __( 'Post status (publish/draft/…)', $t ), array( 'status', 'post_status', 'active', 'enabled', 'published' ) ),
			'catalog_visibility' => array( __( 'Catalog visibility (visible / catalog / search / hidden)', $t ), array( 'catalog_visibility', 'visibility' ) ),
			'featured'           => array( __( 'Featured (yes/no)', $t ), array( 'featured', 'is_featured' ) ),
			'slug'               => array( __( 'Slug', $t ), array( 'slug', 'post_name', 'url_key' ) ),
			'categories'         => array( __( 'Categories (use ">" for hierarchy)', $t ), array( 'categories', 'category', 'category_name', 'product_cat' ) ),
			'tags'               => array( __( 'Tags', $t ), array( 'tags', 'tag', 'keywords' ) ),
			'attributes'         => array( __( 'Attributes (e.g. Color: Red, Blue | Size: S, M)', $t ), array( 'attributes', 'attrs', 'options' ) ),
			'image'              => array( __( 'Main image (URL or file path)', $t ), array( 'image', 'image_url', 'image_path', 'img', 'thumbnail', 'picture', 'photo' ) ),
			'gallery'            => array( __( 'Gallery images (list of URLs / paths)', $t ), array( 'gallery', 'images', 'gallery_images', 'additional_images' ) ),
			'gallery_folder'     => array( __( 'Image folder → gallery (folder path)', $t ), array( 'gallery_folder', 'image_folder', 'images_folder', 'photos_folder', 'folder' ) ),
			'virtual'            => array( __( 'Virtual (yes/no)', $t ), array( 'virtual', 'is_virtual' ) ),
			'tax_status'         => array( __( 'Tax status (taxable / shipping / none)', $t ), array( 'tax_status' ) ),
			'tax_class'          => array( __( 'Tax class (slug, e.g. reduced-rate)', $t ), array( 'tax_class' ) ),
			'shipping_class'     => array( __( 'Shipping class (name or slug)', $t ), array( 'shipping_class' ) ),
			'weight'             => array( __( 'Weight', $t ), array( 'weight' ) ),
			'length'             => array( __( 'Length', $t ), array( 'length' ) ),
			'width'              => array( __( 'Width', $t ), array( 'width' ) ),
			'height'             => array( __( 'Height', $t ), array( 'height' ) ),
			'upsells'            => array( __( 'Upsells (list of SKUs)', $t ), array( 'upsells', 'upsell_skus' ) ),
			'cross_sells'        => array( __( 'Cross-sells (list of SKUs)', $t ), array( 'cross_sells', 'crosssells', 'cross_sell_skus' ) ),
			'grouped_skus'       => array( __( 'Grouped product children (list of SKUs)', $t ), array( 'grouped_skus', 'children', 'child_skus' ) ),
			'product_url'        => array( __( 'External product URL', $t ), array( 'product_url', 'external_url', 'affiliate_url' ) ),
			'button_text'        => array( __( 'External button text', $t ), array( 'button_text' ) ),
			'purchase_note'      => array( __( 'Purchase note', $t ), array( 'purchase_note' ) ),
			'menu_order'         => array( __( 'Menu order', $t ), array( 'menu_order', 'sort_order', 'position' ) ),
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
	 * Normalise a human-formatted number to a plain decimal string ("1.234,50" and
	 * "1,234.50" both become "1234.50"). Returns '' for empty/non-numeric input.
	 *
	 * A lone comma followed by exactly three digits ("1,299") is read as a
	 * thousands separator; otherwise a lone comma is a decimal comma ("12,5").
	 */
	public static function normalize_number( $v ) {
		if ( null === $v ) {
			return '';
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$neg = ( false !== strpos( $v, '-' ) ) || ( '(' === $v[0] );
		$v   = preg_replace( '/[^\d.,]/', '', $v );
		if ( '' === $v ) {
			return '';
		}
		$commas = substr_count( $v, ',' );
		$dots   = substr_count( $v, '.' );

		if ( $commas && $dots ) {
			$dec  = strrpos( $v, ',' ) > strrpos( $v, '.' ) ? ',' : '.';
			$thou = ',' === $dec ? '.' : ',';
			$v    = str_replace( $thou, '', $v );
			$v    = str_replace( $dec, '.', $v );
		} elseif ( $commas ) {
			if ( $commas > 1 || preg_match( '/^\d{1,3},\d{3}$/', $v ) ) {
				$v = str_replace( ',', '', $v );
			} else {
				$v = str_replace( ',', '.', $v );
			}
		} elseif ( $dots > 1 ) {
			$v = str_replace( '.', '', $v );
		}
		return ( $neg ? '-' : '' ) . $v;
	}

	/**
	 * Parse "Color: Red, Blue | Size: S, M" into [ 'Color' => ['Red','Blue'], 'Size' => ['S','M'] ].
	 */
	public static function parse_attributes( $str ) {
		$out = array();
		foreach ( explode( '|', (string) $str ) as $group ) {
			$pos = strpos( $group, ':' );
			if ( false === $pos ) {
				continue;
			}
			$name = trim( substr( $group, 0, $pos ) );
			$vals = array_values( array_filter( array_map( 'trim', explode( ',', substr( $group, $pos + 1 ) ) ), 'strlen' ) );
			if ( '' !== $name && $vals ) {
				$out[ $name ] = $vals;
			}
		}
		return $out;
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

	private function warn( $msg ) {
		$this->warnings[] = $msg;
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

	/** Trimmed string value of a mapped field ('' if unmapped or NULL). */
	private function str( array $data, $field ) {
		return trim( (string) $this->val( $data, $field ) );
	}

	private function truthy( $v ) {
		return in_array( strtolower( trim( (string) $v ) ), array( '1', 'yes', 'y', 'true', 'on', 'x' ), true );
	}

	/** Requested product type, or null when the type column is unmapped/empty. */
	private function mapped_type( array $data ) {
		$v = strtolower( $this->str( $data, 'type' ) );
		if ( '' === $v ) {
			return null;
		}
		return in_array( $v, array( 'simple', 'variable', 'variation', 'grouped', 'external' ), true ) ? $v : 'simple';
	}

	private function find_by_source_id( $source_id, $post_type ) {
		$found = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'meta_key'    => '_wcsi_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/** @return string created|updated|skipped */
	private function import_row( array $data ) {
		$type       = $this->mapped_type( $data );
		$parent_ref = $this->str( $data, 'parent' );
		if ( 'variation' === $type || ( null === $type && '' !== $parent_ref ) ) {
			return $this->import_variation( $data, $parent_ref );
		}

		$name      = $this->str( $data, 'name' );
		$sku       = $this->str( $data, 'sku' );
		$source_id = $this->str( $data, 'source_id' );

		$existing_id = 0;
		if ( 'source_id' === $this->opts['match'] ) {
			if ( '' !== $source_id ) {
				$existing_id = $this->find_by_source_id( $source_id, 'product' );
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
			if ( $product->is_type( 'variation' ) ) {
				throw new Exception( 'SKU "' . $sku . '" belongs to a variation, not a product' );
			}
			if ( $type && $product->get_type() !== $type ) {
				wp_set_object_terms( $existing_id, $type, 'product_type' );
				clean_post_cache( $existing_id );
				$product = wc_get_product( $existing_id );
			}
		} else {
			if ( '' === $name ) {
				throw new Exception( 'missing product name' );
			}
			switch ( $type ) {
				case 'variable':
					$product = new WC_Product_Variable();
					break;
				case 'grouped':
					$product = new WC_Product_Grouped();
					break;
				case 'external':
					$product = new WC_Product_External();
					break;
				default:
					$product = new WC_Product_Simple();
			}
			$product->set_status( $this->opts['status'] );
		}

		if ( '' !== $name ) {
			$product->set_name( $name );
		}
		if ( $this->has( $data, 'slug' ) && '' !== $this->str( $data, 'slug' ) ) {
			$product->set_slug( sanitize_title( $this->val( $data, 'slug' ) ) );
		}
		if ( $this->has( $data, 'short_description' ) ) {
			$product->set_short_description( wp_kses_post( (string) $this->val( $data, 'short_description' ) ) );
		}
		if ( $this->has( $data, 'featured' ) ) {
			$product->set_featured( $this->truthy( $this->val( $data, 'featured' ) ) );
		}
		if ( $this->has( $data, 'sold_individually' ) ) {
			$product->set_sold_individually( $this->truthy( $this->val( $data, 'sold_individually' ) ) );
		}
		if ( $this->has( $data, 'purchase_note' ) ) {
			$product->set_purchase_note( wp_kses_post( (string) $this->val( $data, 'purchase_note' ) ) );
		}
		if ( $this->has( $data, 'catalog_visibility' ) && in_array( strtolower( $this->str( $data, 'catalog_visibility' ) ), array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
			$product->set_catalog_visibility( strtolower( $this->str( $data, 'catalog_visibility' ) ) );
		}
		if ( $this->has( $data, 'categories' ) ) {
			$product->set_category_ids( $this->term_ids( $this->val( $data, 'categories' ), 'product_cat', true ) );
		}
		if ( $this->has( $data, 'tags' ) ) {
			$product->set_tag_ids( $this->term_ids( $this->val( $data, 'tags' ), 'product_tag', false ) );
		}
		if ( $this->has( $data, 'attributes' ) ) {
			$this->apply_attributes( $product, $this->val( $data, 'attributes' ) );
		}
		if ( $product->is_type( 'external' ) ) {
			if ( $this->has( $data, 'product_url' ) ) {
				$product->set_product_url( esc_url_raw( $this->str( $data, 'product_url' ) ) );
			}
			if ( $this->has( $data, 'button_text' ) ) {
				$product->set_button_text( sanitize_text_field( $this->str( $data, 'button_text' ) ) );
			}
		}
		if ( $product->is_type( 'grouped' ) && $this->has( $data, 'grouped_skus' ) ) {
			$product->set_children( $this->sku_ids( $this->val( $data, 'grouped_skus' ), 'grouped child' ) );
		}
		if ( $this->has( $data, 'upsells' ) ) {
			$product->set_upsell_ids( $this->sku_ids( $this->val( $data, 'upsells' ), 'upsell' ) );
		}
		if ( $this->has( $data, 'cross_sells' ) ) {
			$product->set_cross_sell_ids( $this->sku_ids( $this->val( $data, 'cross_sells' ), 'cross-sell' ) );
		}

		$this->apply_common( $product, $data, false );
		$this->apply_images( $product, $data, true );
		$this->apply_custom_meta( $product, $data );

		$id = $product->save();
		if ( '' !== $source_id ) {
			update_post_meta( $id, '_wcsi_source_id', $source_id );
		}
		return $existing_id ? 'updated' : 'created';
	}

	/** @return string created|updated|skipped */
	private function import_variation( array $data, $parent_ref ) {
		if ( '' === $parent_ref ) {
			throw new Exception( 'variation row has no parent (map the "Parent" column)' );
		}
		$parent_id = (int) wc_get_product_id_by_sku( $parent_ref );
		if ( ! $parent_id ) {
			$parent_id = $this->find_by_source_id( $parent_ref, 'product' );
		}
		$parent = $parent_id ? wc_get_product( $parent_id ) : null;
		if ( ! $parent ) {
			throw new Exception( 'parent "' . $parent_ref . '" not found (parent rows must come before their variations in the file)' );
		}
		if ( ! $parent->is_type( 'variable' ) ) {
			throw new Exception( 'parent "' . $parent_ref . '" is not a variable product (set its type to "variable")' );
		}

		$sku       = $this->str( $data, 'sku' );
		$source_id = $this->str( $data, 'source_id' );

		$existing_id = 0;
		if ( 'source_id' === $this->opts['match'] && '' !== $source_id ) {
			$existing_id = $this->find_by_source_id( $source_id, 'product_variation' );
		} elseif ( '' !== $sku ) {
			$found = (int) wc_get_product_id_by_sku( $sku );
			if ( $found ) {
				$p = wc_get_product( $found );
				if ( $p && $p->is_type( 'variation' ) && (int) $p->get_parent_id() === $parent_id ) {
					$existing_id = $found;
				} else {
					throw new Exception( 'SKU "' . $sku . '" is already used by another product' );
				}
			}
		}
		if ( $existing_id && 'skip' === $this->opts['existing'] ) {
			return 'skipped';
		}

		$variation = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Variation();
		if ( ! $variation ) {
			throw new Exception( 'could not load existing variation #' . $existing_id );
		}
		$variation->set_parent_id( $parent_id );
		if ( ! $existing_id ) {
			$variation->set_status( 'publish' );
		}

		if ( $this->has( $data, 'description' ) ) {
			$variation->set_description( wp_kses_post( (string) $this->val( $data, 'description' ) ) );
		}
		if ( $this->has( $data, 'menu_order' ) && is_numeric( $this->val( $data, 'menu_order' ) ) ) {
			$variation->set_menu_order( (int) $this->val( $data, 'menu_order' ) );
		}
		if ( $this->has( $data, 'attributes' ) ) {
			$pairs = array();
			foreach ( self::parse_attributes( $this->val( $data, 'attributes' ) ) as $n => $vals ) {
				$pairs[ sanitize_title( $n ) ] = $vals[0];
			}
			if ( $pairs ) {
				$variation->set_attributes( $pairs );
				$this->add_parent_options( $parent, $pairs, $this->val( $data, 'attributes' ) );
			}
		}

		$this->apply_common( $variation, $data, true );
		$this->apply_images( $variation, $data, false );
		$this->apply_custom_meta( $variation, $data );

		$id = $variation->save();
		if ( '' !== $source_id ) {
			update_post_meta( $id, '_wcsi_source_id', $source_id );
		}
		WC_Product_Variable::sync( $parent_id );
		return $existing_id ? 'updated' : 'created';
	}

	/**
	 * If a variation uses an attribute value the parent doesn't list yet, add it,
	 * so the variation stays selectable on the product page.
	 */
	private function add_parent_options( $parent, array $pairs, $raw ) {
		$changed = false;
		$attrs   = $parent->get_attributes();
		foreach ( self::parse_attributes( $raw ) as $name => $vals ) {
			$key = sanitize_title( $name );
			if ( ! isset( $attrs[ $key ] ) ) {
				continue;
			}
			$opts = $attrs[ $key ]->get_options();
			if ( ! in_array( $vals[0], $opts, true ) ) {
				$opts[] = $vals[0];
				$attrs[ $key ]->set_options( $opts );
				$changed = true;
			}
		}
		if ( $changed ) {
			$parent->set_attributes( $attrs );
			$parent->save();
		}
	}

	/** Fields shared by products and variations. */
	private function apply_common( $product, array $data, $is_variation ) {
		$sku = $this->str( $data, 'sku' );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( ! $is_variation && $this->has( $data, 'description' ) ) {
			$product->set_description( wp_kses_post( (string) $this->val( $data, 'description' ) ) );
		}
		if ( $this->has( $data, 'regular_price' ) ) {
			$product->set_regular_price( $this->price( $this->val( $data, 'regular_price' ) ) );
		}
		if ( $this->has( $data, 'sale_price' ) ) {
			$product->set_sale_price( $this->price( $this->val( $data, 'sale_price' ) ) );
		}
		if ( $this->has( $data, 'sale_from' ) ) {
			$d = $this->str( $data, 'sale_from' );
			$product->set_date_on_sale_from( '' === $d ? null : $d );
		}
		if ( $this->has( $data, 'sale_to' ) ) {
			$d = $this->str( $data, 'sale_to' );
			$product->set_date_on_sale_to( '' === $d ? null : $d );
		}
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $dim ) {
			if ( $this->has( $data, $dim ) ) {
				$product->{"set_$dim"}( $this->price( $this->val( $data, $dim ) ) );
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
		if ( $this->has( $data, 'backorders' ) ) {
			$product->set_backorders( $this->backorders( $this->val( $data, 'backorders' ) ) );
		}
		if ( $this->has( $data, 'virtual' ) ) {
			$product->set_virtual( $this->truthy( $this->val( $data, 'virtual' ) ) );
		}
		if ( $this->has( $data, 'tax_status' ) && in_array( strtolower( $this->str( $data, 'tax_status' ) ), array( 'taxable', 'shipping', 'none' ), true ) ) {
			$product->set_tax_status( strtolower( $this->str( $data, 'tax_status' ) ) );
		}
		if ( $this->has( $data, 'tax_class' ) ) {
			$cls = sanitize_title( $this->str( $data, 'tax_class' ) );
			if ( in_array( $cls, array( '', 'standard' ), true ) ) {
				$product->set_tax_class( '' );
			} elseif ( in_array( $cls, WC_Tax::get_tax_class_slugs(), true ) ) {
				$product->set_tax_class( $cls );
			} else {
				$this->warn( 'unknown tax class "' . $cls . '" ignored' );
			}
		}
		if ( $this->has( $data, 'shipping_class' ) && '' !== $this->str( $data, 'shipping_class' ) ) {
			$ids = $this->term_ids( $this->str( $data, 'shipping_class' ), 'product_shipping_class', false );
			$product->set_shipping_class_id( $ids ? $ids[0] : 0 );
		}
		if ( $this->has( $data, 'status' ) && null !== $this->val( $data, 'status' ) ) {
			$st = $this->post_status( $this->val( $data, 'status' ) );
			if ( $is_variation ) {
				$st = 'publish' === $st ? 'publish' : 'private';
			}
			$product->set_status( $st );
		}
		if ( ! $is_variation && $this->has( $data, 'menu_order' ) && is_numeric( $this->val( $data, 'menu_order' ) ) ) {
			$product->set_menu_order( (int) $this->val( $data, 'menu_order' ) );
		}
	}

	private function apply_attributes( $product, $raw ) {
		$parsed  = self::parse_attributes( $raw );
		$is_var  = $product->is_type( 'variable' );
		$attrs   = array();
		$pos     = 0;
		foreach ( $parsed as $name => $values ) {
			$a = new WC_Product_Attribute();
			$a->set_id( 0 );
			$a->set_name( $name );
			$a->set_options( $values );
			$a->set_position( $pos++ );
			$a->set_visible( true );
			$a->set_variation( $is_var );
			$attrs[ sanitize_title( $name ) ] = $a;
		}
		$product->set_attributes( $attrs );
	}

	/** Save unmapped columns as custom fields (sql_<column>) when enabled. */
	private function apply_custom_meta( $product, array $data ) {
		if ( empty( $this->opts['meta_unmapped'] ) ) {
			return;
		}
		$mapped = array_values( $this->map );
		foreach ( $data as $col => $v ) {
			if ( in_array( $col, $mapped, true ) || null === $v || '' === $v ) {
				continue;
			}
			$product->update_meta_data( 'sql_' . sanitize_key( $col ), $v );
		}
	}

	/** Main image, gallery list and gallery folder. */
	private function apply_images( $product, array $data, $allow_gallery ) {
		if ( empty( $this->opts['images'] ) ) {
			return;
		}
		$main_id = 0;
		$gallery = array();

		if ( $this->has( $data, 'image' ) && '' !== $this->str( $data, 'image' ) ) {
			try {
				$main_id = $this->image_id( $this->val( $data, 'image' ) );
			} catch ( Throwable $e ) {
				$this->warn( 'main image: ' . $e->getMessage() );
			}
		}
		if ( $allow_gallery && $this->has( $data, 'gallery' ) && '' !== $this->str( $data, 'gallery' ) ) {
			foreach ( $this->split_list( $this->val( $data, 'gallery' ) ) as $src ) {
				try {
					$gallery[] = $this->image_id( $src );
				} catch ( Throwable $e ) {
					$this->warn( 'gallery image: ' . $e->getMessage() );
				}
			}
		}
		if ( $allow_gallery && $this->has( $data, 'gallery_folder' ) && '' !== $this->str( $data, 'gallery_folder' ) ) {
			try {
				foreach ( $this->folder_images( $this->val( $data, 'gallery_folder' ) ) as $file ) {
					try {
						$gallery[] = $this->image_id( $file );
					} catch ( Throwable $e ) {
						$this->warn( 'folder image: ' . $e->getMessage() );
					}
				}
			} catch ( Throwable $e ) {
				$this->warn( 'image folder: ' . $e->getMessage() );
			}
		}

		// No explicit main image: optionally promote the first gallery image.
		if ( ! $main_id && ! empty( $this->opts['first_as_main'] ) && $gallery && ! $product->get_image_id() ) {
			$main_id = array_shift( $gallery );
		}
		if ( $main_id ) {
			$product->set_image_id( $main_id );
		}
		if ( $allow_gallery ) {
			$gallery = array_values( array_diff( array_unique( $gallery ), array( $main_id ) ) );
			if ( $gallery ) {
				$product->set_gallery_image_ids( $gallery );
			}
		}
	}

	/** Look up product IDs from a list of SKUs. */
	private function sku_ids( $value, $what ) {
		$ids = array();
		if ( null === $value ) {
			return $ids;
		}
		foreach ( $this->split_list( $value ) as $sku ) {
			$id = (int) wc_get_product_id_by_sku( $sku );
			if ( $id ) {
				$ids[] = $id;
			} else {
				$this->warn( $what . ' SKU "' . $sku . '" not found (must be imported earlier)' );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function price( $v ) {
		$n = self::normalize_number( $v );
		return '' === $n ? '' : wc_format_decimal( $n );
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

	private function backorders( $v ) {
		$v = strtolower( trim( (string) $v ) );
		if ( 'notify' === $v ) {
			return 'notify';
		}
		return in_array( $v, array( 'yes', '1', 'true', 'allow' ), true ) ? 'yes' : 'no';
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

	/**
	 * Resolve a file/folder path from the SQL to a real path that is guaranteed
	 * to live inside the configured image folder (relative, or absolute inside it).
	 */
	private function resolve_local( $path ) {
		$base = isset( $this->opts['image_base'] ) ? (string) $this->opts['image_base'] : '';
		if ( '' === $base || ! is_dir( $base ) ) {
			throw new Exception( $path . ' – not a URL and no valid local image folder configured' );
		}
		$real_base = rtrim( realpath( $base ), '/\\' );
		$path      = trim( (string) $path );
		$absolute  = (bool) preg_match( '#^([a-zA-Z]:[\\\\/]|/|\\\\\\\\)#', $path );
		$real      = realpath( $absolute ? $path : $real_base . DIRECTORY_SEPARATOR . ltrim( $path, '/\\' ) );
		if ( ! $real || 0 !== strpos( $real . DIRECTORY_SEPARATOR, $real_base . DIRECTORY_SEPARATOR ) ) {
			throw new Exception( $path . ' – not found inside the image folder' );
		}
		return $real;
	}

	/** Image files directly inside a folder, in natural filename order. */
	private function folder_images( $folder ) {
		$dir = $this->resolve_local( $folder );
		if ( ! is_dir( $dir ) ) {
			throw new Exception( $folder . ' – is not a folder' );
		}
		$files = array();
		foreach ( (array) scandir( $dir ) as $f ) {
			$full = $dir . DIRECTORY_SEPARATOR . $f;
			if ( is_file( $full ) && preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $f ) ) {
				$files[] = $full;
			}
		}
		natcasesort( $files );
		return array_slice( array_values( $files ), 0, (int) apply_filters( 'wcsi_max_folder_images', 30 ) );
	}

	/** Sideload an image (URL, or file inside the configured image folder). Returns attachment ID. */
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
			$real = $this->resolve_local( $src );
			if ( ! is_file( $real ) ) {
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
