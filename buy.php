<?php
/**
 * Start a credit purchase: POST a pack key, get sent to Paystack's checkout.
 * The price comes from inc/catalog.php — nothing about the amount is taken from
 * the request.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/paystack.php';

$user = require_auth();

if (!sleek_is_post()) sleek_redirect('credits-billing');
csrf_verify();

$packKey = is_string($_POST['pack'] ?? null) ? $_POST['pack'] : '';
if (sleek_pack($packKey) === null) {
    sleek_flash('error', 'That credit pack doesn\'t exist. Pick one below.');
    sleek_redirect('credits-billing');
}

if (!sleek_paystack_configured()) {
    sleek_payments_log('BUY blocked: Paystack secret_key is empty in inc/config.php');
    sleek_flash('error', 'Payments are temporarily unavailable. Please try again shortly.');
    sleek_redirect('credits-billing');
}

try {
    $checkout = sleek_start_payment($user, $packKey);
} catch (Throwable $e) {
    sleek_payments_log('BUY failed for user #' . $user['id'] . ': ' . $e->getMessage());
    sleek_flash('error', 'We couldn\'t reach the payment provider. Nothing was charged — please try again.');
    sleek_redirect('credits-billing');
}

// Off-site, to Paystack's hosted checkout.
header('Location: ' . $checkout, true, 303);
exit;
