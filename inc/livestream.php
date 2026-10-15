<?php
/**
 * SleekAI — Livestream (Decart realtime character swap).
 *
 * The video never touches this server: the browser streams straight to Decart
 * with a short-lived client token minted here. What this server controls is money:
 *
 *   start   The chosen session length is charged up front, and the token carries
 *           constraints.realtime.maxSessionDuration = that length. Decart ends the
 *           stream at the limit itself, so no one can stream time they haven't paid
 *           for — whatever their browser does.
 *   report  While live, the page reports seconds streamed. Reports only ever move
 *           the count up, are capped at the session length, and can't exceed the
 *           wall-clock time since the token was issued.
 *   settle  On end, unused time is refunded (config decart.refund_unused), billed
 *           in bill_increment_seconds steps. A session whose page went silent
 *           without ever reporting is settled at the full prepaid amount.
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/decart.php';

/** Seconds the client token may be used to open the connection. */
const SLEEK_LIVE_TOKEN_TTL = 60;
/** Slack after a session's hard limit before an unreported session is swept. */
const SLEEK_LIVE_GRACE = 30;

final class SleekLiveBusy extends RuntimeException {}

/** "http://localhost" from "http://localhost/sleekai.com.ng" — what the browser sends as Origin. */
function sleek_app_origin(): string
{
    $p = parse_url(sleek_config('app_url'));
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost') . (isset($p['port']) ? ':' . $p['port'] : '');
}

/**
 * Mint a Decart client token: POST /v1/client/tokens.
 *
 * @return array{token: string, expires_at: string}
 */
function sleek_decart_mint_token(int $maxSeconds, array $metadata): array
{
    $cfg  = sleek_config('decart');
    $body = json_encode([
        'expiresIn'      => SLEEK_LIVE_TOKEN_TTL,
        'allowedModels'  => [$cfg['model']],
        'allowedOrigins' => [sleek_app_origin()],
        'constraints'    => ['realtime' => ['maxSessionDuration' => $maxSeconds]],
        'metadata'       => $metadata,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $ch = curl_init(rtrim($cfg['api_base'], '/') . '/v1/client/tokens');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $cfg['api_key'], 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);

    if ($raw === false) throw new SleekProviderError("Could not reach Decart: {$err}");
    $json = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['apiKey'])) {
        $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? json_encode($json)) : mb_substr((string) $raw, 0, 200);
        throw new SleekProviderError("Decart token request failed (HTTP {$status}): {$msg}");
    }
    return ['token' => (string) $json['apiKey'], 'expires_at' => (string) ($json['expiresAt'] ?? '')];
}

/** The latest time a session can still be streaming: token window + hard limit. */
function sleek_live_deadline(array $s): int
{
    return (int) $s['created_at'] + SLEEK_LIVE_TOKEN_TTL + (int) $s['max_seconds'];
}

