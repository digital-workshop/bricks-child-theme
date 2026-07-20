<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SNN_ANALYTICS_OPTIONS_KEY', 'snn_analytics_options' );
define( 'SNN_ANALYTICS_DB_VERSION_OPTION', 'snn_analytics_db_version' );
// Bump whenever the pageviews table schema changes -- snn_analytics_maybe_upgrade_db()
// re-runs dbDelta() automatically the next time an admin page loads.
define( 'SNN_ANALYTICS_DB_VERSION', 2 );

// ----------------------------------------------------------------------
// Table + options helpers
// ----------------------------------------------------------------------

function snn_analytics_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_analytics_pageviews';
}

function snn_analytics_markers_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_analytics_markers';
}

function snn_analytics_get_options() {
    $defaults = array(
        'enabled'        => true,
        'excluded_roles' => array( 'administrator' ),
        'ip_exclusion'   => array(),
        'retention_days' => 400,
    );
    $options = get_option( SNN_ANALYTICS_OPTIONS_KEY, array() );
    return wp_parse_args( is_array( $options ) ? $options : array(), $defaults );
}

/**
 * A once-generated, site-specific secret used to hash visitor identifiers.
 * Never derived from anything guessable, never exposed in the UI.
 */
function snn_analytics_get_site_salt() {
    $salt = get_option( 'snn_analytics_site_salt' );
    if ( ! $salt ) {
        $salt = wp_generate_password( 32, false );
        update_option( 'snn_analytics_site_salt', $salt, false );
    }
    return $salt;
}

// ----------------------------------------------------------------------
// DB table setup (self-healing, versioned -- same pattern as the Code
// Snippets migration: checked on admin_init, no reliance on an activation
// hook that might not fire reliably across a GitHub-based theme update).
// ----------------------------------------------------------------------

function snn_analytics_maybe_upgrade_db() {
    if ( (int) get_option( SNN_ANALYTICS_DB_VERSION_OPTION, 0 ) >= SNN_ANALYTICS_DB_VERSION ) {
        return;
    }

    global $wpdb;
    $table           = snn_analytics_table();
    $markers_table   = snn_analytics_markers_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        page_path VARCHAR(255) NOT NULL,
        referrer_host VARCHAR(255) NULL,
        visitor_hash CHAR(64) NOT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY visitor_hash (visitor_hash)
    ) {$charset_collate};
    CREATE TABLE {$markers_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        marker_date DATE NOT NULL,
        note VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY marker_date (marker_date)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( SNN_ANALYTICS_DB_VERSION_OPTION, SNN_ANALYTICS_DB_VERSION );
}
add_action( 'admin_init', 'snn_analytics_maybe_upgrade_db', 5 );

// ----------------------------------------------------------------------
// Data pruning (daily cron)
// ----------------------------------------------------------------------

function snn_analytics_prune_old_data() {
    global $wpdb;
    $options = snn_analytics_get_options();
    $days    = max( 1, (int) $options['retention_days'] );
    $table   = snn_analytics_table();

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE created_at < %s",
        gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
    ) );
}
add_action( 'snn_analytics_daily_prune', 'snn_analytics_prune_old_data' );

function snn_analytics_schedule_prune() {
    if ( ! wp_next_scheduled( 'snn_analytics_daily_prune' ) ) {
        wp_schedule_event( time(), 'daily', 'snn_analytics_daily_prune' );
    }
}
add_action( 'init', 'snn_analytics_schedule_prune' );

// ----------------------------------------------------------------------
// Tracking
// ----------------------------------------------------------------------

function snn_analytics_is_bot( $user_agent ) {
    $user_agent = trim( (string) $user_agent );
    if ( '' === $user_agent ) {
        return true;
    }
    $needles = array(
        'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'facebookexternalhit',
        'python-requests', 'headlesschrome', 'pingdom', 'uptimerobot', 'ahrefs',
        'semrush', 'mj12bot', 'bingpreview', 'phantomjs', 'lighthouse',
    );
    $ua_lower = strtolower( $user_agent );
    foreach ( $needles as $needle ) {
        if ( false !== strpos( $ua_lower, $needle ) ) {
            return true;
        }
    }
    return false;
}

/**
 * True if the current logged-in user's roles are ALL in the exclusion list
 * (an editor who is also an excluded role is still tracked if any one of
 * their roles isn't excluded). Logged-out visitors are never role-excluded.
 */
function snn_analytics_is_role_excluded() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    $options  = snn_analytics_get_options();
    $excluded = (array) $options['excluded_roles'];
    if ( empty( $excluded ) ) {
        return false;
    }
    $roles = (array) wp_get_current_user()->roles;
    if ( empty( $roles ) ) {
        return false;
    }
    foreach ( $roles as $role ) {
        if ( ! in_array( $role, $excluded, true ) ) {
            return false;
        }
    }
    return true;
}

function snn_analytics_is_ip_excluded() {
    $options = snn_analytics_get_options();
    $rules   = (array) $options['ip_exclusion'];
    if ( empty( $rules ) ) {
        return false;
    }
    $ip = function_exists( 'snn_get_user_ip' ) ? snn_get_user_ip() : '';
    if ( '' === $ip ) {
        return false;
    }
    foreach ( $rules as $rule ) {
        if ( function_exists( 'snn_2fa_ip_matches' ) && snn_2fa_ip_matches( $ip, $rule ) ) {
            return true;
        }
    }
    return false;
}

