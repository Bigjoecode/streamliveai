<?php
/**
 * SleekAI — NOWPayments (crypto). The pack is priced in naira; NOWPayments converts
 * that to whichever coin the buyer picks on its hosted invoice page.
 *
 *   checkout   POST /v1/invoice              x-api-key   → invoice_url
 *   status     GET  /v1/payment/{payment_id} x-api-key   → payment_status, order_id, price_amount
 *   webhook    POST {app_url}/crypto-webhook ("IPN"), header x-nowpayments-sig =
 *              HMAC-SHA512 of the body re-encoded with keys sorted, keyed with the
 *              IPN secret from the NOWPayments dashboard
 *
 * As with Flutterwave, the IPN only prompts a status call — the API decides.
 * Crypto settles in minutes, not seconds: statuses move waiting → confirming →
 * confirmed → sending → finished. Only `finished` credits.
 */

declare(strict_types=1);

function sleek_nowpayments_configured(): bool
{
    $cfg = sleek_config('nowpayments');
    // The IPN secret is required too: without it no payment could ever be confirmed.
    return $cfg['api_key'] !== '' && $cfg['ipn_secret'] !== '';
}

/** Call the NOWPayments API. Returns the decoded body, or throws. */
function sleek_nowpayments_request(string $method, string $path, ?array $body = null): array
{
    $cfg = sleek_config('nowpayments');
    if ($cfg['api_key'] === '') throw new SleekPaymentError('NOWPayments API key is not set.');

    $res = sleek_payment_http($method, rtrim($cfg['api_base'], '/') . $path, ['x-api-key: ' . $cfg['api_key']], $body);
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        $msg = is_array($res['json']) && isset($res['json']['message']) ? (string) $res['json']['message'] : 'unexpected response';
        throw new SleekPaymentError("NOWPayments {$method} {$path} failed (HTTP {$res['status']}): {$msg}");
    }
    return $res['json'];
}

/** Create a hosted crypto invoice for a pending payment. */
function sleek_nowpayments_checkout(array $user, array $payment): string
{
    $cfg  = sleek_config('nowpayments');
    $data = sleek_nowpayments_request('POST', '/v1/invoice', [
        'price_amount'      => $payment['amount_kobo'] / 100,
        'price_currency'    => strtolower($payment['currency']),
        'order_id'          => $payment['reference'],
        'order_description' => $payment['pack']['name'] . ' pack — ' . number_format($payment['pack']['credits']) . ' credits',
        'ipn_callback_url'  => sleek_url('crypto-webhook'),
        'success_url'       => sleek_url('crypto-callback?reference=' . $payment['reference']),
        'cancel_url'        => sleek_url('crypto-callback?reference=' . $payment['reference'] . '&cancelled=1'),
        'is_fixed_rate'     => true,
        'is_fee_paid_by_user' => $cfg['fee_paid_by_user'],
    ]);
    sleek_payment_note($payment['reference'], 'NOWPayments invoice ' . ($data['id'] ?? '?'));
    return (string) ($data['invoice_url'] ?? '');
}

/**
 * What NOWPayments says about one payment, normalised for sleek_fulfil_payment().
 * The reference is NOWPayments' order_id — the buyer can't change it.
 */
function sleek_nowpayments_verify(string $paymentId): array
{
    if (!preg_match('/^\d{1,20}$/', $paymentId)) throw new SleekPaymentError('Bad NOWPayments payment id.');
    $p = sleek_nowpayments_request('GET', '/v1/payment/' . $paymentId);

    $np = (string) ($p['payment_status'] ?? '');
    $status = match ($np) {
        'finished'                      => 'success',
        'failed', 'expired', 'refunded' => 'failed',
        default                         => 'pending',  // waiting, confirming, confirmed, sending, partially_paid
    };
    if ($np === 'partially_paid') {
        sleek_payments_log('PARTIAL crypto payment ' . $paymentId . ' for ' . ($p['order_id'] ?? '?')
            . ': paid ' . ($p['actually_paid'] ?? '?') . ' of ' . ($p['pay_amount'] ?? '?') . ' ' . ($p['pay_currency'] ?? ''));
    }
    return [
        'reference'   => (string) ($p['order_id'] ?? ''),
        'status'      => $status,
        // The invoice price, in the currency we priced it in — what was agreed.
        // 'finished' is NOWPayments' word that the full crypto amount for it arrived.
        'amount_kobo' => sleek_to_kobo($p['price_amount'] ?? -1),
        'currency'    => strtoupper((string) ($p['price_currency'] ?? '')),
        'gateway'     => 'NOWPayments #' . $paymentId . ': ' . $np . ' (' . ($p['pay_currency'] ?? '?') . ')',
    ];
}

/** Recursively sort keys, as NOWPayments does before signing. */
function sleek_nowpayments_ksort(array &$a): void
{
    ksort($a);
    foreach ($a as &$v) if (is_array($v)) sleek_nowpayments_ksort($v);
}

/** IPN authenticity: HMAC-SHA512 over the key-sorted body, keyed with the IPN secret. */
function sleek_nowpayments_signature_valid(string $rawBody, string $signature): bool
{
    $secret = trim(sleek_config('nowpayments')['ipn_secret']);
    if ($secret === '' || $signature === '') return false;

    $data = json_decode($rawBody, true);
    if (!is_array($data)) return false;
    sleek_nowpayments_ksort($data);

    $sorted = json_encode($data, JSON_UNESCAPED_SLASHES);
    return is_string($sorted) && hash_equals(hash_hmac('sha512', $sorted, $secret), strtolower($signature));
}
