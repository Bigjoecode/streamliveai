<?php
/**
 * SleekAI — NOWPayments (crypto). The pack is priced in USD; NOWPayments converts
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

/**
 * IPN authenticity: HMAC-SHA512, keyed with the IPN secret, over the body with its
 * object keys sorted — NOWPayments computes it in JavaScript as
 * JSON.stringify(sortKeys(body)). PHP's json_encode doesn't produce the same bytes:
 * it escapes non-ASCII ("—" → "—", and our order descriptions contain "—")
 * and writes small numbers differently (0.00002 → 2.0e-5). So the body is re-sorted
 * with every number kept exactly as received and strings written the way JS does.
 * The PHP-style form from NOWPayments' own PHP sample is accepted too; both need the
 * secret, so allowing either costs nothing.
 */
function sleek_nowpayments_signature_valid(string $rawBody, string $signature): bool
{
    $secret = trim(sleek_config('nowpayments')['ipn_secret']);
    $signature = strtolower(trim($signature));
    if ($secret === '' || $signature === '') return false;

    $candidates = [];
    $js = sleek_nowpayments_canonical($rawBody);
    if ($js !== null) $candidates[] = $js;

    $data = json_decode($rawBody, true);
    if (is_array($data)) {
        sleek_nowpayments_ksort($data);
        $php = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (is_string($php)) $candidates[] = $php;
    }

    foreach (array_unique($candidates) as $c) {
        if (hash_equals(hash_hmac('sha512', $c, $secret), $signature)) return true;
    }
    return false;
}

/** JSON.stringify(sortKeys(JSON.parse($raw))), done in PHP. Null if $raw isn't a JSON object. */
function sleek_nowpayments_canonical(string $raw): ?string
{
    $i = 0;
    try {
        $out = sleek_np_json_value($raw, $i);
        while ($i < strlen($raw) && strpos(" \t\r\n", $raw[$i]) !== false) $i++;
        return ($i === strlen($raw) && str_starts_with($out, '{')) ? $out : null;
    } catch (UnexpectedValueException) {
        return null;
    }
}

/** Parse one JSON value at $i and return it re-encoded JS-style, with object keys sorted. */
function sleek_np_json_value(string $s, int &$i, int $depth = 0): string
{
    $n = strlen($s);
    while ($i < $n && strpos(" \t\r\n", $s[$i]) !== false) $i++;
    if ($i >= $n || $depth > 32) throw new UnexpectedValueException('bad json');
    $c = $s[$i];

    if ($c === '{' || $c === '[') {
        $isObj = $c === '{';
        $i++;
        $items = [];
        while (true) {
            while ($i < $n && strpos(" \t\r\n", $s[$i]) !== false) $i++;
            if ($i < $n && $s[$i] === ($isObj ? '}' : ']') && !$items) { $i++; break; }
            if ($isObj) {
                if (($s[$i] ?? '') !== '"') throw new UnexpectedValueException('bad key');
                $key = json_decode(sleek_np_json_string_token($s, $i));
                while ($i < $n && strpos(" \t\r\n", $s[$i]) !== false) $i++;
                if (($s[$i] ?? '') !== ':') throw new UnexpectedValueException('bad colon');
                $i++;
                $items[(string) $key] = sleek_np_json_value($s, $i, $depth + 1);   // a repeated key: last wins, like JS
            } else {
                $items[] = sleek_np_json_value($s, $i, $depth + 1);
            }
            while ($i < $n && strpos(" \t\r\n", $s[$i]) !== false) $i++;
            $sep = $s[$i] ?? '';
            $i++;
            if ($sep === ',') continue;
            if ($sep === ($isObj ? '}' : ']')) break;
            throw new UnexpectedValueException('bad separator');
        }
        if (!$isObj) return '[' . implode(',', $items) . ']';
        uksort($items, fn($a, $b) => strcmp((string) $a, (string) $b));   // JS Array.sort(): string order
        $parts = [];
        foreach ($items as $k => $v) $parts[] = sleek_np_js_string((string) $k) . ':' . $v;
        return '{' . implode(',', $parts) . '}';
    }
    if ($c === '"') return sleek_np_js_string((string) json_decode(sleek_np_json_string_token($s, $i)));
    foreach (['true', 'false', 'null'] as $lit) {
        if (substr_compare($s, $lit, $i, strlen($lit)) === 0) { $i += strlen($lit); return $lit; }
    }
    // Numbers stay exactly as sent: the sender's JSON.stringify already chose their form.
    if (preg_match('/-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $s, $m, 0, $i)) {
        $i += strlen($m[0]);
        return $m[0];
    }
    throw new UnexpectedValueException('bad value');
}

/** The raw "..." token starting at $i (escapes intact); advances $i past it. */
function sleek_np_json_string_token(string $s, int &$i): string
{
    $start = $i++;
    $n = strlen($s);
    while ($i < $n) {
        if ($s[$i] === '\\') { $i += 2; continue; }
        if ($s[$i] === '"') { $i++; return substr($s, $start, $i - $start); }
        $i++;
    }
    throw new UnexpectedValueException('unterminated string');
}

/** A string as JSON.stringify writes it: only quote, backslash and control characters escaped. */
function sleek_np_js_string(string $v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
}
