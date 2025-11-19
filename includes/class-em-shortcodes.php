<?php
class EM_Shortcodes {
	public static function init() {
		add_shortcode( 'em_top_students', [ __CLASS__, 'render' ] );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts(
			[
				'limit_per_term' => 3,
				'show_term_dates' => false,
			],
			$atts,
			'em_top_students'
		);

		$limit = max( 1, intval( $atts['limit_per_term'] ) );

		global $wpdb;

		// Get terms ordered by start date DESC
		$terms = get_terms(
			[
				'taxonomy'   => 'em_term',
				'hide_empty' => true,
				'meta_key'   => 'em_start_date',
				'orderby'    => 'meta_value',
				'order'      => 'DESC',
			]
		);

		if ( empty( $terms ) ) {
			return '<p>' . esc_html__( 'No academic terms found.', 'exam-mgmt' ) . '</p>';
		}

		ob_start();
		echo '<div class="em-top-students">';
		foreach ( $terms as $term ) {
			$students = self::get_top_students_in_term( $term->term_id, $limit );
			if ( empty( $students ) ) continue;

			echo '<section class="em-term">';
			echo '<h3 class="em-term-title">';
			echo esc_html( $term->name );
			if ( $atts['show_term_dates'] ) {
				$start = get_term_meta( $term->term_id, 'em_start_date', true );
				$end   = get_term_meta( $term->term_id, 'em_end_date', true );
				if ( $start || $end ) {
					echo ' <small>(' . esc_html( $start ?: '???' ) . ' – ' . esc_html( $end ?: '???' ) . ')</small>';
				}
			}
			echo '</h3>';

			echo '<ol class="em-students-list">';
			foreach ( $students as $student ) {
				$avg_percent = round( $student['avg'], 1 );
				echo '<li class="em-student">';
				echo '<strong>' . esc_html( $student['name'] ) . '</strong>';
				echo ' — ' . esc_html( $student['total'] ) . '/' . esc_html( $student['max'] );
				echo ' <span class="em-average">(' . $avg_percent . '%)</span>';
				echo '</li>';
			}
			echo '</ol>';
			echo '</section>';
		}
		echo '</div>';

		return ob_get_clean();
	}

	private static function get_top_students_in_term( $term_id, $limit ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					r.ID AS result_id,
					pm1.meta_value AS student_id,
					pm2.meta_value AS exam_id,
					pm3.meta_value AS marks_json
				FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->postmeta} pm1 ON r.ID = pm1.post_id AND pm1.meta_key = 'em_student_id'
				INNER JOIN {$wpdb->postmeta} pm2 ON r.ID = pm2.post_id AND pm2.meta_key = 'em_exam_id'
				INNER JOIN {$wpdb->term_relationships} tr ON pm2.meta_value = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'em_term'
				INNER JOIN {$wpdb->postmeta} pm3 ON r.ID = pm3.post_id AND pm3.meta_key = 'em_subject_marks'
				WHERE 
					r.post_type = 'em_result'
					AND tt.term_id = %d",
				$term_id
			),
			ARRAY_A
		);

		$data = [];
		foreach ( $results as $row ) {
			$student_id = (int) $row['student_id'];
			$marks_arr  = json_decode( $row['marks_json'], true );

			if ( ! $student_id || ! is_array( $marks_arr ) ) {
				continue;
			}

			if ( ! isset( $data[ $student_id ] ) ) {
				$student_obj = get_post( $student_id );
				$student_name = $student_obj ? $student_obj->post_title : "Student #{$student_id}";
				$data[ $student_id ] = [
					'name'  => $student_name,
					'total' => 0,
					'max'   => 0,
				];
			}

			foreach ( $marks_arr as $mark ) {
				$mark = (int) $mark;
				if ( $mark >= 0 && $mark <= 100 ) {
					$data[ $student_id ]['total'] += $mark;
					$data[ $student_id ]['max']   += 100;
				}
			}
		}

		$students = [];
		foreach ( $data as $id => $d ) {
			$d['avg'] = $d['max'] > 0 ? ( $d['total'] / $d['max'] ) * 100 : 0;
			$students[] = $d;
		}

		usort(
			$students,
			function ( $a, $b ) {
				return $b['avg'] <=> $a['avg'];
			}
		);

		return array_slice( $students, 0, $limit );
	}
}