<?php
/**
 * SleekAI — admin alerts to a Telegram group via a bot.
 *
 * Set up at /admin-community → Telegram alerts: paste the bot token (from
 * @BotFather), add the bot to your group, press "Find my group", save, send a
 * test. Each alert type can be switched off there.
 *
 * Sending happens after the response has gone to the visitor (sleek_after_response),
 * so a slow or unreachable Telegram never slows the site or breaks a payment.
 * Failures go to storage/telegram.log.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** Every alert type: key => [label, emoji, on by default]. */
const SLEEK_ALERTS = [
    'signup'      => ['New registrations',                       '🆕', true],
    'payment'     => ['Payments received',                       '💰', true],
    'payment_bad' => ['Payment problems (wrong amount, forged webhooks)', '🚨', true],
    'contact'     => ['Contact form messages',                   '📩', true],
    'job'         => ['Tool jobs finished (voice, video, image)', '🎬', true],
    'job_failed'  => ['Tool jobs failed (auto-refunded)',         '⚠️', true],
    'livestream'  => ['Livestream sessions',                     '🎥', true],
    'admin'       => ['Admin actions (accounts, credits, roles)', '🛠', true],
    'reset'       => ['Password reset requests',                 '🔑', false],
    'mail_failed' => ['Emails that failed to send',              '📪', true],
];

function sleek_telegram_token(): string
{
    return trim((string) (sleek_config('telegram')['bot_token'] ?? ''));
}

function sleek_telegram_chat(): string
{
    $c = sleek_setting('telegram_chat_id', '');
    return is_string($c) ? trim($c) : '';
}

function sleek_telegram_ready(): bool
{
    return sleek_telegram_token() !== '' && sleek_telegram_chat() !== '';
}

function sleek_alert_enabled(string $type): bool
{
    $saved = sleek_setting('telegram_alerts', null);
    if (is_array($saved) && array_key_exists($type, $saved)) return (bool) $saved[$type];
    return SLEEK_ALERTS[$type][2] ?? false;
}

/**
 * Queue an alert. $fields are label => value (values are escaped here); $link is
 * an admin page path such as 'admin-user?id=4'. Never throws.
 */
function sleek_alert(string $type, string $headline, array $fields = [], ?string $link = 'admin'): void
{
    try {
        if (!isset(SLEEK_ALERTS[$type]) || !sleek_alert_enabled($type) || !sleek_telegram_ready()) return;
        $text = sleek_alert_format($type, $headline, $fields, $link);
        sleek_after_response(fn() => sleek_telegram_send($text));
    } catch (Throwable $e) {
        sleek_telegram_log('queue failed: ' . $e->getMessage());
    }
}

/** The message, in Telegram HTML: headline, rule, fields, rule, admin link. */
function sleek_alert_format(string $type, string $headline, array $fields, ?string $link): string
{
    $emoji = SLEEK_ALERTS[$type][1] ?? '🔔';
    $rule  = str_repeat('─', 22);
    $h     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $lines = ["{$emoji} <b>" . $h(mb_strtoupper($headline)) . "</b> {$emoji}", $rule];
    foreach ($fields as $label => $value) {
        if ($value === null || $value === '') continue;
        $v = mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $value) ?? '', 0, 400, '…');
        $lines[] = '<b>' . $h($label) . ':</b> ' . $h($v);
    }
    $lines[] = $rule;
    $brand = (string) sleek_config('brand_name');
    $lines[] = $link !== null
        ? '<i>' . $h($brand) . ' · </i><a href="' . $h(sleek_url($link)) . '">Open in admin</a>'
        : '<i>' . $h($brand) . '</i>';
    return implode("\n", $lines);
}

/** Post a message to the configured group now. Returns [ok, error]. */
function sleek_telegram_send(string $html, ?string $chat = null, ?string $token = null): array
{
    $token ??= sleek_telegram_token();
    $chat  ??= sleek_telegram_chat();
    if ($token === '' || $chat === '') return [false, 'Bot token or group not set.'];

    [$status, $json] = sleek_telegram_call($token, 'sendMessage', [
        'chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
    ]);
    if ($status === 200 && !empty($json['ok'])) return [true, ''];
    $err = (string) ($json['description'] ?? ($status === 0 ? 'Telegram could not be reached.' : "HTTP {$status}"));
    sleek_telegram_log("send failed: {$err}");
    return [false, $err];
}

/** @return array{0:int,1:?array} */
function sleek_telegram_call(string $token, string $method, array $params = []): array
{
    $base = rtrim((string) (sleek_config('telegram')['api_base'] ?? 'https://api.telegram.org'), '/');
    $ch = curl_init("{$base}/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [$status, is_array($json) ? $json : null];
}

/**
 * Groups and channels the bot has recently been added to or messaged in, for the
 * "Find my group" button. @return array{0: array<int, array{id:string,title:string,type:string}>, 1: string}
 */
function sleek_telegram_discover(string $token): array
{
    [$status, $json] = sleek_telegram_call($token, 'getUpdates', ['allowed_updates' => ['message', 'my_chat_member', 'channel_post']]);
    if ($status !== 200 || empty($json['ok'])) {
        return [[], (string) ($json['description'] ?? 'Telegram rejected the bot token.')];
    }
    $chats = [];
    foreach ($json['result'] ?? [] as $u) {
        $c = $u['my_chat_member']['chat'] ?? $u['message']['chat'] ?? $u['channel_post']['chat'] ?? null;
        if (!is_array($c) || !in_array($c['type'] ?? '', ['group', 'supergroup', 'channel'], true)) continue;
        $chats[(string) $c['id']] = ['id' => (string) $c['id'], 'title' => (string) ($c['title'] ?? $c['id']), 'type' => (string) $c['type']];
    }
    return [array_values($chats), ''];
}

/** Who the token belongs to — proves it works. @return array{0:?string,1:string} [@username, error] */
function sleek_telegram_whoami(string $token): array
{
    [$status, $json] = sleek_telegram_call($token, 'getMe');
    if ($status === 200 && !empty($json['ok'])) return ['@' . ($json['result']['username'] ?? 'bot'), ''];
    return [null, (string) ($json['description'] ?? 'Telegram rejected the bot token.')];
}

/** Name and email for an account id, for alert text. */
function sleek_alert_user(int $userId): array
{
    $st = sleek_db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: ['id' => $userId, 'name' => "#{$userId}", 'email' => ''];
}

function sleek_telegram_log(string $line): void
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/telegram.log', '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND | LOCK_EX);
}