function snn_analytics_should_track() {
    if ( empty( snn_analytics_get_options()['enabled'] ) ) {
        return false;
    }
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        return false;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }
    if ( is_feed() ) {
        return false;
    }
    if ( snn_analytics_is_bot( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) {
        return false;
    }
    if ( snn_analytics_is_role_excluded() ) {
        return false;
    }
    if ( snn_analytics_is_ip_excluded() ) {
        return false;
    }
    return true;
}

function snn_analytics_record_pageview() {
    if ( ! snn_analytics_should_track() ) {
        return;
    }

    global $wpdb;

    $path = '/';
    if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
        $parsed = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
        if ( $parsed ) {
            $path = substr( $parsed, 0, 255 );
        }
    }

    $referrer_host = null;
    if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
        $ref_host  = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST );
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $ref_host && $ref_host !== $site_host ) {
            $referrer_host = substr( $ref_host, 0, 255 );
        }
    }

    $ip           = function_exists( 'snn_get_user_ip' ) ? snn_get_user_ip() : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $ua           = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
    $visitor_hash = hash( 'sha256', $ip . '|' . $ua . '|' . current_time( 'Y-m-d' ) . '|' . snn_analytics_get_site_salt() );

    $wpdb->insert(
        snn_analytics_table(),
        array(
            'created_at'    => current_time( 'mysql' ),
            'page_path'     => $path,
            'referrer_host' => $referrer_host,
            'visitor_hash'  => $visitor_hash,
        ),
        array( '%s', '%s', '%s', '%s' )
    );
}
add_action( 'wp', 'snn_analytics_record_pageview', 20 );

// ----------------------------------------------------------------------
// Dashboard queries
// ----------------------------------------------------------------------

function snn_analytics_get_range_bounds( $range ) {
    $today = current_time( 'Y-m-d' );
    switch ( $range ) {
        case 'today':
            $start = $today;
            break;
        case '30d':
            $start = gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
            break;
        case 'year':
            $start = gmdate( 'Y-m-d', strtotime( $today . ' -364 days' ) );
            break;
        case '7d':
        default:
            $start = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
            break;
    }
    return array( $start . ' 00:00:00', $today . ' 23:59:59' );
}

function snn_analytics_query_stats( $start, $end ) {
    global $wpdb;
    $table = snn_analytics_table();

    return array(
        'pageviews' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at BETWEEN %s AND %s", $start, $end ) ),
        'visitors'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE created_at BETWEEN %s AND %s", $start, $end ) ),
    );
}

function snn_analytics_query_daily_counts( $start, $end ) {
    global $wpdb;
    $table = snn_analytics_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) as d, COUNT(*) as c FROM {$table} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at)",
        $start, $end
    ), ARRAY_A );

    $counts = array();
    foreach ( $rows as $row ) {
        $counts[ $row['d'] ] = (int) $row['c'];
    }

    // Fill in zero-count days so the chart is a continuous series.
    $out      = array();
    $cursor   = strtotime( substr( $start, 0, 10 ) );
    $end_ts   = strtotime( substr( $end, 0, 10 ) );
    while ( $cursor <= $end_ts ) {
        $d          = gmdate( 'Y-m-d', $cursor );
        $out[ $d ]  = $counts[ $d ] ?? 0;
        $cursor    += DAY_IN_SECONDS;
    }
    return $out;
}

// ----------------------------------------------------------------------
// Chart markers ("here I did X") -- date + short note, shown on the
// pageviews-per-day chart and listed underneath it for a quick overview.
// ----------------------------------------------------------------------

function snn_analytics_get_markers( $start = null, $end = null, $limit = 100 ) {
    global $wpdb;
    $table = snn_analytics_markers_table();

    if ( $start && $end ) {
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, marker_date, note FROM {$table} WHERE marker_date BETWEEN %s AND %s ORDER BY marker_date DESC, id DESC LIMIT %d",
            substr( $start, 0, 10 ), substr( $end, 0, 10 ), $limit
        ), ARRAY_A );
    }

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, marker_date, note FROM {$table} ORDER BY marker_date DESC, id DESC LIMIT %d",
        $limit
    ), ARRAY_A );
}

function snn_analytics_handle_marker_actions() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['snn_analytics_add_marker'] ) && check_admin_referer( 'snn_analytics_marker_action', 'snn_analytics_marker_nonce' ) ) {
        $date = sanitize_text_field( wp_unslash( $_POST['marker_date'] ?? '' ) );
        $note = trim( sanitize_text_field( wp_unslash( $_POST['marker_note'] ?? '' ) ) );

        $date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
        if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date ) {
            add_settings_error( 'snn-analytics-marker', 'invalid_date', __( 'Please enter a valid date.', 'snn' ), 'error' );
        } elseif ( '' === $note ) {
            add_settings_error( 'snn-analytics-marker', 'empty_note', __( 'Please enter a note for the marker.', 'snn' ), 'error' );
        } else {
            global $wpdb;
            $wpdb->insert(
                snn_analytics_markers_table(),
                array(
                    'marker_date' => $date,
                    'note'        => substr( $note, 0, 255 ),
                    'created_at'  => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s' )
            );
        }
    }

    if ( isset( $_POST['snn_analytics_delete_marker'] ) && check_admin_referer( 'snn_analytics_marker_delete_action', 'snn_analytics_marker_delete_nonce' ) ) {
        global $wpdb;
        $wpdb->delete( snn_analytics_markers_table(), array( 'id' => absint( $_POST['marker_id'] ?? 0 ) ), array( '%d' ) );
    }
}
add_action( 'admin_init', 'snn_analytics_handle_marker_actions' );

