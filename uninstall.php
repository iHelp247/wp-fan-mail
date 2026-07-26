<?php
/**
 * iHelp247 WP Fan Mail — uninstall cleanup.
 * © ULXI — UnLimited eXchange, Inc. · published under the iHelp247 brand · GPL-2.0-or-later
 * Deletes all plugin options and the scheduled sweep. The message and contact
 * tables are the SITE OWNER'S data: they are only dropped when the owner has
 * explicitly ticked "Also delete all messages and contacts when the plugin is
 * uninstalled" on the settings page. Off by default — data outlives the plugin.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ih247_delete_data = (bool) get_option( 'ihelp247_wp_fan_mail_delete_data', false );

foreach ( array( 'forms', 'carrier', 'sendgrid_key', 'from_name', 'from_email', 'store_ip', 'default_cc', 'max_upload', 'retention', 'hide_credit', 'delete_data', 'db_version' ) as $ih247_key ) {
	delete_option( 'ihelp247_wp_fan_mail_' . $ih247_key );
}
delete_transient( 'ih247_wpfm_gh_release' );
wp_clear_scheduled_hook( 'ih247_wpfm_daily_maintenance' );

if ( $ih247_delete_data ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ih247_fanmail_messages' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ih247_fanmail_contacts' ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

	// Visitor-uploaded files (File fields) live under uploads/fan-mail/.
	$ih247_up  = wp_get_upload_dir();
	$ih247_dir = $ih247_up['basedir'] . '/fan-mail';
	if ( is_dir( $ih247_dir ) ) {
		$ih247_it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $ih247_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $ih247_it as $ih247_item ) {
			if ( $ih247_item->isDir() ) {
				@rmdir( $ih247_item->getPathname() );
			} else {
				@unlink( $ih247_item->getPathname() );
			}
		}
		@rmdir( $ih247_dir );
	}
}
