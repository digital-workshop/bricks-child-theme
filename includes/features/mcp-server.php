<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Theme-native MCP server (replaces the Easy MCP AI plugin).
 *
 * Lets Claude (claude.ai connector, desktop app, Claude Code) work with this
 * site through the WordPress Abilities API. Everything lives under one path,
 * home_url('/dw-mcp'), so it never collides with another MCP plugin that
 * answers the root /.well-known/ discovery URLs.
 *
 *   /dw-mcp             MCP endpoint (JSON-RPC over Streamable HTTP, POST only)
 *   /dw-mcp/register    OAuth dynamic client registration (RFC 7591)
 *   /dw-mcp/authorize   OAuth authorization (forwards to a wp-admin consent screen)
 *   /dw-mcp/token       OAuth token endpoint (authorization_code + refresh_token)
 *   /.well-known/oauth-protected-resource/dw-mcp     (RFC 9728)
 *   /.well-known/oauth-authorization-server/dw-mcp   (RFC 8414)
 *
 * Security model, in layers:
 *  - Off by default; every endpoint 404s while off.
 *  - HTTPS only.
 *  - Only users explicitly ticked in the settings can approve a connection.
 *  - Only Claude redirect URIs (claude.ai, claude.com, localhost) can register.
 *  - PKCE (S256) is mandatory; codes are single-use and live 2 minutes.
 *  - Access tokens live 1 hour; a connection dies after 30 days without use.
 *    Refresh tokens rotate, and replaying an old one kills the connection.
 *  - Only abilities ticked in the settings are offered; new ones start off.
 *  - Dangerous areas (plugins, themes, users, options, files, code) are
 *    blocked twice: by ability name, and by denying the matching WordPress
 *    capabilities for the whole MCP request (see snn_mcp_guard_caps()).
 *  - Every tool call is written to an audit log.
 *
 * The consent screen and the settings page live in mcp-server-admin.php,
 * the theme's own abilities in mcp-server-abilities.php.
 */

// URL path of the MCP server: https://site/dw-mcp
define( 'SNN_MCP_PATH', 'dw-mcp' );

define( 'SNN_MCP_DB_VERSION_OPTION', 'snn_mcp_db_version' );
// Bump whenever the table schema changes -- snn_mcp_maybe_upgrade_db()
// re-runs dbDelta() automatically the next time an admin page loads.
define( 'SNN_MCP_DB_VERSION', 1 );

define( 'SNN_MCP_ACCESS_TTL', HOUR_IN_SECONDS );
define( 'SNN_MCP_IDLE_TTL', 30 * DAY_IN_SECONDS );
define( 'SNN_MCP_CODE_TTL', 2 * MINUTE_IN_SECONDS );
define( 'SNN_MCP_REQUEST_TTL', 10 * MINUTE_IN_SECONDS );
define( 'SNN_MCP_LOG_RETENTION_DAYS', 90 );
define( 'SNN_MCP_CALLS_PER_MINUTE', 60 );
define( 'SNN_MCP_MAX_BODY_BYTES', 1048576 );

require_once __DIR__ . '/mcp-server-abilities.php';
require_once __DIR__ . '/mcp-server-admin.php';

// ----------------------------------------------------------------------
// Settings + tables
// ----------------------------------------------------------------------

function snn_mcp_connections_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_mcp_connections';
}

function snn_mcp_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_mcp_log';
}

function snn_mcp_get_settings() {
    $defaults = array(
        'enabled'   => false,
        'users'     => array(),
        'abilities' => array(),
        'known'     => array(),
    );
    $settings = get_option( 'snn_mcp_settings', array() );
    $settings = wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );

    $settings['enabled']   = (bool) $settings['enabled'];
    $settings['users']     = array_map( 'absint', (array) $settings['users'] );
    $settings['abilities'] = array_map( 'strval', (array) $settings['abilities'] );
    $settings['known']     = array_map( 'strval', (array) $settings['known'] );
    return $settings;
}

function snn_mcp_is_enabled() {
    $settings = snn_mcp_get_settings();
    return $settings['enabled'] && (int) get_option( SNN_MCP_DB_VERSION_OPTION, 0 ) >= SNN_MCP_DB_VERSION;
}

function snn_mcp_user_is_allowed( $user_id ) {
    $user_id = (int) $user_id;
    if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
        return false;
    }
    return in_array( $user_id, snn_mcp_get_settings()['users'], true );
}

function snn_mcp_maybe_upgrade_db() {
    if ( (int) get_option( SNN_MCP_DB_VERSION_OPTION, 0 ) >= SNN_MCP_DB_VERSION ) {
        return;
    }

    global $wpdb;
    $charset_collate   = $wpdb->get_charset_collate();
    $connections_table = snn_mcp_connections_table();
    $log_table         = snn_mcp_log_table();

    $sql = "CREATE TABLE {$connections_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        client_name VARCHAR(100) NOT NULL DEFAULT '',
        client_hash CHAR(64) NOT NULL,
        access_hash CHAR(64) NOT NULL,
        access_expires DATETIME NOT NULL,
        refresh_hash CHAR(64) NOT NULL,
        prev_refresh_hash CHAR(64) NOT NULL DEFAULT '',
        idle_expires DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        last_used DATETIME NOT NULL,
        last_ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY access_hash (access_hash),
        KEY refresh_hash (refresh_hash),
        KEY prev_refresh_hash (prev_refresh_hash),
        KEY user_id (user_id)
    ) {$charset_collate};
    CREATE TABLE {$log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ability VARCHAR(191) NOT NULL,
        success TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        object_ref VARCHAR(191) NOT NULL DEFAULT '',
        message VARCHAR(255) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( SNN_MCP_DB_VERSION_OPTION, SNN_MCP_DB_VERSION );
}
add_action( 'admin_init', 'snn_mcp_maybe_upgrade_db', 5 );

/**
 * Signing key for client IDs. Rotating it (when the master switch goes off)
 * invalidates every registered client at once.
 */
