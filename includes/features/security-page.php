<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once SNN_PATH . 'includes/features/disable-xmlrpc.php';
require_once SNN_PATH . 'includes/features/disable-wp-json-if-not-logged-in.php'; 
require_once SNN_PATH . 'includes/features/disable-file-editing.php'; 
require_once SNN_PATH . 'includes/features/remove-rss.php'; 
require_once SNN_PATH . 'includes/features/remove-wp-version.php'; 
require_once SNN_PATH . 'includes/features/disable-bundled-theme-install.php';
require_once SNN_PATH . 'includes/features/limit-login-attempts.php';
require_once SNN_PATH . 'includes/features/login-url-security.php';
require_once SNN_PATH . 'includes/features/two-factor-auth.php';

function snn_add_security_submenu() {
    add_submenu_page(
        'snn-settings',
        'Security Settings',
        'Security Settings',
        'manage_options',
        'snn-security',
        'snn_security_page_callback'
    );
}
add_action('admin_menu', 'snn_add_security_submenu');

function snn_security_page_callback() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Security Settings', 'snn' ); ?></h1>
 
        <?php
            settings_errors();
        ?>
 
        <form method="post" action="options.php">
            <?php
                settings_fields( 'snn_security_settings_group' );
                do_settings_sections( 'snn-security' );
                submit_button();
            ?>
        </form>

        <?php
        // Clear blocked IPs section
        $blocked_count = snn_get_blocked_ips_count();
        ?>
        <div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2><?php esc_html_e( 'Blocked IPs Management', 'snn' ); ?></h2>
            <p><?php printf( esc_html__( 'Currently blocked IPs: %d', 'snn' ), $blocked_count ); ?></p>
            <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all blocked IPs?', 'snn' ); ?>');">
                <?php wp_nonce_field('snn_clear_blocked_ips_action', 'snn_clear_blocked_ips_nonce'); ?>
                <input type="submit" name="snn_clear_blocked_ips" class="button button-secondary" value="<?php esc_attr_e( 'Clear All Blocked IPs', 'snn' ); ?>">
            </form>
        </div>
    </div>
    <?php
}

function snn_security_settings_init() {
    register_setting(
        'snn_security_settings_group',
        'snn_security_options'
    );

    add_settings_section(
        'snn_security_main_section',
        __( 'Main Settings', 'snn' ),
        'snn_security_section_callback',
        'snn-security'
    );

    // Limit Login Attempts settings
    add_settings_field(
        'enable_limit_login',
        __( 'Enable Limit Login Attempts', 'snn' ),
        'snn_limit_login_callback',
        'snn-security',
        'snn_security_main_section'
    );

    add_settings_field(
        'max_login_attempts',
        __( 'Maximum Login Attempts', 'snn' ),
        'snn_max_attempts_callback',
        'snn-security',
        'snn_security_main_section'
    );

    add_settings_field(
        'login_reset_time',
        __( 'Reset Failed Attempts After (hours)', 'snn' ),
        'snn_reset_time_callback',
        'snn-security',
        'snn_security_main_section'
    );

    // Two-Factor Authentication
    add_settings_field(
        'enable_2fa',
        __( 'Enable Two-Factor Authentication', 'snn' ),
        'snn_2fa_enable_callback',
        'snn-security',
        'snn_security_main_section'
    );

    add_settings_field(
        '2fa_ip_whitelist',
        __( 'Two-Factor IP Whitelist', 'snn' ),
        'snn_2fa_ip_whitelist_callback',
        'snn-security',
        'snn_security_main_section'
    );
}
add_action( 'admin_init', 'snn_security_settings_init' );

function snn_security_section_callback() {
    ?>
    <style>
    [type="checkbox"]{
        width: 18px !important;
        height: 18px !important;
        float: left;
        margin-right: 10px !important;
    }
    [type="number"]{
        width: 100px !important;
    }
    </style>
    <?php
    echo '<p>' . esc_html__( 'Configure your security settings below:', 'snn' ) . '</p>';
}

function snn_limit_login_callback() {
    $options = get_option('snn_security_options');
    ?>
    <input type="checkbox" name="snn_security_options[enable_limit_login]" value="1" <?php checked(isset($options['enable_limit_login']) && $options['enable_limit_login'], 1); ?>>
    <p><?php esc_html_e( 'Enable this setting to limit login attempts and automatically block IPs with too many failed logins.', 'snn' ); ?></p>
    <?php
}

function snn_max_attempts_callback() {
    $options = get_option('snn_security_options');
    $max_attempts = isset($options['max_login_attempts']) && $options['max_login_attempts'] > 0 ? intval($options['max_login_attempts']) : 5;
    ?>
    <div style="padding-left: 40px;">
        <input type="number" name="snn_security_options[max_login_attempts]" value="<?php echo esc_attr($max_attempts); ?>" min="1" max="100" step="1">
        <p><?php esc_html_e( 'Specify the number of failed login attempts before an IP address is blocked. Default: 5', 'snn' ); ?></p>
    </div>
    <?php
}

