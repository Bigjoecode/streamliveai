<?php
/**
 * Flutterwave's server-to-server notification. In the Flutterwave dashboard
 * (Settings → Webhooks) set the URL to {app_url}/flutterwave-webhook and a secret
 * hash; put the same hash in config as flutterwave.webhook_hash.
 *
 * This is the reliable path — it fires even if the buyer closes the tab before the
 * redirect back. Authenticated by the verif-hash header, not CSRF: no browser is
 * involved. The payload itself is never trusted; it only names a reference to verify.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payments.php';

if (!sleek_is_post()) {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');

if (!sleek_flutterwave_webhook_valid((string) ($_SERVER['HTTP_VERIF_HASH'] ?? ''))) {
    sleek_payments_log('FLW WEBHOOK rejected: bad or missing verif-hash from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);
$reference = is_array($event) ? (string) ($event['data']['tx_ref'] ?? '') : '';

// Acknowledge everything we don't act on, or Flutterwave keeps retrying it.
if (($event['event'] ?? '') !== 'charge.completed' || !sleek_is_payment_reference($reference)) {
    http_response_code(200);
    exit;
}

try {
    $result = sleek_fulfil_payment($reference, sleek_flutterwave_verify($reference));
    sleek_payments_log("FLW WEBHOOK {$reference}: {$result}");
    http_response_code(200);
} catch (Throwable $e) {
    // A 5xx makes Flutterwave retry later — right for a transient failure.
    sleek_payments_log("FLW WEBHOOK {$reference} error: " . $e->getMessage());
    http_response_code(500);
}
