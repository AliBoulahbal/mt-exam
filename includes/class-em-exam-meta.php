<?php
class EM_Exam_Meta {
	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_em_exam', [ __CLASS__, 'save' ] );
	}

	public static function add_meta_boxes() {
		remove_meta_box( 'em_termdiv', 'em_exam', 'side' );

		add_meta_box( 'em_exam_details', __( 'Exam Details', 'exam-mgmt' ), [ __CLASS__, 'render_details' ], 'em_exam', 'normal', 'high' );
		add_meta_box( 'em_exam_term', __( 'Academic Term', 'exam-mgmt' ), [ __CLASS__, 'render_term' ], 'em_exam', 'side', 'core' );
	}

	public static function render_details( $post ) {
		wp_nonce_field( 'em_exam_nonce', 'em_exam_nonce' );

		$start = get_post_meta( $post->ID, 'em_start_datetime', true );
		$end = get_post_meta( $post->ID, 'em_end_datetime', true );
		$subject_ids = get_post_meta( $post->ID, 'em_subject_ids', true );
		$subject_ids = is_array( $subject_ids ) ? $subject_ids : [];

		$subjects = get_posts( [
			'post_type' => 'em_subject',
			'numberposts' => -1,
			'orderby' => 'title'
		] );
		?>
		<p>
			<label for="em_start_datetime"><?php _e( 'Start Datetime:', 'exam-mgmt' ); ?></label><br>
			<input type="datetime-local" id="em_start_datetime" name="em_start_datetime" value="<?php echo esc_attr( $start ); ?>" required>
		</p>
		<p>
			<label for="em_end_datetime"><?php _e( 'End Datetime:', 'exam-mgmt' ); ?></label><br>
			<input type="datetime-local" id="em_end_datetime" name="em_end_datetime" value="<?php echo esc_attr( $end ); ?>" required>
		</p>
		<p>
			<label for="em_subject_ids"><?php _e( 'Subjects:', 'exam-mgmt' ); ?></label><br>
			<select name="em_subject_ids[]" id="em_subject_ids" multiple style="width:100%;" required>
				<?php foreach ( $subjects as $s ) : ?>
					<option value="<?php echo esc_attr( $s->ID ); ?>" <?php echo in_array( $s->ID, $subject_ids ) ? 'selected' : ''; ?>>
						<?php echo esc_html( $s->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php _e( 'Hold Ctrl/Cmd to select multiple subjects.', 'exam-mgmt' ); ?></p>
		</p>
		<?php
	}

	public static function render_term( $post ) {
		wp_nonce_field( 'em_exam_term_nonce', 'em_exam_term_nonce' );

		$terms = wp_get_object_terms( $post->ID, 'em_term' );
		$current = $terms && ! is_wp_error( $terms ) ? $terms[0]->term_id : 0;

		$all_terms = get_terms( [
			'taxonomy' => 'em_term',
			'hide_empty' => false
		] );
		?>
		<select name="em_selected_term" id="em_selected_term" style="width:100%;">
			<option value=""><?php _e( '— Select Term —', 'exam-mgmt' ); ?></option>
			<?php foreach ( $all_terms as $t ) : ?>
				<option value="<?php echo esc_attr( $t->term_id ); ?>" <?php selected( $current, $t->term_id ); ?>>
					<?php echo esc_html( $t->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php _e( 'One term per exam.', 'exam-mgmt' ); ?></p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['em_exam_nonce'] ) || ! wp_verify_nonce( $_POST['em_exam_nonce'], 'em_exam_nonce' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

		if ( isset( $_POST['em_start_datetime'] ) ) {
			update_post_meta( $post_id, 'em_start_datetime', sanitize_text_field( $_POST['em_start_datetime'] ) );
		}
		if ( isset( $_POST['em_end_datetime'] ) ) {
			update_post_meta( $post_id, 'em_end_datetime', sanitize_text_field( $_POST['em_end_datetime'] ) );
		}

		if ( isset( $_POST['em_subject_ids'] ) && is_array( $_POST['em_subject_ids'] ) ) {
			$clean = array_map( 'intval', $_POST['em_subject_ids'] );
			$clean = array_filter( $clean );
			update_post_meta( $post_id, 'em_subject_ids', $clean );
		} else {
			delete_post_meta( $post_id, 'em_subject_ids' );
		}

		// Save term
		if ( isset( $_POST['em_exam_term_nonce'] ) && wp_verify_nonce( $_POST['em_exam_term_nonce'], 'em_exam_term_nonce' ) ) {
			$term_id = intval( $_POST['em_selected_term'] ?? 0 );
			wp_delete_object_term_relationships( $post_id, 'em_term' );
			if ( $term_id ) {
				wp_set_object_terms( $post_id, $term_id, 'em_term' );
			}
		}
	}
}