function snn_analytics_query_top( $column, $start, $end, $limit = 10 ) {
    global $wpdb;
    $table  = snn_analytics_table();
    $column = in_array( $column, array( 'page_path', 'referrer_host' ), true ) ? $column : 'page_path';
    // referrer_host can be NULL ("Direct traffic") -- excluded from the ranking itself.
    $where_extra = 'referrer_host' === $column ? 'AND referrer_host IS NOT NULL' : '';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT {$column} as label, COUNT(*) as c FROM {$table} WHERE created_at BETWEEN %s AND %s {$where_extra} GROUP BY {$column} ORDER BY c DESC LIMIT %d",
        $start, $end, $limit
    ), ARRAY_A );
}

/**
 * Top blog posts (post_type "post") by pageviews. Pulls a generous pool of
 * top page_path candidates, then resolves each one to a post via WordPress's
 * own permalink-to-ID logic and keeps only actual blog posts -- this stays
 * accurate across any permalink structure without needing a separate
 * post-ID column in the tracking table.
 */
function snn_analytics_query_top_posts( $start, $end, $limit = 10 ) {
    $candidates = snn_analytics_query_top( 'page_path', $start, $end, 200 );

    $posts = array();
    foreach ( $candidates as $row ) {
        $post_id = url_to_postid( home_url( $row['label'] ) );
        if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
            continue;
        }
        $posts[] = array(
            'label' => get_the_title( $post_id ),
            'path'  => $row['label'],
            'c'     => (int) $row['c'],
        );
        if ( count( $posts ) >= $limit ) {
            break;
        }
    }
    return $posts;
}

// ----------------------------------------------------------------------
// Social media traffic
// ----------------------------------------------------------------------

/**
 * Known social platform domains, keyed by their canonical domain and mapped
 * to a display label. Matching also covers subdomains (e.g. m.facebook.com,
 * l.instagram.com) -- see snn_analytics_match_social_platform().
 */
function snn_analytics_social_platforms() {
    return array(
        'facebook.com'  => 'Facebook',
        'instagram.com' => 'Instagram',
        'pinterest.com' => 'Pinterest',
        'pin.it'        => 'Pinterest',
        'linkedin.com'  => 'LinkedIn',
        'lnkd.in'       => 'LinkedIn',
        'twitter.com'   => 'X (Twitter)',
        'x.com'         => 'X (Twitter)',
        't.co'          => 'X (Twitter)',
        'tiktok.com'    => 'TikTok',
        'youtube.com'   => 'YouTube',
        'youtu.be'      => 'YouTube',
        'reddit.com'    => 'Reddit',
        'redd.it'       => 'Reddit',
        'whatsapp.com'  => 'WhatsApp',
        'wa.me'         => 'WhatsApp',
        'telegram.org'  => 'Telegram',
        't.me'          => 'Telegram',
        'threads.net'   => 'Threads',
        'bsky.app'      => 'Bluesky',
        'snapchat.com'  => 'Snapchat',
        'xing.com'      => 'Xing',
    );
}

function snn_analytics_match_social_platform( $host ) {
    $host = strtolower( (string) $host );
    foreach ( snn_analytics_social_platforms() as $domain => $label ) {
        if ( $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
            return $label;
        }
    }
    return null;
}

/**
 * Builds a "referrer_host matches a known social domain (or subdomain)"
 * SQL fragment plus its bound params, so the overall summary count can be
 * computed with a single accurate COUNT(DISTINCT ...) query instead of
 * summing per-host visitor counts (which could double-count a visitor who
 * arrived via two host variants of the same platform on the same day).
 */
function snn_analytics_social_where_clause( $column = 'referrer_host' ) {
    global $wpdb;
    $clauses = array();
    $params  = array();
    foreach ( array_keys( snn_analytics_social_platforms() ) as $domain ) {
        $clauses[] = "({$column} = %s OR {$column} LIKE %s)";
        $params[]  = $domain;
        $params[]  = '%.' . $wpdb->esc_like( $domain );
    }
    return array( '(' . implode( ' OR ', $clauses ) . ')', $params );
}

function snn_analytics_query_social_summary( $start, $end ) {
    global $wpdb;
    $table = snn_analytics_table();
    list( $where, $params ) = snn_analytics_social_where_clause();

    $sql = "SELECT COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM {$table} WHERE created_at BETWEEN %s AND %s AND {$where}";
    $row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $start, $end ), $params ) ), ARRAY_A );

    return array(
        'views'    => (int) ( $row['views'] ?? 0 ),
        'visitors' => (int) ( $row['visitors'] ?? 0 ),
    );
}

