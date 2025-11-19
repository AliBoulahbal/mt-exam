<?php
/**
 * Student Statistics Report Module
 *
 * @package Exam Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EM_Report
 */
class EM_Report {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_em_export_pdf', [ __CLASS__, 'export_pdf' ] );
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=em_student',
			__( 'Student Statistics', 'exam-mgmt' ),
			__( 'Statistics Report', 'exam-mgmt' ),
			'manage_options',
			'em_report',
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * Render the report page.
	 */
	public static function render() {
		global $wpdb;

		
		$students = get_posts( [
			'post_type'   => 'em_student',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		] );

		if ( empty( $students ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No students found.', 'exam-mgmt' ) . '</p></div>';
			return;
		}

		
		$data = [];
		foreach ( $students as $s ) {
			$data[ $s->ID ] = [
				'name'        => $s->post_title,
				'terms'       => [],
				'total_all'   => 0,
				'max_all'     => 0,
			];
		}

		

		$student_ids = wp_list_pluck( $students, 'ID' );
		$student_ids = array_map( 'intval', $student_ids );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					r.ID AS result_id,
					pm1.meta_value AS student_id,
					pm2.meta_value AS exam_id,
					pm3.meta_value AS marks_json
				FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->postmeta} pm1 ON r.ID = pm1.post_id AND pm1.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm2 ON r.ID = pm2.post_id AND pm2.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm3 ON r.ID = pm3.post_id AND pm3.meta_key = %s
				WHERE 
					r.post_type = %s 
					AND pm1.meta_value IN (" . implode( ',', $student_ids ) . ")",
				'em_student_id',
				'em_exam_id',
				'em_subject_marks',
				'em_result'
			),
			ARRAY_A
		);

		
		$exam_ids = [];
		foreach ( $results as $row ) {
			$exam_ids[] = (int) $row['exam_id'];
		}
		$exam_ids = array_unique( array_filter( $exam_ids ) );

		$exam_term_map = [];
		if ( ! empty( $exam_ids ) ) {
			$term_data = $wpdb->get_results(
				"SELECT 
					tr.object_id AS exam_id,
					t.term_id,
					t.name AS term_name,
					tm.meta_value AS start_date
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'em_term'
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				LEFT JOIN {$wpdb->termmeta} tm ON t.term_id = tm.term_id AND tm.meta_key = 'em_start_date'
				WHERE tr.object_id IN (" . implode( ',', $exam_ids ) . ")",
				ARRAY_A
			);

			foreach ( $term_data as $td ) {
				$exam_term_map[ $td['exam_id'] ] = [
					'term_id'   => $td['term_id'],
					'term_name' => $td['term_name'],
					'start_date' => $td['start_date'],
				];
			}
		}

		
		foreach ( $results as $row ) {
			$student_id = (int) $row['student_id'];
			$exam_id    = (int) $row['exam_id'];

			if ( ! isset( $data[ $student_id ] ) ) {
				continue;
			}

			// Get the exam's term
			$term_info = $exam_term_map[ $exam_id ] ?? null;
			if ( ! $term_info ) {
				continue; 
			}
			$term_id = $term_info['term_id'];

			$marks = json_decode( $row['marks_json'], true );
			if ( ! is_array( $marks ) ) {
				continue;
			}

			if ( ! isset( $data[ $student_id ]['terms'][ $term_id ] ) ) {
				$data[ $student_id ]['terms'][ $term_id ] = [
					'total' => 0,
					'max'   => 0,
				];
			}

			foreach ( $marks as $mark ) {
				$mark = (int) $mark;
				if ( $mark < 0 || $mark > 100 ) {
					continue;
				}
				$data[ $student_id ]['terms'][ $term_id ]['total'] += $mark;
				$data[ $student_id ]['terms'][ $term_id ]['max']   += 100;
				$data[ $student_id ]['total_all'] += $mark;
				$data[ $student_id ]['max_all']   += 100;
			}
		}

		
		$term_info_list = array_values( $exam_term_map );
		usort(
			$term_info_list,
			function ( $a, $b ) {
				return strcmp( $b['start_date'] ?? '', $a['start_date'] ?? '' );
			}
		);

		$term_info_by_id = [];
		foreach ( $term_info_list as $ti ) {
			$term_info_by_id[ $ti['term_id'] ] = $ti;
		}
		$term_ids = array_keys( $term_info_by_id );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Student Statistics Report', 'exam-mgmt' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="float:right;margin-bottom:1em;">
				<?php wp_nonce_field( 'em_export_pdf', 'em_export_pdf_nonce' ); ?>
				<input type="hidden" name="action" value="em_export_pdf">
				<?php submit_button( __( 'Export as PDF', 'exam-mgmt' ), 'secondary', 'export_pdf', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Student', 'exam-mgmt' ); ?></th>
						<?php foreach ( $term_ids as $tid ) : ?>
							<th><?php echo esc_html( $term_info_by_id[ $tid ]['term_name'] ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Overall Avg', 'exam-mgmt' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data as $sid => $d ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $d['name'] ); ?></strong></td>
							<?php foreach ( $term_ids as $tid ) : ?>
								<td>
								<?php
								if ( isset( $d['terms'][ $tid ] ) ) {
									$t = $d['terms'][ $tid ];
									$avg = $t['max'] > 0 ? ( $t['total'] / $t['max'] ) * 100 : 0;
									printf(
										'%d / %d (%.1f%%)',
										$t['total'],
										$t['max'],
										round( $avg, 1 )
									);
								} else {
									echo '—';
								}
								?>
								</td>
							<?php endforeach; ?>
							<td>
							<?php
							$overall_avg = $d['max_all'] > 0 ? ( $d['total_all'] / $d['max_all'] ) * 100 : 0;
							printf( '%.1f%%', round( $overall_avg, 1 ) );
							?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle PDF export via browser print-to-PDF.
	 */
	public static function export_pdf() {
		check_admin_referer( 'em_export_pdf', 'em_export_pdf_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'exam-mgmt' ) );
		}

		ob_clean();

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<title><?php esc_html_e( 'Student Statistics Report', 'exam-mgmt' ); ?></title>
			<style>
				body { font-family: Arial, sans-serif; margin: 2em; }
				h1 { text-align: center; }
				table { width: 100%; border-collapse: collapse; margin-top: 1em; }
				th, td { border: 1px solid #000; padding: 8px; }
				th { background: #f0f0f0; }
				.print-note { margin-top: 2em; font-style: italic; }
			</style>
			<script>
				window.addEventListener('load', () => {
					setTimeout(() => window.print(), 500);
				});
			</script>
		</head>
		<body>
			<h1><?php esc_html_e( 'Student Statistics Report', 'exam-mgmt' ); ?></h1>
			<p><small><?php echo esc_html( sprintf( __( 'Generated on %s', 'exam-mgmt' ), wp_date( 'Y-m-d H:i:s' ) ) ); ?></small></p>

			<?php self::render_table_for_pdf(); ?>

			<p class="print-note">
				<?php esc_html_e( 'Tip: Use "Save as PDF" in your browser’s print dialog.', 'exam-mgmt' ); ?>
			</p>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Helper: Render clean table for PDF/print.
	 */
	private static function render_table_for_pdf() {
		global $wpdb;

		$students = get_posts( [ 'post_type' => 'em_student', 'numberposts' => -1, 'orderby' => 'title' ] );
		if ( empty( $students ) ) {
			echo '<p>' . esc_html__( 'No students.', 'exam-mgmt' ) . '</p>';
			return;
		}

		$data = [];
		foreach ( $students as $s ) {
			$data[ $s->ID ] = [ 'name' => $s->post_title, 'terms' => [], 'total_all' => 0, 'max_all' => 0 ];
		}

		// Same logic as render(), simplified for PDF
		$student_ids = wp_list_pluck( $students, 'ID' );
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm1.meta_value AS student_id, pm2.meta_value AS exam_id, pm3.meta_value AS marks_json
				FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->postmeta} pm1 ON r.ID = pm1.post_id AND pm1.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm2 ON r.ID = pm2.post_id AND pm2.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm3 ON r.ID = pm3.post_id AND pm3.meta_key = %s
				WHERE r.post_type = %s AND pm1.meta_value IN (" . implode( ',', array_map( 'intval', $student_ids ) ) . ")",
				'em_student_id',
				'em_exam_id',
				'em_subject_marks',
				'em_result'
			),
			ARRAY_A
		);

		$exam_ids = array_unique( wp_list_pluck( $results, 'exam_id' ) );
		$exam_term_map = [];
		if ( ! empty( $exam_ids ) ) {
			$terms = $wpdb->get_results(
				"SELECT tr.object_id AS exam_id, t.term_id, t.name
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE tt.taxonomy = 'em_term' AND tr.object_id IN (" . implode( ',', $exam_ids ) . ")",
				ARRAY_A
			);
			foreach ( $terms as $t ) {
				$exam_term_map[ $t['exam_id'] ] = $t['term_id'];
			}
		}

		foreach ( $results as $row ) {
			$student_id = (int) $row['student_id'];
			$exam_id = (int) $row['exam_id'];
			$term_id = $exam_term_map[ $exam_id ] ?? 0;
			$marks = json_decode( $row['marks_json'], true );
			if ( ! isset( $data[ $student_id ] ) || ! is_array( $marks ) || ! $term_id ) continue;
			if ( ! isset( $data[ $student_id ]['terms'][ $term_id ] ) ) {
				$data[ $student_id ]['terms'][ $term_id ] = [ 'total' => 0, 'max' => 0 ];
			}
			foreach ( $marks as $mark ) {
				$mark = (int) $mark;
				if ( $mark >= 0 && $mark <= 100 ) {
					$data[ $student_id ]['terms'][ $term_id ]['total'] += $mark;
					$data[ $student_id ]['terms'][ $term_id ]['max'] += 100;
					$data[ $student_id ]['total_all'] += $mark;
					$data[ $student_id ]['max_all'] += 100;
				}
			}
		}

		// Get term names
		$term_ids = [];
		foreach ( $data as $d ) {
			$term_ids = array_merge( $term_ids, array_keys( $d['terms'] ) );
		}
		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		$term_names = [];
		if ( ! empty( $term_ids ) ) {
			$terms = $wpdb->get_results(
				"SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN (" . implode( ',', $term_ids ) . ")",
				OBJECT_K
			);
			foreach ( $term_ids as $tid ) {
				$term_names[ $tid ] = $terms[ $tid ]->name ?? "Term #$tid";
			}
		}

		echo '<table><thead><tr><th>' . esc_html__( 'Student', 'exam-mgmt' ) . '</th>';
		foreach ( $term_ids as $tid ) {
			echo '<th>' . esc_html( $term_names[ $tid ] ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Overall Avg', 'exam-mgmt' ) . '</th></tr></thead><tbody>';

		foreach ( $data as $d ) {
			echo '<tr><td><b>' . esc_html( $d['name'] ) . '</b></td>';
			foreach ( $term_ids as $tid ) {
				if ( isset( $d['terms'][ $tid ] ) ) {
					$t = $d['terms'][ $tid ];
					$avg = $t['max'] > 0 ? ( $t['total'] / $t['max'] ) * 100 : 0;
					echo '<td>' . sprintf( '%d/%d (%.1f%%)', $t['total'], $t['max'], $avg ) . '</td>';
				} else {
					echo '<td>—</td>';
				}
			}
			$overall = $d['max_all'] > 0 ? ( $d['total_all'] / $d['max_all'] ) * 100 : 0;
			echo '<td>' . sprintf( '%.1f%%', $overall ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}