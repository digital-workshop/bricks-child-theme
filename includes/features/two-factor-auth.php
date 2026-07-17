<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ----------------------------------------------------------------------
// Settings / eligibility
// ----------------------------------------------------------------------

function snn_2fa_is_enabled() {
    $options = get_option( 'snn_security_options' );
    return ! empty( $options['enable_2fa'] );
}

/**
 * 2FA is required for every user EXCEPT those whose roles are entirely
 * made up of "customer" and/or "subscriber" (i.e. plain WooCommerce shop
 * customers). Any other role present (administrator, editor, author,
 * shop_manager, ...) forces 2FA, even if "customer" is also present --
 * this prevents an elevated account from opting out by also holding the
 * customer role.
 */
function snn_2fa_is_required_for_user( $user ) {
    if ( ! snn_2fa_is_enabled() ) {
        return false;
    }

    $roles        = (array) $user->roles;
    $exempt_roles = array( 'customer', 'subscriber' );
    $non_exempt   = array_diff( $roles, $exempt_roles );

    return ! empty( $non_exempt ) || empty( $roles );
}

// ----------------------------------------------------------------------
// Login interception
// ----------------------------------------------------------------------

add_filter( 'authenticate', 'snn_2fa_maybe_intercept', 40, 3 );
function snn_2fa_maybe_intercept( $user, $username, $password ) {
    // Only act on an already-successful password check (an earlier-priority
    // filter, WordPress core's own, already validated the credentials).
    if ( ! ( $user instanceof WP_User ) ) {
        return $user;
    }
    if ( $password === '' || $password === null ) {
        return $user;
    }
    if ( ! snn_2fa_is_required_for_user( $user ) ) {
        return $user;
    }

    // Non-interactive auth contexts (XML-RPC, REST/Application Passwords)
    // cannot complete an email code challenge -- block outright instead of
    // trying to redirect a programmatic client.
    if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return new WP_Error(
            'snn_2fa_required',
            __( '<strong>ERROR</strong>: Two-factor authentication is required for this account and is not available over this connection method.', 'snn' )
        );
    }

    $remember_me = isset( $_POST['rememberme'] ) && $_POST['rememberme'];
    $token       = snn_2fa_start_challenge( $user, $remember_me );

    if ( ! $token ) {
        return new WP_Error(
            'snn_2fa_send_failed',
            __( '<strong>ERROR</strong>: Could not send the verification code email. Please contact the site administrator.', 'snn' )
        );
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'action' => 'snn_2fa',
                'token'  => $token,
            ),
            wp_login_url()
        )
    );
    exit;
}

// ----------------------------------------------------------------------
// Code generation, storage, and email delivery
// ----------------------------------------------------------------------

function snn_2fa_generate_code() {
    return str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
}

function snn_2fa_transient_key( $token ) {
    return 'snn_2fa_' . $token;
}

/**
 * Starts a pending 2FA challenge: generates a code, emails it, and stores a
 * hashed copy (never plaintext) in a transient keyed by a high-entropy random
 * token -- not the user ID, so the token in the URL/form can't be used to
 * enumerate or target a specific account.
 *
 * @return string|false The token on success, false if the email could not be sent.
 */
function snn_2fa_start_challenge( $user, $remember = false ) {
    $token = bin2hex( random_bytes( 32 ) );
    $code  = snn_2fa_generate_code();

    $redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();

    $payload = array(
        'user_id'     => $user->ID,
        'code_hash'   => wp_hash_password( $code ),
        'attempts'    => 0,
        'remember'    => (bool) $remember,
        'redirect_to' => $redirect_to,
        'last_sent'   => time(),
    );

    set_transient( snn_2fa_transient_key( $token ), $payload, 15 * MINUTE_IN_SECONDS );

    if ( ! snn_2fa_send_code_email( $user, $code ) ) {
        delete_transient( snn_2fa_transient_key( $token ) );
        return false;
    }

    return $token;
}

function snn_2fa_send_code_email( $user, $code ) {
    $subject = __( 'Dein Bestätigungscode', 'snn' );
    $message = sprintf(
        "%s\n\n%s\n\n%s",
        __( 'Bitte schließe die Anmeldung ab, indem du den Bestätigungscode unten eingibst:', 'snn' ),
        $code,
        __( 'Dieser Code läuft in 15 Minuten ab.', 'snn' )
    );

    return wp_mail( $user->user_email, $subject, $message );
}

// ----------------------------------------------------------------------
// Verification screen (wp-login.php?action=snn_2fa&token=...)
// ----------------------------------------------------------------------

