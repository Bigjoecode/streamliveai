<?php
/**
 * LucaAi — send Livestream clips to the user's own Telegram.
 *
 * Linking: the page shows a t.me/<bot>?start=<code> link. The user presses Start
 * in the bot chat; "Check" reads the bot's pending updates, records every private
 * "/start <code>" it sees (so one user's check never loses another user's), marks
 * them read, and saves the chat id on the matching account (users.telegram_chat_id).
 *
 * Sending: the browser records the AI output (labelled "AI-generated" in the
 * frame), uploads it here, and it goes to that chat as a round video note when it
 * is a short square MP4, otherwise as a regular video.
 */

declare(strict_types=1);

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/settings.php';

const SLEEK_TG_CLIP_MAX_BYTES = 20 * 1024 * 1024;   // Telegram's upload limit for bots is 50 MB; keep clips small
const SLEEK_TG_LINK_TTL       = 1800;

function sleek_tg_bot_ready(): bool
{
    return sleek_telegram_token() !== '';
}

/** @return ?string the bot's @username without the @, cached for a day */
function sleek_tg_bot_username(): ?string
{
    $cached = sleek_setting('tg_bot_username', null);
    if (is_array($cached) && ($cached['at'] ?? 0) > time() - 86400 && is_string($cached['name'] ?? null)) return $cached['name'];
    [$name] = sleek_telegram_whoami(sleek_telegram_token());
    if ($name === null) return null;
    $name = ltrim($name, '@');
    sleek_setting_set('tg_bot_username', ['name' => $name, 'at' => time()]);
    return $name;
}

function sleek_tg_user_chat(int $userId): ?string
{
    $st = sleek_db()->prepare('SELECT telegram_chat_id FROM users WHERE id = ?');
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return is_string($v) && $v !== '' ? $v : null;
}

/** A fresh deep link for this browser session. @return ?string */
function sleek_tg_link_url(): ?string
{
    $bot = sleek_tg_bot_username();
    if ($bot === null) return null;
    $code = $_SESSION['tg_link']['code'] ?? '';
    if ($code === '' || ($_SESSION['tg_link']['at'] ?? 0) < time() - SLEEK_TG_LINK_TTL) {
        $code = bin2hex(random_bytes(12));
        $_SESSION['tg_link'] = ['code' => $code, 'at' => time()];
    }
    return "https://t.me/{$bot}?start={$code}";
}

/** Look for this session's /start code; link the account if found. */
function sleek_tg_link_check(int $userId): bool
{
    $code = (string) ($_SESSION['tg_link']['code'] ?? '');
    if ($code === '') return false;

    $seen = sleek_setting('tg_start_codes', []);
    $seen = is_array($seen) ? array_filter($seen, fn($r) => is_array($r) && ($r['at'] ?? 0) > time() - SLEEK_TG_LINK_TTL) : [];

    [$status, $json] = sleek_telegram_call(sleek_telegram_token(), 'getUpdates', ['allowed_updates' => ['message'], 'timeout' => 0]);
    if ($status === 200 && !empty($json['ok'])) {
        $last = null;
        foreach ($json['result'] ?? [] as $u) {
            $last = max($last ?? 0, (int) ($u['update_id'] ?? 0));
            $m = $u['message'] ?? null;
            if (!is_array($m) || ($m['chat']['type'] ?? '') !== 'private') continue;
            if (preg_match('/^\/start\s+([a-f0-9]{24})$/', trim((string) ($m['text'] ?? '')), $mm)) {
                $seen[$mm[1]] = ['chat' => (string) $m['chat']['id'], 'at' => time()];
            }
        }
        sleek_setting_set('tg_start_codes', $seen);
        if ($last !== null) sleek_telegram_call(sleek_telegram_token(), 'getUpdates', ['offset' => $last + 1, 'timeout' => 0]);
    }

    if (!isset($seen[$code])) return false;
    sleek_db()->prepare('UPDATE users SET telegram_chat_id = ? WHERE id = ?')->execute([$seen[$code]['chat'], $userId]);
    unset($seen[$code], $_SESSION['tg_link']);
    sleek_setting_set('tg_start_codes', $seen);
    sleek_telegram_call(sleek_telegram_token(), 'sendMessage', [
        'chat_id' => (string) sleek_tg_user_chat($userId),
        'text'    => 'Connected to ' . sleek_config('brand_name') . '. Clips you send from Livestream will arrive here.',
    ]);
    return true;
}

function sleek_tg_unlink(int $userId): void
{
    sleek_db()->prepare('UPDATE users SET telegram_chat_id = NULL WHERE id = ?')->execute([$userId]);
}

/**
 * Send an uploaded clip to the user's chat. Returns [ok, error].
 *
 * @return array{0: bool, 1: string}
 */
function sleek_tg_send_clip(int $userId, string $path, string $mime, bool $asNote, int $seconds): array
{
    $chat = sleek_tg_user_chat($userId);
    if ($chat === null) return [false, 'Connect Telegram first.'];

    $note   = $asNote && $mime === 'video/mp4';
    $method = $note ? 'sendVideoNote' : 'sendVideo';
    $field  = $note ? 'video_note' : 'video';
    $ext    = $mime === 'video/mp4' ? 'mp4' : 'webm';

    $params = ['chat_id' => $chat, $field => new CURLFile($path, $mime, "clip.{$ext}")];
    if ($seconds > 0) $params['duration'] = (string) $seconds;
    if (!$note) $params['caption'] = 'AI-generated with ' . sleek_config('brand_name') . ' Livestream';

    $base = rtrim((string) (sleek_config('telegram')['api_base'] ?? 'https://api.telegram.org'), '/');
    $ch = curl_init("{$base}/bot" . sleek_telegram_token() . "/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => $params,
    ]);
    $raw    = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json   = is_string($raw) ? json_decode($raw, true) : null;
    if ($status === 200 && !empty($json['ok'])) return [true, ''];

    $err = (string) ($json['description'] ?? ($status === 0 ? 'Telegram could not be reached.' : "HTTP {$status}"));
    sleek_telegram_log("clip send failed for user #{$userId}: {$err}");
    if (str_contains($err, 'blocked') || str_contains($err, 'chat not found')) {
        sleek_tg_unlink($userId);
        return [false, 'Telegram says the bot can\'t message you any more. Connect Telegram again.'];
    }
    return [false, 'Telegram didn\'t accept the clip: ' . $err];
}
