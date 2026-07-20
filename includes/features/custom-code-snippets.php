<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

//  define( 'SNN_CODE_DISABLE', true );

define( 'SNN_CUSTOM_CODES_LOG_OPTION', 'snn_custom_codes_error_log' );
define( 'SNN_CUSTOM_CODES_MAX_LOG_ENTRIES', 150 );
define( 'SNN_FATAL_ERROR_NOTICE_TRANSIENT', 'snn_fatal_error_admin_notice' );
define( 'SNN_SNIPPET_EXECUTING_MARKER_OPTION', 'snn_snippet_currently_executing' );
define( 'SNN_SNIPPETS_MIGRATED_OPTION', 'snn_snippets_migrated_v2' );
// Bump whenever snn_snippets_compile_location()'s output format or logic
// changes, so every site self-heals its stale compiled files on the next
// request instead of silently keeping the old (possibly buggy) ones around.
define( 'SNN_SNIPPETS_COMPILER_VERSION', 2 );
// Legacy option names, only read during one-time migration (see snn_migrate_legacy_snippets()).
define( 'SNN_ADVANCED_CODE_ENABLED_OPTION', 'snn_advanced_raw_code_enabled' );
define( 'SNN_ADVANCED_CODE_CONTENT_OPTION', 'snn_advanced_raw_code_content' );
define( 'SNN_LEGACY_GLOBAL_ENABLED_OPTION', 'snn_codes_snippets_enabled' );

/**
 * Register the Custom Post Type for Code Snippets.
 */
function snn_custom_codes_snippets_register_cpt() {
    $labels = array(
        'name'               => _x( 'Code Snippets', 'post type general name', 'snn' ),
        'singular_name'      => _x( 'Code Snippet', 'post type singular name', 'snn' ),
        'all_items'          => __( 'All Code Snippets', 'snn' ),
        'edit_item'          => __( 'Edit Code Snippet', 'snn' ),
        'new_item'           => __( 'New Code Snippet', 'snn' ),
        'view_item'          => __( 'View Code Snippet', 'snn' ),
        'search_items'       => __( 'Search Code Snippets', 'snn' ),
        'not_found'          => __( 'No code snippets found', 'snn' ),
        'not_found_in_trash' => __( 'No code snippets found in Trash', 'snn' ),
        'revisions'          => __( 'Revisions', 'snn' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => false,
        'show_in_menu'       => false,
        'query_var'          => false,
        'rewrite'            => false,
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'hierarchical'       => false,
        'supports'           => array( 'title', 'editor', 'revisions' ),
        'has_archive'        => false,
        'show_in_rest'       => false,
    );
    register_post_type( 'snn_code_snippet', $args );
}
add_action( 'init', 'snn_custom_codes_snippets_register_cpt' );

/**
 * Add the submenu page for managing snippets.
 */
function snn_custom_codes_snippets_add_submenu() {
    add_submenu_page(
        'snn-settings',
        __( 'Code Snippets', 'snn' ),
        __( 'Code Snippets', 'snn' ),
        'manage_options',
        'snn-custom-codes-snippets',
        'snn_custom_codes_snippets_page'
    );
}
add_action( 'admin_menu', 'snn_custom_codes_snippets_add_submenu', 10 );

// ----------------------------------------------------------------------
// Type / location definitions and per-snippet meta helpers
// ----------------------------------------------------------------------

function snn_snippet_get_type_defs() {
    return array(
        'php'  => array( 'label' => __( 'PHP', 'snn' ), 'badge_color' => '#8b5cf6', 'cm_mode' => 'application/x-httpd-php' ),
        'html' => array( 'label' => __( 'HTML', 'snn' ), 'badge_color' => '#f97316', 'cm_mode' => 'htmlmixed' ),
        'css'  => array( 'label' => __( 'CSS', 'snn' ), 'badge_color' => '#3b82f6', 'cm_mode' => 'text/css' ),
        'js'   => array( 'label' => __( 'JS', 'snn' ), 'badge_color' => '#eab308', 'cm_mode' => 'text/javascript' ),
    );
}

function snn_snippet_get_location_defs() {
    return array(
        'frontend_head'   => __( 'Frontend Head', 'snn' ),
        'frontend_footer' => __( 'Frontend Footer', 'snn' ),
        'admin_head'      => __( 'Admin Head', 'snn' ),
        'immediate'       => __( 'Everywhere (Immediate)', 'snn' ),
    );
}

function snn_snippet_get_meta( $post_id ) {
    $type_defs     = snn_snippet_get_type_defs();
    $location_defs = snn_snippet_get_location_defs();

    $type = get_post_meta( $post_id, '_snn_snippet_type', true );
    if ( ! isset( $type_defs[ $type ] ) ) {
        $type = 'php';
    }

    $location = get_post_meta( $post_id, '_snn_snippet_location', true );
    if ( ! isset( $location_defs[ $location ] ) ) {
        $location = 'frontend_head';
    }

    $priority = get_post_meta( $post_id, '_snn_snippet_priority', true );
    $priority = ( $priority === '' || $priority === false ) ? 10 : (int) $priority;

    $tags_raw = get_post_meta( $post_id, '_snn_snippet_tags', true );
    $tags     = $tags_raw ? array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) : array();

    $allow_output = (bool) get_post_meta( $post_id, '_snn_snippet_allow_output', true );

    return array(
        'type'         => $type,
        'location'     => $location,
        'priority'     => $priority,
        'tags'         => $tags,
        'tags_raw'     => $tags_raw,
        'allow_output' => $allow_output,
    );
}

function snn_snippet_save_meta( $post_id, $data ) {
    $type_defs     = snn_snippet_get_type_defs();
    $location_defs = snn_snippet_get_location_defs();

    $type = isset( $data['type'] ) && isset( $type_defs[ $data['type'] ] ) ? $data['type'] : 'php';
    update_post_meta( $post_id, '_snn_snippet_type', $type );

    $location = isset( $data['location'] ) && isset( $location_defs[ $data['location'] ] ) ? $data['location'] : 'frontend_head';
    update_post_meta( $post_id, '_snn_snippet_location', $location );

    $priority = isset( $data['priority'] ) ? max( 1, min( 999, (int) $data['priority'] ) ) : 10;
    update_post_meta( $post_id, '_snn_snippet_priority', $priority );

    $tags_raw = isset( $data['tags'] ) ? sanitize_text_field( $data['tags'] ) : '';
    update_post_meta( $post_id, '_snn_snippet_tags', $tags_raw );

    update_post_meta( $post_id, '_snn_snippet_allow_output', ! empty( $data['allow_output'] ) ? 1 : 0 );
}

/**
 * Query all active (published) snippets for a given location, sorted by priority.
 */
function snn_snippets_query_by_location( $location ) {
    return get_posts( array(
        'post_type'        => 'snn_code_snippet',
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'suppress_filters' => true,
        'meta_query'       => array(
            'relation'   => 'AND',
            'loc_clause' => array( 'key' => '_snn_snippet_location', 'value' => $location ),
            'pri_clause' => array( 'key' => '_snn_snippet_priority', 'type' => 'NUMERIC' ),
        ),
        'orderby' => array( 'pri_clause' => 'ASC', 'title' => 'ASC' ),
    ) );
}

// ----------------------------------------------------------------------
// One-time migration of the legacy 4-fixed-slot + Advanced Raw Code model
// ----------------------------------------------------------------------

function snn_get_legacy_snippet_id( $slug ) {
    $ids = get_posts( array(
        'post_type'        => 'snn_code_snippet',
        'name'             => $slug,
        'posts_per_page'   => 1,
        'post_status'      => 'private',
        'fields'           => 'ids',
        'suppress_filters' => true,
    ) );
    return ! empty( $ids ) ? $ids[0] : 0;
}

function snn_migrate_legacy_snippets() {
    if ( get_option( SNN_SNIPPETS_MIGRATED_OPTION ) ) {
        return;
    }

    $legacy_map = array(
        'snn-snippet-frontend-head' => array( 'title' => 'Frontend Head PHP/HTML', 'location' => 'frontend_head' ),
        'snn-snippet-footer'        => array( 'title' => 'Frontend Footer PHP/HTML', 'location' => 'frontend_footer' ),
        'snn-snippet-admin-head'    => array( 'title' => 'Admin Head PHP/HTML', 'location' => 'admin_head' ),
        'snn-snippet-functions-php' => array( 'title' => 'PHP (functions.php)', 'location' => 'immediate' ),
    );

    $globally_enabled = (bool) get_option( SNN_LEGACY_GLOBAL_ENABLED_OPTION, 0 );

    foreach ( $legacy_map as $slug => $info ) {
        $post_id = snn_get_legacy_snippet_id( $slug );
        if ( ! $post_id ) {
            continue;
        }

        $content = get_post_field( 'post_content', $post_id );
        if ( trim( (string) $content ) === '' ) {
            wp_delete_post( $post_id, true ); // Empty legacy stub, nothing to keep.
            continue;
        }

        wp_update_post( array(
            'ID'          => $post_id,
            'post_status' => $globally_enabled ? 'publish' : 'draft',
        ) );
        snn_snippet_save_meta( $post_id, array(
            'type'     => 'php',
            'location' => $info['location'],
            'priority' => 10,
            'tags'     => 'migrated',
        ) );
    }

    // Advanced Raw Code was a wp_option, not a CPT post -- migrate it into one.
    $advanced_enabled = (bool) get_option( SNN_ADVANCED_CODE_ENABLED_OPTION, 0 );
    $advanced_code    = get_option( SNN_ADVANCED_CODE_CONTENT_OPTION, '' );
    if ( is_string( $advanced_code ) && trim( $advanced_code ) !== '' ) {
        $new_id = wp_insert_post( array(
            'post_type'    => 'snn_code_snippet',
            'post_title'   => 'Advanced Code (functions.php)',
            'post_content' => $advanced_code,
            'post_status'  => ( $advanced_enabled && $globally_enabled ) ? 'publish' : 'draft',
        ), true );
        if ( ! is_wp_error( $new_id ) ) {
            snn_snippet_save_meta( $new_id, array(
                'type'     => 'php',
                'location' => 'immediate',
                'priority' => 20,
                'tags'     => 'migrated,advanced',
            ) );
        }
    }

    update_option( SNN_SNIPPETS_MIGRATED_OPTION, 1 );
}
add_action( 'admin_init', 'snn_migrate_legacy_snippets', 5 );

// ----------------------------------------------------------------------
// Asset enqueueing (CodeMirror, list/edit screen JS)
// ----------------------------------------------------------------------