add_action( 'login_form_snn_2fa', 'snn_2fa_handle_challenge' );
function snn_2fa_handle_challenge() {
    $token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
    $key   = snn_2fa_transient_key( $token );
    $payload = $token ? get_transient( $key ) : false;

    if ( ! $payload ) {
        snn_2fa_redirect_to_login_with_notice( __( 'This verification link has expired or is invalid. Please log in again.', 'snn' ) );
        return;
    }

    $notice = '';

    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        if ( ! isset( $_POST['snn_2fa_nonce'] ) || ! wp_verify_nonce( $_POST['snn_2fa_nonce'], 'snn_2fa_verify_' . $token ) ) {
            snn_2fa_redirect_to_login_with_notice( __( 'Security check failed. Please log in again.', 'snn' ) );
            return;
        }

        if ( isset( $_POST['snn_2fa_resend'] ) ) {
            $notice = snn_2fa_process_resend( $token, $key, $payload );
            snn_2fa_render_challenge_form( $token, $notice );
            return;
        }

        $submitted_code = isset( $_POST['snn_2fa_code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['snn_2fa_code'] ) ) : '';

        if ( $submitted_code !== '' && wp_check_password( $submitted_code, $payload['code_hash'] ) ) {
            $user = get_userdata( $payload['user_id'] );
            delete_transient( $key );

            if ( ! $user ) {
                snn_2fa_redirect_to_login_with_notice( __( 'Something went wrong. Please log in again.', 'snn' ) );
                return;
            }

            wp_set_auth_cookie( $user->ID, ! empty( $payload['remember'] ) );
            do_action( 'wp_login', $user->user_login, $user );
            wp_safe_redirect( ! empty( $payload['redirect_to'] ) ? $payload['redirect_to'] : admin_url() );
            exit;
        }

        $payload['attempts'] = (int) $payload['attempts'] + 1;

        if ( $payload['attempts'] >= 5 ) {
            delete_transient( $key );
            snn_2fa_redirect_to_login_with_notice( __( 'Too many incorrect attempts. Please log in again.', 'snn' ) );
            return;
        }

        set_transient( $key, $payload, 15 * MINUTE_IN_SECONDS );
        $notice = __( 'Incorrect code. Please try again.', 'snn' );
    }

    snn_2fa_render_challenge_form( $token, $notice );
}

/**
 * Regenerates and re-sends the code for a pending challenge, throttled to
 * once per 60 seconds. Returns a user-facing notice string.
 */
function snn_2fa_process_resend( $token, $key, $payload ) {
    if ( time() - (int) $payload['last_sent'] < 60 ) {
        return __( 'Please wait a minute before requesting a new code.', 'snn' );
    }

    $user = get_userdata( $payload['user_id'] );
    if ( ! $user ) {
        return __( 'Something went wrong. Please log in again.', 'snn' );
    }

    $code                  = snn_2fa_generate_code();
    $payload['code_hash']  = wp_hash_password( $code );
    $payload['attempts']   = 0;
    $payload['last_sent']  = time();
    set_transient( $key, $payload, 15 * MINUTE_IN_SECONDS );

    if ( snn_2fa_send_code_email( $user, $code ) ) {
        return __( 'A new code has been sent.', 'snn' );
    }

    return __( 'Could not send a new code. Please try again shortly.', 'snn' );
}

function snn_2fa_redirect_to_login_with_notice( $message ) {
    wp_safe_redirect( add_query_arg( 'snn_2fa_notice', rawurlencode( $message ), wp_login_url() ) );
    exit;
}

add_filter( 'login_message', 'snn_2fa_maybe_show_notice' );
function snn_2fa_maybe_show_notice( $message ) {
    if ( ! empty( $_GET['snn_2fa_notice'] ) ) {
        $notice   = sanitize_text_field( wp_unslash( $_GET['snn_2fa_notice'] ) );
        $message .= '<p class="message">' . esc_html( $notice ) . '</p>';
    }
    return $message;
}

function snn_2fa_render_challenge_form( $token, $notice = '' ) {
    login_header( __( 'Verification Required', 'snn' ), '', null );

    $action_url = add_query_arg(
        array(
            'action' => 'snn_2fa',
            'token'  => $token,
        ),
        wp_login_url()
    );
    ?>
    <?php if ( $notice ) : ?>
        <p class="message"><?php echo esc_html( $notice ); ?></p>
    <?php endif; ?>

    <p><?php esc_html_e( 'We sent a verification code to your email address. Enter it below to finish signing in.', 'snn' ); ?></p>

    <form name="snn_2fa_form" id="snn_2fa_form" action="<?php echo esc_url( $action_url ); ?>" method="post">
        <p>
            <label for="snn_2fa_code"><?php esc_html_e( 'Verification Code', 'snn' ); ?></label>
            <input type="text" name="snn_2fa_code" id="snn_2fa_code" class="input" style="width: 100%; font-size: 20px; letter-spacing: 4px; text-align: center;" inputmode="numeric" autocomplete="one-time-code" maxlength="8" autofocus>
        </p>
        <?php wp_nonce_field( 'snn_2fa_verify_' . $token, 'snn_2fa_nonce' ); ?>
        <p class="submit">
            <input type="submit" name="snn_2fa_submit" id="snn_2fa_submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'snn' ); ?>">
        </p>
    </form>

    <form name="snn_2fa_resend_form" method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top: 12px; text-align: center;">
        <?php wp_nonce_field( 'snn_2fa_verify_' . $token, 'snn_2fa_nonce' ); ?>
        <input type="hidden" name="snn_2fa_resend" value="1">
        <button type="submit" class="button-link"><?php esc_html_e( 'Resend code', 'snn' ); ?></button>
    </form>

    <p id="backtoblog">
        <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( '&larr; Back to login', 'snn' ); ?></a>
    </p>
    <?php
    login_footer();
}
