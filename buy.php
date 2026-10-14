<?php
/**
 * Start a credit purchase: POST a pack key and a payment method, get sent to that
 * provider's hosted checkout. The price comes from inc/catalog.php — nothing about
 * the amount is taken from the request.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payments.php';

$user = require_auth();

if (!sleek_is_post()) sleek_redirect('credits-billing');
csrf_verify();

$packKey = is_string($_POST['pack'] ?? null) ? $_POST['pack'] : '';
if (sleek_pack($packKey) === null) {
    sleek_flash('error', 'That credit pack doesn\'t exist. Pick one below.');
    sleek_redirect('credits-billing');
}

$method = is_string($_POST['method'] ?? null) ? $_POST['method'] : 'flutterwave';
if (!isset(sleek_payment_methods()[$method])) {
    sleek_flash('error', 'Pick a payment method below.');
    sleek_redirect('credits-billing');
}

if (!sleek_payment_configured($method)) {
    sleek_payments_log("BUY blocked: {$method} keys are not configured");
    sleek_flash('error', 'That payment method is temporarily unavailable. Please try another, or again shortly.');
    sleek_redirect('credits-billing');
}

try {
    $checkout = sleek_start_payment($user, $packKey, $method);
} catch (Throwable $e) {
    sleek_payments_log("BUY failed ({$method}) for user #" . $user['id'] . ': ' . $e->getMessage());
    sleek_flash('error', 'We couldn\'t reach the payment provider. Nothing was charged — please try again.');
    sleek_redirect('credits-billing');
}

// Off-site, to the provider's hosted checkout.
header('Location: ' . $checkout, true, 303);
exit;
