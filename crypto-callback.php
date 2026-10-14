<?php
/**
 * Where NOWPayments sends the buyer back from the crypto invoice. NOWPayments adds
 * the payment id as ?NP_id=…; if it's there, ask its API for the status straight
 * away. Crypto usually still needs network confirmations at this point, so the
 * common outcome is "still confirming" — the IPN webhook credits when it settles.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payments.php';

$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';

if (!sleek_is_payment_reference($reference)) {
    sleek_flash('error', 'That payment link isn\'t valid.');
    sleek_redirect(current_user() ? 'credits-billing' : 'login');
}

if (isset($_GET['cancelled'])) {
    // Leave it pending rather than failed: coins already sent still arrive and
    // the IPN will credit them. The billing page hides stale pending rows.
    sleek_flash('info', 'Crypto checkout closed. If you already sent the payment, your credits will appear once it confirms.');
    sleek_redirect(current_user() ? 'credits-billing' : 'login');
}

$result = 'pending';
$npId = is_string($_GET['NP_id'] ?? null) ? $_GET['NP_id'] : '';
if ($npId !== '') {
    try {
        $tx = sleek_nowpayments_verify($npId);
        // Only act on the payment this link is for — NP_id is just a query param.
        if ($tx['reference'] === $reference) $result = sleek_fulfil_payment($reference, $tx);
    } catch (Throwable $e) {
        sleek_payments_log("NP CALLBACK status failed for {$reference}: " . $e->getMessage());
    }
}

sleek_payment_finish_redirect($reference, $result);
