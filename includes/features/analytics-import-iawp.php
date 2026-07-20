<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Independent Analytics import.
 *
 * Detects the database tables the Independent Analytics plugin creates and
 * offers a one-time, resumable, batched import of its historical pageview
 * data into this theme's own analytics table.
 *
 * The schema below was reverse-engineered from the plugin's actual source
 * (IAWP\Views\View, IAWP\Models\Visitor, IAWP\Query, IAWP\Tables --
 * version 2.14.10, plugins.svn.wordpress.org/independent-analytics)
 * rather than guessed: a wrong assumption here would silently import
 * nothing, or worse, import garbage.
 */

define( 'SNN_ANALYTICS_IAWP_STATUS_OPTION', 'snn_analytics_iawp_import_status' );
define( 'SNN_ANALYTICS_IAWP_BATCH_SIZE', 300 );

function snn_analytics_iawp_table( $name ) {
    global $wpdb;
    return $wpdb->prefix . 'independent_analytics_' . $name;
}

function snn_analytics_iawp_detected() {
    static $detected = null;
    if ( null === $detected ) {
        global $wpdb;
        $table    = snn_analytics_iawp_table( 'views' );
        $detected = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
    return $detected;
}

function snn_analytics_iawp_get_status() {
    $defaults = array(
        'state'          => 'not_started', // not_started | in_progress | completed | dismissed
        'after_id'       => 0,
        'imported'       => 0,
        'skipped'        => 0,
        'total_snapshot' => 0,
        'started_at'     => null,
        'completed_at'   => null,
    );
    $status = get_option( SNN_ANALYTICS_IAWP_STATUS_OPTION, array() );
    return wp_parse_args( is_array( $status ) ? $status : array(), $defaults );
}

function snn_analytics_iawp_save_status( $status ) {
    update_option( SNN_ANALYTICS_IAWP_STATUS_OPTION, $status, false );
}

/**
 * Resolves one Independent Analytics "resource" row to a URL path, using
 * the same WordPress functions that generate that resource's real
 * permalink. Returns null (row skipped, never guessed) when the underlying
 * content no longer exists, or the resource type is too ambiguous to
 * reconstruct reliably (its stored format isn't documented anywhere
 * public).
 */
function snn_analytics_iawp_resolve_path( $row ) {
    $path = null;

    switch ( $row['resource'] ) {
        case 'home':
            $path = '/';
            break;

        case 'singular':
            $permalink = $row['singular_id'] ? get_permalink( (int) $row['singular_id'] ) : false;
            $path      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;
            break;

        case 'post_type_archive':
            $link = $row['post_type'] ? get_post_type_archive_link( $row['post_type'] ) : false;
            $path = $link ? wp_parse_url( $link, PHP_URL_PATH ) : null;
            break;

        case 'term_archive':
            $term = $row['term_id'] ? get_term( (int) $row['term_id'] ) : null;
            if ( $term && ! is_wp_error( $term ) ) {
                $link = get_term_link( $term );
                $path = is_wp_error( $link ) ? null : wp_parse_url( $link, PHP_URL_PATH );
            }
            break;

        case 'author_archive':
            $link = $row['author_id'] ? get_author_posts_url( (int) $row['author_id'] ) : false;
            $path = $link ? wp_parse_url( $link, PHP_URL_PATH ) : null;
            break;

        case '404':
            if ( ! empty( $row['not_found_url'] ) ) {
                $parsed = wp_parse_url( $row['not_found_url'], PHP_URL_PATH );
                $path   = $parsed ? $parsed : '/';
            }
            break;

        case 'search':
            $path = '/?s=' . rawurlencode( (string) $row['search_query'] );
            break;

        case 'virtual_page':
            if ( ! empty( $row['virtual_page_id'] ) ) {
                $path = '/' . ltrim( (string) $row['virtual_page_id'], '/' );
            }
            break;

        // 'date_archive' and any other/future resource type: undocumented
        // stored format, reconstructing it wrong would silently mislabel
        // data, so it's skipped rather than guessed.
        default:
            return null;
    }

    if ( null === $path || '' === $path ) {
        return null;
    }

    // WordPress' default pagination convention for archive-type listings.
    // Singular posts paginate differently (/2/, not /page/2/) and rarely
    // enough that it's not worth the extra ambiguity here.
    $paged_types = array( 'home', 'post_type_archive', 'term_archive', 'author_archive' );
    if ( in_array( $row['resource'], $paged_types, true ) && (int) $row['page'] > 1 ) {
        $path = rtrim( $path, '/' ) . '/page/' . (int) $row['page'] . '/';
    }

    return substr( $path, 0, 255 );
}

/**
 * Imports up to SNN_ANALYTICS_IAWP_BATCH_SIZE rows starting after the given
 * source view ID. The cursor window is fetched from the raw views table
 * FIRST, independent of whether each row successfully joins to a session
 * and resource -- so an occasional orphaned row (e.g. left over from one of
 * the plugin's own internal migrations) can never make the importer think
 * it has reached the end prematurely, and OFFSET-based pagination (which
 * gets slower as it goes) is avoided entirely.
 */
function snn_analytics_iawp_import_batch( $after_id ) {
    global $wpdb;

    $views_table     = snn_analytics_iawp_table( 'views' );
    $sessions_table  = snn_analytics_iawp_table( 'sessions' );
    $resources_table = snn_analytics_iawp_table( 'resources' );
    $referrers_table = snn_analytics_iawp_table( 'referrers' );

    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$views_table} WHERE id > %d ORDER BY id ASC LIMIT %d",
        $after_id, SNN_ANALYTICS_IAWP_BATCH_SIZE
    ) );

    if ( empty( $ids ) ) {
        return array( 'imported' => 0, 'skipped' => 0, 'next_id' => $after_id, 'done' => true );
    }

    $ids_in = implode( ',', array_map( 'absint', $ids ) );
    $rows   = $wpdb->get_results(
        "SELECT v.id, v.viewed_at, v.page, s.visitor_id,
                r.resource, r.singular_id, r.author_id, r.date_archive, r.post_type,
                r.term_id, r.search_query, r.not_found_url, r.virtual_page_id,
                ref.domain AS referrer_domain
         FROM {$views_table} v
         INNER JOIN {$sessions_table} s ON v.session_id = s.session_id
         INNER JOIN {$resources_table} r ON v.resource_id = r.id
         LEFT JOIN {$referrers_table} ref ON s.referrer_id = ref.id
         WHERE v.id IN ({$ids_in})
         ORDER BY v.id ASC",
        ARRAY_A
    );

    $our_table = snn_analytics_table();
    $imported  = 0;
    $skipped   = 0;

    foreach ( $rows as $row ) {
        $path = snn_analytics_iawp_resolve_path( $row );
        if ( null === $path ) {
            $skipped++;
            continue;
        }

        // viewed_at is stored in UTC ("Y-m-d\TH:i:s") -- convert to the
        // site's local time so imported rows bucket into the same days as
        // rows recorded by our own tracker (which uses current_time()).
        $viewed_at_mysql = str_replace( 'T', ' ', $row['viewed_at'] );
        $created_at      = get_date_from_gmt( $viewed_at_mysql, 'Y-m-d H:i:s' );
        $referrer_host   = ! empty( $row['referrer_domain'] ) ? substr( $row['referrer_domain'], 0, 255 ) : null;
        // Independent Analytics' own hash rotates only when its salt does
        // (effectively persistent per visitor), unlike ours which rotates
        // daily -- there's no way to derive an equivalent rotating hash
        // retroactively, so imported rows use a stable hash of their
        // internal visitor_id instead. This still counts "distinct
        // visitors" correctly for historical data; it just won't match the
        // hash format of newly-tracked rows, which doesn't matter since
        // nothing compares hashes across rows other than COUNT(DISTINCT).
        $visitor_hash = hash( 'sha256', 'iawp-import-' . $row['visitor_id'] );

        $wpdb->insert(
            $our_table,
            array(
                'created_at'    => $created_at,
                'page_path'     => $path,
                'referrer_host' => $referrer_host,
                'visitor_hash'  => $visitor_hash,
            ),
            array( '%s', '%s', '%s', '%s' )
        );
        $imported++;
    }

    // Raw IDs that didn't come back joined (orphaned rows) still count as
    // processed so the running totals add up to the raw window size.
    $skipped += count( $ids ) - count( $rows );

    return array(
        'imported' => $imported,
        'skipped'  => $skipped,
        'next_id'  => (int) end( $ids ),
        'done'     => count( $ids ) < SNN_ANALYTICS_IAWP_BATCH_SIZE,
    );
}

