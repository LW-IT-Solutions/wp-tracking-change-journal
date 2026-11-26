<?php
/**
 * Remove everything the plugin stored.
 *
 * @package TrackingChangeJournal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'trcj_settings' );
delete_option( 'trcj_state' );
delete_option( 'trcj_journal' );
delete_option( 'trcj_pending' );
wp_clear_scheduled_hook( 'trcj_daily' );
wp_clear_scheduled_hook( 'trcj_check' );

// Notices after a form on the tools screen, kept for one minute per administrator.
$trcj_admins = get_users(
	array(
		'capability' => 'manage_options',
		'fields'     => 'ID',
	)
);
foreach ( $trcj_admins as $trcj_user_id ) {
	delete_transient( 'trcj_notice_' . (int) $trcj_user_id );
}