function snn_custom_codes_snippets_enqueue_assets( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'snn-custom-codes-snippets' ) {
        return;
    }

    $cm_settings = wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );
    if ( false === $cm_settings ) {
        wp_enqueue_script( 'jquery' );
        return;
    }

    wp_enqueue_script( 'wp-theme-plugin-editor' );
    wp_enqueue_style( 'wp-codemirror' );
    wp_enqueue_style( 'dashicons' );

    wp_add_inline_script(
        'wp-theme-plugin-editor',
        sprintf(
            'jQuery( function( $ ) {
                var editorSettings = %s;
                $( "#snn_snippet_code" ).each( function() {
                    if (wp && wp.codeEditor) {
                        wp.codeEditor.initialize( this, editorSettings );
                    } else {
                        $(this).css({"font-family": "monospace", "font-size": "13px", "border": "1px solid #ddd", "width": "100%%", "padding": "10px"});
                    }
                });
            } );',
            wp_json_encode( $cm_settings )
        )
    );

    $ajax_nonce = wp_create_nonce( 'snn_preview_revision_nonce' );
    $js_for_revisions = "
jQuery(document).ready(function($) {
    var snn_revisions_vars = {
        ajax_url: '" . esc_url( admin_url( 'admin-ajax.php' ) ) . "',
        nonce: '" . esc_js( $ajax_nonce ) . "',
        loading_text: '" . esc_js( __( 'Loading...', 'snn' ) ) . "',
        error_text: '" . esc_js( __( 'Error', 'snn' ) ) . "',
        ajax_error_text: '" . esc_js( __( 'AJAX error fetching revision.', 'snn' ) ) . "',
        confirm_restore_text: '" . esc_js( __( 'Are you sure you want to restore this revision and save? The current content in the editor will be overwritten, saved, and then executed. This could break your site if the revision contains errors.', 'snn' ) ) . "',
        confirm_clear_revisions_text: '" . esc_js( __( 'Are you absolutely sure you want to delete all revisions for this snippet? This action cannot be undone.', 'snn' ) ) . "',
        confirm_clear_logs_text: '" . esc_js( __( 'Are you absolutely sure you want to delete all error logs? This action cannot be undone.', 'snn' ) ) . "'
    };

    $('body').on('click', '.snn-preview-revision', function(e) {
        e.preventDefault();
        var revisionId = $(this).data('revision-id');
        var button = $(this);
        var originalButtonText = button.text();
        var activeEditorTextareaId = $('.snn-revisions-panel').data('active-editor-id');
        if (!activeEditorTextareaId) { return; }

        var editorTextarea = $('#' + activeEditorTextareaId);
        var cmInstance = null;
        if (editorTextarea.length) {
            if (editorTextarea.get(0).CodeMirror) {
                cmInstance = editorTextarea.get(0).CodeMirror;
            } else if (editorTextarea.next('.CodeMirror').get(0) && editorTextarea.next('.CodeMirror').get(0).CodeMirror) {
                cmInstance = editorTextarea.next('.CodeMirror').get(0).CodeMirror;
            }
        }

        button.prop('disabled', true).text(snn_revisions_vars.loading_text);
        $.ajax({
            url: snn_revisions_vars.ajax_url, type: 'POST',
            data: { action: 'snn_get_revision_content', revision_id: revisionId, nonce: snn_revisions_vars.nonce },
            success: function(response) {
                if (response.success) {
                    if (cmInstance) { cmInstance.setValue(response.data.content); cmInstance.refresh(); }
                    else { editorTextarea.val(response.data.content); }
                } else {
                    alert(snn_revisions_vars.error_text + ': ' + (response.data.message || snn_revisions_vars.ajax_error_text));
                }
            },
            error: function() { alert(snn_revisions_vars.ajax_error_text); },
            complete: function() { button.prop('disabled', false).text(originalButtonText); }
        });
    });

    $('body').on('click', '.snn-restore-revision-button', function(e) {
        if (!confirm(snn_revisions_vars.confirm_restore_text)) { e.preventDefault(); }
    });

    $('body').on('click', '.snn-preview-revision', function() {
        $('.snn-restore-revision-button').hide();
        $(this).closest('li').find('.snn-restore-revision-button').show();
    });

    $('body').on('click', '.snn-clear-revisions-button', function(e) {
        if (!confirm(snn_revisions_vars.confirm_clear_revisions_text)) { e.preventDefault(); }
    });

    $('body').on('click', '.snn-clear-error-logs-button', function(e) {
        if (!confirm(snn_revisions_vars.confirm_clear_logs_text)) { e.preventDefault(); }
    });

    $('body').on('click', '.snn-dismiss-fatal-notice', function(e) {
        e.preventDefault();
        var \$button = \$(this);
        $.ajax({
            url: snn_revisions_vars.ajax_url, type: 'POST',
            data: { action: 'snn_dismiss_fatal_error_notice', nonce: '" . esc_js( wp_create_nonce( 'snn_dismiss_fatal_notice_nonce' ) ) . "' },
            success: function(response) { if (response.success) { \$button.closest('.notice-error.snn-fatal-error-notice').fadeOut(); } },
            error: function() { alert('AJAX error dismissing notice.'); }
        });
    });
});
";
    wp_add_inline_script( 'wp-theme-plugin-editor', $js_for_revisions );
}
add_action( 'admin_enqueue_scripts', 'snn_custom_codes_snippets_enqueue_assets' );

/**
 * Admin CSS for the snippets pages (list table + edit screen + error logs).
 */
