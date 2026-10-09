<?php
/**
 * CSV report generator.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWSR_CSV_Generator {
	/**
	 * Creates a temporary UTF-8 CSV file.
	 *
	 * @return array|WP_Error
	 */
	public function generate( $submissions, $filename, $include_form_columns = false ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$placeholder = wp_tempnam( $filename );
		if ( ! $placeholder ) {
			return new WP_Error( 'bwsr_temp_file_failed', __( 'WordPress could not create a temporary CSV file.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$temp_directory = dirname( $placeholder );
		$temp_filename  = wp_unique_filename( $temp_directory, sanitize_file_name( $filename ) );
		$temp_file      = trailingslashit( $temp_directory ) . $temp_filename;
		if ( ! rename( $placeholder, $temp_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $placeholder );
			return new WP_Error( 'bwsr_temp_file_rename_failed', __( 'WordPress could not prepare the CSV attachment filename.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$handle = fopen( $temp_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_delete_file( $temp_file );
			return new WP_Error( 'bwsr_csv_open_failed', __( 'The temporary CSV file could not be opened.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		try {
			fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$columns = $this->get_columns( $submissions );
			$headers = array();
			if ( $include_form_columns ) {
				$headers[] = __( 'Form Name', 'email-reports-for-bricks-builder-form-submissions' );
				$headers[] = __( 'Form ID', 'email-reports-for-bricks-builder-form-submissions' );
			}
			$headers[] = __( 'Submission ID', 'email-reports-for-bricks-builder-form-submissions' );
			$headers[] = __( 'Submitted At', 'email-reports-for-bricks-builder-form-submissions' );
			foreach ( $columns as $column ) {
				$headers[] = $column['header'];
			}

			if ( false === fputcsv( $handle, $headers ) ) {
				throw new RuntimeException( 'CSV header write failed.' );
			}

			foreach ( $submissions as $submission ) {
				$row = array();
				if ( $include_form_columns ) {
					$row[] = isset( $submission['form_name'] ) ? $submission['form_name'] : $submission['form_id'];
					$row[] = $submission['form_id'];
				}
				$row[] = $submission['id'];
				$row[] = $this->format_submission_date( $submission['submitted_at'] );
				$row_fields = $this->map_row_fields( $submission );
				foreach ( $columns as $column_key => $column ) {
					$field = isset( $row_fields[ $column_key ] ) ? $row_fields[ $column_key ] : null;
					$row[] = $field ? $this->format_value( $field['value'], $field['type'] ) : '';
				}
				/*
				 * Keep the write separate from value formatting so fputcsv handles
				 * quoting, delimiters, and multiline values for the complete row.
				 */
				if ( false === fputcsv( $handle, $row ) ) {
					throw new RuntimeException( 'CSV row write failed.' );
				}
			}
		} catch ( Throwable $exception ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $temp_file );
			return new WP_Error( 'bwsr_csv_write_failed', __( 'The CSV report could not be written.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'path' => $temp_file, 'filename' => $filename );
	}

	private function get_columns( $submissions ) {
		$columns = array();
		$sequence = 0;

		foreach ( $submissions as $submission ) {
			foreach ( $this->map_row_fields( $submission ) as $column_key => $field ) {
				if ( isset( $columns[ $column_key ] ) ) {
					continue;
				}
				$columns[ $column_key ] = array(
					'header'   => $field['csv_header'],
					'order'    => isset( $field['order'] ) ? $field['order'] : PHP_INT_MAX,
					'sequence' => $sequence++,
				);
			}
		}

		uasort(
			$columns,
			static function ( $left, $right ) {
				if ( $left['order'] === $right['order'] ) {
					return $left['sequence'] <=> $right['sequence'];
				}
				return $left['order'] <=> $right['order'];
			}
		);

		return $columns;
	}

	/**
	 * Maps equivalent labels from different forms to one CSV column while
	 * preserving duplicate labels within an individual form submission.
	 */
	private function map_row_fields( $submission ) {
		$mapped = array();
		$seen   = array();

		foreach ( $submission['fields'] as $field_id => $field ) {
			$header = ! empty( $field['label'] ) ? (string) $field['label'] : (string) $field_id;
			$key    = $this->normalize_header_key( $header );
			if ( isset( $seen[ $key ] ) ) {
				$key   .= '|' . $field_id;
				$header .= ' (' . $field_id . ')';
			}
			$seen[ $this->normalize_header_key( $field['label'] ? $field['label'] : $field_id ) ] = true;
			$field['csv_header'] = $header;
			$mapped[ $key ] = $field;
		}

		return $mapped;
	}

	private function normalize_header_key( $header ) {
		$header = trim( wp_strip_all_tags( (string) $header ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $header, 'UTF-8' ) : strtolower( $header );
	}

	private function format_submission_date( $datetime ) {
		$date = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $datetime, new DateTimeZone( 'UTC' ) );
		return false === $date ? (string) $datetime : $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	private function format_value( $value, $type ) {
		if ( ! is_array( $value ) ) {
			if ( is_bool( $value ) ) {
				return $value ? __( 'Yes', 'email-reports-for-bricks-builder-form-submissions' ) : __( 'No', 'email-reports-for-bricks-builder-form-submissions' );
			}
			return is_scalar( $value ) || null === $value ? (string) $value : '';
		}

		if ( 'file' === $type ) {
			$files = array();
			foreach ( $value as $file ) {
				if ( ! is_array( $file ) ) {
					$files[] = (string) $file;
					continue;
				}
				if ( ! empty( $file['url'] ) ) {
					$files[] = esc_url_raw( $file['url'] );
				} elseif ( ! empty( $file['name'] ) ) {
					$files[] = sanitize_file_name( $file['name'] );
				} elseif ( ! empty( $file['attachment_id'] ) ) {
					$files[] = 'Attachment ID: ' . absint( $file['attachment_id'] );
				} elseif ( ! empty( $file['file'] ) ) {
					$files[] = sanitize_file_name( wp_basename( $file['file'] ) );
				}
			}
			return implode( ' | ', array_filter( $files, 'strlen' ) );
		}

		$values = array();
		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$values ) {
				if ( is_bool( $item ) ) {
					$values[] = $item ? 'Yes' : 'No';
				} elseif ( is_scalar( $item ) && '' !== (string) $item ) {
					$values[] = (string) $item;
				}
			}
		);

		return implode( ' | ', $values );
	}
}
