<?php
/**
 * Stripe Webhook Handler for Shield Site.
 *
 * Endpoint: https://[shield-site]/?os_stripe_webhook_event=1
 *
 * Register this URL in Stripe Dashboard → Developers → Webhooks → Add endpoint.
 * Listen for: payment_intent.succeeded, payment_intent.payment_failed, charge.refunded
 *
 * Flow:
 *  1. Receive raw POST from Stripe
 *  2. Verify Stripe-Signature using the whsec_ stored in plugin settings
 *  3. Parse event type + extract PI ID / order metadata
 *  4. Forward the normalised event to Gateway Panel (/api/webhook/from-shield)
 *     so Panel can update Transaction record and push WC order status to money site
 */

defined('ABSPATH') || exit;

/**
 * Entry point — hooked on init at priority 5 (before normal init handlers).
 * Terminates with http_response_code + exit so WordPress never sends HTML.
 */
function osc_handle_stripe_webhook_request(): void {
    if (!isset($_GET['os_stripe_webhook_event'])) {
        return;
    }

    // Read raw body BEFORE WordPress or any plugin touches php://input.
    $raw_body   = file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // ── 1. Basic sanity checks ────────────────────────────────────────────────
    if (empty($raw_body)) {
        http_response_code(400);
        echo json_encode(['error' => 'empty_body']);
        exit;
    }

    $payload = json_decode($raw_body, true);
    if (!is_array($payload) || empty($payload['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    // ── 2. Verify Stripe-Signature ────────────────────────────────────────────
    $webhook_secret = osc_get_option('stripe_webhook_secret', '');

    if (!empty($webhook_secret)) {
        if (empty($sig_header)) {
            http_response_code(400);
            echo json_encode(['error' => 'missing_signature']);
            exit;
        }
        if (!osc_verify_stripe_signature($raw_body, $sig_header, $webhook_secret)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_signature']);
            exit;
        }
    }
    // If no webhook_secret configured: accept without signature (allows initial setup/testing).
    // Strongly recommended to set whsec_ in plugin settings for production.

    // ── 3. Parse event ────────────────────────────────────────────────────────
    $event_type = $payload['type'];
    $object     = $payload['data']['object'] ?? [];

    $handled_types = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'charge.refunded',
        'charge.succeeded',
        'charge.failed',
    ];

    if (!in_array($event_type, $handled_types, true)) {
        // Acknowledge but don't process unknown events
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'type' => $event_type]);
        exit;
    }

    // Extract key fields
    $pi_id    = '';
    $order_id = '';
    $amount   = 0;
    $currency = '';
    $status   = 'unknown';

    if (str_starts_with($event_type, 'payment_intent.')) {
        $pi_id    = $object['id']                         ?? '';
        $order_id = $object['metadata']['order_id']       ?? ($object['metadata']['wc_order_id'] ?? '');
        $amount   = (int) ($object['amount']              ?? 0);
        $currency = strtoupper($object['currency']        ?? '');

        $status = match ($event_type) {
            'payment_intent.succeeded'       => 'completed',
            'payment_intent.payment_failed'  => 'failed',
            default                          => 'unknown',
        };
    } elseif (str_starts_with($event_type, 'charge.')) {
        // Charges may have payment_intent reference
        $pi_id    = $object['payment_intent']             ?? $object['id'] ?? '';
        $order_id = $object['metadata']['order_id']       ?? ($object['metadata']['wc_order_id'] ?? '');
        $amount   = (int) ($object['amount_refunded']     ?? $object['amount'] ?? 0);
        $currency = strtoupper($object['currency']        ?? '');

        $status = match ($event_type) {
            'charge.succeeded' => 'completed',
            'charge.failed'    => 'failed',
            'charge.refunded'  => 'refunded',
            default            => 'unknown',
        };
    }

    // ── 4. Forward to Gateway Panel ───────────────────────────────────────────
    if (osc_is_connected()) {
        osc_forward_webhook_to_panel([
            'event_type' => $event_type,
            'pi_id'      => $pi_id,
            'order_id'   => $order_id,
            'amount'     => $amount,
            'currency'   => $currency,
            'status'     => $status,
            'gateway'    => 'stripe',
            'raw'        => $payload,  // full Stripe event for Panel's own processing
        ]);
    }

    // ── 5. Acknowledge to Stripe ──────────────────────────────────────────────
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'received', 'type' => $event_type]);
    exit;
}

/**
 * Verify Stripe-Signature header.
 *
 * Stripe signs with HMAC-SHA256 over "{timestamp}.{raw_body}".
 * Header format: t=1234,v1=abc123,v1=def456,...
 *
 * @see https://stripe.com/docs/webhooks/signatures
 */
function osc_verify_stripe_signature(string $raw_body, string $sig_header, string $secret): bool {
    $parts = [];
    foreach (explode(',', $sig_header) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }

    $timestamp  = (int) ($parts['t'][0] ?? 0);
    $signatures = $parts['v1'] ?? [];

    if (!$timestamp || empty($signatures)) {
        return false;
    }

    // Reject replays older than 5 minutes
    if (abs(time() - $timestamp) > 300) {
        return false;
    }

    $signed_payload = $timestamp . '.' . $raw_body;
    $expected       = hash_hmac('sha256', $signed_payload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

/**
 * Forward a normalised webhook event to the Gateway Panel.
 *
 * Panel will:
 *  - Update the Transaction record status
 *  - Push the new status to the money site (?wc-api=oneshield_webhook)
 *    so WC order status stays in sync even if browser disconnected
 *
 * This is a best-effort fire-and-forget call — if Panel is unreachable,
 * Stripe will retry the original webhook to the shield site.
 */
function osc_forward_webhook_to_panel(array $event): void {
    $payload = array_merge($event, [
        'shield_site_id' => osc_site_id(),
    ]);

    $url = osc_gateway_url() . '/api/webhook/from-shield';

    $response = wp_remote_post($url, [
        'timeout'     => 10,
        'headers'     => osc_build_headers($payload),
        'body'        => json_encode($payload),
        'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
        osc_log('osc_forward_webhook_to_panel error: ' . $response->get_error_message());
    }
}
