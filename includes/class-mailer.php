<?php
/**
 * WordPress mail adapter.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWSR_Mailer {
	public static function sanitize_recipients( $recipients ) {
		if ( is_string( $recipients ) ) {
			$recipients = preg_split( '/[,;\r\n]+/', $recipients );
		}
		if ( ! is_array( $recipients ) ) {
			return array();
		}

		$valid = array();
		foreach ( $recipients as $recipient ) {
			$recipient = sanitize_email( trim( (string) $recipient ) );
			if ( $recipient && is_email( $recipient ) ) {
				$valid[] = $recipient;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	public function send( $recipients, $subject, $message, $attachments ) {
		$recipients = self::sanitize_recipients( $recipients );
		if ( empty( $recipients ) ) {
			return new WP_Error( 'bwsr_no_recipients', __( 'No valid email recipients were provided.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}
		$attachments = is_array( $attachments ) ? $attachments : array( $attachments );
		if ( empty( $attachments ) ) {
			return new WP_Error( 'bwsr_attachment_missing', __( 'The CSV attachments are unavailable.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}
		foreach ( $attachments as $attachment ) {
			if ( ! $attachment || ! is_readable( $attachment ) ) {
				return new WP_Error( 'bwsr_attachment_missing', __( 'A CSV attachment is unavailable.', 'email-reports-for-bricks-builder-form-submissions' ) );
			}
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent = wp_mail(
			$recipients,
			wp_strip_all_tags( (string) $subject ),
			wp_kses_post( (string) $message ),
			$headers,
			$attachments
		);

		if ( ! $sent ) {
			return new WP_Error( 'bwsr_mail_failed', __( 'WordPress did not accept the report email for sending.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		return true;
	}
}
