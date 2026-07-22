<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SNN_REDIRECTS_DB_VERSION_OPTION', 'snn_redirects_db_version' );
// Bump whenever the table schema changes -- snn_redirects_maybe_upgrade_db()
// re-runs dbDelta() automatically the next time an admin page loads.
define( 'SNN_REDIRECTS_DB_VERSION', 2 );

// ----------------------------------------------------------------------
// Table + options helpers
// ----------------------------------------------------------------------

function snn_redirects_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_redirects';
}

function snn_404_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_404_log';
}

function snn_redirects_get_options() {
    $defaults = array(
        'log_404_enabled' => true,
        'exclude_bots'    => true,
        'retention_days'  => 90,
    );
    $options = get_option( 'snn_redirects_options', array() );
    return wp_parse_args( is_array( $options ) ? $options : array(), $defaults );
}

function snn_redirects_maybe_upgrade_db() {
    if ( (int) get_option( SNN_REDIRECTS_DB_VERSION_OPTION, 0 ) >= SNN_REDIRECTS_DB_VERSION ) {
        return;
    }

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $redirects_table = snn_redirects_table();
    $log_table       = snn_404_log_table();

    $sql = "CREATE TABLE {$redirects_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(191) NOT NULL,
        target TEXT NOT NULL,
        http_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
        hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
        enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY source (source)
    ) {$charset_collate};
    CREATE TABLE {$log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url VARCHAR(191) NOT NULL,
        hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
        ignored TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY url (url),
        KEY last_seen (last_seen),
        KEY ignored (ignored)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( SNN_REDIRECTS_DB_VERSION_OPTION, SNN_REDIRECTS_DB_VERSION );
}
add_action( 'admin_init', 'snn_redirects_maybe_upgrade_db', 5 );

// ----------------------------------------------------------------------
// Path helpers
// ----------------------------------------------------------------------

function snn_redirects_normalize_path( $url ) {
    $url = preg_replace( '/^https?:\/\/[^\/]+/i', '', (string) $url );
    $url = rawurldecode( $url );
    if ( substr( $url, 0, 1 ) !== '/' ) {
        $url = '/' . $url;
    }
    if ( $url !== '/' && substr( $url, -1 ) === '/' ) {
        $url = rtrim( $url, '/' );
    }
    $url = function_exists( 'mb_strtolower' ) ? mb_strtolower( $url, 'UTF-8' ) : strtolower( $url );
    return substr( $url, 0, 191 );
}

function snn_redirects_validate_target( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) {
        return false;
    }
    if ( substr( $url, 0, 1 ) === '/' ) {
        return true;
    }
    return (bool) filter_var( $url, FILTER_VALIDATE_URL );
}

// ----------------------------------------------------------------------
// Redirect matching (frontend hot path, cached)
// ----------------------------------------------------------------------

function snn_redirects_clear_cache() {
    delete_transient( 'snn_redirects_cache' );
}

function snn_redirects_get_active() {
    if ( ! is_admin() ) {
        $cached = get_transient( 'snn_redirects_cache' );
        if ( false !== $cached ) {
            return $cached;
        }
    }

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, source, target, http_code, hits FROM " . snn_redirects_table() . " WHERE enabled = 1",
        ARRAY_A
    );

    $exact    = array();
    $wildcard = array();
    foreach ( (array) $rows as $row ) {
        if ( substr( $row['source'], -2 ) === '/*' ) {
            $wildcard[] = $row;
        } else {
            $exact[] = $row;
        }
    }
    // Exact matches must be checked before wildcards regardless of insertion order.
    $active = array( 'exact' => $exact, 'wildcard' => $wildcard );

    if ( ! is_admin() ) {
        set_transient( 'snn_redirects_cache', $active, 12 * HOUR_IN_SECONDS );
    }

    return $active;
}

function snn_redirects_bump_hits( $id ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE " . snn_redirects_table() . " SET hits = hits + 1 WHERE id = %d",
        $id
    ) );
}

