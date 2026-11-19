<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcsi\_job\_%'" );

$u   = wp_upload_dir();
$dir = trailingslashit( $u['basedir'] ) . 'wcsi-imports';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '/{.,}*', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
	@rmdir( $dir ); // phpcs:ignore
}
