<?php
/**
 * NOWPayments' IPN — the server-to-server status updates for crypto payments.
 * The invoice sets this URL itself; in the NOWPayments dashboard (Settings →
 * Payments → Instant payment notifications) generate the IPN secret and put it in
 * config as nowpayments.ipn_secret.
 *
 * Fires on every status change (waiting, confirming, … finished). Each one is
 * signature-checked, then the payment's status is re-read from the API.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payments.php';

if (!sleek_is_post()) {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '');

if (!sleek_nowpayments_signature_valid($raw, $sig)) {
    $peek = json_decode($raw, true);
    sleek_payments_log('NP IPN rejected: bad or missing signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
        . (is_array($peek) ? ' (order ' . preg_replace('/[^A-Za-z0-9-]/', '', (string) ($peek['order_id'] ?? '?'))
            . ', payment ' . preg_replace('/\D/', '', (string) ($peek['payment_id'] ?? '')) . ', status '
            . preg_replace('/[^a-z_]/', '', (string) ($peek['payment_status'] ?? '?')) . ')' : ''));
    require_once __DIR__ . '/inc/telegram.php';
    sleek_alert('payment_bad', 'Rejected crypto (NOWPayments) notification', [
        'Why'   => 'Bad or missing signature — a forgery attempt, or the IPN secret in Admin → Providers doesn\'t match NOWPayments',
        'Order' => is_array($peek) ? (string) ($peek['order_id'] ?? '') : '',
        'From'  => $_SERVER['REMOTE_ADDR'] ?? '?',
    ], 'admin-providers');
    http_response_code(401);
    exit;
}

$ipn       = json_decode($raw, true);
$paymentId = (string) ($ipn['payment_id'] ?? '');
$reference = (string) ($ipn['order_id'] ?? '');

if (!sleek_is_payment_reference($reference) || $paymentId === '') {
    http_response_code(200);   // not one of ours — acknowledge so it stops retrying
    exit;
}

try {
    $tx = sleek_nowpayments_verify($paymentId);
    $result = $tx['reference'] === $reference ? sleek_fulfil_payment($reference, $tx) : 'mismatch';
    sleek_payments_log("NP IPN {$reference} ({$paymentId}): {$result}");
    http_response_code(200);
} catch (Throwable $e) {
    sleek_payments_log("NP IPN {$reference} error: " . $e->getMessage());
    http_response_code(500);
}
