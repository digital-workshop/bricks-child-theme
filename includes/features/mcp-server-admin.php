<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin side of the MCP server (see mcp-server.php):
 *  - the OAuth consent screen (admin.php?page=snn-mcp-authorize), and
 *  - the settings page under SNN Settings (admin.php?page=snn-mcp).
 */

// ----------------------------------------------------------------------
// Menu
// ----------------------------------------------------------------------

function snn_mcp_add_admin_pages() {
    add_submenu_page(
        'snn-settings',
        __( 'AI Connection (MCP)', 'snn' ),
        __( 'AI Connection (MCP)', 'snn' ),
        'manage_options',
        'snn-mcp',
        'snn_mcp_settings_page'
    );
    // Hidden page: registered only so wp-admin lets the consent screen load.
    // It is rendered (and exits) on admin_init, see snn_mcp_consent_screen().
    add_submenu_page( '', __( 'Allow AI connection', 'snn' ), '', 'read', 'snn-mcp-authorize', '__return_null' );
}
add_action( 'admin_menu', 'snn_mcp_add_admin_pages' );

function snn_mcp_current_page() {
    return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
}

// ----------------------------------------------------------------------
// Consent screen
// ----------------------------------------------------------------------

function snn_mcp_consent_screen() {
    if ( 'snn-mcp-authorize' !== snn_mcp_current_page() ) {
        return;
    }

    // Nobody may frame this page (clickjacking the "Allow" button).
    header( 'X-Frame-Options: DENY' );
    header( "Content-Security-Policy: frame-ancestors 'none'" );
    nocache_headers();

    if ( ! snn_mcp_is_enabled() ) {
        wp_die( esc_html__( 'The AI connection is switched off on this site.', 'snn' ), '', array( 'response' => 403 ) );
    }

    $request_id = isset( $_REQUEST['req'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_REQUEST['req'] ) ) : '';
    $request    = ( 32 === strlen( $request_id ) ) ? get_transient( 'snn_mcp_req_' . $request_id ) : false;
    if ( ! is_array( $request ) ) {
        wp_die( esc_html__( 'This connection request has expired. Please start the connection again in Claude.', 'snn' ), '', array( 'response' => 400 ) );
    }

    $user = wp_get_current_user();
    if ( ! snn_mcp_user_is_allowed( $user->ID ) ) {
        wp_die(
            esc_html__( 'Your user account may not allow AI connections. An administrator can change this under SNN Settings → AI Connection (MCP).', 'snn' ),
            '',
            array( 'response' => 403 )
        );
    }

    $redirect_back = function ( array $params ) use ( $request ) {
        $params['iss'] = snn_mcp_issuer();
        if ( '' !== $request['state'] ) {
            $params['state'] = $request['state'];
        }
        snn_mcp_redirect_with( $request['redirect_uri'], $params );
    };

    if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        check_admin_referer( 'snn_mcp_consent_' . $request_id );
        // One answer per request, whatever it is.
        delete_transient( 'snn_mcp_req_' . $request_id );

        if ( isset( $_POST['snn_mcp_allow'] ) ) {
            $redirect_back( array( 'code' => snn_mcp_issue_code( $request, $user->ID ) ) );
        }
        $redirect_back( array( 'error' => 'access_denied', 'error_description' => 'The user denied the request.' ) );
    }

    $redirect_host = (string) wp_parse_url( $request['redirect_uri'], PHP_URL_HOST );
    $is_local      = in_array( $redirect_host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
    $tool_count    = count( snn_mcp_exposed_abilities() );
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php esc_html_e( 'Allow AI connection', 'snn' ); ?></title>
    <style>
        body { margin: 0; background: #f0f0f1; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; }
        .box { max-width: 460px; margin: 8vh auto; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 28px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        h1 { font-size: 20px; margin: 0 0 16px; }
        dl { margin: 0 0 18px; display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; }
        dt { color: #646970; }
        dd { margin: 0; font-weight: 600; word-break: break-all; }
        .note { background: #fcf9e8; border-left: 4px solid #dba617; padding: 10px 12px; margin: 0 0 18px; font-size: 14px; }
        .buttons { display: flex; gap: 10px; }
        button { flex: 1; font-size: 15px; padding: 10px; border-radius: 4px; cursor: pointer; border: 1px solid #2271b1; }
        .allow { background: #2271b1; color: #fff; }
        .deny { background: #fff; color: #2271b1; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?php esc_html_e( 'Allow AI connection?', 'snn' ); ?></h1>
        <dl>
            <dt><?php esc_html_e( 'Program', 'snn' ); ?></dt>
            <dd><?php echo esc_html( '' !== $request['client_name'] ? $request['client_name'] : __( 'Unknown', 'snn' ) ); ?></dd>
            <dt><?php esc_html_e( 'Sends you back to', 'snn' ); ?></dt>
            <dd><?php echo esc_html( $is_local ? __( 'this computer (Claude Code / Claude Desktop)', 'snn' ) : $redirect_host ); ?></dd>
            <dt><?php esc_html_e( 'Acts as', 'snn' ); ?></dt>
            <dd><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></dd>
            <dt><?php esc_html_e( 'Enabled tools', 'snn' ); ?></dt>
            <dd><?php echo esc_html( (string) $tool_count ); ?></dd>
        </dl>
        <p class="note"><?php esc_html_e( 'Only click "Allow" if you just started this connection in Claude yourself. The AI can then use the enabled tools with your user rights until you disconnect it.', 'snn' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'snn_mcp_consent_' . $request_id ); ?>
            <input type="hidden" name="req" value="<?php echo esc_attr( $request_id ); ?>">
            <div class="buttons">
                <button type="submit" name="snn_mcp_deny" value="1" class="deny"><?php esc_html_e( 'Deny', 'snn' ); ?></button>
                <button type="submit" name="snn_mcp_allow" value="1" class="allow"><?php esc_html_e( 'Allow', 'snn' ); ?></button>
            </div>
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}
add_action( 'admin_init', 'snn_mcp_consent_screen', 1 );

// ----------------------------------------------------------------------
// Settings page: actions
// ----------------------------------------------------------------------

function snn_mcp_eligible_users() {
    return get_users( array(
        'capability' => 'edit_posts',
        'orderby'    => 'display_name',
        'fields'     => array( 'ID', 'display_name', 'user_login' ),
    ) );
}

function snn_mcp_handle_settings_actions() {
    if ( 'snn-mcp' !== snn_mcp_current_page() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $settings = snn_mcp_get_settings();

    if ( isset( $_POST['snn_mcp_save_settings'] ) && check_admin_referer( 'snn_mcp_settings_action', 'snn_mcp_settings_nonce' ) ) {
        $eligible = wp_list_pluck( snn_mcp_eligible_users(), 'ID' );
        $eligible = array_map( 'intval', $eligible );
        $users    = isset( $_POST['snn_mcp_users'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['snn_mcp_users'] ) ) : array();
        $users    = array_values( array_intersect( $users, $eligible ) );
        $enabled  = ! empty( $_POST['snn_mcp_enabled'] );

        // Anyone taken off the list loses their connections right away.
        foreach ( array_diff( $settings['users'], $users ) as $removed ) {
            snn_mcp_delete_connections( $removed );
        }
        // Switching off kills every token and every registered client.
        if ( $settings['enabled'] && ! $enabled ) {
            snn_mcp_delete_connections();
            snn_mcp_rotate_secret();
        }

        $settings['enabled'] = $enabled;
        $settings['users']   = $users;
        update_option( 'snn_mcp_settings', $settings, false );
        add_settings_error( 'snn-mcp', 'saved', __( 'Settings saved.', 'snn' ), 'updated' );
    }

    if ( isset( $_POST['snn_mcp_save_abilities'] ) && check_admin_referer( 'snn_mcp_abilities_action', 'snn_mcp_abilities_nonce' ) ) {
        $all     = array_keys( snn_mcp_all_abilities() );
        $checked = isset( $_POST['snn_mcp_abilities'] ) ? array_map( 'strval', (array) wp_unslash( $_POST['snn_mcp_abilities'] ) ) : array();
        $checked = array_values( array_filter( array_intersect( $checked, $all ), function ( $name ) {
            return ! snn_mcp_is_hard_blocked( $name );
        } ) );

        $settings['abilities'] = $checked;
        $settings['known']     = $all;
        update_option( 'snn_mcp_settings', $settings, false );
        add_settings_error( 'snn-mcp', 'saved', __( 'Tools saved.', 'snn' ), 'updated' );
    }

    if ( isset( $_POST['snn_mcp_disconnect'] ) && check_admin_referer( 'snn_mcp_disconnect_action', 'snn_mcp_disconnect_nonce' ) ) {
        global $wpdb;
        $wpdb->delete( snn_mcp_connections_table(), array( 'id' => absint( $_POST['snn_mcp_disconnect'] ) ), array( '%d' ) );
        add_settings_error( 'snn-mcp', 'disconnected', __( 'Connection removed.', 'snn' ), 'updated' );
    }

    if ( isset( $_POST['snn_mcp_disconnect_all'] ) && check_admin_referer( 'snn_mcp_disconnect_all_action', 'snn_mcp_disconnect_all_nonce' ) ) {
        snn_mcp_delete_connections();
        add_settings_error( 'snn-mcp', 'disconnected', __( 'All connections removed.', 'snn' ), 'updated' );
    }
}
add_action( 'admin_init', 'snn_mcp_handle_settings_actions' );

// ----------------------------------------------------------------------
// Settings page: view helpers
// ----------------------------------------------------------------------

/**
 * @return WP_Ability[] every registered ability, keyed by name.
 */
function snn_mcp_all_abilities() {
    if ( ! function_exists( 'wp_get_abilities' ) ) {
        return array();
    }
    $all = array();
    foreach ( wp_get_abilities() as $ability ) {
        $all[ $ability->get_name() ] = $ability;
    }
    ksort( $all );
    return $all;
}

function snn_mcp_group_label( $namespace ) {
    $labels = array(
        'snn-wp'     => __( 'WordPress content', 'snn' ),
        'snn-acf'    => __( 'ACF', 'snn' ),
        'snn-bricks' => __( 'Bricks Builder', 'snn' ),
        'snn-seo'    => __( 'SEOPress settings', 'snn' ),
        'core'       => __( 'WordPress core', 'snn' ),
        'seopress'   => __( 'SEOPress', 'snn' ),
    );
    return $labels[ $namespace ] ?? $namespace;
}

function snn_mcp_kind_badge( WP_Ability $ability ) {
    $annotations = $ability->get_meta_item( 'annotations', array() );
    if ( ! empty( $annotations['readonly'] ) ) {
        return array( 'read', __( 'Read only', 'snn' ) );
    }
    if ( ! empty( $annotations['destructive'] ) ) {
        return array( 'destructive', __( 'Removes / moderates', 'snn' ) );
    }
    if ( isset( $annotations['readonly'] ) && false === $annotations['readonly'] ) {
        return array( 'write', __( 'Changes content', 'snn' ) );
    }
    return array( 'unknown', __( 'Unknown effect', 'snn' ) );
}

function snn_mcp_local_time( $gmt ) {
    return esc_html( get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
}

function snn_mcp_user_label( $user_id ) {
    $user = get_userdata( (int) $user_id );
    return $user ? $user->display_name : '#' . (int) $user_id;
}

function snn_mcp_admin_styles() {
    ?>
    <style>
        .snn-mcp-tabs { margin: 14px 0 0; }
        .snn-mcp-tab-content { display: none; max-width: 1000px; }
        .snn-mcp-tab-content.active { display: block; }
        .snn-mcp-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 16px 20px; margin: 16px 0; }
        .snn-mcp-card h2 { margin-top: 0; }
        .snn-mcp-row { display: flex; align-items: center; gap: 10px; margin: 8px 0; }
        .snn-mcp-url { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .snn-mcp-url code { font-size: 14px; padding: 6px 10px; }
        .snn-mcp-notice { border-left: 4px solid #dba617; background: #fcf9e8; padding: 8px 12px; margin: 10px 0; }
        .snn-mcp-notice.error { border-color: #d63638; background: #fcf0f1; }
        .snn-mcp-notice.info { border-color: #2271b1; background: #f0f6fc; }
        .snn-mcp-group { margin: 18px 0; }
        .snn-mcp-group-head { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px 4px 0 0; }
        .snn-mcp-group-head h3 { margin: 0; font-size: 14px; }
        .snn-mcp-ability { display: flex; gap: 12px; padding: 10px 12px; border: 1px solid #dcdcde; border-top: 0; background: #fff; }
        .snn-mcp-ability.blocked { opacity: .55; }
        .snn-mcp-ability-text { flex: 1; }
        .snn-mcp-ability-text strong { display: block; }
        .snn-mcp-ability-text code { font-size: 11px; }
        .snn-mcp-ability-text p { margin: 4px 0 0; color: #646970; }
        .snn-mcp-badge { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 10px; margin-left: 6px; background: #f0f0f1; color: #50575e; font-weight: 600; vertical-align: middle; }
        .snn-mcp-badge.read { background: #edfaef; color: #00701a; }
        .snn-mcp-badge.write { background: #f0f6fc; color: #135e96; }
        .snn-mcp-badge.destructive, .snn-mcp-badge.unknown { background: #fcf0f1; color: #8a2424; }
        .snn-mcp-badge.new { background: #dba617; color: #fff; }
        .snn-mcp-badge.blocked { background: #1d2327; color: #fff; }
        .snn-mcp-users label { display: flex; align-items: center; gap: 10px; margin: 6px 0; }
        table.snn-mcp-table td { vertical-align: middle; }
        .snn-mcp-ok { color: #00a32a; font-weight: 600; }
        .snn-mcp-fail { color: #d63638; font-weight: 600; }
    </style>
    <?php
}

// ----------------------------------------------------------------------
// Settings page
// ----------------------------------------------------------------------

function snn_mcp_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    global $wpdb;

    $settings    = snn_mcp_get_settings();
    $abilities   = snn_mcp_all_abilities();
    $users       = snn_mcp_eligible_users();
    $connections = $wpdb->get_results( "SELECT * FROM " . snn_mcp_connections_table() . " ORDER BY last_used DESC", ARRAY_A );
    $log         = $wpdb->get_results( "SELECT * FROM " . snn_mcp_log_table() . " ORDER BY id DESC LIMIT 200", ARRAY_A );
    $connections = is_array( $connections ) ? $connections : array();
    $log         = is_array( $log ) ? $log : array();

    $groups = array();
    foreach ( $abilities as $name => $ability ) {
        $groups[ strtok( $name, '/' ) ][ $name ] = $ability;
    }
    // Our own groups first, then core, then everything else alphabetically.
    uksort( $groups, function ( $a, $b ) {
        $order = array( 'snn-wp', 'snn-acf', 'snn-bricks', 'seopress', 'snn-seo', 'core' );
        $ia    = array_search( $a, $order, true );
        $ib    = array_search( $b, $order, true );
        $ia    = false === $ia ? 99 : $ia;
        $ib    = false === $ib ? 99 : $ib;
        return $ia === $ib ? strcmp( $a, $b ) : $ia - $ib;
    } );

    $new_count = count( array_diff( array_keys( $abilities ), $settings['known'] ) );

    snn_mcp_admin_styles();
    settings_errors( 'snn-mcp' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AI Connection (MCP)', 'snn' ); ?></h1>
        <p><?php esc_html_e( 'Lets Claude work with this site: content, media, menus, comments, ACF fields and SEOPress. Plugins, themes, users, settings and files are always blocked.', 'snn' ); ?></p>

        <h2 class="nav-tab-wrapper snn-mcp-tabs">
            <a href="#snn-mcp-settings-tab" class="nav-tab nav-tab-active" data-tab="snn-mcp-settings-tab"><?php esc_html_e( 'Settings', 'snn' ); ?></a>
            <a href="#snn-mcp-tools-tab" class="nav-tab" data-tab="snn-mcp-tools-tab">
                <?php
                echo esc_html( sprintf( __( 'Tools (%1$d of %2$d on)', 'snn' ), count( array_intersect( $settings['abilities'], array_keys( $abilities ) ) ), count( $abilities ) ) );
                if ( $new_count ) {
                    echo ' <span class="snn-mcp-badge new">' . esc_html( sprintf( __( '%d new', 'snn' ), $new_count ) ) . '</span>';
                }
                ?>
            </a>
            <a href="#snn-mcp-connections-tab" class="nav-tab" data-tab="snn-mcp-connections-tab"><?php echo esc_html( sprintf( __( 'Connections (%d)', 'snn' ), count( $connections ) ) ); ?></a>
            <a href="#snn-mcp-log-tab" class="nav-tab" data-tab="snn-mcp-log-tab"><?php esc_html_e( 'Log', 'snn' ); ?></a>
        </h2>

        <!-- Settings -->
        <div id="snn-mcp-settings-tab" class="snn-mcp-tab-content active">
            <?php if ( 0 !== strpos( home_url(), 'https://' ) ) : ?>
                <div class="snn-mcp-notice error"><?php esc_html_e( 'This site does not use HTTPS. The AI connection only works over HTTPS.', 'snn' ); ?></div>
            <?php endif; ?>
            <?php if ( ! function_exists( 'wp_get_abilities' ) ) : ?>
                <div class="snn-mcp-notice error"><?php esc_html_e( 'This WordPress version has no Abilities API. WordPress 6.9 or newer is required.', 'snn' ); ?></div>
            <?php endif; ?>
            <?php if ( $settings['enabled'] && empty( $settings['users'] ) ) : ?>
                <div class="snn-mcp-notice"><?php esc_html_e( 'No user is ticked below yet, so nobody can allow a connection.', 'snn' ); ?></div>
            <?php endif; ?>
            <?php if ( snn_mcp_plugin_is_active( 'easy-mcp-ai/easy-mcp-ai.php' ) ) : ?>
                <div class="snn-mcp-notice info"><?php esc_html_e( 'The Easy MCP AI plugin is still active. Both can run side by side; deactivate the plugin once you no longer need it.', 'snn' ); ?></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'snn_mcp_settings_action', 'snn_mcp_settings_nonce' ); ?>

                <div class="snn-mcp-card">
                    <h2><?php esc_html_e( 'Main switch', 'snn' ); ?></h2>
                    <label class="snn-mcp-row">
                        <span class="snn-admin-toggle"><input type="checkbox" name="snn_mcp_enabled" value="1" <?php checked( $settings['enabled'] ); ?>><span class="snn-admin-toggle-slider"></span></span>
                        <strong><?php esc_html_e( 'Allow AI connections', 'snn' ); ?></strong>
                    </label>
                    <p class="description"><?php esc_html_e( 'Switching this off immediately ends every connection. Claude then has to be connected again.', 'snn' ); ?></p>

                    <?php if ( $settings['enabled'] ) : ?>
                        <p><strong><?php esc_html_e( 'Server URL for Claude:', 'snn' ); ?></strong></p>
                        <div class="snn-mcp-url">
                            <code id="snn-mcp-url"><?php echo esc_html( snn_mcp_resource_url() ); ?></code>
                            <button type="button" class="button" id="snn-mcp-copy"><?php esc_html_e( 'Copy', 'snn' ); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e( 'In claude.ai: Settings → Connectors → Add custom connector, paste this URL. Leave the OAuth fields empty.', 'snn' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="snn-mcp-card snn-mcp-users">
                    <h2><?php esc_html_e( 'Who may allow a connection', 'snn' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Only these users see the "Allow" screen. The AI then acts with that user\'s rights. Removing a user ends their connections.', 'snn' ); ?></p>
                    <?php foreach ( $users as $user ) : ?>
                        <label>
                            <span class="snn-admin-toggle"><input type="checkbox" name="snn_mcp_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( in_array( (int) $user->ID, $settings['users'], true ) ); ?>><span class="snn-admin-toggle-slider"></span></span>
                            <?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="snn-mcp-card">
                    <h2><?php esc_html_e( 'Always blocked', 'snn' ); ?></h2>
                    <p><?php esc_html_e( 'Installing or changing plugins and themes, users and roles, site settings, files and code (including Code Snippets). Deleting only ever moves things to the trash.', 'snn' ); ?></p>
                    <?php if ( ! MEDIA_TRASH ) : ?>
                        <div class="snn-mcp-notice info"><?php echo wp_kses( __( 'Media files have no trash on this site, so the AI cannot remove media. To allow it, add <code>define( \'MEDIA_TRASH\', true );</code> to wp-config.php.', 'snn' ), array( 'code' => array() ) ); ?></div>
                    <?php endif; ?>
                </div>

                <p><button type="submit" name="snn_mcp_save_settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'snn' ); ?></button></p>
            </form>
        </div>

        <!-- Tools -->
        <div id="snn-mcp-tools-tab" class="snn-mcp-tab-content">
            <p><?php esc_html_e( 'Only tools that are switched on are offered to the AI. New tools (e.g. after a plugin update) always start switched off.', 'snn' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'snn_mcp_abilities_action', 'snn_mcp_abilities_nonce' ); ?>
                <?php foreach ( $groups as $namespace => $group ) : ?>
                    <div class="snn-mcp-group">
                        <div class="snn-mcp-group-head">
                            <label class="snn-admin-toggle"><input type="checkbox" class="snn-mcp-group-toggle" data-group="<?php echo esc_attr( $namespace ); ?>"><span class="snn-admin-toggle-slider"></span></label>
                            <h3><?php echo esc_html( snn_mcp_group_label( $namespace ) ); ?> <span class="snn-mcp-badge"><?php echo esc_html( (string) count( $group ) ); ?></span></h3>
                        </div>
                        <?php foreach ( $group as $name => $ability ) :
                            $blocked = snn_mcp_is_hard_blocked( $name );
                            $is_new  = ! in_array( $name, $settings['known'], true );
                            list( $kind_class, $kind_label ) = snn_mcp_kind_badge( $ability );
                            ?>
                            <div class="snn-mcp-ability<?php echo $blocked ? ' blocked' : ''; ?>">
                                <label class="snn-admin-toggle">
                                    <input type="checkbox" name="snn_mcp_abilities[]" value="<?php echo esc_attr( $name ); ?>" data-group="<?php echo esc_attr( $namespace ); ?>" <?php checked( ! $blocked && in_array( $name, $settings['abilities'], true ) ); ?> <?php disabled( $blocked ); ?>><span class="snn-admin-toggle-slider"></span>
                                </label>
                                <div class="snn-mcp-ability-text">
                                    <strong>
                                        <?php echo esc_html( $ability->get_label() ); ?>
                                        <span class="snn-mcp-badge <?php echo esc_attr( $kind_class ); ?>"><?php echo esc_html( $kind_label ); ?></span>
                                        <?php if ( $blocked ) : ?><span class="snn-mcp-badge blocked"><?php esc_html_e( 'Blocked', 'snn' ); ?></span><?php endif; ?>
                                        <?php if ( $is_new ) : ?><span class="snn-mcp-badge new"><?php esc_html_e( 'New', 'snn' ); ?></span><?php endif; ?>
                                    </strong>
                                    <code><?php echo esc_html( $name ); ?></code>
                                    <p><?php echo esc_html( $ability->get_description() ); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p><button type="submit" name="snn_mcp_save_abilities" class="button button-primary"><?php esc_html_e( 'Save Tools', 'snn' ); ?></button></p>
            </form>
        </div>

        <!-- Connections -->
        <div id="snn-mcp-connections-tab" class="snn-mcp-tab-content">
            <p><?php esc_html_e( 'A connection ends by itself after 30 days without use.', 'snn' ); ?></p>
            <?php if ( empty( $connections ) ) : ?>
                <p><em><?php esc_html_e( 'No active connections.', 'snn' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped snn-mcp-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'User', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Program', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Connected', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Last used', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Expires', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'IP', 'snn' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $connections as $connection ) : ?>
                            <tr>
                                <td><?php echo esc_html( snn_mcp_user_label( $connection['user_id'] ) ); ?></td>
                                <td><?php echo esc_html( $connection['client_name'] ); ?></td>
                                <td><?php echo snn_mcp_local_time( $connection['created_at'] ); ?></td>
                                <td><?php echo snn_mcp_local_time( $connection['last_used'] ); ?></td>
                                <td><?php echo snn_mcp_local_time( $connection['idle_expires'] ); ?></td>
                                <td><code><?php echo esc_html( $connection['last_ip'] ); ?></code></td>
                                <td>
                                    <form method="post" style="margin:0">
                                        <?php wp_nonce_field( 'snn_mcp_disconnect_action', 'snn_mcp_disconnect_nonce' ); ?>
                                        <button type="submit" name="snn_mcp_disconnect" value="<?php echo esc_attr( $connection['id'] ); ?>" class="button button-small"><?php esc_html_e( 'Disconnect', 'snn' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" style="margin-top:12px">
                    <?php wp_nonce_field( 'snn_mcp_disconnect_all_action', 'snn_mcp_disconnect_all_nonce' ); ?>
                    <button type="submit" name="snn_mcp_disconnect_all" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Really end all connections?', 'snn' ) ); ?>');"><?php esc_html_e( 'Disconnect all', 'snn' ); ?></button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Log -->
        <div id="snn-mcp-log-tab" class="snn-mcp-tab-content">
            <p><?php echo esc_html( sprintf( __( 'The last 200 actions. Entries are deleted after %d days.', 'snn' ), SNN_MCP_LOG_RETENTION_DAYS ) ); ?></p>
            <?php if ( empty( $log ) ) : ?>
                <p><em><?php esc_html_e( 'Nothing logged yet.', 'snn' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped snn-mcp-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'User', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Result', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Object', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'snn' ); ?></th>
                            <th><?php esc_html_e( 'IP', 'snn' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) : ?>
                            <tr>
                                <td><?php echo snn_mcp_local_time( $entry['created_at'] ); ?></td>
                                <td><?php echo esc_html( snn_mcp_user_label( $entry['user_id'] ) ); ?></td>
                                <td><code><?php echo esc_html( $entry['ability'] ); ?></code></td>
                                <td>
                                    <?php if ( $entry['success'] ) : ?>
                                        <span class="snn-mcp-ok">✓</span>
                                    <?php else : ?>
                                        <span class="snn-mcp-fail">✗</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $entry['object_ref'] ); ?></td>
                                <td><?php echo esc_html( $entry['message'] ); ?></td>
                                <td><code><?php echo esc_html( $entry['ip'] ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var tabLinks = document.querySelectorAll('.snn-mcp-tabs .nav-tab');
        var tabContents = document.querySelectorAll('.snn-mcp-tab-content');
        var TAB_STORAGE_KEY = 'snn_mcp_active_tab';
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
        // Every action here is a real form POST -- keep the tab across the reload.
        try {
            var storedTab = sessionStorage.getItem(TAB_STORAGE_KEY);
            if (storedTab && document.getElementById(storedTab)) {
                activateTab(storedTab);
            }
        } catch (e) {}

        // Group switch: reflects "all on", and turns every non-blocked tool in the group on/off.
        document.querySelectorAll('.snn-mcp-group-toggle').forEach(function(groupToggle) {
            var boxes = Array.prototype.slice.call(
                document.querySelectorAll('input[name="snn_mcp_abilities[]"][data-group="' + groupToggle.dataset.group + '"]:not(:disabled)')
            );
            function sync() {
                groupToggle.checked = boxes.length > 0 && boxes.every(function(b) { return b.checked; });
            }
            groupToggle.disabled = boxes.length === 0;
            groupToggle.addEventListener('change', function() {
                boxes.forEach(function(b) { b.checked = groupToggle.checked; });
            });
            boxes.forEach(function(b) { b.addEventListener('change', sync); });
            sync();
        });

        var copyBtn = document.getElementById('snn-mcp-copy');
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                var text = document.getElementById('snn-mcp-url').textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function() {
                        copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'snn' ) ); ?>';
                    });
                }
            });
        }
    });
    </script>
    <?php
}

// is_plugin_active() lives in wp-admin/includes/plugin.php; this avoids the include.
function snn_mcp_plugin_is_active( $plugin ) {
    return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
}