function snn_custom_codes_snippets_admin_styles() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'snn-custom-codes-snippets' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <style>
        h3{margin-top:10px}
        .CodeMirror { min-height: 600px !important; border: 1px solid #ddd; }
        .snn-snippet-nav-tab-wrapper { margin-bottom: 15px; }
        .form-table th { width: 200px; }

        .snn-snippets-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; }
        .snn-snippets-header h1 { margin: 0; }
        .snn-snippets-header-actions { display: flex; align-items: center; gap: 8px; }
        #snn-snippets-search { min-width: 220px; }

        .snn-snippets-table th, .snn-snippets-table td { vertical-align: middle; }
        .snn-snippets-table .row-actions { visibility: hidden; }
        .snn-snippets-table tr:hover .row-actions { visibility: visible; }

        .snn-toggle-switch { position: relative; display: inline-block; width: 38px; height: 22px; }
        .snn-toggle-switch input { opacity: 0; width: 0; height: 0; }
        .snn-toggle-slider { position: absolute; cursor: pointer; inset: 0; background-color: #ccc; transition: .2s; border-radius: 22px; }
        .snn-toggle-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: #fff; transition: .2s; border-radius: 50%; }
        .snn-toggle-switch input:checked + .snn-toggle-slider { background-color: #2271b1; }
        .snn-toggle-switch input:checked + .snn-toggle-slider:before { transform: translateX(16px); }

        .snn-type-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; color: #fff; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
        .snn-no-snippets td { padding: 30px 10px !important; text-align: center; color: #646970; }

        .snn-modal { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100000; display: flex; align-items: center; justify-content: center; }
        .snn-modal-inner { background: #fff; padding: 20px; border-radius: 4px; width: 600px; max-width: 92vw; }
        .snn-modal-inner textarea { width: 100%; font-family: monospace; font-size: 12px; }

        .snn-editor-revision-wrapper { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 5px; }
        .snn-editor-area { flex: 3; min-width: 380px; position: relative; }
        .snn-revisions-panel { flex: 1; min-width: 300px; max-width: 360px; border-left: 1px solid #ccd0d4; padding-left: 20px; }
        .snn-revisions-panel-inner { max-height: 680px; overflow-y: auto; padding-right: 10px; }
        .snn-revisions-list { list-style: none; margin: 0; padding: 0; }
        .snn-revisions-list li { margin-bottom: 0; padding-bottom: 5px; border-bottom: 1px solid #eee; }
        .snn-revisions-list li:last-child { border-bottom: none; }
        .snn-revisions-list .revision-info { display: block; font-size: .9em; color: #555; margin-bottom: 8px; }
        .snn-revisions-list .revision-actions button { margin-right: 5px; margin-top: 5px; vertical-align: middle; }
        .snn-revisions-panel h4 { margin-top: 0; font-size: 1.1em; }
        .snn-php-execution-warning { border-left-width: 4px; margin-top: 15px; margin-bottom: 15px; }
        .snn-manage-revisions-section { margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px; }

        .snn-error-logs-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        .snn-error-logs-table th, .snn-error-logs-table td { border: 1px solid #ddd; padding: 10px !important; text-align: left; vertical-align: top; }
        .snn-error-logs-table th { background-color: #f0f0f1; font-weight: 600; position: sticky; top: 0; }
        .snn-error-logs-table td pre { white-space: pre-wrap; word-wrap: break-word; margin: 0; font-size: 12px; font-family: "Courier New", Courier, monospace; }
        .snn-error-logs-table .snn-log-message { max-width: 400px; overflow-wrap: break-word; }
        .snn-error-logs-table tr:hover { background-color: #f9f9f9; }
        .snn-error-logs-table details { cursor: pointer; }
        .snn-error-logs-table details summary { color: #2271b1; font-weight: 500; }

        .snn-fatal-error-notice strong { color: #dc3232; }
        .snn-fatal-error-notice code { background: #f9f9f9; border: 1px solid #ddd; padding: 2px 4px; font-size: .9em; display: block; white-space: pre-wrap; word-break: break-all; }
    </style>
    <?php
}
add_action( 'admin_head', 'snn_custom_codes_snippets_admin_styles' );

// ----------------------------------------------------------------------
// Validation / context / logging helpers (unchanged logic)
// ----------------------------------------------------------------------

function snn_validate_php_syntax( $code ) {
    if ( empty( trim( $code ) ) ) {
        return true;
    }

    $code_to_check = "<?php\n" . $code;

    $old_error_level = error_reporting( 0 );
    $tokens = @token_get_all( $code_to_check );
    error_reporting( $old_error_level );

    $last_error = error_get_last();
    if ( $last_error && ( $last_error['type'] === E_PARSE || $last_error['type'] === E_COMPILE_ERROR ) ) {
        @error_clear_last();

        $error_line = 0;
        if ( preg_match( '/on line (\d+)/', $last_error['message'], $matches ) ) {
            $error_line = max( 0, intval( $matches[1] ) - 1 );
        }

        return array(
            'message'      => $last_error['message'],
            'line'         => $error_line,
            'code_context' => snn_get_code_context( $code, $error_line ),
        );
    }

    return true;
}

function snn_get_code_context( $code, $error_line, $context_lines = 3 ) {
    $lines = explode( "\n", $code );
    $start = max( 0, $error_line - $context_lines - 1 );
    $end   = min( count( $lines ), $error_line + $context_lines );

    $context = array();
    for ( $i = $start; $i < $end; $i++ ) {
        $marker = ( $i === $error_line - 1 ) ? ' >>> ' : '     ';
        $context[] = sprintf( '%s%4d: %s', $marker, $i + 1, $lines[ $i ] );
    }

    return implode( "\n", $context );
}

function snn_get_function_context( $code, $error_line ) {
    $lines = explode( "\n", $code );
    $function_name = '';

    for ( $i = min( $error_line - 1, count( $lines ) - 1 ); $i >= 0; $i-- ) {
        if ( preg_match( '/function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/', $lines[ $i ], $matches ) ) {
            $function_name = $matches[1];
            break;
        }
        if ( preg_match( '/(?:public|private|protected|static)?\s*function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/', $lines[ $i ], $matches ) ) {
            $function_name = $matches[1];
            break;
        }
        if ( preg_match( '/add_(?:action|filter)\s*\(\s*[\'"]([^\'\"]+)[\'"]/', $lines[ $i ], $matches ) ) {
            return 'Hook: ' . $matches[1];
        }
    }

    return $function_name ? 'Function: ' . $function_name : '';
}

function snn_log_error_event( $type, $message, $snippet_title, $file = '', $line = 0, $code_context = '', $function_context = '' ) {
    $logs = get_option( SNN_CUSTOM_CODES_LOG_OPTION, array() );
    if ( ! is_array( $logs ) ) {
        $logs = array();
    }

    $log_entry = array(
        'timestamp'        => current_time( 'mysql' ),
        'type'             => sanitize_text_field( $type ),
        'message'          => wp_strip_all_tags( $message ),
        'snippet_title'    => sanitize_text_field( $snippet_title ),
        'file'             => sanitize_text_field( $file ),
        'line'             => absint( $line ),
        'code_context'     => $code_context ? substr( $code_context, 0, 2000 ) : '',
        'function_context' => sanitize_text_field( $function_context ),
    );

    array_unshift( $logs, $log_entry );

    if ( count( $logs ) > SNN_CUSTOM_CODES_MAX_LOG_ENTRIES ) {
        $logs = array_slice( $logs, 0, SNN_CUSTOM_CODES_MAX_LOG_ENTRIES );
    }

    update_option( SNN_CUSTOM_CODES_LOG_OPTION, $logs );
}

// ----------------------------------------------------------------------
// Fatal-error execution marker (identifies which snippet is mid-eval)
// ----------------------------------------------------------------------

function snn_snippet_set_executing_marker( $id ) {
    update_option( SNN_SNIPPET_EXECUTING_MARKER_OPTION, $id, false );
}
function snn_snippet_clear_executing_marker() {
    delete_option( SNN_SNIPPET_EXECUTING_MARKER_OPTION );
}

// ----------------------------------------------------------------------
// PHP execution (eval-based, unchanged core mechanism, now per-snippet-ID aware)
// ----------------------------------------------------------------------

function snn_execute_php_snippet( $code_to_execute, $snippet_id, $snippet_title, $location = 'frontend_head' ) {
    if ( empty( trim( $code_to_execute ) ) ) {
        return '';
    }

    $validation_result = snn_validate_php_syntax( $code_to_execute );
    if ( is_array( $validation_result ) ) {
        if ( $snippet_id ) {
            wp_update_post( array( 'ID' => $snippet_id, 'post_status' => 'draft' ) );
        }

        snn_log_error_event(
            'PHP Syntax Validation Error',
            $validation_result['message'],
            $snippet_title,
            'Pre-execution validation',
            $validation_result['line'],
            $validation_result['code_context'],
            snn_get_function_context( $code_to_execute, $validation_result['line'] )
        );

        set_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT, array(
            'message'    => $validation_result['message'],
            'file'       => 'Snippet: ' . $snippet_title . ' (Line ' . $validation_result['line'] . ')',
            'line'       => $validation_result['line'],
            'type'       => 'Syntax Validation Error',
            'snippet_id' => $snippet_id,
        ), DAY_IN_SECONDS );

        return '';
    }

    $error_occurred = false;

    set_error_handler( function( $errno, $errstr, $errfile, $errline ) use ( &$error_occurred, $snippet_title, $code_to_execute ) {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            if ( $errno === E_DEPRECATED || $errno === E_USER_DEPRECATED || $errno === E_STRICT ) {
                return true;
            }
        }

        $error_occurred = true;
        $error_type_str = 'PHP Error';
        switch ( $errno ) {
            case E_WARNING: case E_USER_WARNING: $error_type_str = 'PHP Warning'; break;
            case E_NOTICE: case E_USER_NOTICE: $error_type_str = 'PHP Notice'; break;
            case E_DEPRECATED: case E_USER_DEPRECATED: $error_type_str = 'PHP Deprecated'; break;
            case E_STRICT: $error_type_str = 'PHP Strict'; break;
        }

        $code_context = snn_get_code_context( $code_to_execute, $errline );
        $function_context = snn_get_function_context( $code_to_execute, $errline );

        snn_log_error_event( $error_type_str, $errstr, $snippet_title, 'eval()\'d code (runtime)', $errline, $code_context, $function_context );
        return true;
    } );

    ob_start();
    snn_snippet_set_executing_marker( $snippet_id );

    try {
        if ( 'immediate' === $location ) {
            // "Everywhere (Immediate)" is functions.php-style: the snippet is
            // bare PHP statements with no surrounding tags (e.g. "add_action(...);"),
            // exactly like code you'd paste directly into functions.php.
            @eval( $code_to_execute );
        } else {
            // Other locations are template-style: content starts in HTML/output
            // mode, like a normal .php file, and only runs as PHP inside explicit
            // PHP tags. The leading close-tag prefix achieves that.
            @eval( "?>" . $code_to_execute );
        }
        snn_snippet_clear_executing_marker();
    } catch ( ParseError $e ) {
        snn_snippet_clear_executing_marker();
        $error_occurred = true;
        $error_line = $e->getLine();
        $code_context = snn_get_code_context( $code_to_execute, $error_line );
        $function_context = snn_get_function_context( $code_to_execute, $error_line );

        snn_log_error_event( 'PHP Parse Error', $e->getMessage(), $snippet_title, 'eval()\'d code', $error_line, $code_context, $function_context );

        if ( $snippet_id ) {
            wp_update_post( array( 'ID' => $snippet_id, 'post_status' => 'draft' ) );
        }

        set_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT, array(
            'message'    => $e->getMessage() . ( $function_context ? ' [' . $function_context . ']' : '' ),
            'file'       => 'Snippet: ' . $snippet_title,
            'line'       => $error_line,
            'type'       => 'Parse Error',
            'snippet_id' => $snippet_id,
        ), DAY_IN_SECONDS );
    } catch ( Throwable $e ) {
        snn_snippet_clear_executing_marker();
        $error_occurred = true;
        $error_line = $e->getLine();
        $code_context = snn_get_code_context( $code_to_execute, $error_line );
        $function_context = snn_get_function_context( $code_to_execute, $error_line );

        snn_log_error_event( get_class( $e ), $e->getMessage(), $snippet_title, 'eval()\'d code', $e->getLine(), $code_context, $function_context );

        if ( $e instanceof Error ) {
            if ( $snippet_id ) {
                wp_update_post( array( 'ID' => $snippet_id, 'post_status' => 'draft' ) );
            }

            set_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT, array(
                'message'    => $e->getMessage() . ( $function_context ? ' [' . $function_context . ']' : '' ),
                'file'       => 'Snippet: ' . $snippet_title,
                'line'       => $error_line,
                'type'       => get_class( $e ),
                'snippet_id' => $snippet_id,
            ), DAY_IN_SECONDS );
        }
    }

    $output_from_snippet = ob_get_clean();
    restore_error_handler();

    if ( $error_occurred ) {
        return "\n\n";
    }

    return $output_from_snippet;
}

/**
 * Type-aware output for a single snippet post. Only PHP goes through eval();
 * CSS/JS/HTML are raw output with no execution risk.
 */
function snn_render_snippet_output( $post, $meta ) {
    $content = $post->post_content;
    if ( trim( $content ) === '' ) {
        return '';
    }

    switch ( $meta['type'] ) {
        case 'css':
            return "\n<style id=\"snn-snippet-{$post->ID}\">\n" . $content . "\n</style>\n";
        case 'js':
            return "\n<script id=\"snn-snippet-{$post->ID}\">\n" . $content . "\n</script>\n";
        case 'html':
            return "\n" . $content . "\n";
        case 'php':
        default:
            return snn_execute_php_snippet( $content, $post->ID, $post->post_title, $meta['location'] );
    }
}

// ----------------------------------------------------------------------
// Compiled-file execution (file-based, like FluentSnippets): snippets are
// compiled to plain PHP files on save and simply include()'d at runtime --
// no database query on normal page loads. The CPT stays the source of
// truth for the admin UI; these files are a disposable, auto-rebuilt cache.
// ----------------------------------------------------------------------

function snn_snippets_get_locations() {
    return array_keys( snn_snippet_get_location_defs() );
}

function snn_snippets_compiled_dir() {
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['basedir'] ) . 'snn-code-snippets';
}

function snn_snippets_compiled_file_path( $location ) {
    return snn_snippets_compiled_dir() . '/' . $location . '.php';
}

/**
 * Cheap check (reads only the first ~300 bytes, no include/eval) for whether
 * a compiled file was built by the current compiler logic. Avoids re-running
 * a request against a file compiled by an older, possibly-buggy version of
 * snn_snippets_compile_location() after a theme update.
 */
function snn_snippets_compiled_file_is_current( $file ) {
    $head = @file_get_contents( $file, false, null, 0, 300 );
    if ( false === $head ) {
        return false;
    }
    return false !== strpos( $head, '// Compiler-Version: ' . SNN_SNIPPETS_COMPILER_VERSION . "\n" );
}

/**
 * Creates the compiled-files directory and blocks direct web access to it.
 * Returns false if the directory can't be created/written to on this host,
 * in which case callers fall back to the live DB+eval() path.
 */
function snn_snippets_ensure_protected_dir() {
    $dir = snn_snippets_compiled_dir();
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
        return false;
    }

    $index_file = $dir . '/index.php';
    if ( ! file_exists( $index_file ) ) {
        @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
    }

    $htaccess_file = $dir . '/.htaccess';
    if ( ! file_exists( $htaccess_file ) ) {
        @file_put_contents( $htaccess_file, "Deny from all\n" );
    }

    return true;
}

/**
 * Writes via a temp file + rename() in the same directory, so a request
 * never sees a half-written compiled file mid-save.
 */
function snn_snippets_write_file_atomic( $path, $contents ) {
    $dir  = trailingslashit( dirname( $path ) );
    $temp = wp_tempnam( basename( $path ), $dir );
    if ( ! $temp || false === @file_put_contents( $temp, $contents ) ) {
        return false;
    }
    if ( ! @rename( $temp, $path ) ) {
        @unlink( $temp );
        return false;
    }
    return true;
}

/**
 * Stronger-than-snn_validate_php_syntax() check used specifically as the
 * compile-time gate: defines the snippet as a function (using the exact
 * same tag-wrapping the compiled file will use) but never calls it, so a
 * real PHP compile error -- e.g. a stray "?>" inside a comment, which a
 * plain tokenizer pass does NOT catch -- throws a catchable ParseError
 * instead of corrupting the whole compiled bundle for every other snippet
 * at that location.
 */
function snn_snippets_validate_for_compile( $code, $location ) {
    if ( trim( $code ) === '' ) {
        return true;
    }

    $fn = 'snn_snippet_validate_' . str_replace( '.', '', uniqid( '', true ) );

    if ( 'immediate' === $location ) {
        $wrapped = "function {$fn}() {\n" . $code . "\n}";
    } else {
        $wrapped = "function {$fn}() {\n?>\n" . $code . "\n<?php\n}";
    }

    try {
        eval( $wrapped );
    } catch ( ParseError $e ) {
        return array(
            'message' => $e->getMessage(),
            'line'    => $e->getLine(),
        );
    }

    return true;
}

function snn_snippets_php_comment_safe( $title ) {
    return preg_replace( '/[\r\n]+/', ' ', str_replace( '*/', '', (string) $title ) );
}

/**
 * Builds the source for one PHP-type snippet inside the compiled bundle.
 * Mirrors the eval()-based semantics of snn_execute_php_snippet(): bare
 * statements for "immediate" (functions.php-style), tag-gated HTML/PHP mix
 * for the other, template-style locations.
 */
function snn_snippets_build_php_block( $post, $code, $location, $suppress ) {
    $id = (int) $post->ID;

    $inner  = "try {\n";
    $inner .= "\t( function () {\n";
    if ( 'immediate' === $location ) {
        $inner .= $code . "\n";
    } else {
        $inner .= "?>\n" . $code . "\n<?php\n";
    }
    $inner .= "\t} )();\n";
    $inner .= "} catch ( Throwable \$snn_e ) {\n";
    $inner .= "\tsnn_snippets_runtime_error( \$snn_e, {$id}, " . var_export( $post->post_title, true ) . ", " . var_export( $location, true ) . " );\n";
    $inner .= "}\n";

    if ( $suppress ) {
        $inner = "ob_start();\n" . $inner . "ob_end_clean();\n";
    }

    return "// Snippet #{$id}: " . snn_snippets_php_comment_safe( $post->post_title ) . "\n" . $inner;
}

/**
 * Catches genuine runtime Throwables (not parse errors -- those are already
 * filtered out at compile time) from a snippet running inside a compiled
 * file, e.g. a call to an undefined function. Only real Errors (not caught
 * Exceptions) disable the snippet, matching the previous eval()-based
 * behaviour. Line numbers here are relative to the compiled bundle, not the
 * snippet's own source, so no per-snippet code context is shown.
 */
function snn_snippets_runtime_error( $e, $snippet_id, $snippet_title, $location ) {
    snn_log_error_event(
        get_class( $e ),
        $e->getMessage(),
        $snippet_title,
        'compiled snippet (' . $location . ')',
        $e->getLine()
    );

    if ( $e instanceof Error ) {
        wp_update_post( array( 'ID' => $snippet_id, 'post_status' => 'draft' ) );

        set_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT, array(
            'message'    => $e->getMessage(),
            'file'       => 'Snippet: ' . $snippet_title,
            'line'       => 0,
            'type'       => get_class( $e ),
            'snippet_id' => $snippet_id,
        ), DAY_IN_SECONDS );
    }
}

function snn_snippets_disable_invalid_snippet( $post, $validation ) {
    wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );

    snn_log_error_event(
        'PHP Syntax Validation Error',
        $validation['message'],
        $post->post_title,
        'Pre-compile validation',
        $validation['line'],
        snn_get_code_context( $post->post_content, $validation['line'] ),
        snn_get_function_context( $post->post_content, $validation['line'] )
    );

    set_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT, array(
        'message'    => $validation['message'],
        'file'       => 'Snippet: ' . $post->post_title,
        'line'       => $validation['line'],
        'type'       => 'Syntax Validation Error',
        'snippet_id' => $post->ID,
    ), DAY_IN_SECONDS );
}

/**
 * Extracts top-level (non-method, non-anonymous) function names a snippet's
 * PHP code declares. Used to catch "Cannot redeclare function" fatals
 * before they happen, rather than after -- e.g. a snippet copied in from
 * the real FluentSnippets plugin (or any other active plugin) without
 * removing the original, or the same snippet accidentally duplicated.
 * Methods inside a class/interface/trait body are intentionally excluded --
 * those aren't declared in the global function table.
 */
function snn_snippets_extract_declared_function_names( $code ) {
    $names  = array();
    $tokens = @token_get_all( "<?php\n" . $code );
    if ( ! is_array( $tokens ) ) {
        return $names;
    }

    $depth                 = 0;
    $class_depth           = null;
    $awaiting_class_brace  = false;
    $count                 = count( $tokens );

    for ( $i = 0; $i < $count; $i++ ) {
        $token = $tokens[ $i ];

        if ( is_array( $token ) ) {
            $id = $token[0];

            if ( in_array( $id, array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
                $awaiting_class_brace = true;
            } elseif ( T_FUNCTION === $id && null === $class_depth ) {
                $j = $i + 1;
                while ( $j < $count && (
                    ( is_array( $tokens[ $j ] ) && ( in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) || '&' === $tokens[ $j ][1] ) )
                    || '&' === $tokens[ $j ]
                ) ) {
                    $j++;
                }
                if ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
                    $names[] = $tokens[ $j ][1];
                }
            }
            continue;
        }

        if ( '{' === $token ) {
            if ( $awaiting_class_brace ) {
                $class_depth          = $depth;
                $awaiting_class_brace = false;
            }
            $depth++;
        } elseif ( '}' === $token ) {
            $depth--;
            if ( null !== $class_depth && $depth <= $class_depth ) {
                $class_depth = null;
            }
        }
    }

    return array_unique( $names );
}

/**
 * One pass over every currently-active PHP snippet across all 4 locations,
 * so a function-name collision can be caught before compiling instead of
 * fataling the live site. Two kinds of collisions are caught:
 *   - Two of our own active snippets declaring the same function (e.g. the
 *     same snippet duplicated by accident).
 *   - A name that's uniquely ours but already exists in the current PHP
 *     process, AND isn't something our own existing compiled output already
 *     declares. That second condition matters: "immediate" always runs on
 *     the init hook, so by the time an admin save triggers a recompile, the
 *     OLD compiled immediate.php has typically already been include()'d
 *     earlier in this very request -- function_exists() would be true for
 *     a snippet's own function purely because of that, not because of a
 *     real conflict. Only a name that's loaded AND absent from our own
 *     existing compiled files can be an unrelated external source (another
 *     active plugin, WP core, or a leftover copy in the real FluentSnippets
 *     plugin's own snippet storage).
 * Returns [ snippet_id => human-readable reason ] for every snippet that
 * must be left out of the compiled output. Computed once per request and
 * cached -- a full recompile calls this once per location (4x), and each
 * call needs the same cross-location picture anyway.
 */
function snn_snippets_get_function_collisions() {
    static $result = null;
    if ( null !== $result ) {
        return $result;
    }

    $already_compiled_by_us = array(); // lowercase function name => true
    foreach ( snn_snippets_get_locations() as $location ) {
        $file = snn_snippets_compiled_file_path( $location );
        if ( ! file_exists( $file ) ) {
            continue;
        }
        $src = file_get_contents( $file );
        if ( false === $src ) {
            continue;
        }
        foreach ( snn_snippets_extract_declared_function_names( $src ) as $name ) {
            $already_compiled_by_us[ strtolower( $name ) ] = true;
        }
    }

    $declared_by   = array(); // lowercase function name => [snippet_id, ...]
    $snippet_names = array(); // snippet_id => [function name, ...]

    foreach ( snn_snippets_get_locations() as $location ) {
        foreach ( snn_snippets_query_by_location( $location ) as $post ) {
            $meta = snn_snippet_get_meta( $post->ID );
            if ( 'php' !== $meta['type'] || trim( $post->post_content ) === '' ) {
                continue;
            }
            $names = snn_snippets_extract_declared_function_names( $post->post_content );
            if ( empty( $names ) ) {
                continue;
            }
            $snippet_names[ $post->ID ] = $names;
            foreach ( $names as $name ) {
                $declared_by[ strtolower( $name ) ][] = $post->ID;
            }
        }
    }

    $result = array();

    foreach ( $snippet_names as $snippet_id => $names ) {
        foreach ( $names as $name ) {
            $owners = array_unique( $declared_by[ strtolower( $name ) ] );

            if ( count( $owners ) > 1 ) {
                sort( $owners );
                if ( (int) $owners[0] !== (int) $snippet_id ) {
                    $result[ $snippet_id ] = sprintf(
                        /* translators: 1: function name, 2: ID of the snippet that keeps using it */
                        __( 'Function "%1$s" is also declared by another active snippet (#%2$d). Only one can keep it.', 'snn' ),
                        $name,
                        $owners[0]
                    );
                }
                continue;
            }

            if ( function_exists( $name ) && empty( $already_compiled_by_us[ strtolower( $name ) ] ) ) {
                $result[ $snippet_id ] = sprintf(
                    /* translators: %s: function name */
                    __( 'Function "%s" is already declared by another active plugin (or a leftover copy outside this system).', 'snn' ),
                    $name
                );
            }
        }
    }

    return $result;
}

/**
 * Compiles one location's active snippets into a single PHP file. Every
 * PHP-type snippet is validated individually first (and excluded + disabled
 * if invalid), then the whole assembled file is validated once more as a
 * final safety net -- so the compiled file is only ever written if it's
 * guaranteed syntactically valid, and one broken snippet can never take
 * down the others at the same location.
 */
function snn_snippets_compile_location( $location ) {
    if ( ! snn_snippets_ensure_protected_dir() ) {
        return false;
    }

    $blocks = array();

    foreach ( snn_snippets_query_by_location( $location ) as $post ) {
        $meta = snn_snippet_get_meta( $post->ID );

        // "Everywhere (Immediate)" runs functions.php-style on every request,
        // including wp-admin, before WordPress has sent any headers -- so
        // unless output is explicitly allowed, nothing at this location may
        // reach the page directly, regardless of snippet type.
        $suppress = ( 'immediate' === $location && empty( $meta['allow_output'] ) );

        if ( 'php' !== $meta['type'] ) {
            if ( $suppress ) {
                continue; // No side effects to preserve for CSS/JS/HTML -- just skip it.
            }
            $rendered = snn_render_snippet_output( $post, $meta );
            if ( '' !== $rendered ) {
                $blocks[] = "// Snippet #{$post->ID}: " . snn_snippets_php_comment_safe( $post->post_title ) . "\n"
                    . 'echo ' . var_export( $rendered, true ) . ";\n";
            }
            continue;
        }

        $code = $post->post_content;
        if ( trim( $code ) === '' ) {
            continue;
        }

        $validation = snn_snippets_validate_for_compile( $code, $location );
        if ( true !== $validation ) {
            snn_snippets_disable_invalid_snippet( $post, $validation );
            continue;
        }

        $collisions = snn_snippets_get_function_collisions();
        if ( isset( $collisions[ $post->ID ] ) ) {
            snn_snippets_disable_invalid_snippet( $post, array(
                'message' => $collisions[ $post->ID ],
                'line'    => 0,
            ) );
            continue;
        }

        $blocks[] = snn_snippets_build_php_block( $post, $code, $location, $suppress );
    }

    $body = "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
        . "// Auto-generated by SNN Code Snippets. Do not edit -- overwritten on every save.\n"
        . "// Compiler-Version: " . SNN_SNIPPETS_COMPILER_VERSION . "\n\n"
        . implode( "\n", $blocks );

    $final_check = snn_validate_php_syntax( $body );
    if ( is_array( $final_check ) ) {
        snn_log_error_event( 'Compile Error', $final_check['message'], 'Snippets Compiler', $location . '.php', $final_check['line'], $final_check['code_context'] );
        return false; // Keep whatever compiled file was already there working.
    }

    return snn_snippets_write_file_atomic( snn_snippets_compiled_file_path( $location ), "<?php\n" . $body );
}

/**
 * Recompiles all 4 locations. Guarded against re-entrancy: disabling an
 * invalid snippet mid-compile fires wp_update_post(), which triggers the
 * save_post hook below and would otherwise recurse.
 */
function snn_snippets_recompile_all() {
    static $running = false;
    if ( $running ) {
        return;
    }
    $running = true;
    foreach ( snn_snippets_get_locations() as $location ) {
        snn_snippets_compile_location( $location );
    }
    $running = false;
}
// Covers save, toggle, migration and revision-restore -- all of them go
// through wp_insert_post()/wp_update_post() on this post type already.
add_action( 'save_post_snn_code_snippet', 'snn_snippets_recompile_all' );

// wp_delete_post() doesn't fire save_post, so it needs its own trigger.
add_action( 'before_delete_post', function( $post_id ) {
    if ( get_post_type( $post_id ) === 'snn_code_snippet' ) {
        snn_snippets_recompile_all();
    }
} );

// ----------------------------------------------------------------------
// Execution loop
// ----------------------------------------------------------------------

function snn_custom_codes_snippets_run_location( $location ) {
    if ( defined( 'SNN_CODE_DISABLE' ) && SNN_CODE_DISABLE ) {
        return;
    }

    $file = snn_snippets_compiled_file_path( $location );

    if ( ! file_exists( $file ) || ! snn_snippets_compiled_file_is_current( $file ) ) {
        // Self-heal: first run after deploy, the cache was cleared, or this
        // file was compiled by an older version of the compiler logic itself
        // (SNN_SNIPPETS_COMPILER_VERSION mismatch) -- one DB-backed compile
        // now, then every later request just includes it. No DB query, no
        // shell access, and no manual cache-clearing needed after an update.
        snn_snippets_compile_location( $location );
    }

    if ( file_exists( $file ) ) {
        include $file;
        return;
    }

    // Compiled directory isn't writable on this host -- fall back to the
    // original DB query + eval() path so the feature still works.
    snn_custom_codes_snippets_run_location_live( $location );
}

function snn_custom_codes_snippets_run_location_live( $location ) {
    foreach ( snn_snippets_query_by_location( $location ) as $post ) {
        $meta = snn_snippet_get_meta( $post->ID );

        // "Everywhere (Immediate)" runs functions.php-style on every request,
        // including wp-admin, before WordPress has sent any headers. Like a
        // real functions.php, it must not output directly unless explicitly
        // allowed -- otherwise stray output (even a leading blank line) causes
        // "headers already sent" errors.
        if ( 'immediate' === $location && empty( $meta['allow_output'] ) ) {
            snn_render_snippet_output( $post, $meta );
            continue;
        }

        echo snn_render_snippet_output( $post, $meta );
    }
}

function snn_custom_codes_snippets_init_execution() {
    if ( defined( 'SNN_CODE_DISABLE' ) && SNN_CODE_DISABLE ) {
        return;
    }

    // "Everywhere (Immediate)" snippets run right now, functions.php-style.
    snn_custom_codes_snippets_run_location( 'immediate' );

    if ( ! is_admin() ) {
        add_action( 'wp_head', function() { snn_custom_codes_snippets_run_location( 'frontend_head' ); }, 1 );
        add_action( 'wp_footer', function() { snn_custom_codes_snippets_run_location( 'frontend_footer' ); }, 9999 );
    } else {
        add_action( 'admin_head', function() { snn_custom_codes_snippets_run_location( 'admin_head' ); }, 1 );
    }
}
add_action( 'init', 'snn_custom_codes_snippets_init_execution', 10 );

// ----------------------------------------------------------------------
// AJAX: revisions preview (unchanged, already generic per post ID)
// ----------------------------------------------------------------------

add_action( 'wp_ajax_snn_get_revision_content', 'snn_ajax_get_revision_content_callback' );
function snn_ajax_get_revision_content_callback() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'snn_preview_revision_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'snn' ) ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied to manage options.', 'snn' ) ), 403 );
        return;
    }
    $revision_id = isset( $_POST['revision_id'] ) ? absint( $_POST['revision_id'] ) : 0;
    if ( ! $revision_id ) {
        wp_send_json_error( array( 'message' => __( 'Missing revision ID.', 'snn' ) ) );
        return;
    }
    $revision = wp_get_post_revision( $revision_id );
    if ( ! $revision ) {
        wp_send_json_error( array( 'message' => __( 'Revision not found.', 'snn' ) ) );
        return;
    }
    if ( ! current_user_can( 'edit_post', $revision->post_parent ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied for accessing this revision content.', 'snn' ) ), 403 );
        return;
    }
    wp_send_json_success( array( 'content' => $revision->post_content, 'title' => wp_post_revision_title_expanded( $revision ) ) );
}

