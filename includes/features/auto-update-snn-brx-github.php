<?php


// Configuration
$snn_fork_repo       = 'digital-workshop/bricks-child-theme';
$snn_fork_theme_slug = get_stylesheet();
$snn_fork_cache_key  = 'snn_brx_fork_release_' . md5($snn_fork_repo);

/**
 * Fetch the latest GitHub release for our own fork, cached to respect
 * GitHub's unauthenticated rate limit (60 requests/hour per IP).
 */
function snn_brx_fork_get_latest_release() {
    global $snn_fork_repo, $snn_fork_cache_key;

    $cached = get_transient($snn_fork_cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $response = wp_remote_get(
        "https://api.github.com/repos/{$snn_fork_repo}/releases/latest",
        array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ),
        )
    );

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        // Cache the miss briefly too, so a down/rate-limited API doesn't get hit on every admin page load.
        set_transient($snn_fork_cache_key, null, HOUR_IN_SECONDS);
        return null;
    }

    $release_data = json_decode(wp_remote_retrieve_body($response));
    if (!$release_data || !isset($release_data->tag_name)) {
        set_transient($snn_fork_cache_key, null, HOUR_IN_SECONDS);
        return null;
    }

    set_transient($snn_fork_cache_key, $release_data, 12 * HOUR_IN_SECONDS);
    return $release_data;
}

/**
 * Resolve the zip to install: prefer a manually uploaded asset named
 * "<theme-slug>.zip" on the release (gives full control over the zip's
 * contents), otherwise fall back to GitHub's auto-generated source zip.
 */
function snn_brx_fork_get_download_url($release_data) {
    global $snn_fork_theme_slug;

    $expected_asset_name = $snn_fork_theme_slug . '.zip';

    if (isset($release_data->assets) && is_array($release_data->assets)) {
        foreach ($release_data->assets as $asset) {
            if (isset($asset->browser_download_url) && $asset->name === $expected_asset_name) {
                return $asset->browser_download_url;
            }
        }
    }

    return $release_data->zipball_url ?? '';
}

/**
 * Check for Theme Updates against our own fork's GitHub releases.
 */
add_filter('pre_set_site_transient_update_themes', 'snn_brx_fork_check_theme_update');
function snn_brx_fork_check_theme_update($transient) {
    global $snn_fork_theme_slug;

    $current_theme   = wp_get_theme($snn_fork_theme_slug);
    $current_version = $current_theme->get('Version');

    $release_data = snn_brx_fork_get_latest_release();
    if (!$release_data) {
        return $transient;
    }

    $latest_version = ltrim($release_data->tag_name, 'vV');
    $download_url   = snn_brx_fork_get_download_url($release_data);

    if ($download_url && version_compare($latest_version, $current_version, '>')) {
        $transient->response[$snn_fork_theme_slug] = array(
            'theme'       => $snn_fork_theme_slug,
            'new_version' => $latest_version,
            'url'         => $release_data->html_url ?? '',
            'package'     => $download_url,
        );
    }

    return $transient;
}

/**
 * Provide Theme Info for the "View version x.x.x details" popup in WP.
 */
add_filter('themes_api', 'snn_brx_fork_theme_info', 10, 3);
function snn_brx_fork_theme_info($result, $action, $args) {
    global $snn_fork_theme_slug;

    if ($action !== 'theme_information' || $args->slug !== $snn_fork_theme_slug) {
        return $result;
    }

    $release_data = snn_brx_fork_get_latest_release();
    if (!$release_data) {
        return $result;
    }

    $latest_version = ltrim($release_data->tag_name, 'vV');
    $download_url   = snn_brx_fork_get_download_url($release_data);

    return (object) array(
        'name'          => $args->slug,
        'slug'          => $args->slug,
        'version'       => $latest_version,
        'requires'      => '5.0',
        'tested'        => get_bloginfo('version'),
        'requires_php'  => '7.4',
        'download_link' => $download_url,
        'sections'      => array(
            'description' => $release_data->body ?? __('Latest release information from GitHub.', 'snn'),
            'changelog'   => $release_data->body ?? __('See GitHub release notes for details.', 'snn'),
        ),
        'added'        => isset($release_data->published_at) ? date('Y-m-d', strtotime($release_data->published_at)) : '',
        'last_updated' => isset($release_data->published_at) ? date('Y-m-d', strtotime($release_data->published_at)) : '',
        'homepage'     => $release_data->html_url ?? '',
    );
}

/**
 * Add JavaScript to admin footer to redirect the version details link
 * to our own fork's releases page instead of the WP thickbox modal.
 */
add_action('admin_footer', 'snn_brx_fork_github_redirect_version_link');
function snn_brx_fork_github_redirect_version_link() {
    global $snn_fork_repo;
    ?>
    <script type="text/javascript">
        (function() {
            const githubUrl = 'https://github.com/<?php echo esc_js($snn_fork_repo); ?>/releases';

            function modifyLink(link) {
                // Target only the FIRST link in the notice
                const notice = link.closest('.notice');
                if (!notice) return;

                const firstLink = notice.querySelector('a[aria-label*="SNN-BRX"], a[aria-label*="Bricks Child Theme"]');

                // Only modify if this is the first link
                if (link === firstLink) {
                    // Replace the href completely
                    link.href = githubUrl;

                    // Remove thickbox classes
                    link.classList.remove('thickbox', 'open-plugin-details-modal');

                    // Set target to open in new tab
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';

                    // Add click handler as extra safety
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        window.open(githubUrl, '_blank', 'noopener,noreferrer');
                        return false;
                    }, true);
                }
            }

            function processLinks() {
                const notices = document.querySelectorAll('.notice');
                notices.forEach(function(notice) {
                    const firstLink = notice.querySelector('strong a[aria-label*="SNN-BRX"]:first-of-type, strong a[aria-label*="Bricks Child Theme"]:first-of-type');
                    if (firstLink) {
                        modifyLink(firstLink);
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', processLinks);
            } else {
                processLinks();
            }

            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if (node.classList && node.classList.contains('notice')) {
                                const firstLink = node.querySelector('strong a[aria-label*="SNN-BRX"]:first-of-type, strong a[aria-label*="Bricks Child Theme"]:first-of-type');
                                if (firstLink) {
                                    modifyLink(firstLink);
                                }
                            }
                            const notices = node.querySelectorAll && node.querySelectorAll('.notice');
                            if (notices) {
                                notices.forEach(function(notice) {
                                    const firstLink = notice.querySelector('strong a[aria-label*="SNN-BRX"]:first-of-type, strong a[aria-label*="Bricks Child Theme"]:first-of-type');
                                    if (firstLink) {
                                        modifyLink(firstLink);
                                    }
                                });
                            }
                        }
                    });
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        })();
    </script>
    <?php
}

/**
 * Clear the cached release info immediately after a manual "Check Again"
 * on the Updates screen, so testing a fresh release doesn't wait 12h.
 */
add_action('load-update-core.php', function () {
    if (isset($_GET['force-check'])) {
        global $snn_fork_cache_key;
        delete_transient($snn_fork_cache_key);
    }
});
