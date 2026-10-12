<?php
/**
 * Paystack's server-to-server notification. Set this URL in the Paystack dashboard
 * (Settings → API Keys & Webhooks → Webhook URL): {app_url}/paystack-webhook
 *
 * This is the reliable path — it fires even if the buyer closes the tab before the
 * redirect back. Authenticated by HMAC signature, not CSRF: no browser is involved.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/paystack.php';

if (!sleek_is_post()) {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');

if (!sleek_paystack_signature_valid($raw, $sig)) {
    sleek_payments_log('WEBHOOK rejected: bad or missing signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);

// Acknowledge everything we don't act on, or Paystack keeps retrying it.
if (!is_array($event) || ($event['event'] ?? '') !== 'charge.success' || !is_array($event['data'] ?? null)) {
    http_response_code(200);
    exit;
}

$reference = (string) ($event['data']['reference'] ?? '');

try {
    $result = sleek_fulfil_payment($reference, $event['data']);
    sleek_payments_log("WEBHOOK {$reference}: {$result}");
    http_response_code(200);
} catch (Throwable $e) {
    // A 5xx makes Paystack retry later — right for a transient failure like a DB blip.
    sleek_payments_log("WEBHOOK {$reference} error: " . $e->getMessage());
    http_response_code(500);
}