function snn_analytics_iawp_ajax_import_batch() {
    check_ajax_referer( 'snn_analytics_iawp_import', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error();
    }
    if ( ! snn_analytics_iawp_detected() ) {
        wp_send_json_error( array( 'message' => __( 'Independent Analytics data not found.', 'snn' ) ) );
    }

    $status = snn_analytics_iawp_get_status();
    if ( 'completed' === $status['state'] ) {
        wp_send_json_success( array( 'done' => true, 'status' => $status ) );
    }

    if ( 'in_progress' !== $status['state'] ) {
        global $wpdb;
        $status['state']          = 'in_progress';
        $status['started_at']     = current_time( 'mysql' );
        $status['total_snapshot'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . snn_analytics_iawp_table( 'views' ) );
    }

    $result = snn_analytics_iawp_import_batch( (int) $status['after_id'] );

    $status['after_id']  = $result['next_id'];
    $status['imported'] += $result['imported'];
    $status['skipped']  += $result['skipped'];

    if ( $result['done'] ) {
        $status['state']        = 'completed';
        $status['completed_at'] = current_time( 'mysql' );
    }

    snn_analytics_iawp_save_status( $status );

    wp_send_json_success( array( 'done' => 'completed' === $status['state'], 'status' => $status ) );
}
add_action( 'wp_ajax_snn_analytics_iawp_import_batch', 'snn_analytics_iawp_ajax_import_batch' );

