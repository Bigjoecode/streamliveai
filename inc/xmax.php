<?php
/**
 * LucaAi — XMAX realtime (Livestream engines X2.1 and X2.0).
 *
 * Like Decart, the video goes browser ↔ XMAX directly. This server only issues
 * each session a temporary API key (tk-…) made with the permanent key (uk-…):
 *
 *   POST {api_base}/temporary-api-key            X-Api-Key: uk-…
 *        {expireSeconds, pointsLimit, temporaryApiKeySessionLimit: 1}
 *        → data.temporaryApiKey, data.expireTimestamp
 *   PUT  {api_base}/temporary-api-key/{tk}/inactive   (switch it off at session end)
 *
 * The key expires shortly after the paid length and allows one session, so a
 * key copied out of the browser can't be reused for unpaid time.
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

function sleek_xmax_configured(): bool
{
    return trim((string) (sleek_config('xmax')['api_key'] ?? '')) !== '';
}

/** @return array<string,string> model id => label, e.g. ['x2.1' => 'X2.1'] */
function sleek_xmax_models(): array
{
    $m = sleek_config('xmax')['models'] ?? [];
    return is_array($m) ? $m : [];
}

/** @return array{0:int,1:?array} */
function sleek_xmax_call(string $method, string $path, ?array $body = null): array
{
    $cfg = sleek_config('xmax');
    $ch  = curl_init(rtrim((string) $cfg['api_base'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $cfg['api_key'], 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $raw    = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    if ($raw === false) throw new SleekProviderError("Could not reach XMAX: {$err}");
    $json = json_decode((string) $raw, true);
    return [$status, is_array($json) ? $json : null];
}

/**
 * A temporary key for one session of $maxSeconds.
 *
 * @return array{token: string, expires_at: string}
 */
function sleek_xmax_mint_key(int $maxSeconds, int $ttl): array
{
    $cap = max(1, (int) (sleek_config('xmax')['points_per_second_cap'] ?? 60));
    [$status, $json] = sleek_xmax_call('POST', '/temporary-api-key', [
        'expireSeconds'               => $maxSeconds + $ttl,
        'pointsLimit'                 => $maxSeconds * $cap,
        'temporaryApiKeySessionLimit' => 1,
    ]);
    $key = is_array($json['data'] ?? null) ? (string) ($json['data']['temporaryApiKey'] ?? '') : '';
    if ($status < 200 || $status >= 300 || empty($json['success']) || $key === '') {
        $msg = (string) ($json['message'] ?? '') ?: ('HTTP ' . $status);
        throw new SleekProviderError("XMAX temporary key request failed (HTTP {$status}, code " . ($json['code'] ?? '?') . "): {$msg}");
    }
    return ['token' => $key, 'expires_at' => (string) ($json['data']['expireTimestamp'] ?? '')];
}

/** Switch a session's temporary key off. Best-effort: it expires on its own anyway. */
function sleek_xmax_deactivate(string $key): void
{
    if ($key === '' || !sleek_xmax_configured()) return;
    try {
        [$status, $json] = sleek_xmax_call('PUT', '/temporary-api-key/' . rawurlencode($key) . '/inactive');
        if ($status >= 300 || empty($json['success'])) {
            sleek_jobs_log("xmax: could not deactivate temporary key (HTTP {$status}): " . ($json['message'] ?? ''));
        }
    } catch (Throwable $e) {
        sleek_jobs_log('xmax: could not deactivate temporary key: ' . $e->getMessage());
    }
}
