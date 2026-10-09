<?php
/**
 * WP-Cron scheduling.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWSR_Scheduler {
	/** @var callable */
	private $callback;

	public function __construct( $callback ) {
		$this->callback = $callback;
	}

	public function init() {
		add_action( BWSR_CRON_HOOK, array( $this, 'handle_event' ) );
	}

	public function handle_event() {
		// Re-arm first so a processing failure cannot permanently stop future reports.
		$this->schedule_next();
		call_user_func( $this->callback );
	}

	public function reschedule() {
		wp_clear_scheduled_hook( BWSR_CRON_HOOK );
		return $this->schedule_next();
	}

	private function schedule_next() {
		$timestamp = $this->next_timestamp();
		if ( ! wp_next_scheduled( BWSR_CRON_HOOK ) ) {
			wp_schedule_single_event( $timestamp, BWSR_CRON_HOOK );
		}
		return $timestamp;
	}

	public function next_timestamp() {
		$settings = wp_parse_args( get_option( BWSR_SETTINGS_OPTION, array() ), BWSR_Admin::default_settings() );
		$weekdays = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		$weekday  = in_array( $settings['weekday'], $weekdays, true ) ? $settings['weekday'] : 'monday';
		$time     = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $settings['time'] ) ? $settings['time'] : '08:00';
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$next = $now->modify( 'this ' . $weekday )->setTime( $hour, $minute, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 week' );
		}
		return $next->getTimestamp();
	}

	public function get_next_scheduled() {
		return wp_next_scheduled( BWSR_CRON_HOOK );
	}
}
