<?php
/**
 * SleekAI — loaded first by every PHP page. Config, escaping, session, CSRF,
 * security headers, then the database and auth layers.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------- config */

/**
 * config.php holds the defaults. An optional config.local.php (same shape, only the
 * keys you want to change) is layered on top — a place for real API keys that
 * stays out of the main file. Both live in inc/, which is denied over HTTP.
 */
function sleek_config(?string $key = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        if (is_file(__DIR__ . '/config.local.php')) {
            $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
        }
        // Super-admin-managed provider keys live outside inc/ and are never served
        // over HTTP; storage/.htaccess blocks direct access to these JSON values.
        $providerSecretsFile = __DIR__ . '/../storage/provider-secrets.json';
        if (is_file($providerSecretsFile)) {
            $providerSecrets = json_decode((string) file_get_contents($providerSecretsFile), true);
            if (is_array($providerSecrets)) $config = array_replace_recursive($config, $providerSecrets);
        }
    }
    return $key === null ? $config : $config[$key];
}

/* --------------------------------------------------------------- helpers */

/** Escape for HTML output. ENT_SUBSTITUTE: a stray bad byte becomes U+FFFD instead of blanking the whole string. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** True when every string in a (nested) request array is valid UTF-8. */
function sleek_valid_utf8(array $input): bool
{
    foreach ($input as $v) {
        if (is_array($v) ? !sleek_valid_utf8($v) : (is_string($v) && !mb_check_encoding($v, 'UTF-8'))) return false;
    }
    return true;
}

/*
 * Browsers always send UTF-8 from these pages, so invalid bytes only come from
 * hand-crafted requests — and would otherwise surface much later as a MySQL
 * "incorrect string value" error or a failed json_encode after credits were spent.
 */
if (!sleek_valid_utf8($_GET) || !sleek_valid_utf8($_POST)) {
    http_response_code(400);
    exit('Bad request: text must be UTF-8.');
}

/** Absolute URL to a page on this site, built from config — never from the Host header. */
function sleek_url(string $path = ''): string
{
    return rtrim(sleek_config('app_url'), '/') . '/' . ltrim($path, '/');
}

/** Relative redirect — works whatever path the app is mounted under. */
function sleek_redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function sleek_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function sleek_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/* --------------------------------------------------------------- session */

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Scope the cookie to this app's folder so other apps on the same localhost
    // (every other project in htdocs) can neither read nor clobber it.
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = rtrim($base, '/') . '/';

    ini_set('session.use_strict_mode', '1');   // reject session ids we didn't issue
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('sleekai_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $base,
        'secure'   => sleek_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** Rejects the request outright on a missing or mismatched token. */
function csrf_verify(): void
{
    $sent = $_POST['_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        // 403, not Laravel's 419: Apache doesn't know 419 and rewrites it to a 500.
        http_response_code(403);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

/* ---------------------------------------------------------------- flash */

/**
 * A one-shot message for the next page view (type: 'success' | 'error' | 'info').
 * $extra rides along, e.g. ['buy' => true] to show a "Buy credits" link.
 */
function sleek_flash(string $type, string $message, array $extra = []): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message] + $extra;
}

/** Read and clear the pending flash message, if any. */
function sleek_flash_take(): ?array
{
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($f) ? $f : null;
}

/* -------------------------------------------------------------- headers */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