// ----------------------------------------------------------------------
// AJAX: dismiss fatal error notice (unchanged)
// ----------------------------------------------------------------------

add_action( 'wp_ajax_snn_dismiss_fatal_error_notice', 'snn_ajax_dismiss_fatal_error_notice_callback' );
function snn_ajax_dismiss_fatal_error_notice_callback() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'snn_dismiss_fatal_notice_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'snn' ) ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'snn' ) ), 403 );
        return;
    }
    delete_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT );
    wp_send_json_success();
}

// ----------------------------------------------------------------------
// AJAX: toggle / delete / export / import (new)
// ----------------------------------------------------------------------

add_action( 'wp_ajax_snn_toggle_snippet', 'snn_ajax_toggle_snippet_callback' );
function snn_ajax_toggle_snippet_callback() {
    check_ajax_referer( 'snn_snippets_manage_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'snn' ) ), 403 );
    }
    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $post = $id ? get_post( $id ) : null;
    if ( ! $post || $post->post_type !== 'snn_code_snippet' ) {
        wp_send_json_error( array( 'message' => __( 'Snippet not found.', 'snn' ) ) );
    }
    $new_status = $post->post_status === 'publish' ? 'draft' : 'publish';
    wp_update_post( array( 'ID' => $id, 'post_status' => $new_status ) );
    if ( $new_status === 'publish' ) {
        delete_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT );
    }
    wp_send_json_success( array( 'status' => $new_status ) );
}