function snn_analytics_iawp_ajax_dismiss() {
    check_ajax_referer( 'snn_analytics_iawp_import', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error();
    }
    $status = snn_analytics_iawp_get_status();
    if ( 'not_started' === $status['state'] ) {
        $status['state'] = 'dismissed';
        snn_analytics_iawp_save_status( $status );
    }
    wp_send_json_success();
}
add_action( 'wp_ajax_snn_analytics_iawp_dismiss', 'snn_analytics_iawp_ajax_dismiss' );

function snn_analytics_iawp_render_notice() {
    if ( ! snn_analytics_iawp_detected() ) {
        return;
    }
    $status = snn_analytics_iawp_get_status();
    if ( ! in_array( $status['state'], array( 'not_started', 'in_progress' ), true ) ) {
        return; // Nothing left to offer once dismissed or completed.
    }
    $resuming = 'in_progress' === $status['state'];
    ?>
    <div id="snn-analytics-iawp-import" class="notice notice-info">
        <p id="snn-iawp-intro" <?php echo $resuming ? 'style="display:none;"' : ''; ?>>
            <?php esc_html_e( 'Independent Analytics data was found on this site. Would you like to import its historical pageview data into this Analytics tool?', 'snn' ); ?>
        </p>
        <p id="snn-iawp-buttons" <?php echo $resuming ? 'style="display:none;"' : ''; ?>>
            <button type="button" class="button button-primary" id="snn-iawp-start-btn"><?php esc_html_e( 'Import Now', 'snn' ); ?></button>
            <button type="button" class="button-link" id="snn-iawp-dismiss-btn"><?php esc_html_e( "Don't Import", 'snn' ); ?></button>
        </p>
        <div id="snn-iawp-progress-wrap" <?php echo $resuming ? '' : 'style="display:none;"'; ?>>
            <div style="background:#f0f0f1; border-radius:3px; height:10px; max-width:400px; overflow:hidden;">
                <div id="snn-iawp-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width .2s;"></div>
            </div>
            <p id="snn-iawp-progress-text"></p>
        </div>
    </div>
    <script>
    ( function() {
        var wrap         = document.getElementById( 'snn-analytics-iawp-import' );
        if ( ! wrap ) { return; }
        var startBtn     = document.getElementById( 'snn-iawp-start-btn' );
        var dismissBtn   = document.getElementById( 'snn-iawp-dismiss-btn' );
        var intro        = document.getElementById( 'snn-iawp-intro' );
        var buttons      = document.getElementById( 'snn-iawp-buttons' );
        var progressWrap = document.getElementById( 'snn-iawp-progress-wrap' );
        var progressBar  = document.getElementById( 'snn-iawp-progress-bar' );
        var progressText = document.getElementById( 'snn-iawp-progress-text' );
        var totalSnapshot   = <?php echo (int) $status['total_snapshot']; ?>;
        var runningImported = <?php echo (int) $status['imported']; ?>;
        var runningSkipped  = <?php echo (int) $status['skipped']; ?>;

        function setProgress() {
            var pct = totalSnapshot > 0 ? Math.min( 100, Math.round( ( runningImported + runningSkipped ) / totalSnapshot * 100 ) ) : 0;
            progressBar.style.width = pct + '%';
            progressText.textContent = runningImported + ' / ' + totalSnapshot + ' <?php echo esc_js( __( 'imported', 'snn' ) ); ?>' + ( runningSkipped > 0 ? ' (' + runningSkipped + ' <?php echo esc_js( __( 'skipped', 'snn' ) ); ?>)' : '' );
        }

        function runBatch() {
            var body = new URLSearchParams();
            body.set( 'action', 'snn_analytics_iawp_import_batch' );
            body.set( 'nonce', '<?php echo esc_js( wp_create_nonce( 'snn_analytics_iawp_import' ) ); ?>' );

            fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
                .then( function( r ) { return r.json(); } )
                .then( function( json ) {
                    if ( ! json.success ) {
                        progressText.textContent = '<?php echo esc_js( __( 'Import failed. Please try again.', 'snn' ) ); ?>';
                        return;
                    }
                    var status = json.data.status;
                    totalSnapshot   = status.total_snapshot || totalSnapshot;
                    runningImported = status.imported;
                    runningSkipped  = status.skipped;
                    setProgress();
                    if ( json.data.done ) {
                        progressText.textContent = '<?php echo esc_js( __( 'Import complete:', 'snn' ) ); ?> ' + runningImported + ' <?php echo esc_js( __( 'imported', 'snn' ) ); ?>' + ( runningSkipped > 0 ? ', ' + runningSkipped + ' <?php echo esc_js( __( 'skipped', 'snn' ) ); ?>' : '' ) + '.';
                        return;
                    }
                    runBatch();
                } )
                .catch( function() {
                    progressText.textContent = '<?php echo esc_js( __( 'Import failed. Please try again.', 'snn' ) ); ?>';
                } );
        }

        if ( startBtn ) {
            startBtn.addEventListener( 'click', function() {
                intro.style.display = 'none';
                buttons.style.display = 'none';
                progressWrap.style.display = '';
                runBatch();
            } );
        }
        if ( dismissBtn ) {
            dismissBtn.addEventListener( 'click', function() {
                var body = new URLSearchParams();
                body.set( 'action', 'snn_analytics_iawp_dismiss' );
                body.set( 'nonce', '<?php echo esc_js( wp_create_nonce( 'snn_analytics_iawp_import' ) ); ?>' );
                fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function() {
                    wrap.remove();
                } );
            } );
        }

        <?php if ( $resuming ) : ?>
        setProgress();
        runBatch();
        <?php endif; ?>
    } )();
    </script>
    <?php
}
add_action( 'snn_analytics_page_notices', 'snn_analytics_iawp_render_notice' );