function snn_redirects_handle_frontend() {
    if ( is_admin() ) {
        return;
    }

    $active = snn_redirects_get_active();
    if ( empty( $active['exact'] ) && empty( $active['wildcard'] ) ) {
        return;
    }

    $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
    $parsed       = wp_parse_url( $request_uri );
    $path         = isset( $parsed['path'] ) ? rawurldecode( $parsed['path'] ) : '/';
    $current_path = snn_redirects_normalize_path( $path );
    $query_string = isset( $parsed['query'] ) ? $parsed['query'] : '';

    foreach ( $active['exact'] as $redirect ) {
        if ( $redirect['source'] !== $current_path ) {
            continue;
        }
        $target = $redirect['target'];
        if ( $query_string ) {
            $target .= ( strpos( $target, '?' ) !== false ? '&' : '?' ) . $query_string;
        }
        if ( strpos( $target, 'http' ) !== 0 ) {
            $target = home_url( $target );
        }
        snn_redirects_bump_hits( $redirect['id'] );
        nocache_headers();
        wp_redirect( $target, (int) $redirect['http_code'] );
        exit;
    }

    foreach ( $active['wildcard'] as $redirect ) {
        $base_from = substr( $redirect['source'], 0, -2 );
        if ( $current_path !== $base_from && strpos( $current_path, $base_from . '/' ) !== 0 ) {
            continue;
        }
        $leftover = ltrim( substr( $current_path, strlen( $base_from ) ), '/' );
        if ( strpos( $leftover, '..' ) !== false ) {
            continue;
        }
        $base_to = $redirect['target'];
        if ( substr( $base_to, -2 ) === '/*' ) {
            $base_to = substr( $base_to, 0, -2 );
        }
        $target = rtrim( $base_to, '/' );
        if ( '' !== $leftover ) {
            $target .= '/' . $leftover;
        }
        if ( $query_string ) {
            $target .= ( strpos( $target, '?' ) !== false ? '&' : '?' ) . $query_string;
        }
        if ( strpos( $target, 'http' ) !== 0 ) {
            $target = home_url( $target );
        }
        snn_redirects_bump_hits( $redirect['id'] );
        nocache_headers();
        wp_redirect( $target, (int) $redirect['http_code'] );
        exit;
    }
}
add_action( 'template_redirect', 'snn_redirects_handle_frontend', 0 );

// ----------------------------------------------------------------------
// 404 logging, grouped by URL
// ----------------------------------------------------------------------

function snn_log_404() {
    if ( ! is_404() ) {
        return;
    }
    $options = snn_redirects_get_options();
    if ( empty( $options['log_404_enabled'] ) ) {
        return;
    }
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        return;
    }
    $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    if ( ! empty( $options['exclude_bots'] ) && function_exists( 'snn_analytics_is_bot' ) && snn_analytics_is_bot( $user_agent ) ) {
        return;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
    $url         = snn_redirects_normalize_path( $request_uri );

    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO " . snn_404_log_table() . " (url, hits, first_seen, last_seen) VALUES (%s, 1, %s, %s)
         ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
        $url, $now, $now, $now
    ) );
}
add_action( 'template_redirect', 'snn_log_404', 20 );

// ----------------------------------------------------------------------
// Daily cleanup
// ----------------------------------------------------------------------

function snn_redirects_prune_404_log() {
    global $wpdb;
    $options = snn_redirects_get_options();
    $days    = max( 1, (int) $options['retention_days'] );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM " . snn_404_log_table() . " WHERE last_seen < %s",
        gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
    ) );
}
add_action( 'snn_redirects_daily_prune', 'snn_redirects_prune_404_log' );

function snn_redirects_schedule_prune() {
    if ( ! wp_next_scheduled( 'snn_redirects_daily_prune' ) ) {
        wp_schedule_event( time(), 'daily', 'snn_redirects_daily_prune' );
    }
}
add_action( 'init', 'snn_redirects_schedule_prune' );

// ----------------------------------------------------------------------
// Admin page
// ----------------------------------------------------------------------

function snn_redirects_add_submenu() {
    add_submenu_page(
        'snn-settings',
        __( 'Redirects', 'snn' ),
        __( 'Redirects', 'snn' ),
        'manage_options',
        'snn-redirects',
        'snn_redirects_page'
    );
}
add_action( 'admin_menu', 'snn_redirects_add_submenu' );