function snn_reset_time_callback() {
    $options = get_option('snn_security_options');
    $reset_time = isset($options['login_reset_time']) && $options['login_reset_time'] > 0 ? intval($options['login_reset_time']) : 24;
    ?>
    <div style="padding-left: 40px;">
        <input type="number" name="snn_security_options[login_reset_time]" value="<?php echo esc_attr($reset_time); ?>" min="1" max="720" step="1">
        <p><?php esc_html_e( 'Time in hours after which failed login attempts count is reset to 0 and blocked IPs are unblocked. Default: 24 hours (1 day)', 'snn' ); ?></p>
    </div>
    <?php
}

function snn_2fa_enable_callback() {
    $options = get_option('snn_security_options');
    ?>
    <input type="checkbox" name="snn_security_options[enable_2fa]" value="1" <?php checked(isset($options['enable_2fa']) && $options['enable_2fa'], 1); ?>>
    <p><?php esc_html_e( 'Require a one-time code sent by email to complete login, for every user account.', 'snn' ); ?></p>
    <p class="description"><?php esc_html_e( 'Exception: users whose only role(s) are WooCommerce "Customer" and/or "Subscriber" are exempt. Any account with an additional role (Administrator, Editor, Author, Shop Manager, ...) is always required to use two-factor authentication, even if it also has the Customer role.', 'snn' ); ?></p>
    <p class="description">
        <?php
        printf(
            /* translators: %s: the constant name SNN_2FA_DISABLE */
            esc_html__( 'Locked out (e.g. the code email isn\'t arriving)? Add %s to wp-config.php to force this off, no database access needed.', 'snn' ),
            '<code>define( \'SNN_2FA_DISABLE\', true );</code>'
        );
        ?>
    </p>
    <?php
}

function snn_2fa_ip_whitelist_callback() {
    $options   = get_option( 'snn_security_options' );
    $whitelist = isset( $options['2fa_ip_whitelist'] ) && is_array( $options['2fa_ip_whitelist'] ) ? $options['2fa_ip_whitelist'] : array();

    if ( empty( $whitelist ) ) {
        $whitelist = array( '' );
    }

    $current_ip = function_exists( 'snn_get_user_ip' ) ? snn_get_user_ip() : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    ?>
    <p style="margin-top: 0;">
        <?php
        printf(
            /* translators: %s: the visitor's current IP address */
            esc_html__( 'Your current IP address is %s.', 'snn' ),
            '<code>' . esc_html( $current_ip ) . '</code>'
        );
        ?>
        <button type="button" id="snn_2fa_add_current_ip" class="button button-small" data-ip="<?php echo esc_attr( $current_ip ); ?>" style="margin-left: 6px;">
            <span class="dashicons dashicons-admin-network" style="vertical-align:middle;"></span>
            <?php esc_html_e( 'Add my IP', 'snn' ); ?>
        </button>
    </p>
    <div id="snn_2fa_ip_whitelist_rows">
        <?php foreach ( $whitelist as $ip ) : ?>
            <div class="snn-2fa-ip-row" style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <input type="text" name="snn_security_options[2fa_ip_whitelist][]" value="<?php echo esc_attr( $ip ); ?>" placeholder="203.0.113.5 <?php esc_attr_e( 'or', 'snn' ); ?> 203.0.113.0/24" style="width:280px;">
                <button type="button" class="button snn-2fa-ip-remove" aria-label="<?php esc_attr_e( 'Remove', 'snn' ); ?>">
                    <span class="dashicons dashicons-no-alt" style="vertical-align:middle;"></span>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="snn_2fa_ip_add" class="button">
        <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span>
        <?php esc_html_e( 'Add IP Address', 'snn' ); ?>
    </button>
    <p class="description"><?php esc_html_e( 'Requests from these IP addresses skip two-factor authentication entirely, for every account -- useful for a trusted office or VPN IP. One address or CIDR range (e.g. 203.0.113.0/24) per field.', 'snn' ); ?></p>
    <script>
    (function() {
        const container = document.getElementById('snn_2fa_ip_whitelist_rows');
        const addBtn = document.getElementById('snn_2fa_ip_add');
        const addCurrentIpBtn = document.getElementById('snn_2fa_add_current_ip');
        if (!container || !addBtn) return;

        function bindRemove(row) {
            const btn = row.querySelector('.snn-2fa-ip-remove');
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
            row.className = 'snn-2fa-ip-row';
            row.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:8px;';
            row.innerHTML = '<input type="text" name="snn_security_options[2fa_ip_whitelist][]" value="" placeholder="203.0.113.5 <?php echo esc_js( __( 'or', 'snn' ) ); ?> 203.0.113.0/24" style="width:280px;">' +
                '<button type="button" class="button snn-2fa-ip-remove" aria-label="<?php echo esc_js( __( 'Remove', 'snn' ) ); ?>"><span class="dashicons dashicons-no-alt" style="vertical-align:middle;"></span></button>';
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

        container.querySelectorAll('.snn-2fa-ip-row').forEach(bindRemove);

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
                row.querySelector('input').focus();
            });
        }
    })();
    </script>
    <?php
}

?>
