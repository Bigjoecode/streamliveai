<?php
/**
 * LucaAi — Daily.co rooms for Livestream group calls.
 *
 * The host's page joins the room with the AI output as its camera; guests open
 * the room link in any browser (Daily's own call page) and see that video. Each
 * room belongs to one live session and expires when the session's paid time does.
 *
 *   POST   {api_base}/rooms            → {name, url}
 *   POST   {api_base}/meeting-tokens   → {token}   (host = owner)
 *   DELETE {api_base}/rooms/{name}
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

function sleek_daily_configured(): bool
{
    return trim((string) (sleek_config('daily')['api_key'] ?? '')) !== '';
}

/** @return array{0:int,1:?array} */
function sleek_daily_call(string $method, string $path, ?array $body = null): array
{
    $cfg = sleek_config('daily');
    $ch  = curl_init(rtrim((string) $cfg['api_base'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['api_key'], 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $raw    = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    if ($raw === false) throw new SleekProviderError("Could not reach Daily: {$err}");
    $json = json_decode((string) $raw, true);
    return [$status, is_array($json) ? $json : null];
}

/**
 * A room that closes at $expiresAt, plus an owner token for the host.
 *
 * @return array{name: string, url: string, token: string}
 */
function sleek_daily_room(int $expiresAt, string $hostName): array
{
    $max = max(2, (int) (sleek_config('daily')['max_guests'] ?? 9) + 1);
    [$status, $room] = sleek_daily_call('POST', '/rooms', [
        'privacy'    => 'public',
        'properties' => [
            'exp'                => $expiresAt,
            'eject_at_room_exp'  => true,
            'max_participants'   => $max,
            'enable_prejoin_ui'  => true,
            'enable_chat'        => true,
            'enable_screenshare' => false,
            'start_video_off'    => false,
        ],
    ]);
    if ($status < 200 || $status >= 300 || empty($room['name']) || empty($room['url'])) {
        throw new SleekProviderError("Daily room create failed (HTTP {$status}): " . ($room['info'] ?? $room['error'] ?? 'no details'));
    }

    [$status, $tok] = sleek_daily_call('POST', '/meeting-tokens', ['properties' => [
        'room_name' => $room['name'],
        'is_owner'  => true,
        'exp'       => $expiresAt,
        'user_name' => mb_substr($hostName, 0, 40),
    ]]);
    if ($status < 200 || $status >= 300 || empty($tok['token'])) {
        sleek_daily_delete((string) $room['name']);
        throw new SleekProviderError("Daily host token failed (HTTP {$status}): " . ($tok['info'] ?? $tok['error'] ?? 'no details'));
    }
    return ['name' => (string) $room['name'], 'url' => (string) $room['url'], 'token' => (string) $tok['token']];
}

/** Close a room now (session ended). Best-effort: it expires on its own anyway. */
function sleek_daily_delete(string $name): void
{
    if ($name === '' || !sleek_daily_configured()) return;
    try {
        sleek_daily_call('DELETE', '/rooms/' . rawurlencode($name));
    } catch (Throwable $e) {
        sleek_jobs_log("daily: could not delete room {$name}: " . $e->getMessage());
    }
}
