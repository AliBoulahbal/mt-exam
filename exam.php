<?php 
/**
 * Plugin Name: Exam Management
 * Plugin URI: https://example.com
 * Description: A WordPress plugin for screening senior developer applicants with custom post types for students, exams, results.
 * Version: 1.0.0
 * Author: Ali Boulahbal
 * Author URI: https://example.com
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'EM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load modules
require_once EM_PLUGIN_DIR . 'includes/class-em-term-meta.php';
require_once EM_PLUGIN_DIR . 'includes/class-em-exam-meta.php';
require_once EM_PLUGIN_DIR . 'includes/class-em-result-meta.php';
require_once EM_PLUGIN_DIR . 'includes/class-em-ajax.php';
require_once EM_PLUGIN_DIR . 'includes/class-em-shortcodes.php';
require_once EM_PLUGIN_DIR . 'includes/class-em-import.php';
require_once EM_PLUGIN_DIR . 'includes/class-em-report.php';

class EM_CPT {
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_cpts' ] );
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ] );
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );
	}

	public static function register_cpts() {
		register_post_type( 'em_student', [
			'labels' => [
				'name' => __( 'Students', 'exam-mgmt' ),
				'singular_name' => __( 'Student', 'exam-mgmt' ),
			],
			'public' => true,
			'supports' => [ 'title' ],
			'menu_icon' => 'dashicons-groups',
			'show_in_rest' => true,
		] );

		register_post_type( 'em_subject', [
			'labels' => [
				'name' => __( 'Subjects', 'exam-mgmt' ),
				'singular_name' => __( 'Subject', 'exam-mgmt' ),
			],
			'public' => true,
			'supports' => [ 'title' ],
			'menu_icon' => 'dashicons-book-alt',
			'show_in_rest' => true,
		] );

		register_post_type( 'em_exam', [
			'labels' => [
				'name' => __( 'Exams', 'exam-mgmt' ),
				'singular_name' => __( 'Exam', 'exam-mgmt' ),
			],
			'public' => true,
			'supports' => [ 'title' ],
			'menu_icon' => 'dashicons-book',
			'show_in_rest' => true,
		] );

		register_post_type( 'em_result', [
			'labels' => [
				'name' => __( 'Results', 'exam-mgmt' ),
				'singular_name' => __( 'Result', 'exam-mgmt' ),
			],
			'public' => true,
			'supports' => [ 'title' ],
			'menu_icon' => 'dashicons-performance',
			'show_in_rest' => true,
		] );
	}

	public static function register_taxonomy() {
		register_taxonomy( 'em_term', [ 'em_exam' ], [
			'labels' => [
				'name' => __( 'Terms', 'exam-mgmt' ),
				'singular_name' => __( 'Term', 'exam-mgmt' ),
			],
			'public' => true,
			'hierarchical' => false,
			'show_ui' => true,
			'show_in_rest' => true,
		] );
	}

	public static function maybe_flush_rewrite_rules() {
		if ( get_option( 'em_flushed' ) !== 'yes' ) {
			flush_rewrite_rules();
			update_option( 'em_flushed', 'yes' );
		}
	}
}


// Optimization: disable heavy processes during saving
function em_skip_heavy_during_save() {
	if ( 
		( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
		( isset( $_POST['action'] ) && in_array( $_POST['action'], [ 'editpost', 'autosave' ] ) )
	) {
		remove_action( 'admin_init', [ 'EM_Report', 'maybe_download_csv' ] );
	}
}
add_action( 'init', 'em_skip_heavy_during_save', 1 );

// Initialize
function em_init() {
	EM_CPT::init();
	EM_Term_Meta::init();
	EM_Exam_Meta::init();
	EM_Result_Meta::init();
	EM_AJAX::init();
	EM_Shortcodes::init();
	EM_Import::init();
	EM_Report::init();
}
add_action( 'plugins_loaded', 'em_init' );

// Assets
function em_admin_enqueue_styles() {
    wp_enqueue_style(
        'em-admin',
        EM_PLUGIN_URL . 'assets/admin.css',
        [],
        '1.0.0'
    );
}
add_action( 'admin_enqueue_scripts', 'em_admin_enqueue_styles' );


function em_enqueue_scripts() {
	if ( is_admin() ) {
		wp_enqueue_style( 'em-admin', EM_PLUGIN_URL . 'assets/admin.css', [], '1.0' );
	} else {
		if ( has_shortcode( get_queried_object()->post_content ?? '', 'em_top_students' ) ) {
			wp_enqueue_style( 'em-frontend', EM_PLUGIN_URL . 'assets/frontend.css', [], '1.0' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'em_enqueue_scripts' );