/** The user's session that may still be live, if any. */
function sleek_live_active(int $userId): ?array
{
    $stmt = sleek_db()->prepare(
        "SELECT ls.*, j.status FROM live_sessions ls JOIN jobs j ON j.id = ls.job_id
         WHERE ls.user_id = ? AND j.status = 'processing' ORDER BY ls.id DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Charge for a session and get a token to open it.
 *
 * @return array{job: int, token: string, model: string, speed: string, max_seconds: int}
 * @throws SleekInsufficientCredits|SleekLiveBusy|SleekProviderError
 */
function sleek_live_start(int $userId, int $minutes, int $consentAt): array
{
    $cfg = sleek_config('decart');
    if (!in_array($minutes, $cfg['session_minutes'], true)) throw new InvalidArgumentException('Pick one of the listed session lengths.');

    if (sleek_live_active($userId) !== null) {
        throw new SleekLiveBusy('You already have a live session. End it before starting another.');
    }

    $seconds  = $minutes * 60;
    $reserved = $seconds * (int) $cfg['credits_per_second'];

    // Titles describe the session only — lists already prefix the tool name.
    $jobId = sleek_job_start($userId, 'livestream', "{$minutes}-minute session", $consentAt, $reserved);

    $now = time();
    sleek_db()->prepare('INSERT INTO live_sessions (job_id, user_id, max_seconds, reserved_credits, token_expires_at, created_at)
                         VALUES (?, ?, ?, ?, ?, ?)')
              ->execute([$jobId, $userId, $seconds, $reserved, $now + SLEEK_LIVE_TOKEN_TTL, $now]);

    try {
        $tok = sleek_decart_mint_token($seconds, ['sleekai_job' => $jobId, 'sleekai_user' => $userId]);
    } catch (Throwable $e) {
        sleek_job_fail($jobId, 'Livestream couldn\'t start. Your credits were refunded.', $e->getMessage());
        throw $e instanceof SleekProviderError ? $e : new SleekProviderError($e->getMessage(), 0, $e);
    }

    return ['job' => $jobId, 'token' => $tok['token'], 'model' => $cfg['model'], 'speed' => sleek_live_speed(), 'max_seconds' => $seconds];
}

/**
 * Decart speed tier, set at /admin-providers: 'standard', or 'fast' — lower latency
 * and smoother motion at twice Decart's per-second price (Lucy 2.5 supports it).
 */
function sleek_live_speed(): string
{
    require_once __DIR__ . '/settings.php';
    return sleek_setting('live_speed', 'standard') === 'fast' ? 'fast' : 'standard';
}

/**
 * Record seconds streamed. Monotonic, capped at the session length and at the
 * wall-clock time since the token was minted. Returns the updated session, or
 * null when it isn't this user's session or it has already ended.
 */
function sleek_live_report(int $jobId, int $userId, int $seconds): ?array
{
    $db   = sleek_db();
    $load = function () use ($db, $jobId, $userId) {
        $stmt = $db->prepare('SELECT ls.*, j.status FROM live_sessions ls JOIN jobs j ON j.id = ls.job_id
                              WHERE ls.job_id = ? AND ls.user_id = ?');
        $stmt->execute([$jobId, $userId]);
        return $stmt->fetch() ?: null;
    };

    $s = $load();
    if ($s === null || $s['status'] !== 'processing') return $s;

    // max_seconds and created_at never change, so clamping here is race-free; the
    // CASE makes the write itself monotonic, so interleaved reports can't regress it.
    $v = max(0, min($seconds, (int) $s['max_seconds'], time() - (int) $s['created_at']));
    $db->prepare('UPDATE live_sessions
                  SET used_seconds = CASE WHEN ? > used_seconds THEN ? ELSE used_seconds END,
                      reported = 1, last_seen_at = ?
                  WHERE job_id = ?')
       ->execute([$v, $v, time(), $jobId]);

    return $load();
}

/**
 * Close out a session and settle the money. Only the first call does anything.
 *
 * @return array{status: string, used: int, charged: int, refunded: int}|null
 */
function sleek_live_settle(int $jobId, bool $silent = false): ?array
{
    $cfg = sleek_config('decart');
    $db  = sleek_db();

    $stmt = $db->prepare('SELECT * FROM live_sessions WHERE job_id = ?');
    $stmt->execute([$jobId]);
    $s = $stmt->fetch();
    if (!$s) return null;

    $reserved = (int) $s['reserved_credits'];
    $used     = (int) $s['used_seconds'];

    if (!$cfg['refund_unused'] || ($silent && !$s['reported'])) {
        // No refunds configured, or the page never reported and simply vanished:
        // it may have streamed the whole prepaid time, so keep the full amount.
        $charge = $reserved;
    } else {
        $step   = max(1, (int) $cfg['bill_increment_seconds']);
        $billed = (int) ceil($used / $step) * $step;
        $charge = min($reserved, $billed * (int) $cfg['credits_per_second']);
    }
    $refund = $reserved - $charge;

    $failed = $charge === 0;
    $title  = match (true) {
        $failed   => 'Didn\'t connect',
        $used > 0 => 'Streamed ' . sleek_duration_label($used),
        default   => intdiv((int) $s['max_seconds'], 60) . '-minute session (no usage reported)',
    };

    $db->beginTransaction();
    try {
        $claim = $db->prepare("UPDATE jobs SET status = ?, credits = ?, title = ?, error = ?, completed_at = ?
                               WHERE id = ? AND status = 'processing'");
        $claim->execute([
            $failed ? 'failed' : 'completed',
            $failed ? $reserved : $charge,
            $title,
            $failed ? 'The stream didn\'t start. Your credits were refunded.' : null,
            time(),
            $jobId,
        ]);
        if ($claim->rowCount() !== 1) {
            $db->rollBack();
            return null;   // already settled
        }

        if ($refund > 0) {
            sleek_credit((int) $s['user_id'], $refund, 'job_refund', "job:{$jobId}",
                $failed ? 'Refund — Livestream (didn\'t start)' : 'Refund — Livestream (unused time)');
        }

        $db->prepare('UPDATE live_sessions SET charged_credits = ?, ended_at = ? WHERE job_id = ?')
           ->execute([$charge, time(), $jobId]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    sleek_jobs_log("livestream job #{$jobId} settled: used {$used}s, charged {$charge}, refunded {$refund}"
        . ($silent ? ' (swept' . ($s['reported'] ? '' : ', never reported') . ')' : ''));

    return ['status' => $failed ? 'failed' : 'completed', 'used' => $used, 'charged' => $charge, 'refunded' => $refund];
}

/** Settle every session past its hard deadline — its page closed without ending it. */
function sleek_live_sweep(): void
{
    $stmt = sleek_db()->prepare(
        "SELECT ls.job_id FROM live_sessions ls JOIN jobs j ON j.id = ls.job_id
         WHERE j.status = 'processing' AND ls.created_at + ? + ls.max_seconds + ? < ?"
    );
    $stmt->execute([SLEEK_LIVE_TOKEN_TTL, SLEEK_LIVE_GRACE, time()]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
        sleek_live_settle((int) $jobId, true);
    }
}

function sleek_duration_label(int $seconds): string
{
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    if ($m === 0) return "{$s}s";
    return $s === 0 ? "{$m}m" : "{$m}m {$s}s";
}
