<?php
/**
 * Admin settings page for OneShield Paygates (money site).
 *
 * Provides:
 * - Blacklist sync status (last sync, entry counts, freshness)
 * - Force Sync button (calls osp_sync_blacklist via AJAX)
 * - Current blacklist config display (action, trap shield ID)
 */

defined('ABSPATH') || exit;

add_action('admin_menu', 'osp_add_admin_menu');
function osp_add_admin_menu(): void {
    add_options_page(
        'OneShield Paygates',
        'OneShield Paygates',
        'manage_options',
        'oneshield-paygates',
        'osp_settings_page'
    );
}

function osp_settings_page(): void {
    // Load current blacklist state
    $stored   = get_option('osp_blacklist_data', null);
    $pushed_at = 0;
    $counts    = ['emails' => 0, 'cities' => 0, 'states' => 0, 'zipcodes' => 0];
    $is_fresh  = false;

    if (is_array($stored)) {
        $pushed_at = (int) ($stored['_pushed_at'] ?? 0);
        $is_fresh  = $pushed_at > 0 && (time() - $pushed_at) < DAY_IN_SECONDS;
        foreach (['emails', 'cities', 'states', 'zipcodes'] as $k) {
            $counts[$k] = is_array($stored[$k] ?? null) ? count($stored[$k]) : 0;
        }
    }

    $action  = get_option('osp_blacklist_action', 'hide');
    $trap_id = get_option('osp_trap_shield_id', null);

    // Gateway config status
    $settings    = get_option('woocommerce_oneshield_stripe_settings', []);
    if (!is_array($settings)) $settings = [];
    $gateway_url = rtrim($settings['gateway_url'] ?? '', '/');
    $site_id     = (int) ($settings['site_id'] ?? 0);
    $configured  = !empty($gateway_url) && !empty($settings['token_secret']) && $site_id > 0;
    ?>
    <div class="wrap">
        <h1>OneShield Paygates</h1>

        <!-- Gateway connection status -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;max-width:640px;">
            <h2 style="margin-top:0;">Gateway Connection</h2>
            <?php if ($configured): ?>
                <p>
                    <span style="color:green;font-weight:bold;">&#10003; Connected</span>
                    to <strong><?php echo esc_html($gateway_url); ?></strong>
                    &nbsp;(Site ID: <code><?php echo esc_html((string) $site_id); ?></code>)
                </p>
            <?php else: ?>
                <p>
                    <span style="color:#d63638;font-weight:bold;">&#10007; Not configured</span> &mdash;
                    configure Gateway URL, Token Secret, and Site ID in
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=oneshield_stripe')); ?>">
                        WooCommerce &rarr; Settings &rarr; Payments &rarr; OneShield Stripe
                    </a>.
                </p>
            <?php endif; ?>
        </div>

        <!-- Blacklist status -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;max-width:640px;">
            <h2 style="margin-top:0;">Blacklist Status</h2>

            <?php if ($pushed_at > 0): ?>
                <p>
                    <?php if ($is_fresh): ?>
                        <span style="color:green;">&#10003; Fresh</span>
                    <?php else: ?>
                        <span style="color:#d63638;">&#9888; Stale (&gt;24h)</span>
                    <?php endif; ?>
                    &mdash; Last synced: <strong><?php echo esc_html(human_time_diff($pushed_at)); ?> ago</strong>
                    <span style="color:#888;font-size:12px;">(<?php echo esc_html(date_i18n('Y-m-d H:i:s', $pushed_at)); ?>)</span>
                </p>
                <table class="widefat" style="max-width:380px;">
                    <thead>
                        <tr><th>Type</th><th style="text-align:right;">Entries</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($counts as $type => $n): ?>
                        <tr>
                            <td><?php echo esc_html(ucfirst($type)); ?></td>
                            <td style="text-align:right;font-weight:<?php echo $n > 0 ? '600' : 'normal'; ?>;color:<?php echo $n > 0 ? '#d63638' : '#555'; ?>;">
                                <?php echo esc_html((string) $n); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><span style="color:#888;">&#9888; No blacklist data — run a sync to populate.</span></p>
            <?php endif; ?>

            <hr style="margin:16px 0;" />

            <p style="color:#555;margin-bottom:10px;">
                Blacklist is pushed automatically via heartbeat (every 5 min via WP cron).<br>
                Use this button to force an immediate refresh.
            </p>

            <?php if ($configured): ?>
                <button type="button" id="osp-sync-blacklist" class="button button-primary">
                    Sync Blacklist Now
                </button>
                <span id="osp-sync-status" style="margin-left:12px;font-weight:600;"></span>
            <?php else: ?>
                <button type="button" class="button button-primary" disabled>Sync Blacklist Now</button>
                <span style="margin-left:10px;color:#888;">Configure gateway connection first.</span>
            <?php endif; ?>
        </div>

        <!-- Blacklist config -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;max-width:640px;">
            <h2 style="margin-top:0;">Blacklist Configuration</h2>
            <p style="color:#555;margin-bottom:12px;">
                These settings are pushed automatically from your Gateway Panel and cannot be edited here.
                Change them in <strong>Gateway Panel &rarr; Shield Sites &rarr; Settings</strong>.
            </p>
            <table class="form-table">
                <tr>
                    <th>Action on blacklisted buyer</th>
                    <td>
                        <?php if ($action === 'trap'): ?>
                            <span style="background:#fef3cd;color:#856404;padding:2px 8px;border-radius:3px;font-weight:600;">TRAP</span>
                            — Route to trap shield site
                        <?php else: ?>
                            <span style="background:#f8d7da;color:#842029;padding:2px 8px;border-radius:3px;font-weight:600;">HIDE</span>
                            — Hide all OneShield payment gateways
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($action === 'trap'): ?>
                <tr>
                    <th>Trap Shield ID</th>
                    <td>
                        <?php if ($trap_id): ?>
                            <code><?php echo esc_html((string) $trap_id); ?></code>
                        <?php else: ?>
                            <span style="color:#d63638;">Not set — configure in Gateway Panel</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

    </div>
    <script>
    (function () {
        var btn = document.getElementById('osp-sync-blacklist');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var status = document.getElementById('osp-sync-status');
            btn.disabled = true;
            status.textContent = 'Syncing\u2026';
            status.style.color = '#555';

            var fd = new FormData();
            fd.append('action', 'osp_sync_blacklist');
            fd.append('nonce', '<?php echo esc_js(wp_create_nonce('osp_sync_blacklist')); ?>');

            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (r.success) {
                        status.textContent = r.data.message || 'Synced!';
                        status.style.color = '#00a32a';
                        // Reload page after 1.2s to reflect updated counts
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        status.textContent = (r.data && r.data.message) ? r.data.message : 'Sync failed.';
                        status.style.color = '#d63638';
                        btn.disabled = false;
                    }
                })
                .catch(function () {
                    status.textContent = 'Network error.';
                    status.style.color = '#d63638';
                    btn.disabled = false;
                });
        });
    })();
    </script>
    <?php
}