function snn_mcp_secret() {
    $secret = get_option( 'snn_mcp_secret', '' );
    if ( ! is_string( $secret ) || strlen( $secret ) < 64 ) {
        $secret = bin2hex( random_bytes( 32 ) );
        update_option( 'snn_mcp_secret', $secret, false );
    }
    return $secret;
}

function snn_mcp_rotate_secret() {
    update_option( 'snn_mcp_secret', bin2hex( random_bytes( 32 ) ), false );
}

function snn_mcp_delete_connections( $user_id = null ) {
    global $wpdb;
    if ( null === $user_id ) {
        $wpdb->query( "DELETE FROM " . snn_mcp_connections_table() );
        return;
    }
    $wpdb->delete( snn_mcp_connections_table(), array( 'user_id' => (int) $user_id ), array( '%d' ) );
}

// ----------------------------------------------------------------------
// Small helpers
// ----------------------------------------------------------------------

function snn_mcp_now() {
    return gmdate( 'Y-m-d H:i:s' );
}

function snn_mcp_gmdate( $timestamp ) {
    return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
}

function snn_mcp_hash( $value ) {
    return hash( 'sha256', (string) $value );
}

function snn_mcp_base64url_encode( $data ) {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function snn_mcp_base64url_decode( $data ) {
    $data = strtr( (string) $data, '-_', '+/' );
    $pad  = strlen( $data ) % 4;
    if ( $pad ) {
        $data .= str_repeat( '=', 4 - $pad );
    }
    return base64_decode( $data, true );
}

/**
 * REMOTE_ADDR only -- forwarded-for headers are client-controlled and would
 * let anyone dodge the rate limits by inventing a new IP per request.
 */
function snn_mcp_client_ip() {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Fixed-window counter. Returns false once $limit hits within $window seconds.
 */
function snn_mcp_rate_limit( $bucket, $limit, $window ) {
    $key   = 'snn_mcp_rl_' . md5( $bucket . '|' . floor( time() / $window ) );
    $count = (int) get_transient( $key );
    if ( $count >= $limit ) {
        return false;
    }
    set_transient( $key, $count + 1, $window );
    return true;
}

function snn_mcp_resource_url() {
    return home_url( '/' . SNN_MCP_PATH, 'https' );
}

function snn_mcp_issuer() {
    return snn_mcp_resource_url();
}

function snn_mcp_endpoint_url( $endpoint ) {
    return snn_mcp_resource_url() . '/' . $endpoint;
}

/**
 * RFC 8414 / RFC 9728 path insertion: https://host/a/b -> https://host/.well-known/{name}/a/b
 */
function snn_mcp_well_known_url( $name ) {
    $parts = wp_parse_url( snn_mcp_resource_url() );
    $port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
    return 'https://' . $parts['host'] . $port . '/.well-known/' . $name . ( $parts['path'] ?? '' );
}

function snn_mcp_send_json( $data, $status = 200, $headers = array() ) {
    nocache_headers();
    status_header( $status );
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    foreach ( $headers as $name => $value ) {
        header( $name . ': ' . $value );
    }
    echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
}

function snn_mcp_oauth_error( $error, $description, $status = 400 ) {
    snn_mcp_send_json(
        array(
            'error'             => $error,
            'error_description' => $description,
        ),
        $status
    );
}

// ----------------------------------------------------------------------
// Capability guard (active only while an MCP request is being served)
// ----------------------------------------------------------------------

/**
 * Capabilities the AI never gets, no matter which ability asks and no matter
 * what the connected user could normally do. Covers "take over the site"
 * paths: plugins, themes, core updates, users/roles, site options, files,
 * imports, and unfiltered HTML (so post content runs through kses).
 */
function snn_mcp_denied_caps() {
    return array(
        'activate_plugins', 'install_plugins', 'edit_plugins', 'delete_plugins', 'update_plugins', 'upload_plugins',
        'resume_plugin', 'deactivate_plugin', 'activate_plugin', 'deactivate_plugins',
        'install_themes', 'switch_themes', 'edit_themes', 'delete_themes', 'update_themes', 'upload_themes', 'resume_theme',
        'update_core', 'update_languages', 'install_languages', 'update_php', 'update_https',
        'create_users', 'edit_users', 'delete_users', 'promote_users', 'remove_users', 'add_users', 'list_users',
        'edit_user', 'delete_user', 'remove_user', 'promote_user',
        'manage_options', 'edit_files', 'unfiltered_upload', 'unfiltered_html', 'import', 'export',
        'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes',
        'manage_network_options', 'create_sites', 'delete_sites', 'delete_site', 'upgrade_network', 'setup_network',
        'manage_privacy_options', 'export_others_personal_data', 'erase_others_personal_data',
        'customize', 'edit_css', 'edit_app_password', 'create_app_password', 'delete_app_passwords',
    );
}

function snn_mcp_request_is_active() {
    return ! empty( $GLOBALS['snn_mcp_request_active'] );
}

/**
 * map_meta_cap (not user_has_cap) so it also binds multisite super admins,
 * whose has_cap() skips user_has_cap but still honours 'do_not_allow'.
 */
function snn_mcp_guard_caps( $caps, $cap, $user_id, $args ) {
    if ( ! snn_mcp_request_is_active() ) {
        return $caps;
    }
    $denied = snn_mcp_denied_caps();
    if ( in_array( $cap, $denied, true ) || array_intersect( (array) $caps, $denied ) ) {
        return array( 'do_not_allow' );
    }
    return $caps;
}
add_filter( 'map_meta_cap', 'snn_mcp_guard_caps', PHP_INT_MAX, 4 );

/**
 * Ability names that are never offered, not even as a switch. Matched as
 * whole dash-separated words over the full name ("namespace/name").
 */
function snn_mcp_is_hard_blocked( $ability_name ) {
    $always_ok = array( 'core/get-user-info' );
    if ( in_array( $ability_name, $always_ok, true ) ) {
        return false;
    }
    $words = '(plugins?|themes?|users?|roles?|capabilit[a-z]*|options?|files?|php|code|snippets?|eval|exec[a-z]*|sql|database|db|cron|install[a-z]*|activate|deactivate|cli|shell|command|core-update|update-core|wp-config|htaccess)';
    $name  = str_replace( '/', '-', strtolower( $ability_name ) );
    return (bool) preg_match( '/(^|-)' . $words . '(-|$)/', $name );
}

// ----------------------------------------------------------------------
// Exposed abilities <-> MCP tools
// ----------------------------------------------------------------------

/**
 * Tool names may only contain [a-zA-Z0-9_-]. Ability names are
 * "namespace/name" with only [a-z0-9-], so "/" <-> "__" is lossless.
 */
function snn_mcp_tool_name( $ability_name ) {
    return str_replace( '/', '__', $ability_name );
}

function snn_mcp_ability_name_from_tool( $tool_name ) {
    return str_replace( '__', '/', (string) $tool_name );
}

/**
 * @return WP_Ability[] keyed by ability name.
 */
function snn_mcp_exposed_abilities() {
    if ( ! function_exists( 'wp_get_abilities' ) ) {
        return array();
    }
    $enabled = snn_mcp_get_settings()['abilities'];
    $result  = array();
    foreach ( wp_get_abilities() as $ability ) {
        $name = $ability->get_name();
        if ( in_array( $name, $enabled, true ) && ! snn_mcp_is_hard_blocked( $name ) ) {
            $result[ $name ] = $ability;
        }
    }
    ksort( $result );
    return $result;
}

/**
 * Make a WP-flavoured JSON schema safe to send as an MCP inputSchema: empty
 * PHP arrays that mean "object" become {}, and anything not representable in
 * JSON (callbacks in arg_options etc.) is dropped.
 */
function snn_mcp_normalize_schema( $schema ) {
    if ( ! is_array( $schema ) ) {
        return $schema;
    }
    $object_keys = array( 'properties', 'patternProperties', 'definitions', '$defs', 'default' );
    $out         = array();
    foreach ( $schema as $key => $value ) {
        if ( 'arg_options' === $key || 'validate_callback' === $key || 'sanitize_callback' === $key || $value instanceof Closure ) {
            continue;
        }
        if ( in_array( $key, $object_keys, true ) && is_array( $value ) && empty( $value ) ) {
            $out[ $key ] = ( 'default' === $key && ( $schema['type'] ?? '' ) === 'array' ) ? array() : new stdClass();
            continue;
        }
        if ( is_array( $value ) && ( 'properties' === $key || 'patternProperties' === $key || 'definitions' === $key || '$defs' === $key ) ) {
            $props = array();
            foreach ( $value as $prop => $sub ) {
                $props[ $prop ] = snn_mcp_normalize_schema( $sub );
            }
            $out[ $key ] = $props;
            continue;
        }
        $out[ $key ] = is_array( $value ) ? snn_mcp_normalize_schema( $value ) : $value;
    }
    return $out;
}

function snn_mcp_tool_definition( WP_Ability $ability ) {
    $schema = $ability->get_input_schema();
    if ( empty( $schema ) || ( $schema['type'] ?? '' ) !== 'object' ) {
        $schema = array( 'type' => 'object', 'properties' => array() );
    }
    $schema = snn_mcp_normalize_schema( $schema );
    unset( $schema['default'] );

    $annotations = $ability->get_meta_item( 'annotations', array() );
    $tool        = array(
        'name'        => snn_mcp_tool_name( $ability->get_name() ),
        'title'       => $ability->get_label(),
        'description' => $ability->get_description(),
        'inputSchema' => $schema,
    );
    $hints = array( 'title' => $ability->get_label() );
    if ( isset( $annotations['readonly'] ) && null !== $annotations['readonly'] ) {
        $hints['readOnlyHint'] = (bool) $annotations['readonly'];
    }
    if ( isset( $annotations['destructive'] ) && null !== $annotations['destructive'] ) {
        $hints['destructiveHint'] = (bool) $annotations['destructive'];
    }
    if ( isset( $annotations['idempotent'] ) && null !== $annotations['idempotent'] ) {
        $hints['idempotentHint'] = (bool) $annotations['idempotent'];
    }
    $hints['openWorldHint'] = false;
    $tool['annotations']    = $hints;
    return $tool;
}

// ----------------------------------------------------------------------
// Router
// ----------------------------------------------------------------------

/**
 * Runs on wp_loaded (after init) so every plugin has had the chance to hook
 * wp_abilities_api_init before the registry is first touched.
 */
function snn_mcp_route_request() {
    if ( is_admin() || wp_doing_cron() || ! snn_mcp_is_enabled() ) {
        return;
    }

    $request_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    if ( ! is_string( $request_path ) || false === strpos( $request_path, SNN_MCP_PATH ) ) {
        return;
    }
    $request_path = untrailingslashit( $request_path );

    $resource_path = (string) wp_parse_url( snn_mcp_resource_url(), PHP_URL_PATH );
    $routes        = array(
        $resource_path                                                           => 'mcp',
        $resource_path . '/register'                                             => 'register',
        $resource_path . '/authorize'                                            => 'authorize',
        $resource_path . '/token'                                                => 'token',
        (string) wp_parse_url( snn_mcp_well_known_url( 'oauth-protected-resource' ), PHP_URL_PATH ) => 'resource_metadata',
        (string) wp_parse_url( snn_mcp_well_known_url( 'oauth-authorization-server' ), PHP_URL_PATH ) => 'server_metadata',
        (string) wp_parse_url( snn_mcp_well_known_url( 'openid-configuration' ), PHP_URL_PATH ) => 'server_metadata',
        $resource_path . '/.well-known/oauth-authorization-server'               => 'server_metadata',
        $resource_path . '/.well-known/openid-configuration'                     => 'server_metadata',
    );
    if ( ! isset( $routes[ $request_path ] ) ) {
        return;
    }

    if ( ! is_ssl() ) {
        snn_mcp_send_json( array( 'error' => 'https_required' ), 403 );
    }

    switch ( $routes[ $request_path ] ) {
        case 'resource_metadata':
            snn_mcp_send_json( snn_mcp_resource_metadata() );
            break;
        case 'server_metadata':
            snn_mcp_send_json( snn_mcp_server_metadata() );
            break;
        case 'register':
            snn_mcp_handle_register();
            break;
        case 'authorize':
            snn_mcp_handle_authorize();
            break;
        case 'token':
            snn_mcp_handle_token();
            break;
        case 'mcp':
            snn_mcp_handle_mcp();
            break;
    }
}
add_action( 'wp_loaded', 'snn_mcp_route_request', 1 );

function snn_mcp_resource_metadata() {
    return array(
        'resource'                 => snn_mcp_resource_url(),
        'authorization_servers'    => array( snn_mcp_issuer() ),
        'bearer_methods_supported' => array( 'header' ),
        'scopes_supported'         => array( 'mcp' ),
        'resource_name'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
    );
}

function snn_mcp_server_metadata() {
    return array(
        'issuer'                                         => snn_mcp_issuer(),
        'authorization_endpoint'                         => snn_mcp_endpoint_url( 'authorize' ),
        'token_endpoint'                                 => snn_mcp_endpoint_url( 'token' ),
        'registration_endpoint'                          => snn_mcp_endpoint_url( 'register' ),
        'response_types_supported'                       => array( 'code' ),
        'response_modes_supported'                       => array( 'query' ),
        'grant_types_supported'                          => array( 'authorization_code', 'refresh_token' ),
        'code_challenge_methods_supported'               => array( 'S256' ),
        'token_endpoint_auth_methods_supported'          => array( 'none' ),
        'scopes_supported'                               => array( 'mcp' ),
        'authorization_response_iss_parameter_supported' => true,
    );
}

// ----------------------------------------------------------------------
// OAuth: clients
// ----------------------------------------------------------------------

/**
 * Only Claude may connect: claude.ai / claude.com web callbacks, and
 * loopback callbacks for the desktop app and Claude Code.
 */
function snn_mcp_redirect_uri_allowed( $uri ) {
    // No whitespace/control characters: the URI ends up in a Location header.
    if ( ! is_string( $uri ) || strlen( $uri ) > 500 || preg_match( '/[\s\x00-\x1f\x7f]/', $uri ) ) {
        return false;
    }
    $parts = wp_parse_url( $uri );
    if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        return false;
    }
    if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
        return false;
    }
    $scheme = strtolower( $parts['scheme'] );
    $host   = strtolower( $parts['host'] );

    $allowed = false;
    if ( 'https' === $scheme && in_array( $host, array( 'claude.ai', 'claude.com' ), true ) && empty( $parts['port'] ) ) {
        $allowed = ( $parts['path'] ?? '' ) === '/api/mcp/auth_callback';
    } elseif ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) {
        $allowed = true;
    }

    /**
     * Filters whether an OAuth redirect URI may register with the MCP server.
     *
     * @param bool   $allowed
     * @param string $uri
     */
    return (bool) apply_filters( 'snn_mcp_redirect_uri_allowed', $allowed, $uri );
}

