<?php
/**
 * Bulk Result Import Module
 *
 * @package Exam Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EM_Import
 */
class EM_Import {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_em_import_results', [ __CLASS__, 'handle_import' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_download_csv' ] );
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=em_result',
			__( 'Bulk Import Results', 'exam-mgmt' ),
			__( 'Bulk Import', 'exam-mgmt' ),
			'manage_options',
			'em_import',
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * Render the import page.
	 */
	public static function render() {
		if ( isset( $_GET['success'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Import successful!', 'exam-mgmt' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Import Results', 'exam-mgmt' ); ?></h1>

			<h2><?php esc_html_e( '1. Download Sample CSV (with your real data)', 'exam-mgmt' ); ?></h2>
			<p><?php esc_html_e( 'This CSV uses real IDs from your site — edit marks and re-upload.', 'exam-mgmt' ); ?></p>
			<a href="<?php echo esc_url( add_query_arg( 'em_action', 'download_sample_csv' ) ); ?>" class="button button-primary">
				<?php esc_html_e( '⬇️ Download Real Sample CSV', 'exam-mgmt' ); ?>
			</a>

			<h2><?php esc_html_e( '2. Upload Your CSV File', 'exam-mgmt' ); ?></h2>
			<p><?php esc_html_e( 'Required columns: <code>student_id,exam_id,subject_id,marks</code>. Extra columns (e.g., names) are ignored.', 'exam-mgmt' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'em_import_nonce', 'em_import_nonce' ); ?>
				<input type="hidden" name="action" value="em_import_results">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV File', 'exam-mgmt' ); ?></label></th>
						<td>
							<input type="file" name="csv_file" id="csv_file" accept=".csv" required>
							<p class="description"><?php esc_html_e( 'Max file size: 2MB. UTF-8 encoding recommended.', 'exam-mgmt' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import Results', 'exam-mgmt' ), 'primary', 'import_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle CSV import.
	 */
	public static function handle_import() {
		if ( ! wp_verify_nonce( $_POST['em_import_nonce'] ?? '', 'em_import_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'exam-mgmt' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'exam-mgmt' ) );
		}

		if ( empty( $_FILES['csv_file'] ) || ! empty( $_FILES['csv_file']['error'] ) ) {
			wp_die( esc_html__( 'File upload error.', 'exam-mgmt' ) );
		}

		$tmp_file = $_FILES['csv_file']['tmp_name'];
		if ( ! is_uploaded_file( $tmp_file ) ) {
			wp_die( esc_html__( 'Invalid file.', 'exam-mgmt' ) );
		}

		// Check file size (≤ 2MB)
		if ( $_FILES['csv_file']['size'] > 2 * 1024 * 1024 ) {
			wp_die( esc_html__( 'File too large (max 2MB).', 'exam-mgmt' ) );
		}

		$handle = fopen( $tmp_file, 'r' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'Could not open CSV file.', 'exam-mgmt' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! $header || count( $header ) < 4 ) {
			fclose( $handle );
			wp_die( esc_html__( 'Invalid CSV: expected at least 4 columns (student_id,exam_id,subject_id,marks).', 'exam-mgmt' ) );
		}

		// Normalize header: find required columns (case-insensitive, trim spaces)
		$required = [ 'student_id', 'exam_id', 'subject_id', 'marks' ];
		$col_map  = [];
		foreach ( $required as $col ) {
			$index = array_search( $col, array_map( 'strtolower', array_map( 'trim', $header ) ) );
			if ( $index === false ) {
				fclose( $handle );
				wp_die( sprintf(
					/* translators: %s: missing column name */
					esc_html__( 'Missing required column: "%s"', 'exam-mgmt' ),
					esc_html( $col )
				) );
			}
			$col_map[ $col ] = $index;
		}

		$success = 0;
		$errors  = [];

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) < 4 ) {
				continue; // skip incomplete rows
			}

			$student_id = isset( $row[ $col_map['student_id'] ] ) ? intval( $row[ $col_map['student_id'] ] ) : 0;
			$exam_id    = isset( $row[ $col_map['exam_id'] ] ) ? intval( $row[ $col_map['exam_id'] ] ) : 0;
			$subject_id = isset( $row[ $col_map['subject_id'] ] ) ? intval( $row[ $col_map['subject_id'] ] ) : 0;
			$marks      = isset( $row[ $col_map['marks'] ] ) ? intval( $row[ $col_map['marks'] ] ) : -1;

			// Validate IDs
			if ( ! $student_id || ! get_post( $student_id ) || get_post_type( $student_id ) !== 'em_student' ) {
				$errors[] = sprintf( 'Invalid student_id: %s', esc_html( $row[ $col_map['student_id'] ] ?? '' ) );
				continue;
			}
			if ( ! $exam_id || ! get_post( $exam_id ) || get_post_type( $exam_id ) !== 'em_exam' ) {
				$errors[] = sprintf( 'Invalid exam_id: %s', esc_html( $row[ $col_map['exam_id'] ] ?? '' ) );
				continue;
			}
			if ( ! $subject_id || ! get_post( $subject_id ) || get_post_type( $subject_id ) !== 'em_subject' ) {
				$errors[] = sprintf( 'Invalid subject_id: %s', esc_html( $row[ $col_map['subject_id'] ] ?? '' ) );
				continue;
			}
			if ( $marks < 0 || $marks > 100 ) {
				$errors[] = sprintf( 'Marks out of range (0–100): %d', $marks );
				continue;
			}

			// Find or create result post
			$existing = get_posts( [
				'post_type'  => 'em_result',
				'meta_query' => [
					'relation' => 'AND',
					[
						'key'   => 'em_student_id',
						'value' => $student_id,
					],
					[
						'key'   => 'em_exam_id',
						'value' => $exam_id,
					],
				],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			] );

			$result_id = $existing ? $existing[0] : wp_insert_post( [
				'post_type'   => 'em_result',
				'post_title'  => sprintf( 'Result: Student #%d, Exam #%d', $student_id, $exam_id ),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			] );

			if ( is_wp_error( $result_id ) ) {
				$errors[] = 'Failed to create result: ' . $result_id->get_error_message();
				continue;
			}

			// Update metadata
			update_post_meta( $result_id, 'em_student_id', $student_id );
			update_post_meta( $result_id, 'em_exam_id', $exam_id );

			$current_marks = get_post_meta( $result_id, 'em_subject_marks', true );
			$marks_arr     = $current_marks ? json_decode( $current_marks, true ) : [];
			if ( ! is_array( $marks_arr ) ) {
				$marks_arr = [];
			}
			$marks_arr[ $subject_id ] = $marks;
			update_post_meta( $result_id, 'em_subject_marks', wp_json_encode( $marks_arr ) );

			$success++;
		}

		fclose( $handle );
		@unlink( $tmp_file ); // cleanup

		if ( ! empty( $errors ) ) {
			$error_list = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $errors, 0, 20 ) ) ) . '</li></ul>';
			if ( count( $errors ) > 20 ) {
				$error_list .= '<p>' . sprintf(
					/* translators: %d: total errors */
					esc_html__( '... and %d more errors.', 'exam-mgmt' ),
					count( $errors ) - 20
				) . '</p>';
			}
			wp_die(
				'<h1>' . esc_html__( 'Import completed with errors', 'exam-mgmt' ) . '</h1>' .
				$error_list .
				'<p><a href="' . esc_url( admin_url( 'edit.php?post_type=em_result&page=em_import' ) ) . '" class="button">' .
				esc_html__( 'Back to Import', 'exam-mgmt' ) . '</a></p>',
				400
			);
		}

		wp_redirect( add_query_arg( 'success', '1', admin_url( 'edit.php?post_type=em_result&page=em_import' ) ) );
		exit;
	}

	/**
	 * Handle sample CSV download.
	 */
	public static function maybe_download_csv() {
		if ( isset( $_GET['em_action'] ) && $_GET['em_action'] === 'download_sample_csv' ) {
			self::download_sample_csv();
		}
	}

	/**
	 * Generate and download a sample CSV with real IDs from the current.
	 */
	private static function download_sample_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		// Fetch real data (limit to avoid overload)
		$students = get_posts( [
			'post_type'      => 'em_student',
			'numberposts'    => 10,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'cache_results'  => false,
		] );
		$exams = get_posts( [
			'post_type'      => 'em_exam',
			'numberposts'    => 5,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'cache_results'  => false,
		] );
		$subjects = get_posts( [
			'post_type'      => 'em_subject',
			'numberposts'    => 5,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'cache_results'  => false,
		] );

		// CSV content
		$output = "student_id,exam_id,subject_id,marks,student_name,exam_title,subject_name\n";

		// Generate 1–2 sample rows per student/exam/subject combo (max 12 rows)
		$rows = 0;
		foreach ( $students as $s ) {
			foreach ( $exams as $e ) {
				// Randomly pick 1–2 subjects per exam
				$selected_subjects = array_rand( $subjects, min( 2, count( $subjects ) ) );
				if ( ! is_array( $selected_subjects ) ) {
					$selected_subjects = [ $selected_subjects ];
				}
				foreach ( $selected_subjects as $idx ) {
					if ( $rows >= 12 ) {
						break 3;
					}
					$subj = $subjects[ $idx ];
					$mark = rand( 65, 98 );
					$output .= sprintf(
						'%d,%d,%d,%d,"%s","%s","%s"',
						$s->ID,
						$e->ID,
						$subj->ID,
						$mark,
						str_replace( '"', '""', $s->post_title ),
						str_replace( '"', '""', $e->post_title ),
						str_replace( '"', '""', $subj->post_title )
					) . "\n";
					$rows++;
				}
			}
		}

		// Fallback if no data
		if ( $rows === 0 ) {
			$output = "student_id,exam_id,subject_id,marks\n1,1,1,85\n2,1,1,92\n";
		}

		// Force download
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sample-results-real.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo $output;
		exit;
	}
}