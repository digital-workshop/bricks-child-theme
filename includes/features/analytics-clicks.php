<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Link click tracking (card on the Analytics dashboard).
 *
 * Counts clicks on links (a href), including tel:, mailto: and WhatsApp.
 * A tiny script in the footer reports each click to admin-ajax in the
 * background. Bots, excluded roles and excluded IPs follow the pageview
 * rules from analytics.php. The device (desktop / tablet / mobile) is
 * decided by the browser width at the time of the click.
 */

define( 'SNN_ANALYTICS_CLICKS_DB_VERSION_OPTION', 'snn_analytics_clicks_db_version' );
define( 'SNN_ANALYTICS_CLICKS_DB_VERSION', 1 );
define( 'SNN_ANALYTICS_CLICKS_EXCLUDE_OPTION', 'snn_analytics_click_exclude_domains' );

function snn_analytics_clicks_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_analytics_clicks';
}

// ----------------------------------------------------------------------
// DB table (self-healing, same pattern as analytics.php)
// ----------------------------------------------------------------------

function snn_analytics_clicks_maybe_upgrade_db() {
    if ( (int) get_option( SNN_ANALYTICS_CLICKS_DB_VERSION_OPTION, 0 ) >= SNN_ANALYTICS_CLICKS_DB_VERSION ) {
        return;
    }

    global $wpdb;
    $table           = snn_analytics_clicks_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        link_url VARCHAR(500) NOT NULL,
        link_host VARCHAR(190) NOT NULL DEFAULT '',
        anchor VARCHAR(255) NOT NULL DEFAULT '',
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        page_path VARCHAR(255) NOT NULL DEFAULT '',
        device VARCHAR(10) NOT NULL DEFAULT 'desktop',
        PRIMARY KEY  (id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( SNN_ANALYTICS_CLICKS_DB_VERSION_OPTION, SNN_ANALYTICS_CLICKS_DB_VERSION );
}
add_action( 'admin_init', 'snn_analytics_clicks_maybe_upgrade_db', 6 );

// Reuse the daily prune job and retention setting of the pageview tracking.
function snn_analytics_clicks_prune() {
    global $wpdb;
    $days = max( 1, (int) snn_analytics_get_options()['retention_days'] );
    $wpdb->query( $wpdb->prepare(
        'DELETE FROM ' . snn_analytics_clicks_table() . ' WHERE created_at < %s',
        gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
    ) );
}
add_action( 'snn_analytics_daily_prune', 'snn_analytics_clicks_prune' );

// ----------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------

function snn_analytics_clicks_device_from_width( $width ) {
    $width = (int) $width;
    if ( $width > 0 && $width < 768 ) {
        return 'mobile';
    }
    if ( $width >= 768 && $width <= 1024 ) {
        return 'tablet';
    }
    return 'desktop';
}

function snn_analytics_clicks_devices() {
    return array(
        'all'     => __( 'All devices', 'snn' ),
        'desktop' => __( 'Desktop', 'snn' ),
        'tablet'  => __( 'Tablet', 'snn' ),
        'mobile'  => __( 'Mobile', 'snn' ),
    );
}

/**
 * Turns one line of the exclusion textarea (a URL or a bare domain) into a
 * lowercase host name. Returns '' if nothing usable is left.
 */
function snn_analytics_clicks_normalize_host( $line ) {
    $line = strtolower( trim( (string) $line ) );
    if ( '' === $line ) {
        return '';
    }
    if ( false === strpos( $line, '://' ) ) {
        $line = 'http://' . $line;
    }
    $host = wp_parse_url( $line, PHP_URL_HOST );
    return $host ? substr( $host, 0, 190 ) : '';
}

function snn_analytics_clicks_excluded_hosts() {
    $hosts   = array();
    $own     = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( $own ) {
        $hosts[] = strtolower( $own );
    }
    $lines = preg_split( '/\r\n|\r|\n/', (string) get_option( SNN_ANALYTICS_CLICKS_EXCLUDE_OPTION, '' ) );
    foreach ( (array) $lines as $line ) {
        $host = snn_analytics_clicks_normalize_host( $line );
        if ( '' !== $host ) {
            $hosts[] = $host;
        }
    }
    return array_values( array_unique( $hosts ) );
}

/**
 * Same rules as the pageview tracking, minus the "is this an ajax request"
 * check (the click arrives through admin-ajax).
 */
function snn_analytics_clicks_should_track() {
    if ( empty( snn_analytics_get_options()['enabled'] ) ) {
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

// ----------------------------------------------------------------------
// Frontend: script + endpoint
// ----------------------------------------------------------------------

function snn_analytics_clicks_print_script() {
    if ( is_admin() || is_feed() || is_customize_preview() ) {
        return;
    }
    foreach ( array( 'bricks_is_builder', 'bricks_is_builder_iframe', 'bricks_is_builder_call' ) as $bricks_check ) {
        if ( function_exists( $bricks_check ) && $bricks_check() ) {
            return;
        }
    }
    if ( ! snn_analytics_clicks_should_track() ) {
        return;
    }

    $config = array(
        'url'    => admin_url( 'admin-ajax.php' ),
        'postId' => (int) get_queried_object_id(),
    );
    ?>
    <script>
    ( function() {
        var cfg = <?php echo wp_json_encode( $config ); ?>;
        function anchorText( a ) {
            var t = ( a.textContent || '' ).replace( /\s+/g, ' ' ).trim();
            if ( t ) { return t; }
            var img = a.querySelector( 'img' );
            if ( img && img.src ) { return 'image:' + img.src; }
            var svgUse = a.querySelector( 'svg' );
            var label = a.getAttribute( 'aria-label' ) || a.getAttribute( 'title' ) || '';
            if ( label ) { return label; }
            return svgUse ? 'svg' : '';
        }
        function send( a ) {
            var href = a.href || '';
            if ( ! href || /^javascript:/i.test( href ) ) { return; }
            var raw = a.getAttribute( 'href' ) || '';
            if ( raw.charAt( 0 ) === '#' ) { return; }
            var data = new FormData();
            data.append( 'action', 'snn_track_click' );
            data.append( 'url', href );
            data.append( 'anchor', anchorText( a ) );
            data.append( 'post_id', cfg.postId );
            data.append( 'path', location.pathname );
            data.append( 'width', window.innerWidth );
            if ( navigator.sendBeacon ) {
                navigator.sendBeacon( cfg.url, data );
            } else if ( window.fetch ) {
                fetch( cfg.url, { method: 'POST', body: data, keepalive: true, credentials: 'same-origin' } );
            }
        }
        function handler( e ) {
            if ( e.type === 'auxclick' && e.button !== 1 ) { return; }
            var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
            if ( a ) { send( a ); }
        }
        document.addEventListener( 'click', handler, true );
        document.addEventListener( 'auxclick', handler, true );
    } )();
    </script>
    <?php
}
add_action( 'wp_footer', 'snn_analytics_clicks_print_script', 99 );

function snn_analytics_clicks_ajax_track() {
    if ( ! snn_analytics_clicks_should_track() ) {
        wp_die();
    }

    $url = isset( $_POST['url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ) : '';
    if ( '' === $url ) {
        wp_die();
    }
    $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
    if ( ! in_array( $scheme, array( 'http', 'https', 'tel', 'mailto', 'sms', 'whatsapp' ), true ) ) {
        wp_die();
    }

    $host = in_array( $scheme, array( 'http', 'https' ), true ) ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';

    $anchor = isset( $_POST['anchor'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) : '';
    $path   = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';

    global $wpdb;
    $wpdb->insert(
        snn_analytics_clicks_table(),
        array(
            'created_at' => current_time( 'mysql' ),
            'link_url'   => substr( $url, 0, 500 ),
            'link_host'  => substr( $host, 0, 190 ),
            'anchor'     => function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, 255 ) : substr( $anchor, 0, 255 ),
            'post_id'    => isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0,
            'page_path'  => substr( $path, 0, 255 ),
            'device'     => snn_analytics_clicks_device_from_width( isset( $_POST['width'] ) ? absint( $_POST['width'] ) : 0 ),
        ),
        array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
    );
    wp_die();
}
add_action( 'wp_ajax_nopriv_snn_track_click', 'snn_analytics_clicks_ajax_track' );
add_action( 'wp_ajax_snn_track_click', 'snn_analytics_clicks_ajax_track' );

// ----------------------------------------------------------------------
// Queries (own domain hidden, optional device filter)
// ----------------------------------------------------------------------

/**
 * @return array array( where_sql, params ) -- always starts after a WHERE of
 *                the date range, so it begins with AND.
 */
function snn_analytics_clicks_filter_sql( $device ) {
    $sql    = '';
    $params = array();

    $hosts = snn_analytics_clicks_excluded_hosts();
    if ( ! empty( $hosts ) ) {
        $sql   .= ' AND link_host NOT IN (' . implode( ',', array_fill( 0, count( $hosts ), '%s' ) ) . ')';
        $params = array_merge( $params, $hosts );
    }
    if ( in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
        $sql     .= ' AND device = %s';
        $params[] = $device;
    }
    return array( $sql, $params );
}

function snn_analytics_clicks_query_daily( $start, $end, $device ) {
    global $wpdb;
    $table = snn_analytics_clicks_table();
    list( $filter, $params ) = snn_analytics_clicks_filter_sql( $device );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(created_at) as d, COUNT(*) as c FROM {$table} WHERE created_at BETWEEN %s AND %s{$filter} GROUP BY DATE(created_at)",
        array_merge( array( $start, $end ), $params )
    ), ARRAY_A );

    $counts = array();
    foreach ( (array) $rows as $row ) {
        $counts[ $row['d'] ] = (int) $row['c'];
    }
    return snn_analytics_fill_daily_series( $counts, $start, $end );
}

function snn_analytics_clicks_query_table( $start, $end, $device, $limit = 100 ) {
    global $wpdb;
    $table = snn_analytics_clicks_table();
    list( $filter, $params ) = snn_analytics_clicks_filter_sql( $device );

    return (array) $wpdb->get_results( $wpdb->prepare(
        "SELECT link_url, anchor, post_id, page_path, COUNT(*) as c FROM {$table} WHERE created_at BETWEEN %s AND %s{$filter} GROUP BY link_url, anchor, post_id, page_path ORDER BY c DESC, link_url ASC LIMIT %d",
        array_merge( array( $start, $end ), $params, array( $limit ) )
    ), ARRAY_A );
}

// ----------------------------------------------------------------------
// Dashboard card
// ----------------------------------------------------------------------

function snn_analytics_clicks_register_meta_box( $screen ) {
    add_meta_box( 'snn_analytics_clicks', __( 'Link Clicks', 'snn' ), 'snn_analytics_mb_clicks', $screen, 'normal', 'default' );
}
add_action( 'snn_analytics_register_meta_boxes', 'snn_analytics_clicks_register_meta_box' );

function snn_analytics_clicks_render_chart( $daily ) {
    $values = array_values( $daily );
    $labels = array_keys( $daily );
    $n      = count( $values );
    if ( $n < 2 ) {
        return '<p>' . esc_html__( 'Not enough data for this time range.', 'snn' ) . '</p>';
    }

    $w = 700; $h = 200; $pad_l = 34; $pad_b = 26; $pad_t = 12; $pad_r = 10;
    $max    = max( 1, max( $values ) );
    $plot_w = $w - $pad_l - $pad_r;
    $plot_h = $h - $pad_t - $pad_b;

    $points = snn_analytics_chart_series_points( $values, $n, $max, $pad_l, $pad_t, $plot_w, $plot_h );

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="snn-analytics-chart" role="img" aria-label="' . esc_attr__( 'Link clicks per day', 'snn' ) . '">';
    for ( $i = 0; $i <= 4; $i++ ) {
        $y    = $pad_t + $plot_h - ( $i / 4 ) * $plot_h;
        $svg .= '<line x1="' . $pad_l . '" y1="' . round( $y, 1 ) . '" x2="' . ( $w - $pad_r ) . '" y2="' . round( $y, 1 ) . '" class="snn-analytics-chart-grid" />';
        $svg .= '<text x="0" y="' . round( $y + 4, 1 ) . '" class="snn-analytics-chart-axis">' . round( $max * $i / 4 ) . '</text>';
    }
    $svg .= '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" class="snn-analytics-chart-line-pageviews" />';
    $svg .= snn_analytics_chart_series_dots( $values, $labels, $n, $max, $pad_l, $pad_t, $plot_w, $plot_h, 'snn-analytics-chart-dot-pageviews', __( 'clicks', 'snn' ) );

    foreach ( array_unique( array( 0, intdiv( $n, 2 ), $n - 1 ) ) as $i ) {
        $x    = $pad_l + ( ( $i / ( $n - 1 ) ) * $plot_w );
        $svg .= '<text x="' . round( $x, 1 ) . '" y="' . ( $h - 6 ) . '" class="snn-analytics-chart-axis" text-anchor="middle">' . esc_html( date_i18n( 'd.m.', strtotime( $labels[ $i ] ) ) ) . '</text>';
    }
    return $svg . '</svg>';
}

function snn_analytics_mb_clicks() {
    list( $range, $start, $end ) = snn_analytics_current_range_bounds();

    $devices = snn_analytics_clicks_devices();
    $device  = isset( $_GET['click_device'] ) ? sanitize_key( wp_unslash( $_GET['click_device'] ) ) : 'all';
    if ( ! isset( $devices[ $device ] ) ) {
        $device = 'all';
    }

    $daily = snn_analytics_clicks_query_daily( $start, $end, $device );
    $rows  = snn_analytics_clicks_query_table( $start, $end, $device );
    $total = array_sum( $daily );
    ?>
    <p class="snn-analytics-clicks-filter">
        <?php foreach ( $devices as $key => $label ) :
            $url = add_query_arg( array( 'page' => 'snn-analytics', 'range' => $range, 'click_device' => $key ), admin_url( 'admin.php' ) );
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="button button-small <?php echo $device === $key ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
        <span class="description" style="margin-left:8px;">
            <?php
            /* translators: %s: number of clicks */
            printf( esc_html__( '%s clicks in this period', 'snn' ), esc_html( number_format_i18n( $total ) ) );
            ?>
        </span>
    </p>
    <?php echo snn_analytics_clicks_render_chart( $daily ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <table class="widefat striped" style="margin-top:12px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Link URL', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Anchor', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Page', 'snn' ); ?></th>
                <th><?php esc_html_e( 'Clicks', 'snn' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="4"><?php esc_html_e( 'No clicks recorded in this period.', 'snn' ); ?></td></tr>
            <?php else : foreach ( $rows as $row ) :
                $post_title = $row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '';
                $page_label = '' !== $post_title ? $post_title : $row['page_path'];
                $is_web     = (bool) preg_match( '#^https?://#i', $row['link_url'] );
                ?>
                <tr>
                    <td style="word-break:break-all;">
                        <?php if ( $is_web ) : ?>
                            <a href="<?php echo esc_url( $row['link_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['link_url'] ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $row['link_url'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td style="word-break:break-word;"><?php echo esc_html( $row['anchor'] ); ?></td>
                    <td><?php echo esc_html( $page_label ); ?></td>
                    <td><strong><?php echo esc_html( number_format_i18n( (int) $row['c'] ) ); ?></strong></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}

// ----------------------------------------------------------------------
// Settings: exclusion list (rendered inside the Analytics settings table)
// ----------------------------------------------------------------------

function snn_analytics_clicks_settings_row() {
    ?>
    <tr>
        <th><label for="snn_analytics_click_exclude"><?php esc_html_e( 'Exclude Domains from Click Tracking', 'snn' ); ?></label></th>
        <td>
            <textarea id="snn_analytics_click_exclude" name="snn_analytics_click_exclude" rows="5" class="large-text code" placeholder="www.example.com"><?php echo esc_textarea( (string) get_option( SNN_ANALYTICS_CLICKS_EXCLUDE_OPTION, '' ) ); ?></textarea>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: the site's own host name */
                    esc_html__( 'Links to your own domain (%s) are hidden automatically. Add further domains here, one per line, e.g. the www variant. The clicks are still recorded, only hidden in the table.', 'snn' ),
                    '<code>' . esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>'
                );
                ?>
            </p>
        </td>
    </tr>
    <?php
}
add_action( 'snn_analytics_settings_rows', 'snn_analytics_clicks_settings_row' );

function snn_analytics_clicks_save_settings() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['snn_analytics_save_settings'] ) ) {
        return;
    }
    if ( ! isset( $_POST['snn_analytics_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_analytics_nonce'] ) ), 'snn_analytics_save_settings_action' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['snn_analytics_click_exclude'] ) ) {
        return;
    }

    $clean = array();
    foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['snn_analytics_click_exclude'] ) ) as $line ) {
        $line = trim( sanitize_text_field( $line ) );
        if ( '' !== $line ) {
            $clean[] = $line;
        }
    }
    update_option( SNN_ANALYTICS_CLICKS_EXCLUDE_OPTION, implode( "\n", $clean ), false );
}
add_action( 'admin_init', 'snn_analytics_clicks_save_settings' );
