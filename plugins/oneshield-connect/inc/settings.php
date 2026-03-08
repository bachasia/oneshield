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
    register_setting('oneshield_connect', 'oneshield_connect_token_secret', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('oneshield_connect', 'oneshield_connect_register_site_id', [
        'sanitize_callback' => 'absint',
    ]);
    register_setting('oneshield_connect', 'oneshield_connect_authorize_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    // stripe_webhook_secret is synced automatically via heartbeat from Gateway Panel — not user-editable here
}

function osc_settings_page(): void {
    $is_connected = osc_is_connected();
    $gateway_url  = osc_gateway_url();
    $site_id      = osc_site_id();
    $notice       = '';

    // Handle "Connect Now" action
    if (isset($_POST['osc_connect']) && check_admin_referer('osc_connect_action')) {
        $new_url        = esc_url_raw($_POST['gateway_url'] ?? '');
        $new_secret     = sanitize_text_field($_POST['token_secret'] ?? '');
        $new_site_id    = absint($_POST['register_site_id'] ?? 0);
        $new_auth_key   = sanitize_text_field($_POST['authorize_key'] ?? '');
        if ($new_url && $new_secret && $new_site_id > 0 && $new_auth_key) {
            osc_update_option('gateway_url', $new_url);
            osc_update_option('token_secret', $new_secret);
            osc_update_option('register_site_id', $new_site_id);
            osc_update_option('authorize_key', $new_auth_key);
            $result = osc_register_site();
            if (is_wp_error($result)) {
                $notice = '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $notice = '<div class="notice notice-success"><p>Connected successfully! Site ID: ' . esc_html($result['site_id']) . '</p></div>';
                $is_connected = true;
                $gateway_url  = $new_url;
                $site_id      = $result['site_id'];
            }
        } elseif (empty($new_url)) {
            $notice = '<div class="notice notice-error"><p>Please enter the Gateway Panel URL.</p></div>';
        } elseif (empty($new_secret)) {
            $notice = '<div class="notice notice-error"><p>Please enter the Token Secret from your Gateway Panel Settings page.</p></div>';
        } elseif ($new_site_id <= 0) {
            $notice = '<div class="notice notice-error"><p>Please enter the Shield Site ID from your Gateway Panel.</p></div>';
        } elseif (empty($new_auth_key)) {
            $notice = '<div class="notice notice-error"><p>Please enter the Authorize Key from your Gateway Panel.</p></div>';
        }
    }

    // Handle disconnect
    if (isset($_POST['osc_disconnect']) && check_admin_referer('osc_disconnect_action')) {
        osc_update_option('site_id', '');
        osc_update_option('site_key', '');
        osc_update_option('register_site_id', '');
        osc_update_option('authorize_key', '');
        $is_connected = false;
        $notice = '<div class="notice notice-success"><p>Disconnected from Gateway Panel.</p></div>';
    }

    // (webhook secret is synced automatically from Gateway Panel via heartbeat — no manual save needed)

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
                        <tr>
                            <th><label for="register_site_id">Site ID</label></th>
                            <td>
                                <input
                                    type="number"
                                    min="1"
                                    id="register_site_id"
                                    name="register_site_id"
                                    value="<?php echo esc_attr((string) osc_register_site_id()); ?>"
                                    placeholder="e.g. 12"
                                    class="regular-text"
                                />
                                <p class="description">Found in Gateway Panel - Shield Sites - your site row.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="authorize_key">Authorize Key</label></th>
                            <td>
                                <input
                                    type="password"
                                    id="authorize_key"
                                    name="authorize_key"
                                    value=""
                                    placeholder="Paste Authorize Key"
                                    class="regular-text"
                                    autocomplete="new-password"
                                />
                                <p class="description">Found in Gateway Panel - Shield Sites - Settings - Authorize Key.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="token_secret">Token Secret</label></th>
                            <td>
                                <input
                                    type="password"
                                    id="token_secret"
                                    name="token_secret"
                                    value=""
                                    placeholder="Paste your Token Secret here"
                                    class="regular-text"
                                    autocomplete="new-password"
                                />
                                <p class="description">
                                    Found in your Gateway Panel under <strong>Settings &rarr; Token Secret</strong>.
                                    This is used to authenticate this site with the Gateway Panel.
                                </p>
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
                <tr>
                    <th>Last Heartbeat</th>
                    <td id="osc-last-heartbeat">
                        <?php
                        $last = (int) osc_get_option('last_heartbeat', 0);
                        if ($last > 0) {
                            $diff = time() - $last;
                            if ($diff < 60) {
                                echo '<span style="color:green;">&#10003; Just now (' . esc_html($diff) . 's ago)</span>';
                            } elseif ($diff < 600) {
                                echo '<span style="color:green;">&#10003; ' . esc_html(human_time_diff($last)) . ' ago</span>';
                            } else {
                                echo '<span style="color:#d63638;">&#9888; ' . esc_html(human_time_diff($last)) . ' ago — site may be offline</span>';
                            }
                        } else {
                            echo '<span style="color:#d63638;">&#9888; No heartbeat received yet</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;max-width:600px;">
            <h2 style="margin-top:0;">Stripe Webhook</h2>
            <p style="margin-bottom:12px;color:#555;">
                Register this URL in your Stripe Dashboard under
                <strong>Developers &rarr; Webhooks &rarr; Add endpoint</strong>.
                Listen for: <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, <code>charge.refunded</code>.
            </p>
            <table class="form-table">
                <tr>
                    <th>Webhook URL</th>
                    <td>
                        <code id="osc-webhook-url" style="background:#f0f0f1;padding:6px 10px;display:inline-block;border-radius:4px;font-size:13px;user-select:all;">
                            <?php echo esc_html(get_site_url() . '/?os_stripe_webhook_event=1'); ?>
                        </code>
                        <button type="button" class="button button-secondary" style="margin-left:8px;vertical-align:middle;"
                            onclick="navigator.clipboard.writeText(document.getElementById('osc-webhook-url').textContent.trim()).then(function(){this.textContent='Copied!';setTimeout(function(){document.querySelector('[onclick*=osc-webhook-url]').textContent='Copy';},2000)}.bind(this))">
                            Copy
                        </button>
                    </td>
                </tr>
                <tr>
                    <th>Signing Secret</th>
                    <td>
                        <?php $has_secret = !empty(osc_get_option('stripe_webhook_secret', '')); ?>
                        <?php if ($has_secret): ?>
                            <span style="color:green;font-weight:600;">&#10003; Configured</span>
                            <p class="description" style="margin-top:4px;">
                                Synced automatically from Gateway Panel. To change it, update the
                                <strong>Stripe Webhook Signing Secret</strong> in your
                                <a href="<?php echo esc_url($gateway_url); ?>" target="_blank">Gateway Panel &rarr; Shield Sites &rarr; Settings</a>.
                            </p>
                        <?php else: ?>
                            <span style="color:#d63638;font-weight:600;">&#9888; Not configured</span>
                            <p class="description" style="margin-top:4px;">
                                Set the <strong>Stripe Webhook Signing Secret</strong> (<code>whsec_...</code>) in your
                                <a href="<?php echo esc_url($gateway_url); ?>" target="_blank">Gateway Panel &rarr; Shield Sites &rarr; Settings</a>.
                                It will sync here automatically on the next heartbeat (within 5 minutes).
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