function snn_redirects_handle_actions() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Add or edit a redirect.
    if ( isset( $_POST['snn_redirect_save'] ) && check_admin_referer( 'snn_redirect_save_action', 'snn_redirect_save_nonce' ) ) {
        $source     = snn_redirects_normalize_path( sanitize_text_field( wp_unslash( $_POST['snn_redirect_source'] ?? '' ) ) );
        $target     = trim( sanitize_text_field( wp_unslash( $_POST['snn_redirect_target'] ?? '' ) ) );
        $http_code  = in_array( (int) ( $_POST['snn_redirect_http_code'] ?? 301 ), array( 301, 302, 307 ), true ) ? (int) $_POST['snn_redirect_http_code'] : 301;
        $edit_id    = isset( $_POST['snn_redirect_id'] ) ? absint( $_POST['snn_redirect_id'] ) : 0;

        if ( '' === $source || '/' === $source ) {
            add_settings_error( 'snn-redirects', 'invalid_source', __( 'Please enter a source path.', 'snn' ), 'error' );
        } elseif ( ! snn_redirects_validate_target( $target ) ) {
            add_settings_error( 'snn-redirects', 'invalid_target', __( 'Please enter a valid target URL or path.', 'snn' ), 'error' );
        } else {
            global $wpdb;
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . snn_redirects_table() . " WHERE source = %s", $source
            ) );

            if ( $existing_id && $existing_id !== $edit_id ) {
                add_settings_error( 'snn-redirects', 'duplicate_source', __( 'A redirect for this source already exists.', 'snn' ), 'error' );
            } else {
                if ( $edit_id ) {
                    $wpdb->update(
                        snn_redirects_table(),
                        array( 'source' => $source, 'target' => $target, 'http_code' => $http_code ),
                        array( 'id' => $edit_id ),
                        array( '%s', '%s', '%d' ),
                        array( '%d' )
                    );
                } else {
                    $wpdb->insert(
                        snn_redirects_table(),
                        array(
                            'source'     => $source,
                            'target'     => $target,
                            'http_code'  => $http_code,
                            'enabled'    => 1,
                            'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%s', '%s', '%d', '%d', '%s' )
                    );
                }
                // Creating a redirect for a URL that was showing up as a 404 --
                // that 404 is now resolved, so drop it from the log.
                $wpdb->delete( snn_404_log_table(), array( 'url' => $source ), array( '%s' ) );

                snn_redirects_clear_cache();
                add_settings_error( 'snn-redirects', 'saved', __( 'Redirect saved.', 'snn' ), 'updated' );
            }
        }
    }

    // Toggle enabled/disabled.
    if ( isset( $_POST['snn_redirect_toggle'] ) && check_admin_referer( 'snn_redirect_toggle_action', 'snn_redirect_toggle_nonce' ) ) {
        global $wpdb;
        $id      = absint( $_POST['snn_redirect_id'] ?? 0 );
        $enabled = isset( $_POST['snn_redirect_enabled'] ) ? 1 : 0;
        if ( $id ) {
            $wpdb->update( snn_redirects_table(), array( 'enabled' => $enabled ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
            snn_redirects_clear_cache();
        }
    }

    // Delete a redirect.
    if ( isset( $_POST['snn_redirect_delete'] ) && check_admin_referer( 'snn_redirect_delete_action', 'snn_redirect_delete_nonce' ) ) {
        global $wpdb;
        $id = absint( $_POST['snn_redirect_id'] ?? 0 );
        if ( $id ) {
            $wpdb->delete( snn_redirects_table(), array( 'id' => $id ), array( '%d' ) );
            snn_redirects_clear_cache();
            add_settings_error( 'snn-redirects', 'deleted', __( 'Redirect deleted.', 'snn' ), 'updated' );
        }
    }

    // Delete a single 404 log entry.
    if ( isset( $_POST['snn_404_delete'] ) && check_admin_referer( 'snn_404_delete_action', 'snn_404_delete_nonce' ) ) {
        global $wpdb;
        $wpdb->delete( snn_404_log_table(), array( 'id' => absint( $_POST['snn_404_id'] ?? 0 ) ), array( '%d' ) );
    }

    // Ignore/un-ignore a single 404 log entry -- for known noise (bot
    // probes, scanner requests) that keeps reappearing after being
    // deleted, since a later hit to the same URL just re-inserts it.
    // Ignoring instead just hides it from the default list while still
    // tracking hits/last_seen, and can be undone.
    if ( isset( $_POST['snn_404_ignore'] ) && check_admin_referer( 'snn_404_ignore_action', 'snn_404_ignore_nonce' ) ) {
        global $wpdb;
        $wpdb->update( snn_404_log_table(), array( 'ignored' => 1 ), array( 'id' => absint( $_POST['snn_404_id'] ?? 0 ) ), array( '%d' ), array( '%d' ) );
    }
    if ( isset( $_POST['snn_404_unignore'] ) && check_admin_referer( 'snn_404_unignore_action', 'snn_404_unignore_nonce' ) ) {
        global $wpdb;
        $wpdb->update( snn_404_log_table(), array( 'ignored' => 0 ), array( 'id' => absint( $_POST['snn_404_id'] ?? 0 ) ), array( '%d' ), array( '%d' ) );
    }

    // Ignore several 404 log entries at once (checkbox selection), so
    // clearing out a batch of recurring bot/scanner noise doesn't require
    // one click per row.
    if ( isset( $_POST['snn_404_bulk_ignore'] ) && check_admin_referer( 'snn_404_bulk_ignore_action', 'snn_404_bulk_ignore_nonce' ) ) {
        global $wpdb;
        $ids = isset( $_POST['snn_404_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['snn_404_ids'] ) ) ) : array();
        if ( $ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( "UPDATE " . snn_404_log_table() . " SET ignored = 1 WHERE id IN ({$placeholders})", $ids ) );
        }
    }

    // Clear all 404 log entries.
    if ( isset( $_POST['snn_404_clear_all'] ) && check_admin_referer( 'snn_404_clear_action', 'snn_404_clear_nonce' ) ) {
        global $wpdb;
        $wpdb->query( 'DELETE FROM ' . snn_404_log_table() );
        add_settings_error( 'snn-redirects', '404_cleared', __( '404 log cleared.', 'snn' ), 'updated' );
    }

    // Save settings.
    if ( isset( $_POST['snn_redirects_save_settings'] ) && check_admin_referer( 'snn_redirects_settings_action', 'snn_redirects_settings_nonce' ) ) {
        update_option( 'snn_redirects_options', array(
            'log_404_enabled' => isset( $_POST['log_404_enabled'] ) ? 1 : 0,
            'exclude_bots'    => isset( $_POST['exclude_bots'] ) ? 1 : 0,
            'retention_days'  => max( 1, absint( $_POST['retention_days'] ?? 90 ) ),
        ) );
        add_settings_error( 'snn-redirects', 'settings_saved', __( 'Settings saved.', 'snn' ), 'updated' );
    }
}
add_action( 'admin_init', 'snn_redirects_handle_actions' );

function snn_redirects_admin_styles() {
    ?>
    <style>
        .snn-redirects-tabs { margin: 14px 0 0; }
        .snn-redirects-tab-content { display: none; }
        .snn-redirects-tab-content.active { display: block; }
        .snn-redirects-add-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 16px 20px; margin: 16px 0; max-width: 900px; }
        .snn-redirects-add-card h2 { margin-top: 0; }
        .snn-redirects-field-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
        .snn-redirects-field-row .field { flex: 1; min-width: 220px; }
        .snn-redirects-field-row label { display: block; font-weight: 600; margin-bottom: 4px; }
        .snn-redirects-field-row input[type="text"], .snn-redirects-field-row select { width: 100%; box-sizing: border-box; }
        table.snn-redirects-table td { vertical-align: middle; }
        .snn-redirects-hits { color: #646970; }
        .snn-redirects-ignored-details { margin-top: 20px; }
        .snn-redirects-ignored-details summary { cursor: pointer; color: #646970; padding: 8px 0; }
        .snn-redirects-ignored-details summary:hover { color: #2271b1; }
        .snn-redirects-ignored-details table { margin-top: 10px; }
        .snn-redirects-table th.snn-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        .snn-redirects-table th.snn-sortable:hover { color: #2271b1; }
        .snn-redirects-table th.snn-sortable::after { content: "\2195"; margin-left: 4px; color: #c3c4c7; font-weight: normal; }
        .snn-redirects-table th.snn-sorted-asc::after { content: "\2191"; color: #2271b1; }
        .snn-redirects-table th.snn-sorted-desc::after { content: "\2193"; color: #2271b1; }
        .snn-redirects-settings-row { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; margin: 12px 0; }
        .snn-redirects-table th.snn-redirects-checkbox-col, .snn-redirects-table td.snn-redirects-checkbox-col { width: 28px; text-align: center; }
        #snn-404-bulk-ignore-btn { margin-left: 6px; }
    </style>
    <?php
}

function snn_redirects_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    snn_redirects_admin_styles();
    settings_errors( 'snn-redirects' );

    global $wpdb;
    $redirects_table = snn_redirects_table();
    $log_table       = snn_404_log_table();
    $options         = snn_redirects_get_options();

    $redirects   = $wpdb->get_results( "SELECT * FROM {$redirects_table} ORDER BY created_at DESC", ARRAY_A );
    $log_404     = $wpdb->get_results( "SELECT * FROM {$log_table} WHERE ignored = 0 ORDER BY last_seen DESC LIMIT 200", ARRAY_A );
    $ignored_404 = $wpdb->get_results( "SELECT * FROM {$log_table} WHERE ignored = 1 ORDER BY last_seen DESC LIMIT 200", ARRAY_A );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Redirects', 'snn' ); ?></h1>

        <h2 class="nav-tab-wrapper snn-redirects-tabs">
            <a href="#snn-redirects-tab" class="nav-tab nav-tab-active" data-tab="snn-redirects-tab"><?php esc_html_e( 'Redirects', 'snn' ); ?></a>
            <a href="#snn-404-tab" class="nav-tab" data-tab="snn-404-tab"><?php echo esc_html( sprintf( __( '404s (%d)', 'snn' ), count( $log_404 ) ) ); ?></a>
            <a href="#snn-redirects-settings-tab" class="nav-tab" data-tab="snn-redirects-settings-tab"><?php esc_html_e( 'Settings', 'snn' ); ?></a>
        </h2>

        <div id="snn-redirects-tab" class="snn-redirects-tab-content active">
            <div class="snn-redirects-add-card">
                <h2><?php esc_html_e( 'Add Redirect', 'snn' ); ?></h2>
                <form method="post" id="snn-redirect-add-form">
                    <?php wp_nonce_field( 'snn_redirect_save_action', 'snn_redirect_save_nonce' ); ?>
                    <input type="hidden" name="snn_redirect_id" id="snn_redirect_id" value="">
                    <div class="snn-redirects-field-row">
                        <div class="field">
                            <label for="snn_redirect_source"><?php esc_html_e( 'Source URL', 'snn' ); ?></label>
                            <input type="text" name="snn_redirect_source" id="snn_redirect_source" placeholder="/old-page or /old-section/*" required>
                        </div>
                        <div class="field">
                            <label for="snn_redirect_target"><?php esc_html_e( 'Target URL', 'snn' ); ?></label>
                            <input type="text" name="snn_redirect_target" id="snn_redirect_target" placeholder="/new-page or https://example.com/new-page" required>
                        </div>
                        <div class="field" style="max-width:220px;">
                            <label for="snn_redirect_http_code"><?php esc_html_e( 'HTTP Code', 'snn' ); ?></label>
                            <select name="snn_redirect_http_code" id="snn_redirect_http_code">
                                <option value="301"><?php esc_html_e( '301 - Moved Permanently', 'snn' ); ?></option>
                                <option value="302"><?php esc_html_e( '302 - Found (Temporary)', 'snn' ); ?></option>
                                <option value="307"><?php esc_html_e( '307 - Temporary Redirect', 'snn' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <p class="description"><?php esc_html_e( 'End the source with /* to redirect everything below that path (e.g. /old-section/* to /new-section/*).', 'snn' ); ?></p>
                    <p class="submit">
                        <button type="submit" name="snn_redirect_save" class="button button-primary" id="snn-redirect-submit-btn"><?php esc_html_e( 'Add Redirect', 'snn' ); ?></button>
                        <button type="button" class="button" id="snn-redirect-cancel-edit" style="display:none;"><?php esc_html_e( 'Cancel Edit', 'snn' ); ?></button>
                    </p>
                </form>
            </div>

            <?php if ( empty( $redirects ) ) : ?>
                <p><?php esc_html_e( 'No redirects yet.', 'snn' ); ?></p>
            <?php else : ?>
                <table class="widefat striped snn-redirects-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Source', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Target', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Code', 'snn' ); ?></th>
                            <th class="snn-sortable"><?php esc_html_e( 'Hits', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Active', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'snn' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $redirects as $r ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $r['source'] ); ?></code></td>
                                <td><code><?php echo esc_html( $r['target'] ); ?></code></td>
                                <td><?php echo esc_html( $r['http_code'] ); ?></td>
                                <td class="snn-redirects-hits" data-sort-value="<?php echo (int) $r['hits']; ?>"><?php echo esc_html( number_format_i18n( (int) $r['hits'] ) ); ?></td>
                                <td>
                                    <form method="post" style="display:inline;" onchange="this.requestSubmit()">
                                        <?php wp_nonce_field( 'snn_redirect_toggle_action', 'snn_redirect_toggle_nonce' ); ?>
                                        <input type="hidden" name="snn_redirect_id" value="<?php echo esc_attr( $r['id'] ); ?>">
                                        <input type="hidden" name="snn_redirect_toggle" value="1">
                                        <label class="snn-admin-toggle">
                                            <input type="checkbox" name="snn_redirect_enabled" value="1" <?php checked( (int) $r['enabled'], 1 ); ?>><span class="snn-admin-toggle-slider"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <button type="button" class="button button-small snn-redirect-edit-btn"
                                        data-id="<?php echo esc_attr( $r['id'] ); ?>"
                                        data-source="<?php echo esc_attr( $r['source'] ); ?>"
                                        data-target="<?php echo esc_attr( $r['target'] ); ?>"
                                        data-code="<?php echo esc_attr( $r['http_code'] ); ?>"><?php esc_html_e( 'Edit', 'snn' ); ?></button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'snn' ) ); ?>');">
                                        <?php wp_nonce_field( 'snn_redirect_delete_action', 'snn_redirect_delete_nonce' ); ?>
                                        <input type="hidden" name="snn_redirect_id" value="<?php echo esc_attr( $r['id'] ); ?>">
                                        <button type="submit" name="snn_redirect_delete" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'snn' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div id="snn-404-tab" class="snn-redirects-tab-content">
            <?php if ( ! empty( $log_404 ) ) : ?>
                <form method="post" id="snn-404-bulk-form" style="display:none;">
                    <?php wp_nonce_field( 'snn_404_bulk_ignore_action', 'snn_404_bulk_ignore_nonce' ); ?>
                </form>
                <p>
                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire 404 log?', 'snn' ) ); ?>');">
                        <?php wp_nonce_field( 'snn_404_clear_action', 'snn_404_clear_nonce' ); ?>
                        <button type="submit" name="snn_404_clear_all" class="button"><?php esc_html_e( 'Clear All', 'snn' ); ?></button>
                    </form>
                    <button type="submit" form="snn-404-bulk-form" name="snn_404_bulk_ignore" id="snn-404-bulk-ignore-btn" class="button" disabled><?php esc_html_e( 'Ignore selected', 'snn' ); ?><span id="snn-404-bulk-count"></span></button>
                </p>
            <?php endif; ?>

            <?php if ( empty( $log_404 ) ) : ?>
                <p><?php esc_html_e( 'No 404s logged.', 'snn' ); ?></p>
            <?php else : ?>
                <table class="widefat striped snn-redirects-table">
                    <thead>
                        <tr>
                            <th class="snn-redirects-checkbox-col"><input type="checkbox" id="snn-404-select-all" aria-label="<?php esc_attr_e( 'Select all', 'snn' ); ?>"></th>
                            <th><?php esc_html_e( 'URL', 'snn' ); ?></th>
                            <th class="snn-sortable"><?php esc_html_e( 'Hits', 'snn' ); ?></th>
                            <th class="snn-sortable"><?php esc_html_e( 'First Seen', 'snn' ); ?></th>
                            <th class="snn-sortable"><?php esc_html_e( 'Last Seen', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'snn' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log_404 as $log ) : ?>
                            <tr>
                                <td class="snn-redirects-checkbox-col"><input type="checkbox" class="snn-404-row-checkbox" name="snn_404_ids[]" form="snn-404-bulk-form" value="<?php echo esc_attr( $log['id'] ); ?>"></td>
                                <td>
                                    <a href="<?php echo esc_url( home_url( $log['url'] ) ); ?>" target="_blank"><code><?php echo esc_html( $log['url'] ); ?></code></a>
                                </td>
                                <td class="snn-redirects-hits" data-sort-value="<?php echo (int) $log['hits']; ?>"><?php echo esc_html( number_format_i18n( (int) $log['hits'] ) ); ?></td>
                                <td data-sort-value="<?php echo (int) strtotime( $log['first_seen'] ); ?>"><?php echo esc_html( $log['first_seen'] ); ?></td>
                                <td data-sort-value="<?php echo (int) strtotime( $log['last_seen'] ); ?>"><?php echo esc_html( $log['last_seen'] ); ?></td>
                                <td>
                                    <button type="button" class="button button-small snn-404-add-redirect-btn" data-url="<?php echo esc_attr( $log['url'] ); ?>"><?php esc_html_e( 'Add Redirect', 'snn' ); ?></button>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( 'snn_404_ignore_action', 'snn_404_ignore_nonce' ); ?>
                                        <input type="hidden" name="snn_404_id" value="<?php echo esc_attr( $log['id'] ); ?>">
                                        <button type="submit" name="snn_404_ignore" class="button button-small" title="<?php esc_attr_e( 'Hide this URL from the list -- future hits still count, but it will not show up here again unless you un-ignore it.', 'snn' ); ?>"><?php esc_html_e( 'Ignore', 'snn' ); ?></button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( 'snn_404_delete_action', 'snn_404_delete_nonce' ); ?>
                                        <input type="hidden" name="snn_404_id" value="<?php echo esc_attr( $log['id'] ); ?>">
                                        <button type="submit" name="snn_404_delete" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'snn' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! empty( $ignored_404 ) ) : ?>
                <details class="snn-redirects-ignored-details">
                    <summary><?php echo esc_html( sprintf( __( 'Ignored URLs (%d)', 'snn' ), count( $ignored_404 ) ) ); ?></summary>
                    <table class="widefat striped snn-redirects-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'URL', 'snn' ); ?></th>
                                <th class="snn-sortable"><?php esc_html_e( 'Hits', 'snn' ); ?></th>
                                <th class="snn-sortable"><?php esc_html_e( 'Last Seen', 'snn' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'snn' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $ignored_404 as $log ) : ?>
                                <tr>
                                    <td><code><?php echo esc_html( $log['url'] ); ?></code></td>
                                    <td class="snn-redirects-hits" data-sort-value="<?php echo (int) $log['hits']; ?>"><?php echo esc_html( number_format_i18n( (int) $log['hits'] ) ); ?></td>
                                    <td data-sort-value="<?php echo (int) strtotime( $log['last_seen'] ); ?>"><?php echo esc_html( $log['last_seen'] ); ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field( 'snn_404_unignore_action', 'snn_404_unignore_nonce' ); ?>
                                            <input type="hidden" name="snn_404_id" value="<?php echo esc_attr( $log['id'] ); ?>">
                                            <button type="submit" name="snn_404_unignore" class="button button-small"><?php esc_html_e( 'Un-ignore', 'snn' ); ?></button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field( 'snn_404_delete_action', 'snn_404_delete_nonce' ); ?>
                                            <input type="hidden" name="snn_404_id" value="<?php echo esc_attr( $log['id'] ); ?>">
                                            <button type="submit" name="snn_404_delete" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'snn' ); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>

        <div id="snn-redirects-settings-tab" class="snn-redirects-tab-content">
            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'snn_redirects_settings_action', 'snn_redirects_settings_nonce' ); ?>
                <div class="snn-redirects-settings-row">
                    <label class="snn-admin-check-label">
                        <input type="checkbox" name="log_404_enabled" value="1" <?php checked( ! empty( $options['log_404_enabled'] ) ); ?>><span class="snn-admin-toggle-slider"></span>
                        <?php esc_html_e( 'Log 404 errors', 'snn' ); ?>
                    </label>
                    <label class="snn-admin-check-label">
                        <input type="checkbox" name="exclude_bots" value="1" <?php checked( ! empty( $options['exclude_bots'] ) ); ?>><span class="snn-admin-toggle-slider"></span>
                        <?php esc_html_e( 'Exclude bots/crawlers from 404 logging', 'snn' ); ?>
                    </label>
                    <label>
                        <?php esc_html_e( 'Keep 404 log entries for', 'snn' ); ?>
                        <input type="number" name="retention_days" value="<?php echo esc_attr( $options['retention_days'] ); ?>" min="1" style="width:80px;">
                        <?php esc_html_e( 'days since last hit', 'snn' ); ?>
                    </label>
                </div>
                <p class="submit">
                    <button type="submit" name="snn_redirects_save_settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'snn' ); ?></button>
                </p>
            </form>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var tabLinks = document.querySelectorAll('.snn-redirects-tabs .nav-tab');
        var tabContents = document.querySelectorAll('.snn-redirects-tab-content');
        var TAB_STORAGE_KEY = 'snn_redirects_active_tab';
        function activateTab(id) {
            tabLinks.forEach(function(l) { l.classList.toggle('nav-tab-active', l.dataset.tab === id); });
            tabContents.forEach(function(c) { c.classList.toggle('active', c.id === id); });
            try { sessionStorage.setItem(TAB_STORAGE_KEY, id); } catch (e) {}
        }
        tabLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                activateTab(this.dataset.tab);
            });
        });
        // Every action on this page (ignore, delete, toggle, ...) is a real
        // form POST that reloads the page -- without this, the reload always
        // landed back on the first tab, no matter which tab the action was
        // performed from. Remember the active tab across the reload.
        try {
            var storedTab = sessionStorage.getItem(TAB_STORAGE_KEY);
            if (storedTab && document.getElementById(storedTab)) {
                activateTab(storedTab);
            }
        } catch (e) {}

        var form        = document.getElementById('snn-redirect-add-form');
        var idField      = document.getElementById('snn_redirect_id');
        var sourceField   = document.getElementById('snn_redirect_source');
        var targetField   = document.getElementById('snn_redirect_target');
        var codeField     = document.getElementById('snn_redirect_http_code');
        var submitBtn     = document.getElementById('snn-redirect-submit-btn');
        var cancelBtn     = document.getElementById('snn-redirect-cancel-edit');

        function resetForm() {
            idField.value = '';
            form.reset();
            submitBtn.textContent = '<?php echo esc_js( __( 'Add Redirect', 'snn' ) ); ?>';
            cancelBtn.style.display = 'none';
        }

        document.querySelectorAll('.snn-redirect-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                idField.value = this.dataset.id;
                sourceField.value = this.dataset.source;
                targetField.value = this.dataset.target;
                codeField.value = this.dataset.code;
                submitBtn.textContent = '<?php echo esc_js( __( 'Save Changes', 'snn' ) ); ?>';
                cancelBtn.style.display = 'inline-block';
                sourceField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });
        if (cancelBtn) {
            cancelBtn.addEventListener('click', resetForm);
        }

        // Bulk-select 404 rows to ignore several at once instead of one
        // click per row.
        var selectAll     = document.getElementById('snn-404-select-all');
        var rowCheckboxes  = document.querySelectorAll('.snn-404-row-checkbox');
        var bulkIgnoreBtn  = document.getElementById('snn-404-bulk-ignore-btn');
        var bulkCountLabel = document.getElementById('snn-404-bulk-count');
        function updateBulkState() {
            var checked = document.querySelectorAll('.snn-404-row-checkbox:checked');
            if (bulkIgnoreBtn) {
                bulkIgnoreBtn.disabled = checked.length === 0;
            }
            if (bulkCountLabel) {
                bulkCountLabel.textContent = checked.length ? ' (' + checked.length + ')' : '';
            }
            if (selectAll) {
                selectAll.checked = checked.length > 0 && checked.length === rowCheckboxes.length;
            }
        }
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                rowCheckboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                updateBulkState();
            });
        }
        rowCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', updateBulkState);
        });

        document.querySelectorAll('.snn-404-add-redirect-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                activateTab('snn-redirects-tab');
                resetForm();
                sourceField.value = this.dataset.url;
                targetField.focus();
                sourceField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });

        // Click-to-sort table columns (Hits / First Seen / Last Seen) --
        // client-side since the data is already fully rendered (max 200
        // rows) and this avoids a page reload resetting the active tab.
        document.querySelectorAll( '.snn-redirects-table th.snn-sortable' ).forEach( function( th ) {
            th.addEventListener( 'click', function() {
                var table   = th.closest( 'table' );
                var tbody   = table.querySelector( 'tbody' );
                var headers = Array.prototype.slice.call( th.parentElement.children );
                var index   = headers.indexOf( th );
                var rows    = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) );
                var nextDir = th.getAttribute( 'data-sort-dir' ) === 'desc' ? 'asc' : 'desc';

                headers.forEach( function( h ) {
                    h.removeAttribute( 'data-sort-dir' );
                    h.classList.remove( 'snn-sorted-asc', 'snn-sorted-desc' );
                } );
                th.setAttribute( 'data-sort-dir', nextDir );
                th.classList.add( 'asc' === nextDir ? 'snn-sorted-asc' : 'snn-sorted-desc' );

                rows.sort( function( a, b ) {
                    var aCell = a.children[ index ];
                    var bCell = b.children[ index ];
                    var aVal  = aCell.hasAttribute( 'data-sort-value' ) ? parseFloat( aCell.getAttribute( 'data-sort-value' ) ) : aCell.textContent.trim().toLowerCase();
                    var bVal  = bCell.hasAttribute( 'data-sort-value' ) ? parseFloat( bCell.getAttribute( 'data-sort-value' ) ) : bCell.textContent.trim().toLowerCase();
                    if ( aVal < bVal ) { return 'asc' === nextDir ? -1 : 1; }
                    if ( aVal > bVal ) { return 'asc' === nextDir ? 1 : -1; }
                    return 0;
                } );
                rows.forEach( function( row ) { tbody.appendChild( row ); } );
            } );
        } );
    });
    </script>
    <?php
}