add_action( 'wp_ajax_snn_delete_snippet', 'snn_ajax_delete_snippet_callback' );
function snn_ajax_delete_snippet_callback() {
    check_ajax_referer( 'snn_snippets_manage_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'snn' ) ), 403 );
    }
    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $post = $id ? get_post( $id ) : null;
    if ( ! $post || $post->post_type !== 'snn_code_snippet' ) {
        wp_send_json_error( array( 'message' => __( 'Snippet not found.', 'snn' ) ) );
    }
    wp_delete_post( $id, true );
    wp_send_json_success();
}

add_action( 'wp_ajax_snn_export_snippets', 'snn_ajax_export_snippets_callback' );
function snn_ajax_export_snippets_callback() {
    check_ajax_referer( 'snn_snippets_manage_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'snn' ) ), 403 );
    }
    $posts = get_posts( array(
        'post_type'        => 'snn_code_snippet',
        'post_status'      => array( 'publish', 'draft' ),
        'posts_per_page'   => -1,
        'suppress_filters' => true,
    ) );
    $export = array();
    foreach ( $posts as $p ) {
        $meta = snn_snippet_get_meta( $p->ID );
        $export[] = array(
            'title'       => $p->post_title,
            'description' => $p->post_excerpt,
            'content'     => $p->post_content,
            'status'      => $p->post_status,
            'type'        => $meta['type'],
            'location'    => $meta['location'],
            'priority'    => $meta['priority'],
            'tags'        => $meta['tags_raw'],
            'allow_output' => $meta['allow_output'],
        );
    }
    wp_send_json_success( array( 'snippets' => $export ) );
}

add_action( 'wp_ajax_snn_import_snippets', 'snn_ajax_import_snippets_callback' );
function snn_ajax_import_snippets_callback() {
    check_ajax_referer( 'snn_snippets_manage_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'snn' ) ), 403 );
    }
    $json = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';
    $data = json_decode( $json, true );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid JSON.', 'snn' ) ) );
    }

    $type_defs     = snn_snippet_get_type_defs();
    $location_defs = snn_snippet_get_location_defs();
    $imported = 0;

    foreach ( $data as $item ) {
        if ( ! is_array( $item ) || empty( $item['content'] ) ) {
            continue;
        }
        $status = isset( $item['status'] ) && $item['status'] === 'publish' ? 'publish' : 'draft';
        $new_id = wp_insert_post( array(
            'post_type'    => 'snn_code_snippet',
            'post_title'   => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : __( 'Imported Snippet', 'snn' ),
            'post_excerpt' => isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '',
            'post_content' => $item['content'],
            'post_status'  => $status,
        ), true );
        if ( is_wp_error( $new_id ) ) {
            continue;
        }
        $type     = isset( $item['type'] ) && isset( $type_defs[ $item['type'] ] ) ? $item['type'] : 'php';
        $location = isset( $item['location'] ) && isset( $location_defs[ $item['location'] ] ) ? $item['location'] : 'frontend_head';
        snn_snippet_save_meta( $new_id, array(
            'type'     => $type,
            'location' => $location,
            'priority' => isset( $item['priority'] ) ? (int) $item['priority'] : 10,
            'tags'     => isset( $item['tags'] ) ? $item['tags'] : '',
            'allow_output' => ! empty( $item['allow_output'] ),
        ) );
        $imported++;
    }

    wp_send_json_success( array( 'imported' => $imported ) );
}

// ----------------------------------------------------------------------
// Single-snippet download (plain nonce'd GET, outside the normal page render)
// ----------------------------------------------------------------------

add_action( 'admin_init', 'snn_maybe_handle_snippet_download' );
function snn_maybe_handle_snippet_download() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'snn-custom-codes-snippets' || ! isset( $_GET['snn_download'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $id = absint( $_GET['snn_download'] );
    check_admin_referer( 'snn_download_' . $id );

    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'snn_code_snippet' ) {
        return;
    }

    $meta = snn_snippet_get_meta( $id );
    $extensions = array( 'php' => 'php', 'css' => 'css', 'js' => 'js', 'html' => 'html' );
    $ext = isset( $extensions[ $meta['type'] ] ) ? $extensions[ $meta['type'] ] : 'txt';
    $filename = sanitize_title( $post->post_title ) . '.' . $ext;

    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    echo $post->post_content;
    exit;
}

// ----------------------------------------------------------------------
// Fatal error shutdown handler (now precise via the executing-snippet marker)
// ----------------------------------------------------------------------

function snn_register_fatal_error_handler() {
    register_shutdown_function( 'snn_fatal_error_shutdown_handler' );
}
add_action( 'init', 'snn_register_fatal_error_handler', 1 );

