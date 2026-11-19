<?php
/**
 * Admin UI: Products → SQL Import.
 *
 * @package Telco_SQL_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCSI_Admin {

	const SLUG = 'wcsi-import';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wcsi_analyze', array( __CLASS__, 'handle_analyze' ) );
		add_action( 'admin_post_wcsi_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'wp_ajax_wcsi_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Telco SQL Sync', 'telco-sql-sync' ),
			__( 'Telco SQL Sync', 'telco-sql-sync' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wcsi-admin', WCSI_URL . 'assets/admin.css', array(), WCSI_VERSION );
		if ( isset( $_GET['step'], $_GET['job'] ) && 'run' === $_GET['step'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_script( 'wcsi-admin', WCSI_URL . 'assets/admin.js', array(), WCSI_VERSION, true );
			wp_localize_script(
				'wcsi-admin',
				'WCSI',
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'wcsi_batch' ),
					'job'   => sanitize_key( wp_unslash( $_GET['job'] ) ), // phpcs:ignore WordPress.Security.NonceVerification
				)
			);
		}
	}

	/* ---------------------------------------------------------------- Job storage */

	private static function get_job( $id ) {
		$job = get_option( 'wcsi_job_' . sanitize_key( $id ) );
		return is_array( $job ) ? $job : null;
	}

	private static function save_job( array $job ) {
		update_option( 'wcsi_job_' . $job['id'], $job, false );
	}

	public static function upload_dir() {
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . 'wcsi-imports';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Order allow,deny\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** Recursively delete a directory (only ever called on folders this plugin created). */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . DIRECTORY_SEPARATOR . $f;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::rrmdir( $p );
			} else {
				@unlink( $p ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Extract an uploaded ZIP of images into a private folder and delete anything
	 * that is not an image (so no scripts can be smuggled in). Returns the folder.
	 */
	private static function extract_images_zip( array $f, $job_id ) {
		if ( UPLOAD_ERR_OK !== (int) $f['error'] ) {
			throw new Exception( __( 'Image ZIP upload failed – it may exceed PHP upload_max_filesize. Use the server image folder option instead.', 'telco-sql-sync' ) );
		}
		if ( 'zip' !== strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			throw new Exception( __( 'The images file must be a .zip.', 'telco-sql-sync' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$dest = self::upload_dir() . '/img-' . $job_id;
		wp_mkdir_p( $dest );
		$res = unzip_file( $f['tmp_name'], $dest );
		if ( is_wp_error( $res ) ) {
			self::rrmdir( $dest );
			throw new Exception( $res->get_error_message() );
		}
		self::prune_non_images( $dest );

		// If the ZIP contains a single top-level folder, use that as the base.
		$entries = array_values( array_diff( (array) scandir( $dest ), array( '.', '..' ) ) );
		if ( 1 === count( $entries ) && is_dir( $dest . '/' . $entries[0] ) ) {
			$dest .= '/' . $entries[0];
		}
		return $dest;
	}

	private static function prune_non_images( $dir ) {
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . DIRECTORY_SEPARATOR . $f;
			if ( is_link( $p ) ) {
				@unlink( $p ); // phpcs:ignore
			} elseif ( is_dir( $p ) ) {
				self::prune_non_images( $p );
			} elseif ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $f ) ) {
				@unlink( $p ); // phpcs:ignore
			}
		}
	}

	private static function fail( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wcsi_error' => rawurlencode( $msg ) ), admin_url( 'edit.php?post_type=product' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- Step 1 → 2 */

	public static function handle_analyze() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'telco-sql-sync' ) );
		}
		check_admin_referer( 'wcsi_analyze' );

		$file     = '';
		$uploaded = false;

		if ( ! empty( $_FILES['sql_file']['name'] ) ) {
			$f = $_FILES['sql_file']; // phpcs:ignore WordPress.Security
			if ( UPLOAD_ERR_OK !== (int) $f['error'] ) {
				self::fail( __( 'Upload failed (error code ' . (int) $f['error'] . '). The file may exceed PHP upload_max_filesize – use the server path option for big files.', 'telco-sql-sync' ) );
			}
			if ( 'sql' !== strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
				self::fail( __( 'Please upload a .sql file.', 'telco-sql-sync' ) );
			}
			$file = self::upload_dir() . '/' . wp_generate_password( 24, false ) . '.sql';
			if ( ! move_uploaded_file( $f['tmp_name'], $file ) ) {
				self::fail( __( 'Could not store the uploaded file.', 'telco-sql-sync' ) );
			}
			$uploaded = true;
		} elseif ( ! empty( $_POST['sql_path'] ) ) {
			$path = realpath( wp_normalize_path( trim( wp_unslash( $_POST['sql_path'] ) ) ) ); // phpcs:ignore WordPress.Security
			if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) || 'sql' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				self::fail( __( 'That path is not a readable .sql file on the server.', 'telco-sql-sync' ) );
			}
			$file = $path;
		} else {
			self::fail( __( 'Choose a file or enter a server path.', 'telco-sql-sync' ) );
		}

		@set_time_limit( 0 ); // phpcs:ignore
		$tables = array();
		$create = array();
		try {
			$parser = new WCSI_SQL_Parser( $file );
			foreach ( $parser->statements() as $s ) {
				$stmt = $s[0];
				if ( 0 === stripos( $stmt, 'CREATE' ) ) {
					$ct = WCSI_SQL_Parser::parse_create_table( $stmt );
					if ( $ct ) {
						$create[ $ct['table'] ] = $ct['columns'];
					}
					continue;
				}
				$table = WCSI_SQL_Parser::sniff_insert_table( $stmt );
				if ( null === $table ) {
					continue;
				}
				if ( ! isset( $tables[ $table ] ) ) {
					$ins     = WCSI_SQL_Parser::parse_insert( $stmt );
					$samples = array();
					if ( $ins ) {
						foreach ( $ins['rows'] as $r ) {
							$samples[] = array_map(
								function ( $v ) {
									return null === $v ? null : mb_substr( (string) $v, 0, 60 );
								},
								$r
							);
							if ( count( $samples ) >= 3 ) {
								break;
							}
						}
					}
					$tables[ $table ] = array(
						'columns' => ( $ins && $ins['columns'] ) ? $ins['columns'] : ( isset( $create[ $table ] ) ? $create[ $table ] : null ),
						'samples' => $samples,
						'inserts' => 0,
					);
				}
				$tables[ $table ]['inserts']++;
			}
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}

		if ( ! $tables ) {
			if ( $uploaded ) {
				wp_delete_file( $file );
			}
			self::fail( __( 'No INSERT statements found in that file.', 'telco-sql-sync' ) );
		}

		$job = array(
			'id'        => strtolower( wp_generate_password( 12, false ) ),
			'file'      => $file,
			'uploaded'  => $uploaded,
			'size'      => filesize( $file ),
			'tables'    => $tables,
			'table'     => '',
			'columns'   => null,
			'mapping'   => array(),
			'options'   => array(),
			'offset'    => 0,
			'skip_rows' => 0,
			'rows_seen' => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'log'       => array(),
			'new_log'   => array(),
			'done'       => false,
			'image_base' => '',
			'images_dir' => '',
		);

		if ( ! empty( $_FILES['images_zip']['name'] ) ) {
			try {
				$job['images_dir'] = self::extract_images_zip( $_FILES['images_zip'], $job['id'] ); // phpcs:ignore WordPress.Security
				$job['image_base'] = $job['images_dir'];
			} catch ( Throwable $e ) {
				if ( $uploaded ) {
					wp_delete_file( $file );
				}
				self::fail( $e->getMessage() );
			}
		}
		self::save_job( $job );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'step' => 'map', 'job' => $job['id'] ), admin_url( 'edit.php?post_type=product' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- Step 2 → 3 */

	public static function handle_start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'telco-sql-sync' ) );
		}
		check_admin_referer( 'wcsi_start' );

		$job = self::get_job( isset( $_POST['job'] ) ? wp_unslash( $_POST['job'] ) : '' ); // phpcs:ignore WordPress.Security
		if ( ! $job ) {
			self::fail( __( 'Import job not found.', 'telco-sql-sync' ) );
		}
		$table = isset( $_POST['table'] ) ? wp_unslash( $_POST['table'] ) : ''; // phpcs:ignore WordPress.Security
		if ( ! isset( $job['tables'][ $table ] ) ) {
			self::fail( __( 'Unknown table.', 'telco-sql-sync' ) );
		}
		$columns = (array) $job['tables'][ $table ]['columns'];

		$mapping = array();
		$posted  = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security
		foreach ( WCSI_Importer::fields() as $key => $def ) {
			if ( ! empty( $posted[ $key ] ) && in_array( $posted[ $key ], $columns, true ) ) {
				$mapping[ $key ] = $posted[ $key ];
			}
		}
		if ( empty( $mapping['name'] ) && empty( $mapping['sku'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'step' => 'map', 'job' => $job['id'], 'table' => $table, 'wcsi_error' => rawurlencode( __( 'Map at least the product name.', 'telco-sql-sync' ) ) ), admin_url( 'edit.php?post_type=product' ) ) );
			exit;
		}

		$status_in = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish'; // phpcs:ignore WordPress.Security
		$base      = isset( $_POST['image_base'] ) ? trim( wp_unslash( $_POST['image_base'] ) ) : ''; // phpcs:ignore WordPress.Security
		$job['options'] = array(
			'existing'   => ( isset( $_POST['existing'] ) && 'skip' === $_POST['existing'] ) ? 'skip' : 'update', // phpcs:ignore WordPress.Security
			'match'      => ( isset( $_POST['match'] ) && 'source_id' === $_POST['match'] ) ? 'source_id' : 'sku', // phpcs:ignore WordPress.Security
			'status'     => in_array( $status_in, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status_in : 'publish',
			'images'     => ! empty( $_POST['images'] ),
			'image_base' => $base,
			'first_as_main' => ! empty( $_POST['first_as_main'] ),
			'list_sep'   => isset( $_POST['list_sep'] ) ? (string) wp_unslash( $_POST['list_sep'] ) : ',', // phpcs:ignore WordPress.Security
		);
		$job['table']   = $table;
		$job['columns'] = $columns;
		$job['mapping'] = $mapping;
		self::save_job( $job );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'step' => 'run', 'job' => $job['id'] ), admin_url( 'edit.php?post_type=product' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- AJAX */

	public static function ajax_batch() {
		check_ajax_referer( 'wcsi_batch', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		$job = self::get_job( isset( $_POST['job'] ) ? wp_unslash( $_POST['job'] ) : '' ); // phpcs:ignore WordPress.Security
		if ( ! $job || '' === $job['table'] ) {
			wp_send_json_error( 'Import job not found.' );
		}

		if ( ! $job['done'] ) {
			@set_time_limit( 0 ); // phpcs:ignore
			$max    = (int) ini_get( 'max_execution_time' );
			$budget = $max > 0 ? max( 3, min( 10, (int) ( $max * 0.5 ) ) ) : 10;
			try {
				WCSI_Importer::run_batch( $job, apply_filters( 'wcsi_batch_seconds', $budget ) );
			} catch ( Throwable $e ) {
				$job['new_log'][] = 'Fatal: ' . $e->getMessage();
				$job['done']      = true;
			}
		}

		if ( $job['done'] ) {
			if ( ! empty( $job['uploaded'] ) && is_file( $job['file'] ) ) {
				wp_delete_file( $job['file'] );
			}
			if ( ! empty( $job['images_dir'] ) ) {
				self::rrmdir( self::upload_dir() . '/img-' . $job['id'] );
			}
		}
		self::save_job( $job );

		wp_send_json_success(
			array(
				'done'     => (bool) $job['done'],
				'progress' => $job['size'] ? min( 100, round( 100 * $job['offset'] / $job['size'], 1 ) ) : 100,
				'created'  => $job['created'],
				'updated'  => $job['updated'],
				'skipped'  => $job['skipped'],
				'failed'   => $job['failed'],
				'log'      => $job['new_log'],
			)
		);
	}

	/* ---------------------------------------------------------------- Rendering */

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'upload'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap wcsi"><h1>' . esc_html__( 'Telco SQL Sync – import products from a SQL file', 'telco-sql-sync' ) . '</h1>';

		if ( ! empty( $_GET['wcsi_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( wp_unslash( $_GET['wcsi_error'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		$job = null;
		if ( 'upload' !== $step ) {
			$job = self::get_job( isset( $_GET['job'] ) ? wp_unslash( $_GET['job'] ) : '' ); // phpcs:ignore WordPress.Security
			if ( ! $job ) {
				echo '<p>' . esc_html__( 'Import job not found.', 'telco-sql-sync' ) . '</p></div>';
				return;
			}
		}

		if ( 'map' === $step ) {
			self::render_map( $job );
		} elseif ( 'run' === $step ) {
			self::render_run( $job );
		} else {
			self::render_upload();
		}
		echo '</div>';
	}

	private static function render_upload() {
		?>
		<p><?php esc_html_e( 'The SQL file is parsed, not executed: only INSERT statements are read and turned into WooCommerce products. Your database is never touched by the SQL itself.', 'telco-sql-sync' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wcsi_analyze">
			<?php wp_nonce_field( 'wcsi_analyze' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="sql_file"><?php esc_html_e( 'Upload .sql file', 'telco-sql-sync' ); ?></label></th>
					<td><input type="file" id="sql_file" name="sql_file" accept=".sql">
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: size */ __( 'Max upload size on this server: %s', 'telco-sql-sync' ), size_format( wp_max_upload_size() ) ) ); ?></p></td>
				</tr>
				<tr>
					<th><label for="sql_path"><?php esc_html_e( '…or path on this server', 'telco-sql-sync' ); ?></label></th>
					<td><input type="text" class="large-text" id="sql_path" name="sql_path" placeholder="C:\data\products.sql  or  /home/user/products.sql">
					<p class="description"><?php esc_html_e( 'Use this for large dumps or a file already on the web server. Must end in .sql.', 'telco-sql-sync' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="images_zip"><?php esc_html_e( 'Images ZIP (optional)', 'telco-sql-sync' ); ?></label></th>
					<td><input type="file" id="images_zip" name="images_zip" accept=".zip">
					<p class="description"><?php esc_html_e( 'A ZIP of your product images. Image paths and folders in the SQL file are then resolved inside it. Only image files are kept. Skip this if you point to an image folder on the server in the next step.', 'telco-sql-sync' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button( __( 'Analyze file', 'telco-sql-sync' ) ); ?>
		</form>
		<?php
	}

	private static function render_map( array $job ) {
		$table = isset( $_GET['table'] ) ? wp_unslash( $_GET['table'] ) : ''; // phpcs:ignore WordPress.Security
		if ( '' === $table && 1 === count( $job['tables'] ) ) {
			$table = (string) key( $job['tables'] );
		}
		$page_url = admin_url( 'edit.php?post_type=product&page=' . self::SLUG );

		if ( '' === $table || ! isset( $job['tables'][ $table ] ) ) {
			echo '<h2>' . esc_html__( 'Which table contains the products?', 'telco-sql-sync' ) . '</h2><ul>';
			foreach ( $job['tables'] as $name => $t ) {
				$url = add_query_arg( array( 'step' => 'map', 'job' => $job['id'], 'table' => $name ), $page_url );
				printf( '<li><a href="%s"><strong>%s</strong></a> – %s</li>', esc_url( $url ), esc_html( $name ), esc_html( sprintf( /* translators: %d: count */ _n( '%d INSERT statement', '%d INSERT statements', $t['inserts'], 'telco-sql-sync' ), $t['inserts'] ) ) );
			}
			echo '</ul>';
			return;
		}

		$t       = $job['tables'][ $table ];
		$columns = (array) $t['columns'];
		if ( ! $columns ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This table has no column list: the INSERT statements have no column names and no CREATE TABLE was found. Add a CREATE TABLE statement to the file or export with column names (mysqldump --complete-insert).', 'telco-sql-sync' ) . '</p></div>';
			return;
		}
		$guess = WCSI_Importer::guess_mapping( $columns );
		?>
		<h2><?php echo esc_html( sprintf( /* translators: %s: table */ __( 'Table: %s', 'telco-sql-sync' ), $table ) ); ?></h2>
		<h3><?php esc_html_e( 'Sample rows', 'telco-sql-sync' ); ?></h3>
		<div class="wcsi-scroll"><table class="widefat striped"><thead><tr>
			<?php foreach ( $columns as $c ) { echo '<th>' . esc_html( $c ) . '</th>'; } ?>
		</tr></thead><tbody>
			<?php
			foreach ( $t['samples'] as $row ) {
				echo '<tr>';
				foreach ( $row as $v ) {
					echo '<td>' . ( null === $v ? '<em>NULL</em>' : esc_html( $v ) ) . '</td>';
				}
				echo '</tr>';
			}
			?>
		</tbody></table></div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wcsi_start">
			<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
			<input type="hidden" name="table" value="<?php echo esc_attr( $table ); ?>">
			<?php wp_nonce_field( 'wcsi_start' ); ?>

			<h3><?php esc_html_e( 'Column mapping', 'telco-sql-sync' ); ?></h3>
			<table class="form-table wcsi-map">
				<?php foreach ( WCSI_Importer::fields() as $key => $def ) : ?>
					<tr>
						<th><label for="map_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def[0] ); ?></label></th>
						<td><select name="map[<?php echo esc_attr( $key ); ?>]" id="map_<?php echo esc_attr( $key ); ?>">
							<option value=""><?php esc_html_e( '— do not import —', 'telco-sql-sync' ); ?></option>
							<?php foreach ( $columns as $c ) : ?>
								<option value="<?php echo esc_attr( $c ); ?>" <?php selected( isset( $guess[ $key ] ) ? $guess[ $key ] : '', $c ); ?>><?php echo esc_html( $c ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h3><?php esc_html_e( 'Options', 'telco-sql-sync' ); ?></h3>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Match existing products by', 'telco-sql-sync' ); ?></th><td>
					<label><input type="radio" name="match" value="sku" checked> <?php esc_html_e( 'SKU', 'telco-sql-sync' ); ?></label>
					<label><input type="radio" name="match" value="source_id"> <?php esc_html_e( 'Source ID', 'telco-sql-sync' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'If a product already exists', 'telco-sql-sync' ); ?></th><td>
					<label><input type="radio" name="existing" value="update" checked> <?php esc_html_e( 'Update it (only mapped fields)', 'telco-sql-sync' ); ?></label>
					<label><input type="radio" name="existing" value="skip"> <?php esc_html_e( 'Skip it', 'telco-sql-sync' ); ?></label></td></tr>
				<tr><th><label for="status"><?php esc_html_e( 'Status for new products', 'telco-sql-sync' ); ?></label></th><td>
					<select name="status" id="status"><option value="publish">publish</option><option value="draft">draft</option><option value="pending">pending</option><option value="private">private</option></select></td></tr>
				<tr><th><label for="list_sep"><?php esc_html_e( 'List separator', 'telco-sql-sync' ); ?></label></th><td>
					<input type="text" name="list_sep" id="list_sep" value="," size="3"> <span class="description"><?php esc_html_e( 'For categories, tags and gallery images.', 'telco-sql-sync' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Images', 'telco-sql-sync' ); ?></th><td>
					<label><input type="checkbox" name="images" value="1" checked> <?php esc_html_e( 'Download / import images into the media library', 'telco-sql-sync' ); ?></label><br>
					<input type="text" class="large-text" name="image_base" value="<?php echo esc_attr( $job['image_base'] ); ?>" placeholder="<?php esc_attr_e( 'Image folder on the server (paths in the SQL are relative to it, or absolute inside it)', 'telco-sql-sync' ); ?>">
					<p class="description"><?php echo esc_html( $job['image_base'] ? __( 'Pre-filled from your uploaded ZIP.', 'telco-sql-sync' ) : __( 'Not needed for image URLs. Files outside this folder are never read.', 'telco-sql-sync' ) ); ?></p>
					<label><input type="checkbox" name="first_as_main" value="1" checked> <?php esc_html_e( 'If a product has no main image, use the first image from its gallery folder', 'telco-sql-sync' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Start import', 'telco-sql-sync' ) ); ?>
		</form>
		<?php
	}

	private static function render_run( array $job ) {
		?>
		<p><?php echo esc_html( sprintf( /* translators: %s: table */ __( 'Importing from table "%s". Keep this page open until it finishes.', 'telco-sql-sync' ), $job['table'] ) ); ?></p>
		<div class="wcsi-bar"><div class="wcsi-bar-fill" id="wcsi-fill"></div></div>
		<p id="wcsi-status"><?php esc_html_e( 'Starting…', 'telco-sql-sync' ); ?></p>
		<p><button type="button" class="button button-primary" id="wcsi-go"><?php esc_html_e( 'Run import', 'telco-sql-sync' ); ?></button></p>
		<pre id="wcsi-log" class="wcsi-log"></pre>
		<?php
	}
}
