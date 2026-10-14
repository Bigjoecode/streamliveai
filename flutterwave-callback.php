<?php
/**
 * Where Flutterwave sends the buyer after checkout (?status=…&tx_ref=…&transaction_id=…).
 * The query string only says which payment to look at — whether it succeeded, and
 * for how much, is asked of Flutterwave's verify API directly.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/payments.php';

$reference = is_string($_GET['tx_ref'] ?? null) ? $_GET['tx_ref'] : '';

if (!sleek_is_payment_reference($reference)) {
    sleek_flash('error', 'That payment link isn\'t valid.');
    sleek_redirect(current_user() ? 'credits-billing' : 'login');
}

try {
    $result = sleek_fulfil_payment($reference, sleek_flutterwave_verify($reference));
} catch (Throwable $e) {
    sleek_payments_log("FLW CALLBACK verify failed for {$reference}: " . $e->getMessage());
    // A buyer who closed the checkout leaves no transaction to verify. Anything
    // else may still be completed by the webhook — don't tell them it failed.
    if (($_GET['status'] ?? '') === 'cancelled') {
        sleek_payment_note($reference, 'Cancelled at Flutterwave checkout', 'failed');
        $result = 'failed';
    } else {
        $result = 'pending';
    }
}

sleek_payment_finish_redirect($reference, $result);
