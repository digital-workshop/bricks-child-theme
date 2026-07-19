<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared standalone-look design system for the SNN admin settings screens --
 * additive CSS only, layered on top of the existing functional markup those
 * pages already render. See includes/features/admin-ui-design.css.
 */
function snn_admin_ui_design_page_slugs() {
    return array(
        'snn-custom-post-types',
        'snn-custom-fields',
        'snn-taxonomies',
        'snn-security',
        'snn-interactions',
        'snn-other-settings',
        'editor-settings',
        'snn-mail-customizer',
        'snn-cookie-settings',
    );
}

function snn_enqueue_admin_ui_design() {
    // Matched against the admin.php?page=... query var directly rather than
    // get_current_screen()->id, since predicting WP's internal hook-suffix
    // naming for a submenu registered under a custom parent slug is fragile.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( ! in_array( $page, snn_admin_ui_design_page_slugs(), true ) ) {
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
