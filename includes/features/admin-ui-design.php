<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared standalone-look design system for the SNN admin builder screens
 * (Post Types, Custom Fields, Taxonomies) -- additive CSS only, layered on
 * top of the existing functional markup those pages already render. See
 * includes/features/admin-ui-design.css.
 */
function snn_admin_ui_design_screen_ids() {
    return array(
        'snn-settings_page_snn-custom-post-types',
        'snn-settings_page_snn-custom-fields',
        'snn-settings_page_snn-taxonomies',
    );
}

function snn_enqueue_admin_ui_design() {
    $current_screen = get_current_screen();
    if ( ! $current_screen || ! in_array( $current_screen->id, snn_admin_ui_design_screen_ids(), true ) ) {
        return;
    }

    $css_path = SNN_PATH . 'includes/features/admin-ui-design.css';
    wp_enqueue_style(
        'snn-admin-ui-design',
        get_stylesheet_directory_uri() . '/includes/features/admin-ui-design.css',
        array(),
        file_exists( $css_path ) ? filemtime( $css_path ) : false
    );
}
add_action( 'admin_enqueue_scripts', 'snn_enqueue_admin_ui_design' );