/**
 * Stateless client IDs: the registration data is signed into the ID itself,
 * so the public registration endpoint can't be used to fill the database.
 */
function snn_mcp_make_client_id( array $redirect_uris, $client_name ) {
    $payload = wp_json_encode(
        array(
            'r' => array_values( $redirect_uris ),
            'n' => (string) $client_name,
            't' => time(),
        ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $sig = hash_hmac( 'sha256', $payload, snn_mcp_secret(), true );
    return snn_mcp_base64url_encode( $payload ) . '.' . snn_mcp_base64url_encode( $sig );
}

/**
 * @return array|false ['redirect_uris' => string[], 'client_name' => string]
 */
function snn_mcp_parse_client_id( $client_id ) {
    if ( ! is_string( $client_id ) || strlen( $client_id ) > 4000 || substr_count( $client_id, '.' ) !== 1 ) {
        return false;
    }
    list( $payload_b64, $sig_b64 ) = explode( '.', $client_id );
    $payload = snn_mcp_base64url_decode( $payload_b64 );
    $sig     = snn_mcp_base64url_decode( $sig_b64 );
    if ( false === $payload || false === $sig ) {
        return false;
    }
    if ( ! hash_equals( hash_hmac( 'sha256', $payload, snn_mcp_secret(), true ), $sig ) ) {
        return false;
    }
    $data = json_decode( $payload, true );
    if ( ! is_array( $data ) || empty( $data['r'] ) || ! is_array( $data['r'] ) ) {
        return false;
    }
    return array(
        'redirect_uris' => array_map( 'strval', $data['r'] ),
        'client_name'   => isset( $data['n'] ) ? (string) $data['n'] : '',
    );
}

function snn_mcp_read_json_body() {
    $raw = file_get_contents( 'php://input', false, null, 0, SNN_MCP_MAX_BODY_BYTES + 1 );
    if ( false === $raw || strlen( $raw ) > SNN_MCP_MAX_BODY_BYTES ) {
        return null;
    }
    $data = json_decode( $raw, true );
    return ( JSON_ERROR_NONE === json_last_error() ) ? $data : null;
}

function snn_mcp_handle_register() {
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        snn_mcp_send_json( array( 'error' => 'method_not_allowed' ), 405, array( 'Allow' => 'POST' ) );
    }
    if ( ! snn_mcp_rate_limit( 'register|' . snn_mcp_client_ip(), 20, HOUR_IN_SECONDS ) ) {
        snn_mcp_oauth_error( 'temporarily_unavailable', 'Too many registrations.', 429 );
    }

    $body = snn_mcp_read_json_body();
    if ( ! is_array( $body ) ) {
        snn_mcp_oauth_error( 'invalid_client_metadata', 'Body must be a JSON object.' );
    }

    $redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? array_values( $body['redirect_uris'] ) : array();
    if ( empty( $redirect_uris ) || count( $redirect_uris ) > 5 ) {
        snn_mcp_oauth_error( 'invalid_redirect_uri', 'Between 1 and 5 redirect_uris are required.' );
    }
    foreach ( $redirect_uris as $uri ) {
        if ( ! snn_mcp_redirect_uri_allowed( $uri ) ) {
            snn_mcp_oauth_error( 'invalid_redirect_uri', 'This redirect_uri is not allowed on this server.' );
        }
    }

    $client_name = isset( $body['client_name'] ) && is_string( $body['client_name'] ) ? $body['client_name'] : 'MCP Client';
    $client_name = mb_substr( sanitize_text_field( $client_name ), 0, 100 );

    snn_mcp_send_json(
        array(
            'client_id'                  => snn_mcp_make_client_id( $redirect_uris, $client_name ),
            'client_id_issued_at'        => time(),
            'client_name'                => $client_name,
            'redirect_uris'              => $redirect_uris,
            'grant_types'                => array( 'authorization_code', 'refresh_token' ),
            'response_types'             => array( 'code' ),
            'token_endpoint_auth_method' => 'none',
        ),
        201
    );
}

// ----------------------------------------------------------------------
// OAuth: authorize (validates, then hands over to the wp-admin consent screen)
// ----------------------------------------------------------------------

function snn_mcp_authorize_fail( $message ) {
    wp_die(
        esc_html( $message ),
        esc_html__( 'Connection not possible', 'snn' ),
        array( 'response' => 400 )
    );
}

function snn_mcp_redirect_with( $redirect_uri, array $params ) {
    $separator = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
    $url       = $redirect_uri . $separator . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
    // Plain header instead of wp_redirect(): the target was already checked
    // against the client's signed redirect_uris, and wp_redirect() would
    // rewrite loopback/127.0.0.1 targets through wp_sanitize_redirect().
    nocache_headers();
    header( 'Location: ' . $url, true, 302 );
    exit;
}

function snn_mcp_handle_authorize() {
    if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        snn_mcp_authorize_fail( __( 'Invalid request method.', 'snn' ) );
    }
    if ( ! snn_mcp_rate_limit( 'authorize|' . snn_mcp_client_ip(), 30, 10 * MINUTE_IN_SECONDS ) ) {
        snn_mcp_authorize_fail( __( 'Too many attempts. Please wait a few minutes.', 'snn' ) );
    }

    $get = function ( $key ) {
        return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
    };

    $client_id    = $get( 'client_id' );
    $redirect_uri = $get( 'redirect_uri' );
    $client       = snn_mcp_parse_client_id( $client_id );

    // Until client and redirect_uri are verified, errors must never redirect.
    if ( ! $client ) {
        snn_mcp_authorize_fail( __( 'Unknown client. Please remove the connector and add it again.', 'snn' ) );
    }
    if ( '' === $redirect_uri && 1 === count( $client['redirect_uris'] ) ) {
        $redirect_uri = $client['redirect_uris'][0];
    }
    if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) || ! snn_mcp_redirect_uri_allowed( $redirect_uri ) ) {
        snn_mcp_authorize_fail( __( 'The redirect address does not match the registered client.', 'snn' ) );
    }

    $state = substr( $get( 'state' ), 0, 500 );
    $fail  = function ( $error, $description ) use ( $redirect_uri, $state ) {
        $params = array( 'error' => $error, 'error_description' => $description, 'iss' => snn_mcp_issuer() );
        if ( '' !== $state ) {
            $params['state'] = $state;
        }
        snn_mcp_redirect_with( $redirect_uri, $params );
    };

    if ( 'code' !== $get( 'response_type' ) ) {
        $fail( 'unsupported_response_type', 'Only response_type=code is supported.' );
    }
    $challenge = $get( 'code_challenge' );
    if ( 'S256' !== $get( 'code_challenge_method' ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
        $fail( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
    }
    $resource = $get( 'resource' );
    if ( '' !== $resource && untrailingslashit( $resource ) !== snn_mcp_resource_url() ) {
        $fail( 'invalid_target', 'Unknown resource.' );
    }

    $request_id = bin2hex( random_bytes( 16 ) );
    set_transient(
        'snn_mcp_req_' . $request_id,
        array(
            'client_id'    => $client_id,
            'client_name'  => $client['client_name'],
            'redirect_uri' => $redirect_uri,
            'challenge'    => $challenge,
            'state'        => $state,
        ),
        SNN_MCP_REQUEST_TTL
    );

    // wp-admin takes care of login (custom login URL, 2FA) via auth_redirect().
    nocache_headers();
    wp_safe_redirect( admin_url( 'admin.php?page=snn-mcp-authorize&req=' . $request_id ) );
    exit;
}

/**
 * Called by the consent screen once the user clicked "Allow".
 */
function snn_mcp_issue_code( array $request, $user_id ) {
    $code = bin2hex( random_bytes( 32 ) );
    set_transient(
        'snn_mcp_code_' . snn_mcp_hash( $code ),
        array(
            'client_id'    => $request['client_id'],
            'client_name'  => $request['client_name'],
            'redirect_uri' => $request['redirect_uri'],
            'challenge'    => $request['challenge'],
            'user_id'      => (int) $user_id,
        ),
        SNN_MCP_CODE_TTL
    );
    return $code;
}

// ----------------------------------------------------------------------
// OAuth: token
// ----------------------------------------------------------------------

function snn_mcp_pkce_matches( $verifier, $challenge ) {
    if ( ! is_string( $verifier ) || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
        return false;
    }
    return hash_equals( (string) $challenge, snn_mcp_base64url_encode( hash( 'sha256', $verifier, true ) ) );
}

function snn_mcp_token_response( $access_token, $refresh_token ) {
    snn_mcp_send_json(
        array(
            'access_token'  => $access_token,
            'token_type'    => 'Bearer',
            'expires_in'    => SNN_MCP_ACCESS_TTL,
            'refresh_token' => $refresh_token,
            'scope'         => 'mcp',
        )
    );
}

function snn_mcp_new_token_pair() {
    return array(
        'snnmcp_at_' . bin2hex( random_bytes( 32 ) ),
        'snnmcp_rt_' . bin2hex( random_bytes( 32 ) ),
    );
}

function snn_mcp_handle_token() {
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        snn_mcp_send_json( array( 'error' => 'method_not_allowed' ), 405, array( 'Allow' => 'POST' ) );
    }
    if ( ! snn_mcp_rate_limit( 'token|' . snn_mcp_client_ip(), 30, MINUTE_IN_SECONDS ) ) {
        snn_mcp_oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
    }

    $post = function ( $key ) {
        return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
    };

    $client_id = $post( 'client_id' );
    if ( ! snn_mcp_parse_client_id( $client_id ) ) {
        snn_mcp_oauth_error( 'invalid_client', 'Unknown client.', 401 );
    }

    $grant_type = $post( 'grant_type' );
    if ( 'authorization_code' === $grant_type ) {
        snn_mcp_grant_authorization_code( $client_id, $post( 'code' ), $post( 'redirect_uri' ), $post( 'code_verifier' ) );
    } elseif ( 'refresh_token' === $grant_type ) {
        snn_mcp_grant_refresh_token( $client_id, $post( 'refresh_token' ) );
    }
    snn_mcp_oauth_error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
}

