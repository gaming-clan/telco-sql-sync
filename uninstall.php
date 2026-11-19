<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcsi\_job\_%'" );

$u   = wp_upload_dir();
$dir = trailingslashit( $u['basedir'] ) . 'wcsi-imports';
if ( is_dir( $dir ) ) {
	$rm = function ( $d ) use ( &$rm ) {
		foreach ( (array) scandir( $d ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $d . DIRECTORY_SEPARATOR . $f;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				$rm( $p );
			} else {
				@unlink( $p ); // phpcs:ignore
			}
		}
		@rmdir( $d ); // phpcs:ignore
	};
	$rm( $dir );
}