function snn_fatal_error_shutdown_handler() {
    $error = error_get_last();

    if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
        return;
    }

    $executing_id = (int) get_option( SNN_SNIPPET_EXECUTING_MARKER_OPTION, 0 );
    $error_source_is_snippet = false;

    if ( $executing_id ) {
        // Precise: we know exactly which snippet was mid-eval when this happened.
        $error_source_is_snippet = true;
    } elseif ( isset( $error['message'] ) ) {
        // Fallback heuristic for the rare case the marker wasn't set (e.g. the
        // fatal happened before any eval() was reached).
        if ( strpos( $error['message'], "eval()'d code" ) !== false || preg_match( '/\beval\(\)/i', $error['message'] ) ) {
            $error_source_is_snippet = true;
        }
        $current_file_path_normalized = wp_normalize_path( __FILE__ );
        $error_file_normalized = isset( $error['file'] ) ? wp_normalize_path( $error['file'] ) : '';
        if ( ! empty( $error_file_normalized ) && $error_file_normalized === $current_file_path_normalized ) {
            $error_source_is_snippet = true;
        }
    }

    if ( ! $error_source_is_snippet ) {
        return;
    }

    $snippet_title = __( 'Unknown snippet', 'snn' );
    if ( $executing_id ) {
        $post = get_post( $executing_id );
        if ( $post ) {
            $snippet_title = $post->post_title;
            wp_update_post( array( 'ID' => $executing_id, 'post_status' => 'draft' ) );
        }
    }

    snn_log_error_event(
        'PHP Fatal Error (Shutdown Handler)',
        $error['message'],
        $snippet_title,
        $error['file'],
        $error['line'],
        ''
    );

    set_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT, array(
        'message'    => $error['message'],
        'file'       => $error['file'],
        'line'       => $error['line'],
        'type'       => snn_get_php_error_type_string( $error['type'] ),
        'snippet_id' => $executing_id,
    ), DAY_IN_SECONDS );

    delete_option( SNN_SNIPPET_EXECUTING_MARKER_OPTION );
}

function snn_get_php_error_type_string( $type ) {
    switch ( $type ) {
        case E_ERROR: return 'E_ERROR (Fatal run-time error)';
        case E_WARNING: return 'E_WARNING (Run-time warning)';
        case E_PARSE: return 'E_PARSE (Compile-time parse error)';
        case E_NOTICE: return 'E_NOTICE (Run-time notice)';
        case E_CORE_ERROR: return 'E_CORE_ERROR (Fatal error during PHP startup)';
        case E_CORE_WARNING: return 'E_CORE_WARNING (Warning during PHP startup)';
        case E_COMPILE_ERROR: return 'E_COMPILE_ERROR (Fatal compile-time error)';
        case E_COMPILE_WARNING: return 'E_COMPILE_WARNING (Compile-time warning)';
        case E_USER_ERROR: return 'E_USER_ERROR (User-generated error message)';
        case E_USER_WARNING: return 'E_USER_WARNING (User-generated warning message)';
        case E_USER_NOTICE: return 'E_USER_NOTICE (User-generated notice message)';
        case E_STRICT: return 'E_STRICT (Run-time notice for deprecated code or bad practices)';
        case E_RECOVERABLE_ERROR: return 'E_RECOVERABLE_ERROR (Catchable fatal error)';
        case E_DEPRECATED: return 'E_DEPRECATED (Run-time notice for code that will not work in future PHP versions)';
        case E_USER_DEPRECATED: return 'E_USER_DEPRECATED (User-generated warning for deprecated code)';
        default: return "Unknown error type ($type)";
    }
}

function snn_display_fatal_error_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $fatal_error_details = get_transient( SNN_FATAL_ERROR_NOTICE_TRANSIENT );

    if ( $fatal_error_details && is_array( $fatal_error_details ) ) {
        $snippet_id = isset( $fatal_error_details['snippet_id'] ) ? (int) $fatal_error_details['snippet_id'] : 0;
        ?>
        <div class="notice notice-error is-dismissible snn-fatal-error-notice">
            <p><strong><?php esc_html_e( 'A code snippet was automatically disabled', 'snn' ); ?></strong></p>
            <p><?php esc_html_e( 'A snippet caused a fatal PHP error, so it was switched off automatically to keep the rest of the site running. All other snippets are unaffected.', 'snn' ); ?></p>
            <p><strong><?php esc_html_e( 'Error Details:', 'snn' ); ?></strong></p>
            <p>
                <code>
                    <?php
                    $type = isset( $fatal_error_details['type'] ) ? $fatal_error_details['type'] : 'Unknown Type';
                    $message = isset( $fatal_error_details['message'] ) ? $fatal_error_details['message'] : 'No message provided.';
                    $file = isset( $fatal_error_details['file'] ) ? $fatal_error_details['file'] : 'Unknown file.';
                    $line = isset( $fatal_error_details['line'] ) ? $fatal_error_details['line'] : 'Unknown line.';
                    echo esc_html( sprintf( "Type: %s\nMessage: %s\nFile: %s\nLine: %d", $type, $message, $file, $line ) );
                    ?>
                </code>
            </p>
            <p>
                <?php if ( $snippet_id ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'view' => 'edit', 'id' => $snippet_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Fix this snippet', 'snn' ); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'view' => 'error_logs' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View Error Logs', 'snn' ); ?></a>
                <button type="button" class="button snn-dismiss-fatal-notice"><?php esc_html_e( 'Dismiss This Notice', 'snn' ); ?></button>
            </p>
        </div>
        <?php
    }
}
add_action( 'admin_notices', 'snn_display_fatal_error_admin_notice' );

// ----------------------------------------------------------------------
// Admin page: router + form submit handling
// ----------------------------------------------------------------------

function snn_custom_codes_snippets_handle_form_submit() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        return;
    }

    // Save a snippet from the edit screen.
    if ( isset( $_POST['snn_save_snippet'] ) && isset( $_POST['snn_snippet_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_snippet_nonce'] ) ), 'snn_save_snippet_action' ) ) {
        $id          = isset( $_POST['snippet_id'] ) ? absint( $_POST['snippet_id'] ) : 0;
        $title       = isset( $_POST['snippet_title'] ) ? sanitize_text_field( wp_unslash( $_POST['snippet_title'] ) ) : '';
        $description = isset( $_POST['snippet_description'] ) ? sanitize_text_field( wp_unslash( $_POST['snippet_description'] ) ) : '';
        $content     = isset( $_POST['snippet_code'] ) ? wp_unslash( $_POST['snippet_code'] ) : '';
        $type        = isset( $_POST['snippet_type'] ) ? sanitize_key( $_POST['snippet_type'] ) : 'php';
        $location    = isset( $_POST['snippet_location'] ) ? sanitize_key( $_POST['snippet_location'] ) : 'frontend_head';
        $priority    = isset( $_POST['snippet_priority'] ) ? absint( $_POST['snippet_priority'] ) : 10;
        $tags        = isset( $_POST['snippet_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['snippet_tags'] ) ) : '';
        $allow_output = isset( $_POST['snippet_allow_output'] ) ? 1 : 0;

        if ( $title === '' ) {
            $title = __( 'Untitled Snippet', 'snn' );
        }

        $post_data = array(
            'post_type'    => 'snn_code_snippet',
            'post_title'   => $title,
            'post_excerpt' => $description,
            'post_content' => $content,
        );

        if ( $id ) {
            $post_data['ID'] = $id;
            $result = wp_update_post( $post_data, true );
        } else {
            $post_data['post_status'] = 'draft';
            $result = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'snn-custom-codes', 'save_failed', sprintf( __( 'Failed to save snippet: %s', 'snn' ), esc_html( $result->get_error_message() ) ), 'error' );
        } else {
            $id = $result;
            snn_snippet_save_meta( $id, array( 'type' => $type, 'location' => $location, 'priority' => $priority, 'tags' => $tags, 'allow_output' => $allow_output ) );
            add_settings_error( 'snn-custom-codes', 'snippet_saved', __( 'Snippet saved.', 'snn' ), 'updated' );
            $_GET['view'] = 'edit';
            $_GET['id']   = $id;
        }
        return;
    }

    // Clear all error logs.
    if ( isset( $_POST['snn_clear_error_logs_button'] ) ) {
        check_admin_referer( 'snn_clear_error_logs_action', 'snn_clear_error_logs_nonce' );
        update_option( SNN_CUSTOM_CODES_LOG_OPTION, array() );
        add_settings_error( 'snn-custom-codes', 'logs_cleared', __( 'All error logs have been cleared.', 'snn' ), 'updated' );
        $_GET['view'] = 'error_logs';
        return;
    }

    // Clear revisions for one snippet.
    if ( isset( $_POST['snn_clear_revisions_button'] ) ) {
        $snippet_id = isset( $_POST['snn_snippet_id_to_clear'] ) ? absint( $_POST['snn_snippet_id_to_clear'] ) : 0;
        if ( $snippet_id ) {
            check_admin_referer( 'snn_clear_revisions_' . $snippet_id, 'snn_clear_revisions_nonce' );
            if ( current_user_can( 'delete_post', $snippet_id ) ) {
                foreach ( wp_get_post_revisions( $snippet_id, array( 'fields' => 'ids', 'posts_per_page' => -1 ) ) as $rev_id ) {
                    wp_delete_post_revision( $rev_id );
                }
                add_settings_error( 'snn-custom-codes', 'revisions_cleared', __( 'Revisions cleared.', 'snn' ), 'updated' );
            }
            $_GET['view'] = 'edit';
            $_GET['id']   = $snippet_id;
        }
        return;
    }

    // Restore a revision.
    if ( isset( $_POST['snn_restore_submit_button'] ) ) {
        $revision_id = absint( $_POST['snn_restore_submit_button'] );
        $revision = wp_get_post_revision( $revision_id );
        if ( $revision && current_user_can( 'edit_post', $revision->post_parent ) ) {
            wp_restore_post_revision( $revision_id );
            add_settings_error( 'snn-custom-codes', 'revision_restored', __( 'Revision restored.', 'snn' ), 'updated' );
            $_GET['view'] = 'edit';
            $_GET['id']   = $revision->post_parent;
        }
        return;
    }
}

function snn_custom_codes_snippets_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'snn' ) );
    }

    snn_custom_codes_snippets_handle_form_submit();

    $view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
    $is_disabled_by_constant = defined( 'SNN_CODE_DISABLE' ) && SNN_CODE_DISABLE;

    echo '<div class="wrap snn-snippets-wrap">';

    if ( $is_disabled_by_constant ) {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Execution Disabled by Constant:', 'snn' ) . '</strong> ' .
            sprintf(
                // translators: %s: the constant name SNN_CODE_DISABLE
                esc_html__( 'All snippet execution is currently disabled by the %s constant. To re-enable execution, remove it from wp-config.php.', 'snn' ),
                '<code>SNN_CODE_DISABLE</code>'
            ) . '</p></div>';
    }

    settings_errors( 'snn-custom-codes' );

    if ( $view === 'edit' ) {
        $snippet_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        snn_render_snippet_edit_view( $snippet_id );
    } elseif ( $view === 'error_logs' ) {
        snn_render_error_logs_view();
    } else {
        snn_render_snippets_list_view();
    }

    echo '</div>';
}

