<?php
/**
 * Plugin Name:       Telco SQL Sync
 * Description:       Import WooCommerce products from a local .sql dump file. The SQL is parsed, never executed.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       telco-sql-sync
 * License:           GPL-2.0-or-later
 *
 * @package Telco_SQL_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'WCSI_VERSION', '1.0.0' );
define( 'WCSI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCSI_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Telco SQL Sync requires WooCommerce to be active.', 'telco-sql-sync' ) . '</p></div>';
				}
			);
			return;
		}
		require_once WCSI_DIR . 'includes/class-wcsi-sql-parser.php';
		require_once WCSI_DIR . 'includes/class-wcsi-importer.php';
		require_once WCSI_DIR . 'includes/class-wcsi-admin.php';
		WCSI_Admin::init();
	}
);
