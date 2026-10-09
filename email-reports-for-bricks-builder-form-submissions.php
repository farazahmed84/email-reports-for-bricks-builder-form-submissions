<?php
/**
 * Plugin Name: Email Reports for Bricks Builder Form Submissions
 * Description: Emails scheduled and manual CSV reports from Bricks Builder's saved form submissions.
 * Version: 1.0.0
 * Author: Faraz The Web Guy
 * Author URI: https://farazthewebguy.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: email-reports-for-bricks-builder-form-submissions
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BWSR_VERSION', '1.0.0' );
define( 'BWSR_FILE', __FILE__ );
define( 'BWSR_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWSR_URL', plugin_dir_url( __FILE__ ) );
define( 'BWSR_SETTINGS_OPTION', 'bwsr_settings' );
define( 'BWSR_STATUS_OPTION', 'bwsr_status' );
define( 'BWSR_CHECKPOINTS_OPTION', 'bwsr_checkpoints' );
define( 'BWSR_LOCK_OPTION', 'bwsr_report_lock' );
define( 'BWSR_CRON_HOOK', 'bwsr_weekly_report' );

require_once BWSR_PATH . 'includes/class-submissions.php';
require_once BWSR_PATH . 'includes/class-csv-generator.php';
require_once BWSR_PATH . 'includes/class-mailer.php';
require_once BWSR_PATH . 'includes/class-scheduler.php';
require_once BWSR_PATH . 'includes/class-admin.php';
require_once BWSR_PATH . 'includes/class-plugin.php';

/**
 * Returns the main plugin instance.
 *
 * @return BWSR_Plugin
 */
function bwsr_plugin() {
	return BWSR_Plugin::instance();
}

bwsr_plugin()->init();

register_activation_hook( __FILE__, array( 'BWSR_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BWSR_Plugin', 'deactivate' ) );

