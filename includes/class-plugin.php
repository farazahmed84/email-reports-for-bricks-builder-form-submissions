<?php
/**
 * Main plugin coordinator.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWSR_Plugin {
	/** @var BWSR_Plugin|null */
	private static $instance = null;

	/** @var BWSR_Submissions */
	private $submissions;

	/** @var BWSR_CSV_Generator */
	private $csv;

	/** @var BWSR_Mailer */
	private $mailer;

	/** @var BWSR_Scheduler */
	private $scheduler;

	/** @var BWSR_Admin */
	private $admin;

	/**
	 * @return BWSR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->submissions = new BWSR_Submissions();
		$this->csv         = new BWSR_CSV_Generator();
		$this->mailer      = new BWSR_Mailer();
		$this->scheduler   = new BWSR_Scheduler( array( $this, 'run_scheduled_report' ) );
		$this->admin       = new BWSR_Admin( $this->submissions, $this->scheduler, array( $this, 'run_manual_report' ) );
	}

	public function init() {
		$this->migrate_single_form_settings();
		$this->scheduler->init();

		if ( is_admin() ) {
			$this->admin->init();
		}
	}

	/**
	 * Removes the retired scheduled form choice and updates untouched defaults.
	 */
	private function migrate_single_form_settings() {
		$settings = get_option( BWSR_SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return;
		}

		$legacy_subject = 'Weekly Bricks Form Submissions - {form_name}';
		$legacy_messages = array(
			'Attached is the weekly CSV export for {form_name}.',
			'Attached is the weekly CSV export containing new submissions from all Bricks forms.',
		);
		$changed        = false;

		if ( array_key_exists( 'form_id', $settings ) ) {
			unset( $settings['form_id'] );
			$changed = true;
		}

		if ( empty( $settings['subject'] ) || $legacy_subject === $settings['subject'] ) {
			$settings['subject'] = BWSR_Admin::default_settings()['subject'];
			$changed = true;
		}
		if ( empty( $settings['message'] ) || in_array( $settings['message'], $legacy_messages, true ) ) {
			$settings['message'] = BWSR_Admin::default_settings()['message'];
			$changed = true;
		}

		if ( $changed ) {
			update_option( BWSR_SETTINGS_OPTION, $settings, false );
		}
	}

	public static function activate() {
		$defaults = BWSR_Admin::default_settings();
		if ( false === get_option( BWSR_SETTINGS_OPTION, false ) ) {
			add_option( BWSR_SETTINGS_OPTION, $defaults, '', false );
		}

		$scheduler = new BWSR_Scheduler( '__return_false' );
		$scheduler->reschedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( BWSR_CRON_HOOK );
		delete_option( BWSR_LOCK_OPTION );
	}

	/**
	 * Processes the scheduled report.
	 *
	 * @return array|WP_Error
	 */
	public function run_scheduled_report() {
		$settings   = wp_parse_args( get_option( BWSR_SETTINGS_OPTION, array() ), BWSR_Admin::default_settings() );
		$recipients = BWSR_Mailer::sanitize_recipients( $settings['recipients'] );
		if ( empty( $recipients ) ) {
			return $this->record_error( __( 'No valid report recipient is configured.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$forms = $this->submissions->get_forms();
		if ( is_wp_error( $forms ) ) {
			return $this->record_error( $forms->get_error_message() );
		}
		if ( empty( $forms ) ) {
			return $this->record_error( __( 'No Bricks forms with saved submissions were found.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$checkpoints = get_option( BWSR_CHECKPOINTS_OPTION, array() );
		$next_checkpoints = $checkpoints;
		$rows = array();

		foreach ( $forms as $form ) {
			$form_id  = $form['id'];
			$after_id = isset( $checkpoints[ $form_id ] ) ? absint( $checkpoints[ $form_id ] ) : 0;
			$form_rows = $this->submissions->get_submissions_after( $form_id, $after_id );
			if ( is_wp_error( $form_rows ) ) {
				return $this->record_error( $form_rows->get_error_message() );
			}

			foreach ( $form_rows as &$row ) {
				$row['form_name'] = $form['name'];
			}
			unset( $row );

			if ( ! empty( $form_rows ) ) {
				$last = end( $form_rows );
				$next_checkpoints[ $form_id ] = absint( $last['id'] );
				$rows = array_merge( $rows, $form_rows );
			}
		}

		usort( $rows, static function ( $left, $right ) { return $left['id'] <=> $right['id']; } );

		if ( empty( $rows ) && empty( $settings['send_empty'] ) ) {
			$this->record_success( 'all', 0, 0, true );
			return array( 'sent' => false, 'empty' => true, 'count' => 0 );
		}

		$result = $this->send_report( '', $rows, $recipients, $settings, 'scheduled' );
		if ( is_wp_error( $result ) ) {
			return $this->record_error( $result->get_error_message() );
		}

		update_option( BWSR_CHECKPOINTS_OPTION, $next_checkpoints, false );
		$this->record_success( 'all', 0, count( $rows ), false );

		return $result;
	}

	/**
	 * Processes a manual date-range report without changing checkpoints.
	 *
	 * @param array $args Manual report arguments.
	 * @return array|WP_Error
	 */
	public function run_manual_report( $args ) {
		$recipients = BWSR_Mailer::sanitize_recipients( $args['recipients'] );

		if ( empty( $recipients ) ) {
			return new WP_Error( 'bwsr_invalid_manual_report', __( 'Provide at least one valid recipient.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$forms = $this->submissions->get_forms();
		if ( is_wp_error( $forms ) ) {
			return $forms;
		}
		if ( empty( $forms ) ) {
			return new WP_Error( 'bwsr_no_forms', __( 'No Bricks forms with saved submissions were found.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$rows = array();
		foreach ( $forms as $form ) {
			$form_rows = $this->submissions->get_submissions_between( $form['id'], $args['start_utc'], $args['end_utc'] );
			if ( is_wp_error( $form_rows ) ) {
				return $form_rows;
			}
			foreach ( $form_rows as &$row ) {
				$row['form_name'] = $form['name'];
			}
			unset( $row );
			$rows = array_merge( $rows, $form_rows );
		}
		usort( $rows, static function ( $left, $right ) { return $left['id'] <=> $right['id']; } );

		$settings               = wp_parse_args( get_option( BWSR_SETTINGS_OPTION, array() ), BWSR_Admin::default_settings() );
		$settings['start_date'] = $args['start_display'];
		$settings['end_date']   = $args['end_display'];

		return $this->send_report( '', $rows, $recipients, $settings, 'manual' );
	}

	/**
	 * Generates and sends a report while holding an expiring lock.
	 *
	 * @return array|WP_Error
	 */
	private function send_report( $form_id, $rows, $recipients, $settings, $context ) {
		$lock = $this->acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$temp_files = array();

		try {
			if ( '' === $form_id ) {
				$form_name = __( 'All Bricks Forms', 'email-reports-for-bricks-builder-form-submissions' );
				$forms = $this->submissions->get_forms();
				if ( is_wp_error( $forms ) ) {
					return $forms;
				}
			} else {
				$form      = $this->submissions->get_form( $form_id );
				if ( is_wp_error( $form ) ) {
					return $form;
				}
				$form_name = $form['name'];
				$forms     = array( $form );
			}
			$date_range = $this->get_report_date_range( $rows, $settings );

			foreach ( $forms as $form ) {
				$form_rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $form ) {
							return (string) $row['form_id'] === (string) $form['id'];
						}
					)
				);
				if ( 'scheduled' === $context && empty( $form_rows ) && empty( $settings['send_empty'] ) ) {
					continue;
				}
				$filename = sanitize_file_name( sanitize_title( $form['name'] ) . '-' . $form['id'] . '-submissions-' . $date_range['start'] . '-to-' . $date_range['end'] . '.csv' );
				$generated = $this->csv->generate( $form_rows, $filename );
				if ( is_wp_error( $generated ) ) {
					return $generated;
				}
				$temp_files[] = $generated['path'];
			}

			$replacements = array(
				'{form_name}'  => $form_name,
				'{start_date}' => $date_range['start'],
				'{end_date}'   => $date_range['end'],
			);
			$subject = strtr( (string) $settings['subject'], $replacements );
			$message = strtr( (string) $settings['message'], $replacements );

			if ( 'manual' === $context ) {
				/* translators: %s: Name of the forms included in the report. */
				$subject = sprintf( __( 'Manual Bricks Form Submissions - %s', 'email-reports-for-bricks-builder-form-submissions' ), $form_name );
			}

			$sent = $this->mailer->send( $recipients, $subject, $message, $temp_files );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}

			return array(
				'sent'       => true,
				'empty'      => empty( $rows ),
				'count'      => count( $rows ),
				'attachments' => count( $temp_files ),
				'form_name'  => $form_name,
				'recipients' => $recipients,
			);
		} catch ( Throwable $exception ) {
			$this->debug_log( 'Report processing failed for form ' . $form_id . '.' );
			return new WP_Error( 'bwsr_report_exception', __( 'The report could not be processed because of an unexpected error.', 'email-reports-for-bricks-builder-form-submissions' ) );
		} finally {
			foreach ( $temp_files as $temp_file ) {
				if ( $temp_file && file_exists( $temp_file ) ) {
					wp_delete_file( $temp_file );
				}
			}
			$this->release_lock( $lock );
		}
	}

	private function get_report_date_range( $rows, $settings ) {
		if ( isset( $settings['start_date'], $settings['end_date'] ) ) {
			return array( 'start' => $settings['start_date'], 'end' => $settings['end_date'] );
		}

		if ( empty( $rows ) ) {
			$today = wp_date( 'Y-m-d' );
			return array( 'start' => $today, 'end' => $today );
		}

		$first = reset( $rows );
		$last  = end( $rows );

		return array(
			'start' => $this->submissions->format_utc_date( $first['submitted_at'], 'Y-m-d' ),
			'end'   => $this->submissions->format_utc_date( $last['submitted_at'], 'Y-m-d' ),
		);
	}

	private function acquire_lock() {
		$now      = time();
		$existing = get_option( BWSR_LOCK_OPTION, array() );

		if ( is_array( $existing ) && ! empty( $existing['expires'] ) && (int) $existing['expires'] <= $now ) {
			delete_option( BWSR_LOCK_OPTION );
		}

		$token = wp_generate_uuid4();
		$lock  = array( 'token' => $token, 'expires' => $now + ( 30 * MINUTE_IN_SECONDS ) );

		if ( ! add_option( BWSR_LOCK_OPTION, $lock, '', false ) ) {
			return new WP_Error( 'bwsr_report_locked', __( 'Another report is currently being processed. Please try again shortly.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		return $token;
	}

	private function release_lock( $token ) {
		$lock = get_option( BWSR_LOCK_OPTION, array() );
		if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
			delete_option( BWSR_LOCK_OPTION );
		}
	}

	private function record_success( $form_id, $checkpoint, $count, $empty_skipped ) {
		$status = get_option( BWSR_STATUS_OPTION, array() );
		$status['last_success'] = time();
		$status['last_form_id'] = $form_id;
		$status['last_count'] = absint( $count );
		$status['last_checkpoint'] = absint( $checkpoint );
		$status['last_empty_skipped'] = (bool) $empty_skipped;
		$status['last_error'] = '';
		$status['last_error_time'] = 0;
		update_option( BWSR_STATUS_OPTION, $status, false );
	}

	private function record_error( $message ) {
		$safe_message = sanitize_text_field( (string) $message );
		$status = get_option( BWSR_STATUS_OPTION, array() );
		$status['last_error'] = $safe_message;
		$status['last_error_time'] = time();
		update_option( BWSR_STATUS_OPTION, $status, false );
		$this->debug_log( $safe_message );

		return new WP_Error( 'bwsr_report_failed', $safe_message );
	}

	private function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Email Reports for Bricks Builder Form Submissions: ' . sanitize_text_field( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
