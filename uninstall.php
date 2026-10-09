<?php
/**
 * Removes Email Reports for Bricks Builder Form Submissions data when deleted.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'bwsr_weekly_report' );
delete_option( 'bwsr_settings' );
delete_option( 'bwsr_status' );
delete_option( 'bwsr_checkpoints' );
delete_option( 'bwsr_report_lock' );

