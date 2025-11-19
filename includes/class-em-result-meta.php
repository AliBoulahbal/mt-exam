<?php
/**
 * Result Meta Box
 *
 * @package Exam Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EM_Result_Meta {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'save_post_em_result', [ __CLASS__, 'save_meta' ], 10, 1 );
		add_action( 'wp_ajax_em_get_exam_subjects', [ __CLASS__, 'ajax_get_exam_subjects' ] );
	}

	public static function add_meta_box() {
		add_meta_box(
			'em_result_meta',
			__( 'Result Details', 'exam-mgmt' ),
			[ __CLASS__, 'render' ],
			'em_result',
			'normal',
			'high'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( 'em_result_nonce', 'em_result_nonce' );

		$student_id = get_post_meta( $post->ID, 'em_student_id', true );
		$exam_id    = get_post_meta( $post->ID, 'em_exam_id', true );
		$marks_json = get_post_meta( $post->ID, 'em_subject_marks', true );
		$marks      = $marks_json ? json_decode( $marks_json, true ) : [];

		$students = get_posts( [
			'post_type'   => 'em_student',
			'numberposts' => -1,
			'orderby'     => 'title',
		] );
		$exams    = get_posts( [
			'post_type'   => 'em_exam',
			'numberposts' => -1,
			'orderby'     => 'title',
		] );
		?>
		<div class="em-section">
			<h3><?php _e( 'Student & Exam', 'exam-mgmt' ); ?></h3>
			<p>
				<label for="em_student_id"><?php _e( 'Student:', 'exam-mgmt' ); ?></label><br>
				<select name="em_student_id" id="em_student_id" required>
					<option value=""><?php _e( '— Select Student —', 'exam-mgmt' ); ?></option>
					<?php foreach ( $students as $s ) : ?>
						<option value="<?php echo esc_attr( $s->ID ); ?>" <?php selected( $student_id, $s->ID ); ?>>
							<?php echo esc_html( $s->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="em_exam_id"><?php _e( 'Exam:', 'exam-mgmt' ); ?></label><br>
				<select name="em_exam_id" id="em_exam_id" required>
					<option value=""><?php _e( '— Select Exam —', 'exam-mgmt' ); ?></option>
					<?php foreach ( $exams as $e ) : ?>
						<option value="<?php echo esc_attr( $e->ID ); ?>" <?php selected( $exam_id, $e->ID ); ?>>
							<?php echo esc_html( $e->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>

		<div class="em-section">
			<h3><?php _e( 'Subject Marks', 'exam-mgmt' ); ?></h3>
			<div id="em_subject_marks_container">
				<?php if ( $exam_id ) : ?>
					<?php self::render_marks_fields( $exam_id, $marks ); ?>
				<?php else : ?>
					<p class="description"><?php _e( 'Select an exam to load subjects and enter marks.', 'exam-mgmt' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#em_exam_id').on('change', function() {
				const examId = $(this).val();
				if (!examId) {
					$('#em_subject_marks_container').html(
						'<p class="description"><?php echo esc_js(__('Select an exam to load subjects and enter marks.', 'exam-mgmt')); ?></p>'
					);
					return;
				}
				$.post(ajaxurl, {
					action: 'em_get_exam_subjects',
					exam_id: examId,
					nonce: '<?php echo wp_create_nonce("em_get_exam_subjects"); ?>'
				}, function(response) {
					if (response.success && response.data) {
						$('#em_subject_marks_container').html(response.data);
					} else {
						$('#em_subject_marks_container').html(
							'<div class="em-error"><?php echo esc_js(__('Failed to load subjects. Please ensure the exam has subjects assigned.', 'exam-mgmt')); ?></div>'
						);
					}
				});
			});

			
			if ($('#em_exam_id').val()) {
				$('#em_exam_id').trigger('change');
			}
		});
		</script>
		<?php
	}

	private static function render_marks_fields( $exam_id, $marks ) {
		$subject_ids = get_post_meta( $exam_id, 'em_subject_ids', true );
		if ( ! is_array( $subject_ids ) || empty( $subject_ids ) ) {
			echo '<div class="em-error">' . __( 'This exam has no subjects assigned. Please edit the exam and add subjects.', 'exam-mgmt' ) . '</div>';
			return;
		}

		foreach ( $subject_ids as $subj_id ) {
			$subject = get_post( $subj_id );
			if ( ! $subject ) continue;

			$current_mark = isset( $marks[ $subj_id ] ) ? intval( $marks[ $subj_id ] ) : '';

			echo '<h4>' . esc_html( $subject->post_title ) . '</h4>';
			echo '<p><label>' . __( 'Marks (out of 100):', 'exam-mgmt' ) . '</label><br>';
			echo '<input type="number" name="em_subject_marks[' . esc_attr( $subj_id ) . ']" ';
			echo 'value="' . esc_attr( $current_mark ) . '" min="0" max="100" required ';
			echo 'style="width: 90px; text-align: right; font-family: monospace;"';
			echo '> / 100</p>';
		}
	}

	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['em_result_nonce'] ) || ! wp_verify_nonce( $_POST['em_result_nonce'], 'em_result_nonce' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$student_id = intval( $_POST['em_student_id'] ?? 0 );
		$exam_id    = intval( $_POST['em_exam_id'] ?? 0 );

		// Save relationships
		update_post_meta( $post_id, 'em_student_id', $student_id );
		update_post_meta( $post_id, 'em_exam_id', $exam_id );

		// Save marks
		$clean_marks = [];
		if ( isset( $_POST['em_subject_marks'] ) && is_array( $_POST['em_subject_marks'] ) ) {
			foreach ( $_POST['em_subject_marks'] as $subj_id => $mark ) {
				$subj_id = intval( $subj_id );
				$mark    = intval( $mark );
				if ( $subj_id > 0 && $mark >= 0 && $mark <= 100 ) {
					$clean_marks[ $subj_id ] = $mark;
				}
			}
		}
		update_post_meta( $post_id, 'em_subject_marks', wp_json_encode( $clean_marks ) );

		//  Generate clean, readable title
		$title = 'Result';
		if ( $student_id ) {
			$student = get_post( $student_id );
			$title .= ': ' . ( $student ? $student->post_title : 'Student #' . $student_id );
		}
		if ( $exam_id ) {
			$exam = get_post( $exam_id );
			if ( $exam ) {
				$title .= ' — ' . $exam->post_title;
				$terms = wp_get_object_terms( $exam_id, 'em_term', [ 'fields' => 'names' ] );
				if ( ! empty( $terms ) ) {
					$title .= ' (' . $terms[0] . ')';
				}
			} else {
				$title .= ' (Exam #' . $exam_id . ')';
			}
		}

		// Update title (without triggering extra hooks)
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_title' => $title ],
			[ 'ID' => $post_id ],
			[ '%s' ],
			[ '%d' ]
		);

		// Invalidate cache after save
		delete_transient( 'em_top_students_cache' );
	}

	public static function ajax_get_exam_subjects() {
		check_ajax_referer( 'em_get_exam_subjects' );

		$exam_id = intval( $_POST['exam_id'] );
		if ( ! $exam_id ) {
			wp_send_json_error( 'Invalid exam ID' );
		}

		ob_start();
		self::render_marks_fields( $exam_id, [] );
		$html = ob_get_clean();

		wp_send_json_success( $html );
	}
}