// ----------------------------------------------------------------------
// List view
// ----------------------------------------------------------------------

function snn_render_snippets_list_view() {
    $type_defs     = snn_snippet_get_type_defs();
    $location_defs = snn_snippet_get_location_defs();
    $current_type_filter = isset( $_GET['snn_type'] ) ? sanitize_key( $_GET['snn_type'] ) : '';

    $posts = get_posts( array(
        'post_type'        => 'snn_code_snippet',
        'post_status'      => array( 'publish', 'draft' ),
        'posts_per_page'   => -1,
        'suppress_filters' => true,
        'orderby'          => 'title',
        'order'            => 'ASC',
    ) );

    $ajax_nonce = wp_create_nonce( 'snn_snippets_manage_nonce' );
    $new_snippet_url = add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'view' => 'edit' ), admin_url( 'admin.php' ) );
    $error_logs_url  = add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'view' => 'error_logs' ), admin_url( 'admin.php' ) );
    ?>
    <div class="snn-snippets-header">
        <h1><?php esc_html_e( 'Code Snippets', 'snn' ); ?></h1>
        <div class="snn-snippets-header-actions">
            <input type="text" id="snn-snippets-search" placeholder="<?php esc_attr_e( 'Search…', 'snn' ); ?>">
            <a href="<?php echo esc_url( $new_snippet_url ); ?>" class="button button-primary"><?php esc_html_e( 'New Snippet', 'snn' ); ?></a>
            <button type="button" id="snn-export-btn" class="button"><?php esc_html_e( 'Export', 'snn' ); ?></button>
            <button type="button" id="snn-import-btn" class="button"><?php esc_html_e( 'Import', 'snn' ); ?></button>
            <a href="<?php echo esc_url( $error_logs_url ); ?>" class="button"><?php esc_html_e( 'Error Logs', 'snn' ); ?></a>
        </div>
    </div>

    <h2 class="nav-tab-wrapper snn-snippet-nav-tab-wrapper">
        <a href="#" class="nav-tab snn-type-tab <?php echo $current_type_filter === '' ? 'nav-tab-active' : ''; ?>" data-type=""><?php esc_html_e( 'All Snippets', 'snn' ); ?></a>
        <?php foreach ( $type_defs as $type_key => $def ) : ?>
            <a href="#" class="nav-tab snn-type-tab <?php echo $current_type_filter === $type_key ? 'nav-tab-active' : ''; ?>" data-type="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $def['label'] ); ?></a>
        <?php endforeach; ?>
    </h2>

    <table class="widefat striped snn-snippets-table" id="snn-snippets-table">
        <thead>
            <tr>
                <th style="width:44px;"></th>
                <th><?php esc_html_e( 'Title', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Description', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Type', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Location', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Tags', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Updated At', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Priority', 'snn' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $posts ) ) : ?>
                <tr class="snn-no-snippets"><td colspan="9"><?php esc_html_e( 'No snippets yet. Click "New Snippet" to create one.', 'snn' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $posts as $post ) :
                $meta = snn_snippet_get_meta( $post->ID );
                $edit_url = add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'view' => 'edit', 'id' => $post->ID ), admin_url( 'admin.php' ) );
                $download_url = wp_nonce_url( add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'snn_download' => $post->ID ), admin_url( 'admin.php' ) ), 'snn_download_' . $post->ID );
                $type_def = isset( $type_defs[ $meta['type'] ] ) ? $type_defs[ $meta['type'] ] : $type_defs['php'];
                ?>
                <tr data-type="<?php echo esc_attr( $meta['type'] ); ?>" data-title="<?php echo esc_attr( strtolower( $post->post_title ) ); ?>">
                    <td>
                        <label class="snn-toggle-switch">
                            <input type="checkbox" class="snn-snippet-toggle" data-id="<?php echo esc_attr( $post->ID ); ?>" <?php checked( $post->post_status, 'publish' ); ?>>
                            <span class="snn-toggle-slider"></span>
                        </label>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $post->post_title ); ?></strong></a>
                        <div class="row-actions">
                            <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'snn' ); ?></a> | </span>
                            <span class="delete"><a href="#" class="snn-delete-snippet" data-id="<?php echo esc_attr( $post->ID ); ?>"><?php esc_html_e( 'Delete', 'snn' ); ?></a> | </span>
                            <span class="download"><a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'snn' ); ?></a></span>
                        </div>
                    </td>
                    <td><?php echo esc_html( $post->post_excerpt ?: '--' ); ?></td>
                    <td><span class="snn-type-badge" style="background:<?php echo esc_attr( $type_def['badge_color'] ); ?>;"><?php echo esc_html( $type_def['label'] ); ?></span></td>
                    <td><?php echo esc_html( $location_defs[ $meta['location'] ] ); ?></td>
                    <td><?php echo esc_html( implode( ', ', $meta['tags'] ) ); ?></td>
                    <td><?php echo esc_html( human_time_diff( strtotime( $post->post_modified ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'snn' ) ); ?></td>
                    <td><?php echo esc_html( $meta['priority'] ); ?></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div id="snn-export-import-modal" class="snn-modal" style="display:none;">
        <div class="snn-modal-inner">
            <h2 id="snn-modal-title"></h2>
            <textarea id="snn-modal-textarea" rows="16"></textarea>
            <p>
                <button type="button" class="button button-primary" id="snn-modal-confirm-import" style="display:none;"><?php esc_html_e( 'Import', 'snn' ); ?></button>
                <button type="button" class="button" id="snn-modal-close"><?php esc_html_e( 'Close', 'snn' ); ?></button>
            </p>
        </div>
    </div>

    <script>
    (function($){
        var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce = '<?php echo esc_js( $ajax_nonce ); ?>';

        $('.snn-snippet-toggle').on('change', function(){
            var $cb = $(this), id = $cb.data('id');
            $.post(ajaxUrl, {action:'snn_toggle_snippet', id:id, nonce:nonce}, function(resp){
                if (!resp.success) {
                    alert(resp.data && resp.data.message ? resp.data.message : 'Error');
                    $cb.prop('checked', !$cb.prop('checked'));
                }
            }).fail(function(){
                alert('AJAX error');
                $cb.prop('checked', !$cb.prop('checked'));
            });
        });

        $('.snn-delete-snippet').on('click', function(e){
            e.preventDefault();
            if (!confirm('<?php echo esc_js( __( 'Delete this snippet? This cannot be undone.', 'snn' ) ); ?>')) return;
            var id = $(this).data('id');
            var $row = $(this).closest('tr');
            $.post(ajaxUrl, {action:'snn_delete_snippet', id:id, nonce:nonce}, function(resp){
                if (resp.success) { $row.fadeOut(200, function(){ $row.remove(); }); }
                else { alert(resp.data && resp.data.message ? resp.data.message : 'Error'); }
            });
        });

        $('#snn-snippets-search').on('keyup', function(){
            var q = $(this).val().toLowerCase();
            $('#snn-snippets-table tbody tr[data-title]').each(function(){
                $(this).toggle($(this).data('title').indexOf(q) !== -1);
            });
        });

        $('.snn-type-tab').on('click', function(e){
            e.preventDefault();
            var type = $(this).data('type');
            $('.snn-type-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('#snn-snippets-table tbody tr[data-type]').each(function(){
                $(this).toggle(type === '' || $(this).data('type') === type);
            });
        });

        $('#snn-export-btn').on('click', function(){
            $.post(ajaxUrl, {action:'snn_export_snippets', nonce:nonce}, function(resp){
                if (resp.success) {
                    $('#snn-modal-title').text('<?php echo esc_js( __( 'Export Snippets', 'snn' ) ); ?>');
                    $('#snn-modal-textarea').val(JSON.stringify(resp.data.snippets, null, 2));
                    $('#snn-modal-confirm-import').hide();
                    $('#snn-export-import-modal').show();
                }
            });
        });

        $('#snn-import-btn').on('click', function(){
            $('#snn-modal-title').text('<?php echo esc_js( __( 'Import Snippets', 'snn' ) ); ?>');
            $('#snn-modal-textarea').val('');
            $('#snn-modal-confirm-import').show();
            $('#snn-export-import-modal').show();
        });

        $('#snn-modal-close').on('click', function(){ $('#snn-export-import-modal').hide(); });

        $('#snn-modal-confirm-import').on('click', function(){
            var json = $('#snn-modal-textarea').val();
            $.post(ajaxUrl, {action:'snn_import_snippets', json:json, nonce:nonce}, function(resp){
                if (resp.success) {
                    alert('<?php echo esc_js( __( 'Imported', 'snn' ) ); ?> ' + resp.data.imported + ' <?php echo esc_js( __( 'snippet(s).', 'snn' ) ); ?>');
                    window.location.reload();
                } else {
                    alert(resp.data && resp.data.message ? resp.data.message : 'Error');
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}

// ----------------------------------------------------------------------
// Edit view
// ----------------------------------------------------------------------

function snn_render_snippet_edit_view( $snippet_id ) {
    $is_new = ! $snippet_id;
    $post = $snippet_id ? get_post( $snippet_id ) : null;
    if ( $snippet_id && ( ! $post || $post->post_type !== 'snn_code_snippet' ) ) {
        echo '<p>' . esc_html__( 'Snippet not found.', 'snn' ) . '</p>';
        return;
    }

    $meta = $snippet_id ? snn_snippet_get_meta( $snippet_id ) : array( 'type' => 'php', 'location' => 'frontend_head', 'priority' => 10, 'tags' => array(), 'tags_raw' => '' );
    $type_defs = snn_snippet_get_type_defs();
    $location_defs = snn_snippet_get_location_defs();
    $back_url = remove_query_arg( array( 'view', 'id' ) );
    ?>
    <p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to all snippets', 'snn' ); ?></a></p>
    <h1><?php echo $is_new ? esc_html__( 'New Snippet', 'snn' ) : esc_html__( 'Edit Snippet', 'snn' ); ?></h1>

    <div class="notice notice-warning inline snn-php-execution-warning">
        <p><strong><?php esc_html_e( 'Warning:', 'snn' ); ?></strong> <?php esc_html_e( 'ATTENTION PLEASE! These settings are not for normal users! If you don\'t have at least some basic knowledge of HTML, CSS, and FTP login, DO NOT USE IT!', 'snn' ); ?></p>
        <p>
            <strong><?php esc_html_e( 'INFO:', 'snn' ); ?></strong>
            <?php printf( esc_html__( 'If needed use define( %s, true ); in wp-config.php to disable the code snippets feature temporarily.', 'snn' ), '<code>SNN_CODE_DISABLE</code>' ); ?>
        </p>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=snn-custom-codes-snippets' ) ); ?>">
        <?php wp_nonce_field( 'snn_save_snippet_action', 'snn_snippet_nonce' ); ?>
        <input type="hidden" name="snippet_id" value="<?php echo esc_attr( $snippet_id ); ?>">

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="snippet_title"><?php esc_html_e( 'Title', 'snn' ); ?></label></th>
                <td><input type="text" id="snippet_title" name="snippet_title" class="regular-text" value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>" required></td>
            </tr>
            <tr>
                <th><label for="snippet_description"><?php esc_html_e( 'Description', 'snn' ); ?></label></th>
                <td><input type="text" id="snippet_description" name="snippet_description" class="regular-text" value="<?php echo esc_attr( $post ? $post->post_excerpt : '' ); ?>"></td>
            </tr>
            <tr>
                <th><label for="snippet_type"><?php esc_html_e( 'Type', 'snn' ); ?></label></th>
                <td>
                    <select id="snippet_type" name="snippet_type">
                        <?php foreach ( $type_defs as $key => $def ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" data-cm-mode="<?php echo esc_attr( $def['cm_mode'] ); ?>" <?php selected( $meta['type'], $key ); ?>><?php echo esc_html( $def['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="snippet_location"><?php esc_html_e( 'Location', 'snn' ); ?></label></th>
                <td>
                    <select id="snippet_location" name="snippet_location">
                        <?php foreach ( $location_defs as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta['location'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Where and when this snippet runs.', 'snn' ); ?></p>
                </td>
            </tr>
            <tr id="snippet_allow_output_row" style="display:none;">
                <th><?php esc_html_e( 'Allow Direct Output', 'snn' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="snippet_allow_output" name="snippet_allow_output" value="1" <?php checked( ! empty( $meta['allow_output'] ) ); ?>>
                        <?php esc_html_e( 'Allow this snippet to output content directly', 'snn' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Like functions.php, "Everywhere (Immediate)" normally only defines functions and hooks and should not output anything directly -- any output is discarded to avoid "headers already sent" errors. Enable this only if the snippet intentionally echoes content.', 'snn' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="snippet_tags"><?php esc_html_e( 'Tags', 'snn' ); ?></label></th>
                <td><input type="text" id="snippet_tags" name="snippet_tags" class="regular-text" value="<?php echo esc_attr( $meta['tags_raw'] ); ?>" placeholder="<?php esc_attr_e( 'comma, separated, tags', 'snn' ); ?>"></td>
            </tr>
            <tr>
                <th><label for="snippet_priority"><?php esc_html_e( 'Priority', 'snn' ); ?></label></th>
                <td>
                    <input type="number" id="snippet_priority" name="snippet_priority" value="<?php echo esc_attr( $meta['priority'] ); ?>" min="1" max="999" style="width:100px;">
                    <p class="description"><?php esc_html_e( 'Lower numbers run first among snippets at the same location. Default: 10.', 'snn' ); ?></p>
                </td>
            </tr>
        </table>

        <div class="snn-editor-revision-wrapper">
            <div class="snn-editor-area">
                <textarea id="snn_snippet_code" name="snippet_code" class="large-text code" rows="25"><?php echo esc_textarea( $post ? $post->post_content : '' ); ?></textarea>
            </div>
            <?php if ( ! $is_new ) : ?>
                <div class="snn-revisions-panel" data-active-editor-id="snn_snippet_code">
                    <h4><?php esc_html_e( 'Revisions', 'snn' ); ?></h4>
                    <div class="snn-revisions-panel-inner">
                        <?php
                        $revisions = wp_revisions_enabled( $post ) ? wp_get_post_revisions( $snippet_id, array( 'posts_per_page' => 20 ) ) : array();
                        if ( ! empty( $revisions ) ) :
                            ?>
                            <ul class="snn-revisions-list">
                                <?php foreach ( $revisions as $revision ) :
                                    $author = get_userdata( $revision->post_author );
                                    $time_diff = human_time_diff( strtotime( $revision->post_date_gmt ), current_time( 'timestamp', true ) );
                                    ?>
                                    <li>
                                        <span class="revision-info">
                                            <?php
                                            printf(
                                                /* translators: 1: time ago, 2: author name */
                                                esc_html__( '%1$s ago by %2$s', 'snn' ),
                                                esc_html( $time_diff ),
                                                esc_html( $author ? $author->display_name : __( 'Unknown', 'snn' ) )
                                            );
                                            ?>
                                        </span>
                                        <div class="revision-actions">
                                            <button type="button" class="button button-secondary button-small snn-preview-revision" data-revision-id="<?php echo esc_attr( $revision->ID ); ?>"><?php esc_html_e( 'Preview in Editor', 'snn' ); ?></button>
                                            <button type="submit" name="snn_restore_submit_button" value="<?php echo esc_attr( $revision->ID ); ?>" class="button button-primary button-small snn-restore-revision-button" style="display:none;"><?php esc_html_e( 'Load Revision & Save', 'snn' ); ?></button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="snn-manage-revisions-section">
                                <?php wp_nonce_field( 'snn_clear_revisions_' . $snippet_id, 'snn_clear_revisions_nonce' ); ?>
                                <input type="hidden" name="snn_snippet_id_to_clear" value="<?php echo esc_attr( $snippet_id ); ?>">
                                <button type="submit" name="snn_clear_revisions_button" class="button button-danger snn-clear-revisions-button"><?php esc_html_e( 'Clear All Revisions', 'snn' ); ?></button>
                            </div>
                        <?php else : ?>
                            <p><?php esc_html_e( 'No past revisions yet. Save changes to start tracking revisions.', 'snn' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <p class="submit">
            <button type="submit" name="snn_save_snippet" class="button button-primary button-large"><?php esc_html_e( 'Save Snippet', 'snn' ); ?></button>
            <?php if ( ! $is_new ) :
                $download_url = wp_nonce_url( add_query_arg( array( 'page' => 'snn-custom-codes-snippets', 'snn_download' => $snippet_id ), admin_url( 'admin.php' ) ), 'snn_download_' . $snippet_id );
                ?>
                <a href="<?php echo esc_url( $download_url ); ?>" class="button"><?php esc_html_e( 'Download', 'snn' ); ?></a>
                <a href="#" class="button button-link-delete snn-delete-snippet-inline" data-id="<?php echo esc_attr( $snippet_id ); ?>" data-redirect="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Delete', 'snn' ); ?></a>
            <?php endif; ?>
        </p>
    </form>

    <script>
    (function($){
        var typeModeMap = {};
        $('#snippet_type option').each(function(){ typeModeMap[$(this).val()] = $(this).data('cm-mode'); });
        $('#snippet_type').on('change', function(){
            var mode = typeModeMap[$(this).val()];
            var ta = document.getElementById('snn_snippet_code');
            if (ta && ta.CodeMirror && mode) { ta.CodeMirror.setOption('mode', mode); }
        });

        function toggleAllowOutputRow(){
            $('#snippet_allow_output_row').toggle($('#snippet_location').val() === 'immediate');
        }
        $('#snippet_location').on('change', toggleAllowOutputRow);
        toggleAllowOutputRow();

        $('.snn-delete-snippet-inline').on('click', function(e){
            e.preventDefault();
            if (!confirm('<?php echo esc_js( __( 'Delete this snippet? This cannot be undone.', 'snn' ) ); ?>')) return;
            var id = $(this).data('id'), redirect = $(this).data('redirect');
            $.post('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {action:'snn_delete_snippet', id:id, nonce:'<?php echo esc_js( wp_create_nonce( 'snn_snippets_manage_nonce' ) ); ?>'}, function(resp){
                if (resp.success) { window.location.href = redirect; }
                else { alert(resp.data && resp.data.message ? resp.data.message : 'Error'); }
            });
        });
    })(jQuery);
    </script>
    <?php
}

// ----------------------------------------------------------------------
// Error logs view
// ----------------------------------------------------------------------

function snn_render_error_logs_view() {
    $back_url = remove_query_arg( array( 'view', 'id' ) );
    ?>
    <p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to all snippets', 'snn' ); ?></a></p>
    <h1><?php esc_html_e( 'Snippet Execution Error Logs', 'snn' ); ?></h1>
    <p><?php printf( esc_html__( 'This log shows the last %d errors recorded from snippet executions. A fatal error automatically disables only the snippet that caused it.', 'snn' ), SNN_CUSTOM_CODES_MAX_LOG_ENTRIES ); ?></p>
    <?php
    $error_logs = get_option( SNN_CUSTOM_CODES_LOG_OPTION, array() );
    if ( ! is_array( $error_logs ) ) {
        $error_logs = array();
    }

    if ( ! empty( $error_logs ) ) :
        ?>
        <form method="post">
            <table class="snn-error-logs-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Timestamp', 'snn' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'snn' ); ?></th>
                        <th><?php esc_html_e( 'Snippet', 'snn' ); ?></th>
                        <th><?php esc_html_e( 'Line', 'snn' ); ?></th>
                        <th><?php esc_html_e( 'Function/Context', 'snn' ); ?></th>
                        <th class="snn-log-message"><?php esc_html_e( 'Error Message', 'snn' ); ?></th>
                        <th><?php esc_html_e( 'Code Context', 'snn' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $error_logs as $log_entry ) :
                        $snippet_title = isset( $log_entry['snippet_title'] ) ? $log_entry['snippet_title'] : __( 'Unknown', 'snn' );
                        $function_context = isset( $log_entry['function_context'] ) ? $log_entry['function_context'] : '';
                        $line_number = isset( $log_entry['line'] ) ? absint( $log_entry['line'] ) : 0;
                        ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log_entry['timestamp'] ) ) ); ?></td>
                            <td><strong><?php echo esc_html( $log_entry['type'] ); ?></strong></td>
                            <td><strong><?php echo esc_html( $snippet_title ); ?></strong></td>
                            <td style="text-align: center;"><?php echo $line_number > 0 ? '<strong>' . esc_html( $line_number ) . '</strong>' : '<em>N/A</em>'; ?></td>
                            <td><?php echo $function_context ? '<code>' . esc_html( $function_context ) . '</code>' : '<em>' . esc_html__( 'Top-level', 'snn' ) . '</em>'; ?></td>
                            <td class="snn-log-message"><pre style="max-width: 400px; overflow-x: auto;"><?php echo esc_html( $log_entry['message'] ); ?></pre></td>
                            <td class="snn-log-message">
                                <?php if ( ! empty( $log_entry['code_context'] ) ) : ?>
                                    <details><summary><?php esc_html_e( 'View Code', 'snn' ); ?></summary><pre style="background: #f9f9f9; padding: 10px; border: 1px solid #ddd; font-size: 11px; line-height: 1.4; overflow-x: auto;"><?php echo esc_html( $log_entry['code_context'] ); ?></pre></details>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'N/A', 'snn' ); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <?php wp_nonce_field( 'snn_clear_error_logs_action', 'snn_clear_error_logs_nonce' ); ?>
                <button type="submit" name="snn_clear_error_logs_button" class="button button-danger snn-clear-error-logs-button"><?php esc_html_e( 'Clear All Error Logs', 'snn' ); ?></button>
            </p>
        </form>
    <?php else : ?>
        <p><?php esc_html_e( 'No errors logged yet.', 'snn' ); ?></p>
    <?php endif;
}
