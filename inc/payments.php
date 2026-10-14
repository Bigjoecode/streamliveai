<?php
/**
 * SleekAI — payments, independent of who processes them.
 *
 * Two providers sell the same credit packs:
 *   flutterwave  card, bank transfer, USSD   (inc/flutterwave.php)
 *   nowpayments  crypto                      (inc/nowpayments.php)
 *
 * Flow: buy.php records a pending payment and sends the buyer to the provider's
 * hosted checkout. The provider then reports the result twice — by redirecting the
 * buyer back to its *-callback page, and by POSTing its *-webhook. Either can
 * arrive first, or only one may arrive at all (the buyer closes the tab), so both
 * end in sleek_fulfil_payment(), which credits exactly once.
 *
 * Adapters never hand raw provider payloads to this file. They translate what the
 * provider's own API reports into one normalised shape:
 *   ['reference' => 'SLK-…', 'status' => 'success'|'failed'|'pending',
 *    'amount_kobo' => int, 'currency' => 'NGN', 'gateway' => 'short note']
 */

declare(strict_types=1);

require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/catalog.php';

final class SleekPaymentError extends RuntimeException {}

/** Every provider the code knows, with the label buyers see. */
function sleek_payment_methods(): array
{
    return [
        'flutterwave' => ['label' => 'Pay with card / bank', 'busy' => 'Opening checkout…'],
        'nowpayments' => ['label' => 'Pay with crypto',      'busy' => 'Opening crypto checkout…'],
    ];
}

function sleek_payment_configured(string $provider): bool
{
    return match ($provider) {
        'flutterwave' => sleek_flutterwave_configured(),
        'nowpayments' => sleek_nowpayments_configured(),
        default       => false,
    };
}

/** Methods with keys set — the only buttons the billing page shows. */
function sleek_payment_methods_available(): array
{
    return array_filter(sleek_payment_methods(), fn($m, $k) => sleek_payment_configured($k), ARRAY_FILTER_USE_BOTH);
}

/**
 * Record a pending payment for a pack and return it. The price comes from the
 * catalog only — nothing about the amount is taken from the request.
 *
 * @return array{reference:string, pack:array, amount_kobo:int, currency:string}
 */
