<?php
/**
 * SleekAI — Flutterwave (naira: card, bank transfer, USSD). Flutterwave Standard v3:
 * a hosted checkout link, verified afterwards by our own reference.
 *
 *   checkout   POST /v3/payments                                → data.link
 *   verify     GET  /v3/transactions/verify_by_reference?tx_ref= → data.status/amount/currency
 *   webhook    POST {app_url}/flutterwave-webhook, header verif-hash = the secret
 *              hash set in the Flutterwave dashboard (plain compare, not an HMAC)
 *
 * Whatever the redirect or webhook says, the verify call decides — both are only
 * a prompt to go and ask.
 */

declare(strict_types=1);

function sleek_flutterwave_configured(): bool
{
    return sleek_config('flutterwave')['secret_key'] !== '';
}

/** Call the Flutterwave API. Returns the decoded `data`, or throws. */
function sleek_flutterwave_request(string $method, string $path, ?array $body = null): array
{
    $cfg = sleek_config('flutterwave');
    if ($cfg['secret_key'] === '') throw new SleekPaymentError('Flutterwave secret key is not set.');

    $res  = sleek_payment_http($method, rtrim($cfg['api_base'], '/') . $path, ['Authorization: Bearer ' . $cfg['secret_key']], $body);
    $json = $res['json'];
    if (!is_array($json) || ($json['status'] ?? '') !== 'success' || !is_array($json['data'] ?? null)) {
        $msg = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'unexpected response';
        throw new SleekPaymentError("Flutterwave {$method} {$path} failed (HTTP {$res['status']}): {$msg}");
    }
    return $json['data'];
}

/** Ask Flutterwave for a hosted checkout link for a pending payment. */
function sleek_flutterwave_checkout(array $user, array $payment): string
{
    $cfg  = sleek_config('flutterwave');
    $data = sleek_flutterwave_request('POST', '/v3/payments', [
        'tx_ref'          => $payment['reference'],
        'amount'          => $payment['amount_kobo'] / 100,
        'currency'        => $payment['currency'],
        'redirect_url'    => sleek_url('flutterwave-callback'),
        'payment_options' => $cfg['payment_options'],
        'customer'        => ['email' => $user['email'], 'name' => $user['name']],
        'customizations'  => [
            'title'       => (string) sleek_config('brand_name'),
            'description' => $payment['pack']['name'] . ' pack — ' . number_format($payment['pack']['credits']) . ' credits',
        ],
        'meta'            => ['user_id' => (int) $user['id'], 'credits' => $payment['pack']['credits']],
    ]);
    return (string) ($data['link'] ?? '');
}

/**
 * What Flutterwave says happened to one of our references, normalised for
 * sleek_fulfil_payment(). Throws if Flutterwave can't be asked.
 */
function sleek_flutterwave_verify(string $reference): array
{
    $tx = sleek_flutterwave_request('GET', '/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference));

    $status = match ((string) ($tx['status'] ?? '')) {
        'successful' => 'success',
        'failed'     => 'failed',
        default      => 'pending',   // e.g. a bank transfer that hasn't landed yet
    };
    return [
        'reference'   => (string) ($tx['tx_ref'] ?? ''),
        'status'      => $status,
        'amount_kobo' => sleek_to_kobo($tx['amount'] ?? -1),
        'currency'    => (string) ($tx['currency'] ?? ''),
        'gateway'     => 'Flutterwave #' . ($tx['id'] ?? '?') . ': ' . ($tx['processor_response'] ?? $tx['status'] ?? ''),
    ];
}

/** Webhook authenticity: the verif-hash header must equal our secret hash. */
function sleek_flutterwave_webhook_valid(string $header): bool
{
    $hash = sleek_config('flutterwave')['webhook_hash'];
    return $hash !== '' && $header !== '' && hash_equals($hash, $header);
}
