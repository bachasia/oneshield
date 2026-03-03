<?php
/**
 * WordPress Admin settings page for OneShield Connect.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', 'osc_add_menu');
function osc_add_menu(): void {
    add_options_page(
        'OneShield Connect',
        'OneShield Connect',
        'manage_options',
        'oneshield-connect',
        'osc_settings_page'
    );
}

add_action('admin_init', 'osc_register_settings');
function osc_register_settings(): void {
    register_setting('oneshield_connect', 'oneshield_connect_gateway_url', [
        'sanitize_callback' => 'esc_url_raw',
    ]);
}

function osc_settings_page(): void {
    $is_connected = osc_is_connected();
    $gateway_url  = osc_gateway_url();
    $site_id      = osc_site_id();
    $notice       = '';

    // Handle "Connect Now" action
    if (isset($_POST['osc_connect']) && check_admin_referer('osc_connect_action')) {
        $new_url = esc_url_raw($_POST['gateway_url'] ?? '');
        if ($new_url) {
            osc_update_option('gateway_url', $new_url);
            $result = osc_register_site();
            if (is_wp_error($result)) {
                $notice = '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $notice = '<div class="notice notice-success"><p>Connected successfully! Site ID: ' . esc_html($result['site_id']) . '</p></div>';
                $is_connected = true;
                $gateway_url  = $new_url;
                $site_id      = $result['site_id'];
            }
        }
    }

    // Handle disconnect
    if (isset($_POST['osc_disconnect']) && check_admin_referer('osc_disconnect_action')) {
        osc_update_option('site_id', '');
        osc_update_option('site_key', '');
        $is_connected = false;
        $notice = '<div class="notice notice-success"><p>Disconnected from Gateway Panel.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>OneShield Connect</h1>
        <?php echo $notice; ?>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;max-width:600px;">

            <!-- Status -->
            <h2 style="margin-top:0;">Connection Status</h2>
            <?php if ($is_connected): ?>
                <p>
                    <span style="color:green;font-weight:bold;">&#10003; Connected</span>
                    to <strong><?php echo esc_html($gateway_url); ?></strong>
                    (Site ID: <code><?php echo esc_html($site_id); ?></code>)
                </p>
                <form method="post">
                    <?php wp_nonce_field('osc_disconnect_action'); ?>
                    <input type="submit" name="osc_disconnect" class="button button-secondary" value="Disconnect" />
                </form>
            <?php else: ?>
                <p><span style="color:#d63638;font-weight:bold;">&#10007; Not Connected</span></p>

                <form method="post">
                    <?php wp_nonce_field('osc_connect_action'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="gateway_url">Gateway Panel URL</label></th>
                            <td>
                                <input
                                    type="url"
                                    id="gateway_url"
                                    name="gateway_url"
                                    value="<?php echo esc_attr($gateway_url); ?>"
                                    placeholder="https://gateway.oneshield.io"
                                    class="regular-text"
                                />
                                <p class="description">Enter the URL of your OneShield Gateway Panel.</p>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="osc_connect" class="button button-primary" value="Connect Now" />
                </form>
            <?php endif; ?>
        </div>

        <?php if ($is_connected): ?>
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;max-width:600px;">
            <h2 style="margin-top:0;">Plugin Information</h2>
            <table class="form-table">
                <tr><th>Plugin Version</th><td><?php echo esc_html(OSC_VERSION); ?></td></tr>
                <tr><th>Site URL</th><td><?php echo esc_html(get_site_url()); ?></td></tr>
                <tr><th>Site ID</th><td><code><?php echo esc_html($site_id); ?></code></td></tr>
                <tr><th>Last Heartbeat</th><td id="osc-last-heartbeat">checking...</td></tr>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