function snn_mcp_grant_authorization_code( $client_id, $code, $redirect_uri, $verifier ) {
    if ( '' === $code ) {
        snn_mcp_oauth_error( 'invalid_request', 'Missing code.' );
    }
    $key  = 'snn_mcp_code_' . snn_mcp_hash( $code );
    $data = get_transient( $key );
    // Single use: gone before anything else is checked.
    delete_transient( $key );

    if ( ! is_array( $data ) ) {
        snn_mcp_oauth_error( 'invalid_grant', 'Code is invalid or expired.' );
    }
    if ( ! hash_equals( $data['client_id'], $client_id ) ) {
        snn_mcp_oauth_error( 'invalid_grant', 'Code was issued to another client.' );
    }
    if ( '' !== $redirect_uri && $redirect_uri !== $data['redirect_uri'] ) {
        snn_mcp_oauth_error( 'invalid_grant', 'redirect_uri does not match.' );
    }
    if ( ! snn_mcp_pkce_matches( $verifier, $data['challenge'] ) ) {
        snn_mcp_oauth_error( 'invalid_grant', 'PKCE verification failed.' );
    }
    if ( ! snn_mcp_user_is_allowed( $data['user_id'] ) ) {
        snn_mcp_oauth_error( 'invalid_grant', 'This user may not connect.' );
    }

    list( $access, $refresh ) = snn_mcp_new_token_pair();
    $now                      = time();

    global $wpdb;
    $wpdb->insert(
        snn_mcp_connections_table(),
        array(
            'user_id'        => (int) $data['user_id'],
            'client_name'    => mb_substr( (string) $data['client_name'], 0, 100 ),
            'client_hash'    => snn_mcp_hash( $client_id ),
            'access_hash'    => snn_mcp_hash( $access ),
            'access_expires' => snn_mcp_gmdate( $now + SNN_MCP_ACCESS_TTL ),
            'refresh_hash'   => snn_mcp_hash( $refresh ),
            'idle_expires'   => snn_mcp_gmdate( $now + SNN_MCP_IDLE_TTL ),
            'created_at'     => snn_mcp_gmdate( $now ),
            'last_used'      => snn_mcp_gmdate( $now ),
            'last_ip'        => snn_mcp_client_ip(),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );

    snn_mcp_log( 'oauth/connect', true, array(
        'user_id'       => (int) $data['user_id'],
        'connection_id' => (int) $wpdb->insert_id,
        'message'       => (string) $data['client_name'],
    ) );

    snn_mcp_token_response( $access, $refresh );
}

function snn_mcp_grant_refresh_token( $client_id, $refresh_token ) {
    if ( '' === $refresh_token ) {
        snn_mcp_oauth_error( 'invalid_request', 'Missing refresh_token.' );
    }
    global $wpdb;
    $table = snn_mcp_connections_table();
    $hash  = snn_mcp_hash( $refresh_token );

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE refresh_hash = %s LIMIT 1", $hash ), ARRAY_A );
    if ( ! $row ) {
        // A rotated-out refresh token showing up again means it was copied.
        // Kill the whole connection; the real client simply re-authorizes.
        $stolen = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$table} WHERE prev_refresh_hash = %s LIMIT 1", $hash ), ARRAY_A );
        if ( $stolen ) {
            $wpdb->delete( $table, array( 'id' => (int) $stolen['id'] ), array( '%d' ) );
            snn_mcp_log( 'oauth/refresh-reuse', false, array(
                'user_id'       => (int) $stolen['user_id'],
                'connection_id' => (int) $stolen['id'],
                'message'       => 'Old refresh token was reused -- connection revoked.',
            ) );
        }
        snn_mcp_oauth_error( 'invalid_grant', 'Refresh token is invalid.' );
    }

    if ( ! hash_equals( $row['client_hash'], snn_mcp_hash( $client_id ) ) ) {
        snn_mcp_oauth_error( 'invalid_grant', 'Refresh token was issued to another client.' );
    }
    if ( strtotime( $row['idle_expires'] . ' UTC' ) < time() || ! snn_mcp_user_is_allowed( $row['user_id'] ) ) {
        $wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
        snn_mcp_oauth_error( 'invalid_grant', 'Connection expired. Please connect again.' );
    }

    list( $access, $refresh ) = snn_mcp_new_token_pair();
    $now                      = time();

    $updated = $wpdb->update(
        $table,
        array(
            'access_hash'       => snn_mcp_hash( $access ),
            'access_expires'    => snn_mcp_gmdate( $now + SNN_MCP_ACCESS_TTL ),
            'refresh_hash'      => snn_mcp_hash( $refresh ),
            'prev_refresh_hash' => $hash,
            'idle_expires'      => snn_mcp_gmdate( $now + SNN_MCP_IDLE_TTL ),
            'last_used'         => snn_mcp_gmdate( $now ),
            'last_ip'           => snn_mcp_client_ip(),
        ),
        // Matching on the old hash too makes two parallel refreshes with the
        // same token race-safe: only one of them can win the row.
        array( 'id' => (int) $row['id'], 'refresh_hash' => $hash ),
        array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
        array( '%d', '%s' )
    );
    if ( 1 !== $updated ) {
        snn_mcp_oauth_error( 'invalid_grant', 'Refresh token is invalid.' );
    }

    snn_mcp_token_response( $access, $refresh );
}