function snn_analytics_query_social_breakdown( $start, $end ) {
    global $wpdb;
    $table = snn_analytics_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT referrer_host, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors FROM {$table} WHERE created_at BETWEEN %s AND %s AND referrer_host IS NOT NULL GROUP BY referrer_host",
        $start, $end
    ), ARRAY_A );

    $platforms = array();
    foreach ( $rows as $row ) {
        $label = snn_analytics_match_social_platform( $row['referrer_host'] );
        if ( ! $label ) {
            continue;
        }
        if ( ! isset( $platforms[ $label ] ) ) {
            $platforms[ $label ] = array( 'label' => $label, 'views' => 0, 'visitors' => 0 );
        }
        // Approximate: a visitor who hit two host variants of the same
        // platform (e.g. facebook.com and m.facebook.com) on the same day
        // is counted once per host here. Good enough for a ranking table.
        $platforms[ $label ]['views']    += (int) $row['views'];
        $platforms[ $label ]['visitors'] += (int) $row['visitors'];
    }

    usort( $platforms, function( $a, $b ) { return $b['views'] <=> $a['views']; } );
    return array_values( $platforms );
}

// ----------------------------------------------------------------------
// Admin page
// ----------------------------------------------------------------------

function snn_analytics_add_submenu() {
    // Own top-level menu item, positioned right after "Dashboard" (position 2)
    // and before "Posts" (position 5), so it's immediately visible without
    // digging into theme settings or the Dashboard submenu flyout.
    $hook = add_menu_page(
        __( 'Analytics', 'snn' ),
        __( 'Analytics', 'snn' ),
        'manage_options',
        'snn-analytics',
        'snn_analytics_page',
        'dashicons-chart-line',
        3
    );
    // Meta boxes must be registered on this exact hook -- using the value
    // add_menu_page() actually returned rather than guessing the screen ID
    // string (a guessed ID bit us elsewhere in this codebase before).
    add_action( "load-{$hook}", 'snn_analytics_register_meta_boxes' );
}
add_action( 'admin_menu', 'snn_analytics_add_submenu' );

/**
 * Registers each dashboard section as a real WP meta box so it gets native
 * drag-to-reorder, collapse, and a per-user remembered position/column --
 * all handled by core (postboxes.js + the built-in meta-box-order /
 * closedpostboxes user-option ajax handlers), no custom JS needed here.
 */
function snn_analytics_register_meta_boxes() {
    $screen = get_current_screen();

    add_meta_box( 'snn_analytics_overview', __( 'Overview', 'snn' ), 'snn_analytics_mb_overview', $screen, 'normal', 'default' );
    add_meta_box( 'snn_analytics_chart', __( 'Pageviews per day', 'snn' ), 'snn_analytics_mb_chart', $screen, 'normal', 'default' );
    add_meta_box( 'snn_analytics_top_pages', __( 'Top 10 Pages', 'snn' ), 'snn_analytics_mb_top_pages', $screen, 'normal', 'default' );
    add_meta_box( 'snn_analytics_top_posts', __( 'Top 10 Blog Posts', 'snn' ), 'snn_analytics_mb_top_posts', $screen, 'normal', 'default' );
    add_meta_box( 'snn_analytics_referrers', __( 'Top 10 Referrers', 'snn' ), 'snn_analytics_mb_referrers', $screen, 'side', 'default' );
    add_meta_box( 'snn_analytics_social', __( 'Social Media Traffic', 'snn' ), 'snn_analytics_mb_social', $screen, 'side', 'default' );

    wp_enqueue_script( 'postbox' );
}

function snn_analytics_current_range_bounds() {
    $range        = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '7d';
    $valid_ranges = array( 'today', '7d', '30d', 'year' );
    if ( ! in_array( $range, $valid_ranges, true ) ) {
        $range = '7d';
    }
    list( $start, $end ) = snn_analytics_get_range_bounds( $range );
    return array( $range, $start, $end );
}

function snn_analytics_mb_overview() {
    list( , $start, $end ) = snn_analytics_current_range_bounds();
    $stats = snn_analytics_query_stats( $start, $end );
    ?>
    <div class="snn-analytics-overview-stats">
        <div class="snn-analytics-stat-card">
            <div class="value"><?php echo esc_html( number_format_i18n( $stats['pageviews'] ) ); ?></div>
            <div class="label"><?php esc_html_e( 'Pageviews', 'snn' ); ?></div>
        </div>
        <div class="snn-analytics-stat-card">
            <div class="value"><?php echo esc_html( number_format_i18n( $stats['visitors'] ) ); ?></div>
            <div class="label"><?php esc_html_e( 'Visitors', 'snn' ); ?></div>
        </div>
    </div>
    <?php
}

