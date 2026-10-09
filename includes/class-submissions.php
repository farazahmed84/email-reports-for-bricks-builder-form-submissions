<?php
/**
 * Bricks submission storage adapter.
 *
 * @package BricksWeeklySubmissionReports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWSR_Submissions {
	/** @var array|null */
	private $schema = null;

	/** @var array */
	private $form_cache = array();

	public function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bricks_form_submissions';
	}

	public function table_exists() {
		global $wpdb;
		$table = $this->get_table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The current Bricks table state is required.
	}

	/**
	 * Maps required concepts to columns found in the installed Bricks table.
	 *
	 * @return array|WP_Error
	 */
	public function get_schema() {
		if ( null !== $this->schema ) {
			return $this->schema;
		}

		if ( ! $this->table_exists() ) {
			return new WP_Error( 'bwsr_table_missing', __( 'Bricks form submissions could not be found. Make sure Bricks is active and Save submissions is enabled for the form.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		global $wpdb;
		$table   = $this->get_table_name();
		$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name comes from the WordPress database prefix and a fixed suffix.
		$aliases = array(
			'id'           => array( 'id', 'submission_id', 'entry_id' ),
			'post_id'      => array( 'post_id', 'page_id' ),
			'form_id'      => array( 'form_id', 'element_id' ),
			'submitted_at' => array( 'created_at', 'submitted_at', 'date_created', 'created' ),
			'form_data'    => array( 'form_data', 'data', 'fields' ),
		);
		$schema = array();

		foreach ( $aliases as $concept => $candidates ) {
			foreach ( $candidates as $candidate ) {
				if ( in_array( $candidate, $columns, true ) ) {
					$schema[ $concept ] = $candidate;
					break;
				}
			}
		}

		$missing = array_diff( array_keys( $aliases ), array_keys( $schema ) );
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'bwsr_schema_unsupported', __( 'The installed Bricks submissions table uses an unsupported schema.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$this->schema = $schema;
		return $schema;
	}

	/**
	 * Discovers forms represented by saved submissions.
	 *
	 * @return array|WP_Error
	 */
	public function get_forms() {
		$schema = $this->get_schema();
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		global $wpdb;
		$table       = $this->get_table_name();
		$form_column = $schema['form_id'];
		$post_column = $schema['post_id'];
		$id_column   = $schema['id'];
		$sql         = "SELECT `{$form_column}` AS form_id, `{$post_column}` AS post_id, COUNT(*) AS submission_count, MAX(`{$id_column}`) AS latest_id FROM `{$table}` GROUP BY `{$form_column}`, `{$post_column}` ORDER BY latest_id DESC";
		$rows        = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers are selected from the discovered allow-listed schema; fresh submission data is required.
		$grouped     = array();

		foreach ( $rows as $row ) {
			$form_id = sanitize_text_field( (string) $row['form_id'] );
			if ( '' === $form_id ) {
				continue;
			}

			if ( ! isset( $grouped[ $form_id ] ) ) {
				$grouped[ $form_id ] = array( 'count' => 0, 'post_ids' => array() );
			}
			$grouped[ $form_id ]['count'] += absint( $row['submission_count'] );
			$post_id = absint( $row['post_id'] );
			if ( $post_id && ! in_array( $post_id, $grouped[ $form_id ]['post_ids'], true ) ) {
				$grouped[ $form_id ]['post_ids'][] = $post_id;
			}
		}

		$forms = array();
		foreach ( $grouped as $form_id => $data ) {
			$forms[] = $this->resolve_form( $form_id, $data['post_ids'], $data['count'] );
		}

		usort(
			$forms,
			static function ( $left, $right ) {
				return strcasecmp( $left['name'], $right['name'] );
			}
		);

		return $forms;
	}

	public function get_form( $form_id ) {
		$form_id = sanitize_text_field( (string) $form_id );
		if ( isset( $this->form_cache[ $form_id ] ) ) {
			return $this->form_cache[ $form_id ];
		}

		$forms = $this->get_forms();
		if ( is_wp_error( $forms ) ) {
			return $forms;
		}

		foreach ( $forms as $form ) {
			if ( $form_id === $form['id'] ) {
				return $form;
			}
		}

		return new WP_Error( 'bwsr_form_missing', __( 'The selected Bricks form no longer has any saved submissions.', 'email-reports-for-bricks-builder-form-submissions' ) );
	}

	public function get_submissions_after( $form_id, $after_id ) {
		return $this->query_submissions( $form_id, 'after', absint( $after_id ), '' );
	}

	public function get_submissions_between( $form_id, $start_utc, $end_utc ) {
		return $this->query_submissions( $form_id, 'between', $start_utc, $end_utc );
	}

	/**
	 * Converts a stored UTC datetime to the site's timezone.
	 */
	public function format_utc_date( $datetime, $format ) {
		$date = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $datetime, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			return sanitize_text_field( (string) $datetime );
		}
		return $date->setTimezone( wp_timezone() )->format( $format );
	}

	private function query_submissions( $form_id, $mode, $first, $second ) {
		$schema = $this->get_schema();
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		global $wpdb;
		$table       = $this->get_table_name();
		$id_column   = $schema['id'];
		$post_column = $schema['post_id'];
		$form_column = $schema['form_id'];
		$date_column = $schema['submitted_at'];
		$data_column = $schema['form_data'];
		$select      = "SELECT `{$id_column}` AS id, `{$post_column}` AS post_id, `{$form_column}` AS form_id, `{$date_column}` AS submitted_at, `{$data_column}` AS form_data FROM `{$table}`";

		if ( 'after' === $mode ) {
			$sql = $wpdb->prepare( $select . " WHERE `{$form_column}` = %s AND `{$id_column}` > %d ORDER BY `{$id_column}` ASC", $form_id, $first ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The SELECT clause contains allow-listed schema identifiers; values use placeholders.
		} else {
			$sql = $wpdb->prepare( $select . " WHERE `{$form_column}` = %s AND `{$date_column}` BETWEEN %s AND %s ORDER BY `{$id_column}` ASC", $form_id, $first, $second ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The SELECT clause contains allow-listed schema identifiers; values use placeholders.
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query values are prepared above and fresh submission data is required.
		if ( null === $rows && $wpdb->last_error ) {
			return new WP_Error( 'bwsr_query_failed', __( 'The Bricks submissions query failed.', 'email-reports-for-bricks-builder-form-submissions' ) );
		}

		$settings_cache = array();
		$normalized     = array();
		foreach ( $rows as $row ) {
			$post_id = absint( $row['post_id'] );
			$cache_key = $post_id . ':' . $form_id;
			if ( ! isset( $settings_cache[ $cache_key ] ) ) {
				$settings_cache[ $cache_key ] = $this->find_form_settings( $post_id, $form_id );
			}
			$normalized[] = $this->normalize_submission( $row, $settings_cache[ $cache_key ] );
		}

		return $normalized;
	}

	private function normalize_submission( $row, $settings ) {
		$decoded = json_decode( (string) $row['form_data'], true );
		$labels  = array();
		$order   = array();

		if ( ! empty( $settings['fields'] ) && is_array( $settings['fields'] ) ) {
			foreach ( $settings['fields'] as $field ) {
				if ( ! is_array( $field ) || empty( $field['id'] ) ) {
					continue;
				}
				$field_id = (string) $field['id'];
				$labels[ $field_id ] = ! empty( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : $field_id;
				$order[] = $field_id;
			}
		}

		$fields = array();
		if ( ! is_array( $decoded ) ) {
			$fields['__bwsr_parse_error'] = array(
				'label' => __( 'Submission Data Error', 'email-reports-for-bricks-builder-form-submissions' ),
				'type'  => 'text',
				'value' => __( 'Unable to decode the stored submission data.', 'email-reports-for-bricks-builder-form-submissions' ),
				'order' => PHP_INT_MAX,
			);
		} else {
			foreach ( $decoded as $field_id => $field_data ) {
				$field_id   = sanitize_text_field( (string) $field_id );
				$field_data = is_array( $field_data ) ? $field_data : array( 'value' => $field_data );
				$position   = array_search( $field_id, $order, true );
				$fields[ $field_id ] = array(
					'label' => isset( $labels[ $field_id ] ) ? $labels[ $field_id ] : $field_id,
					'type'  => isset( $field_data['type'] ) ? sanitize_key( $field_data['type'] ) : '',
					'value' => isset( $field_data['value'] ) ? $field_data['value'] : '',
					'order' => false === $position ? PHP_INT_MAX : $position,
				);
			}
		}

		return array(
			'id'           => absint( $row['id'] ),
			'post_id'      => absint( $row['post_id'] ),
			'form_id'      => sanitize_text_field( (string) $row['form_id'] ),
			'submitted_at' => sanitize_text_field( (string) $row['submitted_at'] ),
			'fields'       => $fields,
		);
	}

	private function resolve_form( $form_id, $post_ids, $count ) {
		$name     = '';
		$settings = array();

		foreach ( $post_ids as $post_id ) {
			$settings = $this->find_form_settings( $post_id, $form_id );
			if ( ! empty( $settings ) ) {
				$name = ! empty( $settings['submissionFormName'] ) ? wp_strip_all_tags( (string) $settings['submissionFormName'] ) : '';
				if ( '' === $name ) {
					$name = get_the_title( $post_id );
				}
				break;
			}
		}

		if ( '' === $name ) {
			/* translators: %s: Bricks form element ID. */
			$name = sprintf( __( 'Bricks Form %s', 'email-reports-for-bricks-builder-form-submissions' ), $form_id );
		}

		$form = array(
			'id'       => $form_id,
			'name'     => $name,
			'post_ids' => $post_ids,
			'count'    => absint( $count ),
			'settings' => $settings,
		);
		$this->form_cache[ $form_id ] = $form;
		return $form;
	}

	/**
	 * Uses Bricks' public helper when available, with a post-meta fallback.
	 */
	private function find_form_settings( $post_id, $form_id ) {
		if ( class_exists( '\\Bricks\\Integrations\\Form\\Submission_Database' ) && method_exists( '\\Bricks\\Integrations\\Form\\Submission_Database', 'get_form_settings' ) ) {
			try {
				$settings = \Bricks\Integrations\Form\Submission_Database::get_form_settings( $post_id, $form_id, $form_id );
				if ( is_array( $settings ) && ! empty( $settings ) ) {
					return $settings;
				}
			} catch ( Throwable $exception ) {
				// Continue with the independent metadata fallback.
			}
		}

		$meta_keys = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );
		foreach ( $meta_keys as $meta_key ) {
			$elements = get_post_meta( $post_id, $meta_key, true );
			$settings = $this->find_element_settings( $elements, $form_id );
			if ( ! empty( $settings ) ) {
				return $settings;
			}
		}

		return $this->find_form_settings_globally( $form_id, $meta_keys );
	}

	/**
	 * Finds forms reused from templates or copied Bricks content.
	 *
	 * Bricks submissions store the page on which a form was submitted, but the
	 * actual form element can live in another Bricks post/template. Bricks uses
	 * a similar serialized-ID metadata search for this fallback.
	 */
	private function find_form_settings_globally( $form_id, $meta_keys ) {
		global $wpdb;

		$serialized_needle = '%id";s:' . strlen( (string) $form_id ) . ':"' . $wpdb->esc_like( (string) $form_id ) . '%';
		$json_needle       = '%"id":"' . $wpdb->esc_like( (string) $form_id ) . '"%';
		$placeholders      = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		$sql               = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND (meta_value LIKE %s OR meta_value LIKE %s) LIMIT 50";
		$args              = array_merge( $meta_keys, array( $serialized_needle, $json_needle ) );
		$values            = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Current Bricks metadata is required and query values use placeholders.
		$best_settings     = array();
		$best_score        = -1;

		foreach ( $values as $value ) {
			$elements = maybe_unserialize( $value );
			$settings = $this->find_element_settings( $elements, $form_id );
			if ( empty( $settings ) ) {
				continue;
			}

			$fields = ! empty( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array();
			$score  = count( $fields );
			if ( ! empty( $settings['submissionFormName'] ) ) {
				$score += 1000;
			}

			if ( $score > $best_score ) {
				$best_score    = $score;
				$best_settings = $settings;
			}
		}

		return $best_settings;
	}

	private function find_element_settings( $value, $form_id ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		if ( isset( $value['id'] ) && (string) $value['id'] === (string) $form_id && isset( $value['settings'] ) && is_array( $value['settings'] ) ) {
			return $value['settings'];
		}

		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$found = $this->find_element_settings( $child, $form_id );
				if ( ! empty( $found ) ) {
					return $found;
				}
			}
		}

		return array();
	}
}