// ----------------------------------------------------------------------
// MCP endpoint
// ----------------------------------------------------------------------

function snn_mcp_bearer_token() {
    $header = '';
    foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $header = (string) $_SERVER[ $key ];
            break;
        }
    }
    if ( '' === $header && function_exists( 'getallheaders' ) ) {
        foreach ( (array) getallheaders() as $name => $value ) {
            if ( 'authorization' === strtolower( $name ) ) {
                $header = (string) $value;
                break;
            }
        }
    }
    if ( preg_match( '/^Bearer\s+(snnmcp_at_[a-f0-9]{64})$/i', trim( $header ), $m ) ) {
        return $m[1];
    }
    return '';
}

function snn_mcp_unauthorized( $description ) {
    $metadata = snn_mcp_well_known_url( 'oauth-protected-resource' );
    snn_mcp_send_json(
        array( 'error' => 'invalid_token', 'error_description' => $description ),
        401,
        array( 'WWW-Authenticate' => 'Bearer resource_metadata="' . $metadata . '", error="invalid_token", scope="mcp"' )
    );
}

/**
 * @return array Connection row; never returns on failure.
 */
function snn_mcp_authenticate() {
    $ip = snn_mcp_client_ip();
    // Tokens are 256-bit random, so guessing is hopeless anyway; this only
    // keeps junk traffic cheap. Deliberately a short throttle, not a long
    // lockout -- claude.ai traffic shares IPs, a lockout would let any
    // stranger lock the real owner out.
    if ( get_transient( 'snn_mcp_fail_' . md5( $ip ) ) >= 20 ) {
        snn_mcp_send_json( array( 'error' => 'too_many_requests' ), 429, array( 'Retry-After' => '60' ) );
    }

    $token = snn_mcp_bearer_token();
    $row   = null;
    if ( '' !== $token ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . snn_mcp_connections_table() . " WHERE access_hash = %s LIMIT 1", snn_mcp_hash( $token ) ),
            ARRAY_A
        );
    }

    $now   = time();
    $error = '';
    if ( '' === $token ) {
        $error = 'Missing bearer token.';
    } elseif ( ! $row ) {
        $error = 'Unknown token.';
    } elseif ( strtotime( $row['access_expires'] . ' UTC' ) < $now ) {
        $error = 'Token expired.';
    } elseif ( strtotime( $row['idle_expires'] . ' UTC' ) < $now || ! snn_mcp_user_is_allowed( $row['user_id'] ) ) {
        $error = 'Connection is no longer valid.';
    }

    if ( '' !== $error ) {
        if ( '' !== $token ) {
            $key = 'snn_mcp_fail_' . md5( $ip );
            set_transient( $key, (int) get_transient( $key ) + 1, MINUTE_IN_SECONDS );
        }
        snn_mcp_unauthorized( $error );
    }

    if ( ! snn_mcp_rate_limit( 'calls|' . $row['id'], SNN_MCP_CALLS_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
        snn_mcp_send_json( array( 'error' => 'too_many_requests' ), 429, array( 'Retry-After' => '60' ) );
    }

    // "Last used" drives the 30-day idle expiry; one write per minute is plenty.
    if ( strtotime( $row['last_used'] . ' UTC' ) < $now - MINUTE_IN_SECONDS ) {
        global $wpdb;
        $wpdb->update(
            snn_mcp_connections_table(),
            array(
                'last_used'    => snn_mcp_gmdate( $now ),
                'idle_expires' => snn_mcp_gmdate( $now + SNN_MCP_IDLE_TTL ),
                'last_ip'      => $ip,
            ),
            array( 'id' => (int) $row['id'] ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    return $row;
}

/**
 * DNS-rebinding protection from the MCP spec: Claude's servers and the
 * desktop apps send no Origin; a browser page on another site would.
 */
function snn_mcp_origin_allowed() {
    if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
        return true;
    }
    $origin = wp_parse_url( (string) $_SERVER['HTTP_ORIGIN'] );
    $home   = wp_parse_url( home_url() );
    return is_array( $origin ) && isset( $origin['host'], $home['host'] ) && strtolower( $origin['host'] ) === strtolower( $home['host'] );
}

function snn_mcp_supported_protocol_versions() {
    return array( '2025-11-25', '2025-06-18', '2025-03-26' );
}

function snn_mcp_handle_mcp() {
    if ( ! snn_mcp_origin_allowed() ) {
        snn_mcp_send_json( array( 'error' => 'forbidden_origin' ), 403 );
    }
    if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        // No server-initiated SSE stream: the spec says answer GET with 405.
        snn_mcp_send_json( array( 'error' => 'method_not_allowed' ), 405, array( 'Allow' => 'POST' ) );
    }

    $connection = snn_mcp_authenticate();

    // Guard first, user second: wp_set_current_user() re-runs kses_init(),
    // which must already see unfiltered_html as denied.
    $GLOBALS['snn_mcp_request_active'] = true;
    $GLOBALS['snn_mcp_connection']     = $connection;
    wp_set_current_user( (int) $connection['user_id'] );

    $message = snn_mcp_read_json_body();
    if ( ! is_array( $message ) ) {
        snn_mcp_send_json( snn_mcp_rpc_error( null, -32700, 'Parse error' ), 400 );
    }
    if ( array_keys( $message ) === range( 0, count( $message ) - 1 ) ) {
        snn_mcp_send_json( snn_mcp_rpc_error( null, -32600, 'Batch requests are not supported' ), 400 );
    }
    if ( ( $message['jsonrpc'] ?? '' ) !== '2.0' || ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
        snn_mcp_send_json( snn_mcp_rpc_error( $message['id'] ?? null, -32600, 'Invalid Request' ), 400 );
    }

    // Notifications (no id) get no body.
    if ( ! array_key_exists( 'id', $message ) ) {
        nocache_headers();
        status_header( 202 );
        exit;
    }

    $id     = $message['id'];
    $params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

    switch ( $message['method'] ) {
        case 'initialize':
            $requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
            $versions  = snn_mcp_supported_protocol_versions();
            $result    = array(
                'protocolVersion' => in_array( $requested, $versions, true ) ? $requested : $versions[0],
                'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
                'serverInfo'      => array(
                    'name'    => SNN_MCP_PATH,
                    'title'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
                    'version' => (string) wp_get_theme()->get( 'Version' ),
                ),
                'instructions'    => 'WordPress site ' . home_url() . '. Deleting always moves items to the trash. Bricks Builder page layouts are read-only.',
            );
            snn_mcp_send_json( snn_mcp_rpc_result( $id, $result ) );
            break;

        case 'ping':
            snn_mcp_send_json( snn_mcp_rpc_result( $id, new stdClass() ) );
            break;

        case 'tools/list':
            $tools = array();
            foreach ( snn_mcp_exposed_abilities() as $ability ) {
                $tools[] = snn_mcp_tool_definition( $ability );
            }
            snn_mcp_send_json( snn_mcp_rpc_result( $id, array( 'tools' => $tools ) ) );
            break;

        case 'tools/call':
            snn_mcp_send_json( snn_mcp_rpc_result( $id, snn_mcp_call_tool( $params ) ) );
            break;

        default:
            snn_mcp_send_json( snn_mcp_rpc_error( $id, -32601, 'Method not found' ) );
    }
}

function snn_mcp_rpc_result( $id, $result ) {
    return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
}

function snn_mcp_rpc_error( $id, $code, $message ) {
    return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
}

function snn_mcp_tool_error_result( $text ) {
    return array(
        'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ),
        'isError' => true,
    );
}

function snn_mcp_call_tool( array $params ) {
    $tool_name    = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
    $ability_name = snn_mcp_ability_name_from_tool( $tool_name );
    $arguments    = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
    $exposed      = snn_mcp_exposed_abilities();

    if ( ! isset( $exposed[ $ability_name ] ) ) {
        snn_mcp_log( substr( $ability_name, 0, 191 ), false, array( 'message' => 'Tool not enabled', 'args' => $arguments ) );
        return snn_mcp_tool_error_result( 'Unknown or disabled tool: ' . $tool_name );
    }
    $ability = $exposed[ $ability_name ];

    // Abilities without an input schema only accept null.
    $input = empty( $ability->get_input_schema() ) ? ( empty( $arguments ) ? null : $arguments ) : $arguments;

    try {
        $output = $ability->execute( $input );
    } catch ( Throwable $e ) {
        $output = new WP_Error( 'snn_mcp_exception', 'The tool failed with an internal error.' );
        snn_mcp_log( $ability_name, false, array( 'message' => get_class( $e ) . ': ' . $e->getMessage(), 'args' => $arguments ) );
        return snn_mcp_tool_error_result( $output->get_error_message() );
    }

    if ( is_wp_error( $output ) ) {
        snn_mcp_log( $ability_name, false, array( 'message' => $output->get_error_message(), 'args' => $arguments ) );
        return snn_mcp_tool_error_result( $output->get_error_message() );
    }

    snn_mcp_log( $ability_name, true, array( 'args' => $arguments ) );

    $text   = is_string( $output ) ? $output : wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $result = array( 'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ) );

    $output_schema = $ability->get_output_schema();
    if ( ( $output_schema['type'] ?? '' ) === 'object' && is_array( $output ) ) {
        $result['structuredContent'] = empty( $output ) ? new stdClass() : $output;
    }
    return $result;
}

// ----------------------------------------------------------------------
// Audit log
// ----------------------------------------------------------------------

/**
 * Short "which object" summary from the tool arguments -- IDs only, never
 * content, so the log stays small and holds no post text.
 */
function snn_mcp_object_ref( array $args ) {
    $parts = array();
    foreach ( array( 'post_type', 'id', 'post_id', 'object_type', 'taxonomy', 'term_id', 'menu_id', 'item_id', 'comment_id', 'attachment_id' ) as $key ) {
        if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
            $parts[] = $key . '=' . sanitize_text_field( (string) $args[ $key ] );
        }
    }
    // SEOPress settings: log which keys were changed.
    if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
        $parts[] = 'settings=' . sanitize_text_field( implode( ',', array_map( 'strval', array_keys( $args['settings'] ) ) ) );
    }
    return mb_substr( implode( ' ', $parts ), 0, 191 );
}