function snn_analytics_mb_chart() {
    list( , $start, $end ) = snn_analytics_current_range_bounds();
    $markers = snn_analytics_get_markers( $start, $end );

    settings_errors( 'snn-analytics-marker' );

    echo snn_analytics_render_line_chart( snn_analytics_query_daily_counts( $start, $end ), $markers );
    ?>
    <form method="post" class="snn-analytics-marker-form">
        <?php wp_nonce_field( 'snn_analytics_marker_action', 'snn_analytics_marker_nonce' ); ?>
        <div class="field">
            <label for="snn_analytics_marker_date"><?php esc_html_e( 'Date', 'snn' ); ?></label>
            <input type="date" id="snn_analytics_marker_date" name="marker_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
        </div>
        <div class="field" style="flex:1; min-width:200px;">
            <label for="snn_analytics_marker_note"><?php esc_html_e( 'Note', 'snn' ); ?></label>
            <input type="text" id="snn_analytics_marker_note" name="marker_note" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Published new blog post', 'snn' ); ?>" maxlength="255" required>
        </div>
        <button type="submit" name="snn_analytics_add_marker" class="button"><?php esc_html_e( 'Add Marker', 'snn' ); ?></button>
    </form>
    <?php if ( empty( $markers ) ) : ?>
        <p class="description"><?php esc_html_e( 'No markers in this time range.', 'snn' ); ?></p>
    <?php else : ?>
        <ul class="snn-analytics-marker-list">
            <?php foreach ( $markers as $marker ) : ?>
                <li>
                    <span class="snn-analytics-marker-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $marker['marker_date'] ) ) ); ?></span>
                    <span class="snn-analytics-marker-note"><?php echo esc_html( $marker['note'] ); ?></span>
                    <form method="post" class="snn-analytics-marker-delete">
                        <?php wp_nonce_field( 'snn_analytics_marker_delete_action', 'snn_analytics_marker_delete_nonce' ); ?>
                        <input type="hidden" name="marker_id" value="<?php echo esc_attr( $marker['id'] ); ?>">
                        <button type="submit" name="snn_analytics_delete_marker" class="button-link-delete" aria-label="<?php esc_attr_e( 'Delete marker', 'snn' ); ?>">&times;</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif;
}

