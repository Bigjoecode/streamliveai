<?php
/**
 * Where Paystack sends the buyer after checkout. The query string only says which
 * transaction to look at — whether it succeeded, and for how much, is asked of
 * Paystack's verify API directly. Nothing in the URL is trusted.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/paystack.php';

$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';

if (!preg_match('/^SLK-[A-F0-9]{20}$/', $reference)) {
    sleek_flash('error', 'That payment link isn\'t valid.');
    sleek_redirect(current_user() ? 'credits-billing' : 'login');
}

try {
    $result = sleek_fulfil_payment($reference, sleek_paystack_verify($reference));
} catch (Throwable $e) {
    sleek_payments_log("CALLBACK verify failed for {$reference}: " . $e->getMessage());
    // The webhook may still complete it; don't tell the buyer it failed.
    $result = 'pending';
}

$stmt = sleek_db()->prepare('SELECT credits, amount_kobo FROM payments WHERE reference = ?');
$stmt->execute([$reference]);
$p = $stmt->fetch();

match ($result) {
    'credited', 'already' => sleek_flash('success', ($p ? number_format($p['credits']) : 'Your') . ' credits added. Thanks for your purchase.'),
    'failed'              => sleek_flash('error', 'That payment didn\'t go through, so nothing was charged. You can try again below.'),
    'mismatch'            => sleek_flash('error', 'We couldn\'t confirm that payment. If you were charged, contact support with reference ' . $reference . '.'),
    'unknown'             => sleek_flash('error', 'We couldn\'t find that payment.'),
    default               => sleek_flash('info', 'We\'re still confirming your payment. Your credits will appear here within a few minutes.'),
};

// The session may have expired while the buyer was at checkout. The payment is
// already applied to its owner either way; they'll see it after logging in.
sleek_redirect(current_user() ? 'credits-billing' : 'login');