function snn_mcp_log( $ability, $success, array $extra = array() ) {
    global $wpdb;
    $connection = $GLOBALS['snn_mcp_connection'] ?? null;
    $wpdb->insert(
        snn_mcp_log_table(),
        array(
            'created_at'    => snn_mcp_now(),
            'user_id'       => isset( $extra['user_id'] ) ? (int) $extra['user_id'] : get_current_user_id(),
            'connection_id' => isset( $extra['connection_id'] ) ? (int) $extra['connection_id'] : (int) ( $connection['id'] ?? 0 ),
            'ability'       => mb_substr( (string) $ability, 0, 191 ),
            'success'       => $success ? 1 : 0,
            'object_ref'    => isset( $extra['args'] ) && is_array( $extra['args'] ) ? snn_mcp_object_ref( $extra['args'] ) : '',
            'message'       => mb_substr( wp_strip_all_tags( (string) ( $extra['message'] ?? '' ) ), 0, 255 ),
            'ip'            => snn_mcp_client_ip(),
        ),
        array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
    );
}

function snn_mcp_daily_cleanup() {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM " . snn_mcp_log_table() . " WHERE created_at < %s",
        snn_mcp_gmdate( time() - SNN_MCP_LOG_RETENTION_DAYS * DAY_IN_SECONDS )
    ) );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM " . snn_mcp_connections_table() . " WHERE idle_expires < %s",
        snn_mcp_now()
    ) );
}
add_action( 'snn_mcp_daily_cleanup', 'snn_mcp_daily_cleanup' );

function snn_mcp_schedule_cleanup() {
    if ( (int) get_option( SNN_MCP_DB_VERSION_OPTION, 0 ) >= SNN_MCP_DB_VERSION && ! wp_next_scheduled( 'snn_mcp_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'snn_mcp_daily_cleanup' );
    }
}
add_action( 'init', 'snn_mcp_schedule_cleanup' );
