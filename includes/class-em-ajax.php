<?php
/**
 * AJAX Exam List Handler
 *
 * @package Exam Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EM_AJAX {

	public static function init() {
		add_action( 'wp_ajax_em_get_exams', [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_nopriv_em_get_exams', [ __CLASS__, 'handle' ] );
	}

	public static function handle() {
		// Sanitize input
		$page     = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
		$per_page = 10;

		global $wpdb;
		$now = current_time( 'mysql' );

		// Optimized direct SQL — no WP_Query overhead
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					e.ID AS id,
					e.post_title AS title,
					start_meta.meta_value AS start,
					end_meta.meta_value AS end,
					subj.post_title AS subject,
					term.name AS term
				FROM {$wpdb->posts} e
				INNER JOIN {$wpdb->postmeta} start_meta 
					ON e.ID = start_meta.post_id AND start_meta.meta_key = 'em_start_datetime'
				INNER JOIN {$wpdb->postmeta} end_meta 
					ON e.ID = end_meta.post_id AND end_meta.meta_key = 'em_end_datetime'
				LEFT JOIN {$wpdb->postmeta} subj_meta 
					ON e.ID = subj_meta.post_id AND subj_meta.meta_key = 'em_subject_id'
				LEFT JOIN {$wpdb->posts} subj ON subj_meta.meta_value = subj.ID
				LEFT JOIN {$wpdb->term_relationships} tr ON e.ID = tr.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt 
					ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'em_term'
				LEFT JOIN {$wpdb->terms} term ON tt.term_id = term.term_id
				WHERE e.post_type = 'em_exam' AND e.post_status = 'publish'
				ORDER BY 
					CASE 
						WHEN %s BETWEEN start_meta.meta_value AND end_meta.meta_value THEN 1
						WHEN start_meta.meta_value > %s THEN 2
						ELSE 3
					END ASC,
					start_meta.meta_value ASC
				LIMIT %d OFFSET %d",
				$now,
				$now,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		// Get total count for pagination
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'em_exam' AND post_status = 'publish'"
		);

		// Format response
		$exams = [];
		foreach ( $results as $row ) {
			$start = $row['start'] ?: '';
			$end   = $row['end'] ?: '';

			$exams[] = [
				'id'      => (int) $row['id'],
				'title'   => sanitize_text_field( $row['title'] ),
				'start'   => sanitize_text_field( $start ),
				'end'     => sanitize_text_field( $end ),
				'subject' => $row['subject'] ? sanitize_text_field( $row['subject'] ) : '',
				'term'    => $row['term'] ? sanitize_text_field( $row['term'] ) : '',
				'status'  => self::get_exam_status( $start, $end, $now ),
			];
		}

		wp_send_json_success( [
			'data'       => $exams,
			'pagination' => [
				'current_page' => $page,
				'total_pages'  => (int) ceil( $total / $per_page ),
				'total_items'  => (int) $total,
			],
		] );
	}

	/**
	 * Determines exam status: ongoing, upcoming, or past.
	 *
	 * @param string $start Start datetime (Y-m-d H:i:s)
	 * @param string $end   End datetime (Y-m-d H:i:s)
	 * @param string $now   Current datetime
	 * @return string 'ongoing'|'upcoming'|'past'|'unknown'
	 */
	private static function get_exam_status( $start, $end, $now ) {
		if ( ! $start || ! $end ) {
			return 'unknown';
		}
		if ( $now >= $start && $now <= $end ) {
			return 'ongoing';
		}
		if ( $now < $start ) {
			return 'upcoming';
		}
		return 'past';
	}
}