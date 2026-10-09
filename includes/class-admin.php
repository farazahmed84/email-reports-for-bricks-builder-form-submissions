<?php
/**
 * WordPress admin pages and actions.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWSR_Admin {
	/** @var BWSR_Submissions */
	private $submissions;

	/** @var BWSR_Scheduler */
	private $scheduler;

	/** @var callable */
	private $manual_callback;

	public function __construct( $submissions, $scheduler, $manual_callback ) {
		$this->submissions     = $submissions;
		$this->scheduler       = $scheduler;
		$this->manual_callback = $manual_callback;
	}

	public static function default_settings() {
		return array(
			'recipients' => get_option( 'admin_email', '' ),
			'weekday'    => 'monday',
			'time'       => '08:00',
			'subject'    => __( 'Weekly Bricks Form Submissions Report', 'email-reports-for-bricks-builder-form-submissions' ),
			'message'    => __( 'Attached are the weekly CSV exports containing new submissions from all Bricks forms.', 'email-reports-for-bricks-builder-form-submissions' ),
			'send_empty' => 0,
		);
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_bwsr_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_bwsr_manual_report', array( $this, 'send_manual_report' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BWSR_FILE ), array( $this, 'add_settings_link' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Email Reports for Bricks Builder Form Submissions', 'email-reports-for-bricks-builder-form-submissions' ),
			__( 'Bricks Email Reports', 'email-reports-for-bricks-builder-form-submissions' ),
			'manage_options',
			'bwsr-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-media-spreadsheet',
			58
		);
		add_submenu_page(
			'bwsr-settings',
			__( 'Settings', 'email-reports-for-bricks-builder-form-submissions' ),
			__( 'Settings', 'email-reports-for-bricks-builder-form-submissions' ),
			'manage_options',
			'bwsr-settings',
			array( $this, 'render_settings_page' )
		);
		add_submenu_page(
			'bwsr-settings',
			__( 'Manual Report', 'email-reports-for-bricks-builder-form-submissions' ),
			__( 'Manual Report', 'email-reports-for-bricks-builder-form-submissions' ),
			'manage_options',
			'bwsr-manual-report',
			array( $this, 'render_manual_page' )
		);
	}

	public function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $page, array( 'bwsr-settings', 'bwsr-manual-report' ), true ) ) {
			wp_enqueue_style( 'bwsr-admin', BWSR_URL . 'assets/admin.css', array(), BWSR_VERSION );
		}
	}

	public function add_settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=bwsr-settings' ) ) . '">' . esc_html__( 'Settings', 'email-reports-for-bricks-builder-form-submissions' ) . '</a>' );
		return $links;
	}

	public function render_settings_page() {
		$this->require_capability();
		$settings    = wp_parse_args( get_option( BWSR_SETTINGS_OPTION, array() ), self::default_settings() );
		$forms       = $this->submissions->get_forms();
		$status      = get_option( BWSR_STATUS_OPTION, array() );
		$checkpoints = get_option( BWSR_CHECKPOINTS_OPTION, array() );
		$next_run    = $this->scheduler->get_next_scheduled();
		/* translators: %s: WordPress site timezone. */
		$timezone_description = sprintf( __( 'Uses the WordPress timezone: %s.', 'email-reports-for-bricks-builder-form-submissions' ), wp_timezone_string() );
		?>
		<div class="wrap bwsr-wrap">
			<h1><?php esc_html_e( 'Bricks Builder Form Email Reports', 'email-reports-for-bricks-builder-form-submissions' ); ?></h1>
			<?php $this->render_notice(); ?>
			<?php if ( is_wp_error( $forms ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $forms->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<div class="bwsr-grid">
				<div class="bwsr-card">
					<h2><?php esc_html_e( 'Weekly Report Settings', 'email-reports-for-bricks-builder-form-submissions' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bwsr_save_settings">
						<?php wp_nonce_field( 'bwsr_save_settings', 'bwsr_nonce' ); ?>
						<p><?php esc_html_e( 'The scheduled report includes new submissions from every discovered Bricks form.', 'email-reports-for-bricks-builder-form-submissions' ); ?></p>
						<table class="form-table" role="presentation">
							<tr><th><label for="bwsr_recipients"><?php esc_html_e( 'Recipients', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><input class="regular-text" id="bwsr_recipients" name="recipients" type="text" value="<?php echo esc_attr( $settings['recipients'] ); ?>" required><p class="description"><?php esc_html_e( 'Separate multiple email addresses with commas.', 'email-reports-for-bricks-builder-form-submissions' ); ?></p></td></tr>
							<tr><th><label for="bwsr_weekday"><?php esc_html_e( 'Weekday', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><select id="bwsr_weekday" name="weekday"><?php foreach ( $this->weekdays() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['weekday'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
							<tr><th><label for="bwsr_time"><?php esc_html_e( 'Time', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><input id="bwsr_time" name="time" type="time" value="<?php echo esc_attr( $settings['time'] ); ?>" required><p class="description"><?php echo esc_html( $timezone_description ); ?></p></td></tr>
							<tr><th><label for="bwsr_subject"><?php esc_html_e( 'Email Subject', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><input class="regular-text" id="bwsr_subject" name="subject" type="text" value="<?php echo esc_attr( $settings['subject'] ); ?>" required></td></tr>
							<tr><th><label for="bwsr_message"><?php esc_html_e( 'Email Message', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><textarea class="large-text" rows="5" id="bwsr_message" name="message" required><?php echo esc_textarea( $settings['message'] ); ?></textarea><p class="description"><?php esc_html_e( 'Available placeholders: {form_name}, {start_date}, {end_date}', 'email-reports-for-bricks-builder-form-submissions' ); ?></p></td></tr>
							<tr><th><?php esc_html_e( 'Empty Reports', 'email-reports-for-bricks-builder-form-submissions' ); ?></th><td><label><input name="send_empty" type="checkbox" value="1" <?php checked( ! empty( $settings['send_empty'] ) ); ?>> <?php esc_html_e( 'Send email when there are no new submissions', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></td></tr>
						</table>
						<?php submit_button( __( 'Save Settings', 'email-reports-for-bricks-builder-form-submissions' ) ); ?>
					</form>
				</div>
				<div class="bwsr-card">
					<h2><?php esc_html_e( 'Report Status', 'email-reports-for-bricks-builder-form-submissions' ); ?></h2>
					<dl class="bwsr-status">
						<dt><?php esc_html_e( 'Next scheduled report', 'email-reports-for-bricks-builder-form-submissions' ); ?></dt><dd><?php echo esc_html( $next_run ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) : __( 'Not scheduled', 'email-reports-for-bricks-builder-form-submissions' ) ); ?></dd>
						<dt><?php esc_html_e( 'Last successful scheduled report', 'email-reports-for-bricks-builder-form-submissions' ); ?></dt><dd><?php echo esc_html( ! empty( $status['last_success'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['last_success'] ) : __( 'Never', 'email-reports-for-bricks-builder-form-submissions' ) ); ?></dd>
						<dt><?php esc_html_e( 'Form checkpoints', 'email-reports-for-bricks-builder-form-submissions' ); ?></dt><dd><?php $this->render_checkpoint_status( $forms, $checkpoints ); ?></dd>
						<dt><?php esc_html_e( 'Most recent error', 'email-reports-for-bricks-builder-form-submissions' ); ?></dt><dd><?php echo esc_html( ! empty( $status['last_error'] ) ? $status['last_error'] : __( 'None', 'email-reports-for-bricks-builder-form-submissions' ) ); ?></dd>
					</dl>
					<p class="description"><?php esc_html_e( 'WP-Cron runs when the site receives traffic, so reports may run after the configured time. The submission checkpoint prevents late runs from skipping entries.', 'email-reports-for-bricks-builder-form-submissions' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_manual_page() {
		$this->require_capability();
		$forms    = $this->submissions->get_forms();
		$settings = wp_parse_args( get_option( BWSR_SETTINGS_OPTION, array() ), self::default_settings() );
		$today    = wp_date( 'Y-m-d' );
		$week_ago = wp_date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );
		?>
		<div class="wrap bwsr-wrap">
			<h1><?php esc_html_e( 'Manual Bricks Builder Form Email Report', 'email-reports-for-bricks-builder-form-submissions' ); ?></h1>
			<?php $this->render_notice(); ?>
			<?php if ( is_wp_error( $forms ) ) : ?><div class="notice notice-warning"><p><?php echo esc_html( $forms->get_error_message() ); ?></p></div><?php endif; ?>
			<div class="bwsr-card bwsr-manual-card">
				<p><?php esc_html_e( 'Choose an inclusive date range in the WordPress site timezone. Manual reports do not change the scheduled export checkpoint.', 'email-reports-for-bricks-builder-form-submissions' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bwsr_manual_report">
					<?php wp_nonce_field( 'bwsr_manual_report', 'bwsr_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="bwsr_start_date"><?php esc_html_e( 'Start Date', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><input id="bwsr_start_date" name="start_date" type="date" value="<?php echo esc_attr( $week_ago ); ?>" max="<?php echo esc_attr( $today ); ?>" required></td></tr>
						<tr><th><label for="bwsr_end_date"><?php esc_html_e( 'End Date', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><input id="bwsr_end_date" name="end_date" type="date" value="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $today ); ?>" required></td></tr>
						<tr><th><label for="bwsr_manual_recipients"><?php esc_html_e( 'Email Address', 'email-reports-for-bricks-builder-form-submissions' ); ?></label></th><td><input class="regular-text" id="bwsr_manual_recipients" name="recipients" type="text" value="<?php echo esc_attr( $settings['recipients'] ); ?>" required><p class="description"><?php esc_html_e( 'Separate multiple email addresses with commas.', 'email-reports-for-bricks-builder-form-submissions' ); ?></p></td></tr>
					</table>
					<?php submit_button( __( 'Generate and Send Report', 'email-reports-for-bricks-builder-form-submissions' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public function save_settings() {
		$this->require_capability();
		check_admin_referer( 'bwsr_save_settings', 'bwsr_nonce' );
		$recipients = isset( $_POST['recipients'] ) ? BWSR_Mailer::sanitize_recipients( sanitize_textarea_field( wp_unslash( $_POST['recipients'] ) ) ) : array();
		$weekday = isset( $_POST['weekday'] ) ? sanitize_key( wp_unslash( $_POST['weekday'] ) ) : 'monday';
		$time = isset( $_POST['time'] ) ? sanitize_text_field( wp_unslash( $_POST['time'] ) ) : '08:00';

		if ( empty( $recipients ) || ! array_key_exists( $weekday, $this->weekdays() ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$this->redirect( 'bwsr-settings', 'invalid' );
		}

		$settings = array(
			'recipients' => implode( ', ', $recipients ),
			'weekday'    => $weekday,
			'time'       => $time,
			'subject'    => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'message'    => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
			'send_empty' => isset( $_POST['send_empty'] ) ? 1 : 0,
		);
		if ( '' === $settings['subject'] || '' === $settings['message'] ) {
			$this->redirect( 'bwsr-settings', 'invalid' );
		}

		update_option( BWSR_SETTINGS_OPTION, $settings, false );
		$this->scheduler->reschedule();
		$this->redirect( 'bwsr-settings', 'saved' );
	}

	public function send_manual_report() {
		$this->require_capability();
		check_admin_referer( 'bwsr_manual_report', 'bwsr_nonce' );
		$recipients = isset( $_POST['recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['recipients'] ) ) : '';
		$start      = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end        = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$start_date = $this->parse_date( $start );
		$end_date   = $this->parse_date( $end );

		if ( false === $start_date || false === $end_date || $start_date > $end_date ) {
			$this->redirect( 'bwsr-manual-report', 'invalid_date' );
		}

		$start_utc = $start_date->setTime( 0, 0, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end_utc   = $end_date->setTime( 23, 59, 59 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$result    = call_user_func(
			$this->manual_callback,
			array(
				'recipients'    => $recipients,
				'start_utc'     => $start_utc,
				'end_utc'       => $end_utc,
				'start_display' => $start,
				'end_display'   => $end,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'bwsr-manual-report', 'error', $result->get_error_message() );
		}
		/* translators: %d: Number of submissions included in the report. */
		$success_message = sprintf( __( 'Report sent with %d submission(s).', 'email-reports-for-bricks-builder-form-submissions' ), $result['count'] );
		$this->redirect( 'bwsr-manual-report', 'sent', $success_message );
	}

	private function render_checkpoint_status( $forms, $checkpoints ) {
		if ( is_wp_error( $forms ) || empty( $forms ) ) {
			echo esc_html__( 'No forms discovered', 'email-reports-for-bricks-builder-form-submissions' );
			return;
		}

		echo '<ul class="bwsr-checkpoints">';
		foreach ( $forms as $form ) {
			$checkpoint = isset( $checkpoints[ $form['id'] ] ) ? absint( $checkpoints[ $form['id'] ] ) : 0;
			printf(
				'<li>%1$s (%2$s): %3$s</li>',
				esc_html( $form['name'] ),
				esc_html( $form['id'] ),
				esc_html( (string) $checkpoint )
			);
		}
		echo '</ul>';
	}

	private function render_notice() {
		$status = isset( $_GET['bwsr_notice'] ) ? sanitize_key( wp_unslash( $_GET['bwsr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['bwsr_message'] ) ? sanitize_text_field( wp_unslash( $_GET['bwsr_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices = array(
			'saved'        => array( 'success', __( 'Settings saved and the weekly report was rescheduled.', 'email-reports-for-bricks-builder-form-submissions' ) ),
			'sent'         => array( 'success', $message ? $message : __( 'The report was accepted by WordPress for sending.', 'email-reports-for-bricks-builder-form-submissions' ) ),
			'invalid'      => array( 'error', __( 'Please provide a valid schedule and recipient list.', 'email-reports-for-bricks-builder-form-submissions' ) ),
			'invalid_date' => array( 'error', __( 'Please provide a valid date range.', 'email-reports-for-bricks-builder-form-submissions' ) ),
			'error'        => array( 'error', $message ? $message : __( 'The report could not be sent.', 'email-reports-for-bricks-builder-form-submissions' ) ),
		);
		if ( isset( $notices[ $status ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $notices[ $status ][0] ) . ' is-dismissible"><p>' . esc_html( $notices[ $status ][1] ) . '</p></div>';
		}
	}

	private function weekdays() {
		return array(
			'monday'    => __( 'Monday', 'email-reports-for-bricks-builder-form-submissions' ),
			'tuesday'   => __( 'Tuesday', 'email-reports-for-bricks-builder-form-submissions' ),
			'wednesday' => __( 'Wednesday', 'email-reports-for-bricks-builder-form-submissions' ),
			'thursday'  => __( 'Thursday', 'email-reports-for-bricks-builder-form-submissions' ),
			'friday'    => __( 'Friday', 'email-reports-for-bricks-builder-form-submissions' ),
			'saturday'  => __( 'Saturday', 'email-reports-for-bricks-builder-form-submissions' ),
			'sunday'    => __( 'Sunday', 'email-reports-for-bricks-builder-form-submissions' ),
		);
	}

	private function parse_date( $value ) {
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, wp_timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
			return false;
		}
		return $date;
	}

	private function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these reports.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}
	}

	private function redirect( $page, $notice, $message = '' ) {
		$args = array( 'page' => $page, 'bwsr_notice' => $notice );
		if ( $message ) {
			$args['bwsr_message'] = $message;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
