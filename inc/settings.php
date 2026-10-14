<?php
/**
 * SleekAI — site settings the super admin edits from /admin, stored in the
 * `settings` table as JSON. Each has a code default, so a fresh install works
 * before anyone has saved anything.
 *
 *   packs                 credit packs: name, credits, price (USD), badge, features
 *   telegram_url          public Telegram channel, shown in the footer and on Contact
 *   contact_notify_email  where new contact messages are emailed (optional)
 */

declare(strict_types=1);

function sleek_setting(string $name, mixed $default = null): mixed
{
    $all = sleek_settings_all();
    return array_key_exists($name, $all) ? $all[$name] : $default;
}

/** Every saved setting, read once per request. */
function sleek_settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        foreach (sleek_db()->query('SELECT name, value FROM settings')->fetchAll() as $r) {
            $cache[$r['name']] = json_decode((string) $r['value'], true);
        }
    }
    return $cache;
}

function sleek_setting_set(string $name, mixed $value): void
{
    $db   = sleek_db();
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $sql  = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
        : 'INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at';
    $db->prepare($sql)->execute([$name, $json, time()]);
    sleek_settings_all(true);
}

/** The Telegram channel link, or '' when none is set. */
function sleek_telegram_url(): string
{
    $url = sleek_setting('telegram_url', '');
    return is_string($url) ? $url : '';
}

/**
 * Normalise what an admin typed into a t.me link: accepts @channel, t.me/channel,
 * https://t.me/channel or an invite link (t.me/+abc). Returns null if it isn't one.
 */
function sleek_normalize_telegram(string $input): ?string
{
    $s = trim($input);
    if ($s === '') return '';
    if (preg_match('/^@([A-Za-z0-9_]{4,64})$/', $s, $m)) return 'https://t.me/' . $m[1];
    if (preg_match('#^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/(\+?[A-Za-z0-9_-]{4,64}|joinchat/[A-Za-z0-9_-]{4,64})/?$#', $s, $m)) {
        return 'https://t.me/' . $m[1];
    }
    return null;
}