function sleek_payment_create(array $user, string $packKey, string $provider): array
{
    $pack = sleek_pack($packKey);
    if ($pack === null) throw new InvalidArgumentException('Unknown credit pack.');

    $reference = 'SLK-' . strtoupper(bin2hex(random_bytes(10)));
    $kobo      = $pack['price'] * 100;
    $currency  = 'NGN';

    sleek_db()->prepare('INSERT INTO payments (user_id, provider, reference, pack_key, credits, amount_kobo, currency, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
              ->execute([$user['id'], $provider, $reference, $packKey, $pack['credits'], $kobo, $currency, 'pending', time()]);

    return ['reference' => $reference, 'pack' => $pack, 'amount_kobo' => $kobo, 'currency' => $currency];
}

/** Start a purchase with the chosen provider. Returns the checkout URL. */
function sleek_start_payment(array $user, string $packKey, string $provider): string
{
    $payment = sleek_payment_create($user, $packKey, $provider);
    try {
        $url = match ($provider) {
            'flutterwave' => sleek_flutterwave_checkout($user, $payment),
            'nowpayments' => sleek_nowpayments_checkout($user, $payment),
            default       => throw new InvalidArgumentException('Unknown payment method.'),
        };
    } catch (Throwable $e) {
        sleek_payment_note($payment['reference'], 'Checkout failed: ' . $e->getMessage(), 'failed');
        throw $e;
    }
    if (!preg_match('#^https://#', $url)) throw new SleekPaymentError('The provider returned no checkout URL.');
    return $url;
}

/** Look a payment up by our reference. */
function sleek_payment_find(string $reference): ?array
{
    $stmt = sleek_db()->prepare('SELECT * FROM payments WHERE reference = ?');
    $stmt->execute([$reference]);
    return $stmt->fetch() ?: null;
}

/** Store a short provider note; optionally move a not-yet-paid payment to $status. */
function sleek_payment_note(string $reference, string $note, ?string $status = null): void
{
    $note = mb_substr($note, 0, 255);
    if ($status === null) {
        sleek_db()->prepare("UPDATE payments SET gateway_response = ? WHERE reference = ? AND status <> 'success'")
                  ->execute([$note, $reference]);
    } else {
        sleek_db()->prepare("UPDATE payments SET status = ?, gateway_response = ? WHERE reference = ? AND status <> 'success'")
                  ->execute([$status, $note, $reference]);
    }
}

function sleek_is_payment_reference(string $s): bool
{
    return (bool) preg_match('/^SLK-[A-F0-9]{20}$/', $s);
}

/**
 * Apply what a provider's API says happened. Safe to call any number of times for
 * the same reference — only the first successful call credits anything.
 *
 * @param array $tx normalised transaction (see the header of this file)
 * @return string 'credited' | 'already' | 'failed' | 'pending' | 'mismatch' | 'unknown'
 */
function sleek_fulfil_payment(string $reference, array $tx): string
{
    $db = sleek_db();

    $stmt = $db->prepare('SELECT p.*, u.name AS buyer_name, u.referred_by
                          FROM payments p JOIN users u ON u.id = p.user_id WHERE p.reference = ?');
    $stmt->execute([$reference]);
    $payment = $stmt->fetch();
    if (!$payment) return 'unknown';

    if (($tx['reference'] ?? null) !== $reference) return 'mismatch';

    $gateway = mb_substr((string) ($tx['gateway'] ?? $tx['status'] ?? ''), 0, 255);
    $status  = $tx['status'] ?? '';

    if ($status === 'pending') {
        if ($gateway !== '') sleek_payment_note($reference, $gateway);
        return $payment['status'] === 'success' ? 'already' : 'pending';
    }

    if ($status !== 'success') {
        $db->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE reference = ? AND status = 'pending'")
           ->execute([$gateway, $reference]);
        return $payment['status'] === 'success' ? 'already' : 'failed';
    }

    // Never trust that the amount paid is the amount we asked for — a tampered
    // checkout could pay ₦1 for the ₦280,000 pack.
    if ((int) ($tx['amount_kobo'] ?? -1) !== (int) $payment['amount_kobo']
        || strtoupper((string) ($tx['currency'] ?? '')) !== $payment['currency']) {
        $db->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE reference = ? AND status <> 'success'")
           ->execute([mb_substr('Amount/currency mismatch: got ' . ($tx['amount_kobo'] ?? '?') . ' ' . ($tx['currency'] ?? '?'), 0, 255), $reference]);
        sleek_payments_log("MISMATCH {$reference}: expected {$payment['amount_kobo']} {$payment['currency']}, got "
            . json_encode(['amount_kobo' => $tx['amount_kobo'] ?? null, 'currency' => $tx['currency'] ?? null]));
        return 'mismatch';
    }

    $db->beginTransaction();
    try {
        // The claim: exactly one caller flips this row to success. A second caller
        // (callback and webhook arriving together) blocks on the row lock, then sees
        // status = 'success' and updates nothing.
        $claim = $db->prepare("UPDATE payments SET status = 'success', paid_at = ?, gateway_response = ?
                               WHERE reference = ? AND status <> 'success'");
        $claim->execute([time(), $gateway, $reference]);
        if ($claim->rowCount() !== 1) {
            $db->rollBack();
            return 'already';
        }

        $pack = sleek_pack($payment['pack_key']);
        sleek_credit(
            (int) $payment['user_id'], (int) $payment['credits'], 'purchase', $reference,
            ($pack['name'] ?? 'Credit') . ' pack'
        );

        sleek_referral_bonus($payment);

        $db->commit();
        return 'credited';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof SleekAlreadyApplied) return 'already';
        throw $e;
    }
}

/**
 * The referrer's cut of a referral's FIRST purchase. Keyed on the buyer, not the
 * payment, so the ledger's unique index allows one bonus per referred person ever.
 * Runs inside sleek_fulfil_payment's transaction.
 */
function sleek_referral_bonus(array $payment): void
{
    if (empty($payment['referred_by'])) return;

    $stmt = sleek_db()->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'success' AND reference <> ?");
    $stmt->execute([$payment['user_id'], $payment['reference']]);
    if ((int) $stmt->fetchColumn() > 0) return;   // not their first purchase

    $bonus = intdiv((int) $payment['credits'] * (int) sleek_config('referrals')['bonus_percent'], 100);
    if ($bonus <= 0) return;

    $first = explode(' ', trim((string) $payment['buyer_name']))[0];
    try {
        sleek_credit((int) $payment['referred_by'], $bonus, 'referral_bonus',
            'user:' . $payment['user_id'], "Referral bonus — {$first}'s first purchase");
    } catch (SleekAlreadyApplied) {
        // Already paid for this referral; the purchase itself still stands.
    }
}

/**
 * Flash the buyer-facing message for a fulfilment result and send them on —
 * shared by every provider's callback page.
 */
function sleek_payment_finish_redirect(string $reference, string $result): never
{
    $p = sleek_payment_find($reference);

    match ($result) {
        'credited', 'already' => sleek_flash('success', ($p ? number_format((int) $p['credits']) : 'Your') . ' credits added. Thanks for your purchase.'),
        'failed'              => sleek_flash('error', 'That payment didn\'t go through, so nothing was charged. You can try again below.'),
        'mismatch'            => sleek_flash('error', 'We couldn\'t confirm that payment. If you were charged, contact support with reference ' . $reference . '.'),
        'unknown'             => sleek_flash('error', 'We couldn\'t find that payment.'),
        default               => sleek_flash('info', 'We\'re still confirming your payment. Your credits will appear here automatically once it clears.'),
    };

    // The session may have expired while the buyer was at checkout. The payment is
    // already applied to its owner either way; they'll see it after logging in.
    sleek_redirect(current_user() ? 'credits-billing' : 'login');
}

/**
 * Small JSON-over-HTTPS client for the provider adapters.
 *
 * @return array{status:int, json:?array}
 */
function sleek_payment_http(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);

    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($raw === false) throw new SleekPaymentError("Could not reach {$host}: {$err}");

    $json = json_decode((string) $raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null];
}

/** Naira in kobo from a provider's decimal amount, without float drift. */
function sleek_to_kobo(int|float|string $amount): int
{
    return (int) round(((float) $amount) * 100);
}

function sleek_payments_log(string $line): void
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/payments.log', '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND | LOCK_EX);
}

require_once __DIR__ . '/flutterwave.php';
require_once __DIR__ . '/nowpayments.php';