function snn_analytics_mb_top_pages() {
    list( , $start, $end ) = snn_analytics_current_range_bounds();
    $top_pages = snn_analytics_query_top( 'page_path', $start, $end );
    ?>
    <table class="widefat striped">
        <thead><tr><th><?php esc_html_e( 'Page', 'snn' ); ?></th><th><?php esc_html_e( 'Views', 'snn' ); ?></th></tr></thead>
        <tbody>
            <?php if ( empty( $top_pages ) ) : ?>
                <tr><td colspan="2"><?php esc_html_e( 'No data.', 'snn' ); ?></td></tr>
            <?php else : foreach ( $top_pages as $row ) : ?>
                <tr><td><?php echo esc_html( $row['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['c'] ) ); ?></td></tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

function snn_analytics_mb_top_posts() {
    list( , $start, $end ) = snn_analytics_current_range_bounds();
    $top_posts = snn_analytics_query_top_posts( $start, $end );
    ?>
    <table class="widefat striped">
        <thead><tr><th><?php esc_html_e( 'Post', 'snn' ); ?></th><th><?php esc_html_e( 'Views', 'snn' ); ?></th></tr></thead>
        <tbody>
            <?php if ( empty( $top_posts ) ) : ?>
                <tr><td colspan="2"><?php esc_html_e( 'No data.', 'snn' ); ?></td></tr>
            <?php else : foreach ( $top_posts as $row ) : ?>
                <tr><td><?php echo esc_html( $row['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( $row['c'] ) ); ?></td></tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

function snn_analytics_mb_referrers() {
    list( , $start, $end ) = snn_analytics_current_range_bounds();
    $top_referrers = snn_analytics_query_top( 'referrer_host', $start, $end );
    ?>
    <table class="widefat striped">
        <thead><tr><th><?php esc_html_e( 'Source', 'snn' ); ?></th><th><?php esc_html_e( 'Views', 'snn' ); ?></th></tr></thead>
        <tbody>
            <?php if ( empty( $top_referrers ) ) : ?>
                <tr><td colspan="2"><?php esc_html_e( 'No external referrals.', 'snn' ); ?></td></tr>
            <?php else : foreach ( $top_referrers as $row ) : ?>
                <tr><td><?php echo esc_html( $row['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['c'] ) ); ?></td></tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

function snn_analytics_mb_social() {
    list( , $start, $end ) = snn_analytics_current_range_bounds();
    $social_summary   = snn_analytics_query_social_summary( $start, $end );
    $social_platforms = snn_analytics_query_social_breakdown( $start, $end );
    ?>
    <div class="snn-analytics-social-stats">
        <div class="snn-analytics-stat-card">
            <div class="value"><?php echo esc_html( number_format_i18n( $social_summary['visitors'] ) ); ?></div>
            <div class="label"><?php esc_html_e( 'Visitors from Social Media', 'snn' ); ?></div>
        </div>
        <div class="snn-analytics-stat-card">
            <div class="value"><?php echo esc_html( number_format_i18n( $social_summary['views'] ) ); ?></div>
            <div class="label"><?php esc_html_e( 'Views from Social Media', 'snn' ); ?></div>
        </div>
    </div>
    <?php if ( empty( $social_platforms ) ) : ?>
        <p class="snn-analytics-social-empty"><?php esc_html_e( 'No social media referrals in this period.', 'snn' ); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Platform', 'snn' ); ?></th><th><?php esc_html_e( 'Visitors', 'snn' ); ?></th><th><?php esc_html_e( 'Views', 'snn' ); ?></th></tr></thead>
            <tbody>
                <?php foreach ( $social_platforms as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['label'] ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $row['visitors'] ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif;
}

function snn_analytics_add_settings_submenu() {
    // Configuration (exclusions, retention, on/off) lives with the rest of
    // the theme's settings, not next to the Dashboard stats page.
    add_submenu_page(
        'snn-settings',
        __( 'Analytics', 'snn' ),
        __( 'Analytics', 'snn' ),
        'manage_options',
        'snn-analytics-settings',
        'snn_analytics_settings_page'
    );
}
add_action( 'admin_menu', 'snn_analytics_add_settings_submenu' );

function snn_analytics_handle_form_submit() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['snn_analytics_save_settings'] ) ) {
        return;
    }
    if ( ! isset( $_POST['snn_analytics_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_analytics_nonce'] ) ), 'snn_analytics_save_settings_action' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $excluded_roles = array();
    if ( isset( $_POST['excluded_roles'] ) && is_array( $_POST['excluded_roles'] ) ) {
        $excluded_roles = array_map( 'sanitize_key', wp_unslash( $_POST['excluded_roles'] ) );
    }

    $ip_exclusion = array();
    if ( isset( $_POST['ip_exclusion'] ) && is_array( $_POST['ip_exclusion'] ) ) {
        foreach ( wp_unslash( $_POST['ip_exclusion'] ) as $ip ) {
            $ip = trim( sanitize_text_field( $ip ) );
            if ( '' !== $ip ) {
                $ip_exclusion[] = $ip;
            }
        }
    }

    $retention_days = isset( $_POST['retention_days'] ) ? max( 1, absint( $_POST['retention_days'] ) ) : 400;
    $enabled        = isset( $_POST['enabled'] ) ? 1 : 0;

    update_option( SNN_ANALYTICS_OPTIONS_KEY, array(
        'enabled'        => $enabled,
        'excluded_roles' => $excluded_roles,
        'ip_exclusion'   => $ip_exclusion,
        'retention_days' => $retention_days,
    ) );

    add_settings_error( 'snn-analytics-settings', 'settings_saved', __( 'Settings saved.', 'snn' ), 'updated' );
}
add_action( 'admin_init', 'snn_analytics_handle_form_submit' );

function snn_analytics_render_line_chart( $daily_counts, $markers = array() ) {
    $values = array_values( $daily_counts );
    $labels = array_keys( $daily_counts );
    $n      = count( $values );
    if ( $n < 2 ) {
        return '<p>' . esc_html__( 'Not enough data for this time range.', 'snn' ) . '</p>';
    }

    $w = 700; $h = 220; $pad_l = 34; $pad_b = 26; $pad_t = 12; $pad_r = 10;
    $max     = max( 1, max( $values ) );
    $plot_w  = $w - $pad_l - $pad_r;
    $plot_h  = $h - $pad_t - $pad_b;

    $points = array();
    foreach ( $values as $i => $v ) {
        $x        = $pad_l + ( 0 === $i && $n === 1 ? 0 : ( $i / ( $n - 1 ) ) * $plot_w );
        $y        = $pad_t + $plot_h - ( $v / $max ) * $plot_h;
        $points[] = round( $x, 1 ) . ',' . round( $y, 1 );
    }

    // Group markers by date so multiple notes on the same day share one line.
    $notes_by_date = array();
    foreach ( $markers as $marker ) {
        $notes_by_date[ $marker['marker_date'] ][] = $marker['note'];
    }

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="snn-analytics-chart" role="img" aria-label="' . esc_attr__( 'Pageviews per day', 'snn' ) . '">';

    for ( $i = 0; $i <= 4; $i++ ) {
        $y    = $pad_t + $plot_h - ( $i / 4 ) * $plot_h;
        $svg .= '<line x1="' . $pad_l . '" y1="' . round( $y, 1 ) . '" x2="' . ( $w - $pad_r ) . '" y2="' . round( $y, 1 ) . '" class="snn-analytics-chart-grid" />';
        $svg .= '<text x="0" y="' . round( $y + 4, 1 ) . '" class="snn-analytics-chart-axis">' . round( $max * $i / 4 ) . '</text>';
    }

    foreach ( $notes_by_date as $date => $notes ) {
        $idx = array_search( $date, $labels, true );
        if ( false === $idx ) {
            continue; // Marker falls outside the currently selected range.
        }
        $x       = $pad_l + ( 0 === $idx && $n === 1 ? 0 : ( $idx / ( $n - 1 ) ) * $plot_w );
        $x       = round( $x, 1 );
        $tooltip = esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) . "\n" . esc_html( implode( "\n", $notes ) );
        $svg    .= '<g class="snn-analytics-chart-marker">'
            . '<line x1="' . $x . '" y1="' . $pad_t . '" x2="' . $x . '" y2="' . ( $pad_t + $plot_h ) . '" class="snn-analytics-chart-marker-line" />'
            . '<circle cx="' . $x . '" cy="' . $pad_t . '" r="4" class="snn-analytics-chart-marker-dot" />'
            . '<title>' . $tooltip . '</title>'
            . '</g>';
    }

    $svg .= '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" class="snn-analytics-chart-line" />';

    $label_indexes = array_unique( array( 0, intdiv( $n, 2 ), $n - 1 ) );
    foreach ( $label_indexes as $i ) {
        $x    = $pad_l + ( ( $i / ( $n - 1 ) ) * $plot_w );
        $svg .= '<text x="' . round( $x, 1 ) . '" y="' . ( $h - 6 ) . '" class="snn-analytics-chart-axis" text-anchor="middle">' . esc_html( date_i18n( 'd.m.', strtotime( $labels[ $i ] ) ) ) . '</text>';
    }

    $svg .= '</svg>';
    return $svg;
}

function snn_analytics_admin_styles() {
    ?>
    <style>
        .snn-analytics-range { margin: 14px 0; }
        .snn-analytics-range a { margin-right: 4px; }
        .snn-analytics-range a.button-primary { pointer-events: none; }
        .snn-analytics-overview-stats { display: flex; gap: 16px; flex-wrap: wrap; }
        .snn-analytics-stat-card { background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px; padding: 16px 20px; flex: 1; min-width: 140px; }
        .snn-analytics-stat-card .value { font-size: 28px; font-weight: 600; line-height: 1.2; }
        .snn-analytics-stat-card .label { color: #646970; }
        .snn-analytics-chart { width: 100%; height: auto; }
        .snn-analytics-chart-grid { stroke: #eee; stroke-width: 1; }
        .snn-analytics-chart-line { fill: none; stroke: #2271b1; stroke-width: 2; }
        .snn-analytics-chart-axis { font-size: 10px; fill: #646970; }
        .snn-analytics-chart-marker-line { stroke: #d63638; stroke-width: 1; stroke-dasharray: 3 2; }
        .snn-analytics-chart-marker-dot { fill: #d63638; cursor: pointer; }
        .snn-analytics-chart-marker:hover .snn-analytics-chart-marker-line { stroke-width: 1.5; }
        .snn-analytics-marker-form { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin: 16px 0 12px; padding-top: 12px; border-top: 1px solid #eee; }
        .snn-analytics-marker-form .field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
        .snn-analytics-marker-list { margin: 0; }
        .snn-analytics-marker-list li { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
        .snn-analytics-marker-list li:last-child { border-bottom: none; }
        .snn-analytics-marker-date { color: #646970; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .snn-analytics-marker-note { flex: 1; }
        .snn-analytics-marker-delete { margin-left: auto; }
        .snn-analytics-social-stats { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
        .snn-analytics-social-stats .snn-analytics-stat-card { border-style: dashed; }
        .snn-analytics-social-empty { color: #646970; }
        .snn-analytics-settings-wrap { max-width: 700px; }
        #snn_analytics_ip_wrap .button .dashicons { line-height: 1 !important; vertical-align: middle; }
        #snn_analytics_ip_wrap .snn-analytics-ip-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        #snn_analytics_ip_wrap .snn-analytics-ip-row input[type="text"] { width: 280px; height: 30px; padding: 0 8px; box-sizing: border-box; }
        #snn_analytics_ip_wrap .snn-analytics-ip-remove { height: 30px; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; padding: 0 8px; flex-shrink: 0; }
    </style>
    <?php
}

function snn_analytics_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    snn_analytics_admin_styles();

    list( $range, , ) = snn_analytics_current_range_bounds();

    $range_labels = array(
        'today' => __( 'Today', 'snn' ),
        '7d'    => __( '7 Days', 'snn' ),
        '30d'   => __( '30 Days', 'snn' ),
        'year'  => __( 'This Year', 'snn' ),
    );

    $options = snn_analytics_get_options();
    $screen  = get_current_screen();
    ?>
    <div class="wrap">
        <h1>
            <?php esc_html_e( 'Analytics', 'snn' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=snn-analytics-settings' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Settings', 'snn' ); ?></a>
        </h1>

        <?php if ( empty( $options['enabled'] ) ) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e( 'Tracking is currently disabled. Existing data below is still shown, but no new pageviews are being recorded.', 'snn' ); ?></p></div>
        <?php endif; ?>

        <nav class="snn-analytics-range">
            <?php foreach ( $range_labels as $key => $label ) :
                $url = add_query_arg( array( 'page' => 'snn-analytics', 'range' => $key ), admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="button <?php echo $range === $key ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <div id="dashboard-widgets-wrap">
            <div id="dashboard-widgets" class="metabox-holder columns-2">
                <div id="postbox-container-1" class="postbox-container">
                    <?php do_meta_boxes( $screen->id, 'normal', null ); ?>
                </div>
                <div id="postbox-container-2" class="postbox-container">
                    <?php do_meta_boxes( $screen->id, 'side', null ); ?>
                </div>
            </div>
            <br class="clear">
        </div>
    </div>
    <script>
    jQuery( document ).ready( function( $ ) {
        postboxes.add_postbox_toggles( '<?php echo esc_js( $screen->id ); ?>' );
    } );
    </script>
    <?php
}

function snn_analytics_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    snn_analytics_admin_styles();

    $options = snn_analytics_get_options();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Analytics', 'snn' ); ?></h1>

        <?php settings_errors( 'snn-analytics-settings' ); ?>

        <form method="post" class="snn-analytics-settings-wrap">
            <?php wp_nonce_field( 'snn_analytics_save_settings_action', 'snn_analytics_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Enable Analytics', 'snn' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>>
                            <?php esc_html_e( 'Track pageviews', 'snn' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When disabled, no new pageviews are recorded. Previously collected data is kept and still shown on the Analytics dashboard.', 'snn' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Exclude Roles from Tracking', 'snn' ); ?></th>
                    <td>
                        <?php foreach ( wp_roles()->get_names() as $role_key => $role_label ) : ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $options['excluded_roles'], true ) ); ?>>
                                <?php echo esc_html( translate_user_role( $role_label ) ); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e( 'Logged-in users whose role(s) are all checked here are not counted.', 'snn' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Exclude IP Addresses', 'snn' ); ?></th>
                    <td>
                        <?php snn_analytics_render_ip_exclusion_field( (array) $options['ip_exclusion'] ); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="snn_analytics_retention_days"><?php esc_html_e( 'Retention Period (Days)', 'snn' ); ?></label></th>
                    <td>
                        <input type="number" id="snn_analytics_retention_days" name="retention_days" min="1" value="<?php echo esc_attr( $options['retention_days'] ); ?>" style="width:100px;">
                        <p class="description"><?php esc_html_e( 'Older records are automatically deleted daily.', 'snn' ); ?></p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="snn_analytics_save_settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'snn' ); ?></button></p>
        </form>
    </div>
    <?php
}

function snn_analytics_render_ip_exclusion_field( $whitelist ) {
    if ( empty( $whitelist ) ) {
        $whitelist = array( '' );
    }
    $current_ip = function_exists( 'snn_get_user_ip' ) ? snn_get_user_ip() : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    ?>
    <div id="snn_analytics_ip_wrap">
        <p style="margin-top: 0;">
            <?php
            printf(
                /* translators: %s: the visitor's current IP address */
                esc_html__( 'Your current IP address is %s.', 'snn' ),
                '<code>' . esc_html( $current_ip ) . '</code>'
            );
            ?>
            <button type="button" id="snn_analytics_add_current_ip" class="button button-small" data-ip="<?php echo esc_attr( $current_ip ); ?>" style="margin-left: 6px;">
                <span class="dashicons dashicons-admin-network"></span>
                <?php esc_html_e( 'Add my IP', 'snn' ); ?>
            </button>
        </p>
        <div id="snn_analytics_ip_rows">
            <?php foreach ( $whitelist as $ip ) : ?>
                <div class="snn-analytics-ip-row">
                    <input type="text" name="ip_exclusion[]" value="<?php echo esc_attr( $ip ); ?>" placeholder="203.0.113.5 <?php esc_attr_e( 'or', 'snn' ); ?> 203.0.113.0/24">
                    <button type="button" class="button snn-analytics-ip-remove" aria-label="<?php esc_attr_e( 'Remove', 'snn' ); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="snn_analytics_ip_add" class="button" style="margin-top: 10px;">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( 'Add IP Address', 'snn' ); ?>
        </button>
        <p class="description"><?php esc_html_e( 'Visits from these IP addresses are not counted. One address or CIDR range (e.g. 203.0.113.0/24) per field.', 'snn' ); ?></p>
    </div>
    <script>
    (function() {
        const container = document.getElementById('snn_analytics_ip_rows');
        const addBtn = document.getElementById('snn_analytics_ip_add');
        const addCurrentIpBtn = document.getElementById('snn_analytics_add_current_ip');
        if (!container || !addBtn) return;

        function bindRemove(row) {
            const btn = row.querySelector('.snn-analytics-ip-remove');
            if (!btn) return;
            btn.addEventListener('click', function() {
                if (container.children.length > 1) {
                    row.remove();
                } else {
                    const input = row.querySelector('input');
                    if (input) input.value = '';
                }
            });
        }

        function makeRow(value) {
            const row = document.createElement('div');
            row.className = 'snn-analytics-ip-row';
            row.innerHTML = '<input type="text" name="ip_exclusion[]" value="" placeholder="203.0.113.5 <?php echo esc_js( __( 'or', 'snn' ) ); ?> 203.0.113.0/24">' +
                '<button type="button" class="button snn-analytics-ip-remove" aria-label="<?php echo esc_js( __( 'Remove', 'snn' ) ); ?>"><span class="dashicons dashicons-no-alt"></span></button>';
            if (value) {
                row.querySelector('input').value = value;
            }
            bindRemove(row);
            return row;
        }

        addBtn.addEventListener('click', function() {
            const row = makeRow();
            container.appendChild(row);
            row.querySelector('input').focus();
        });

        container.querySelectorAll('.snn-analytics-ip-row').forEach(bindRemove);

        if (addCurrentIpBtn) {
            addCurrentIpBtn.addEventListener('click', function() {
                const ip = addCurrentIpBtn.dataset.ip;
                if (!ip) return;

                const inputs = Array.from(container.querySelectorAll('input'));

                const already = inputs.find(function(inp) { return inp.value.trim() === ip; });
                if (already) {
                    already.focus();
                    return;
                }

                const empty = inputs.find(function(inp) { return inp.value.trim() === ''; });
                if (empty) {
                    empty.value = ip;
                    empty.focus();
                    return;
                }

                const row = makeRow(ip);
                container.appendChild(row);
            });
        }
    })();
    </script>
    <?php
}
