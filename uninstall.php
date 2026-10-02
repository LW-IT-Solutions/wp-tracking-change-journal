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
