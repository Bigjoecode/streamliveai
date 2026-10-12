<?php
/**
 * SleekAI — Paystack payments.
 *
 * Flow: buy.php creates a pending payment and sends the buyer to Paystack's hosted
 * checkout. Paystack then tells us about the result twice — by redirecting the
 * buyer back to paystack-callback.php, and by POSTing paystack-webhook.php. Either
 * one can arrive first, or only one may arrive at all (the buyer closes the tab),
 * so both call the same sleek_fulfil_payment(), which credits exactly once.
 */

declare(strict_types=1);

require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/catalog.php';

final class SleekPaystackError extends RuntimeException {}

function sleek_paystack_configured(): bool
{
    return sleek_config('paystack')['secret_key'] !== '';
}

/** Call the Paystack API. Returns the decoded `data` object, or throws. */
function sleek_paystack_request(string $method, string $path, ?array $body = null): array
{
    $cfg = sleek_config('paystack');
    if ($cfg['secret_key'] === '') throw new SleekPaystackError('Paystack secret key is not set in inc/config.php.');

    $ch = curl_init(rtrim($cfg['api_base'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['secret_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);

    if ($raw === false) throw new SleekPaystackError("Could not reach Paystack: {$err}");

    $json = json_decode((string) $raw, true);
    if (!is_array($json) || empty($json['status']) || !isset($json['data']) || !is_array($json['data'])) {
        $msg = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'unexpected response';
        throw new SleekPaystackError("Paystack {$method} {$path} failed (HTTP {$status}): {$msg}");
    }
    return $json['data'];
}

/**
 * Start a purchase: record a pending payment, then ask Paystack for a checkout URL.
 * Returns the URL to send the buyer to.
 */
function sleek_start_payment(array $user, string $packKey): string
{
    $pack = sleek_pack($packKey);
    if ($pack === null) throw new InvalidArgumentException('Unknown credit pack.');

    $cfg       = sleek_config('paystack');
    $reference = 'SLK-' . strtoupper(bin2hex(random_bytes(10)));
    $kobo      = $pack['price'] * 100;

    sleek_db()->prepare('INSERT INTO payments (user_id, reference, pack_key, credits, amount_kobo, currency, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
              ->execute([$user['id'], $reference, $packKey, $pack['credits'], $kobo, $cfg['currency'], 'pending', time()]);

    try {
        $data = sleek_paystack_request('POST', '/transaction/initialize', [
            'email'        => $user['email'],
            'amount'       => $kobo,
            'currency'     => $cfg['currency'],
            'reference'    => $reference,
            'callback_url' => sleek_url('paystack-callback'),
            'metadata'     => ['user_id' => (int) $user['id'], 'pack' => $packKey, 'credits' => $pack['credits']],
        ]);
    } catch (Throwable $e) {
        sleek_db()->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE reference = ?")
                  ->execute([mb_substr('Initialize failed: ' . $e->getMessage(), 0, 255), $reference]);
        throw $e;
    }

    $url = (string) ($data['authorization_url'] ?? '');
    if (!preg_match('#^https?://#', $url)) throw new SleekPaystackError('Paystack returned no checkout URL.');
    return $url;
}

/** Ask Paystack what really happened to a transaction. */
function sleek_paystack_verify(string $reference): array
{
    return sleek_paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
}

/** Webhook authenticity: HMAC-SHA512 of the raw body, keyed with the secret key. */
function sleek_paystack_signature_valid(string $rawBody, string $signature): bool
{
    $secret = sleek_config('paystack')['secret_key'];
    if ($secret === '' || $signature === '') return false;
    return hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower($signature));
}

/**
 * Apply a transaction Paystack says happened. Safe to call any number of times
 * for the same reference — only the first successful call credits anything.
 *
 * $tx is Paystack's transaction object, from the verify API or a webhook.
 *
 * @return string 'credited' | 'already' | 'failed' | 'mismatch' | 'unknown'
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

    $gateway = mb_substr((string) ($tx['gateway_response'] ?? $tx['status'] ?? ''), 0, 255);

    if (($tx['status'] ?? '') !== 'success') {
        $db->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE reference = ? AND status = 'pending'")
           ->execute([$gateway, $reference]);
        return $payment['status'] === 'success' ? 'already' : 'failed';
    }

    // Never trust that the amount paid is the amount we asked for — a tampered
    // checkout could pay ₦1 for the ₦280,000 pack.
    if ((int) ($tx['amount'] ?? -1) !== (int) $payment['amount_kobo']
        || strtoupper((string) ($tx['currency'] ?? '')) !== $payment['currency']) {
        $db->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE reference = ? AND status <> 'success'")
           ->execute([mb_substr('Amount/currency mismatch: got ' . ($tx['amount'] ?? '?') . ' ' . ($tx['currency'] ?? '?'), 0, 255), $reference]);
        sleek_payments_log("MISMATCH {$reference}: expected {$payment['amount_kobo']} {$payment['currency']}, got "
            . json_encode(['amount' => $tx['amount'] ?? null, 'currency' => $tx['currency'] ?? null]));
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

function sleek_payments_log(string $line): void
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/payments.log', '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND | LOCK_EX);
}
