<?php
/**
 * StreamLive AI — registration, login, logout and the page guards.
 */

declare(strict_types=1);

/**
 * A real bcrypt hash of a random string nobody knows. Verifying against it when an
 * email doesn't exist makes a miss cost the same as a wrong password, so response
 * time can't be used to find out which emails have accounts.
 */
const SLEEK_DUMMY_HASH = '$2y$10$VkB6H1TP3B1/roG5TKqCU.XYXmXs2PqkmOJHrLO/5jNAoa2dlc4Ai';

/** Pages the guard may send someone back to after login — nothing user-supplied. */
const SLEEK_INTENDED_PAGES = ['admin', 'admin-setup', 'dashboard', 'job-history', 'credits-billing', 'referrals', 'voice-isolator', 'voice-cloning', 'livestream', 'image-to-video', 'ai-image-edit'];

function sleek_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/* ============================================================== current user */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;

    $id = $_SESSION['uid'] ?? null;
    if (!is_int($id)) return $user = null;

    $stmt = sleek_db()->prepare('SELECT id, name, email, credits, referral_code, referred_by, created_at, role FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        // Account deleted out from under a live session.
        unset($_SESSION['uid']);
        return $user = null;
    }
    return $user = $row;
}

/** For signed-in pages: returns the user, or bounces to login. Call before any output. */
function require_auth(): array
{
    $user = current_user();
    if ($user !== null) return $user;

    // SCRIPT_NAME is the real file (referrals.php) even when the URL was /referrals.
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    if (in_array($page, SLEEK_INTENDED_PAGES, true)) $_SESSION['intended'] = $page;

    sleek_redirect('login');
}

function require_super_admin(): array
{
    $user = require_auth();
    if (($user['role'] ?? 'user') !== 'super_admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    return $user;
}

function sleek_claim_initial_super_admin(string $email, string $token): bool
{
    $email = sleek_normalize_email($email);
    $auth = sleek_config('auth');
    $expectedEmail = sleek_normalize_email((string) ($auth['initial_super_admin_email'] ?? ''));
    $expectedToken = (string) ($auth['super_admin_setup_token'] ?? '');
    if ($expectedEmail === '' || $expectedToken === '' || !hash_equals($expectedEmail, $email) || !hash_equals($expectedToken, $token)) return false;

    $db = sleek_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id === false) { $db->rollBack(); return false; }
        $db->exec("UPDATE users SET role = 'user' WHERE role = 'super_admin'");
        $db->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?")->execute([(int) $id]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sleek_super_admin_exists(): bool
{
    return (int) sleek_db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn() > 0;
}

/**
 * First-run setup (/admin-setup): the signed-in user becomes super admin by
 * entering auth.super_admin_setup_token — a code placed privately on the server
 * (config.local.php or storage/provider-secrets.json). Only works while no super
 * admin exists, so it locks itself once used. If auth.initial_super_admin_email
 * is set, only that account may claim.
 *
 * @return string 'ok' | 'taken' | 'disabled' | 'locked' | 'wrong'
 */
function sleek_claim_first_super_admin(array $user, string $code): string
{
    $auth     = sleek_config('auth');
    $expected = trim((string) ($auth['super_admin_setup_token'] ?? ''));
    if (strlen($expected) < 24) return 'disabled';   // unset or too weak to expose
    if (sleek_super_admin_exists()) return 'taken';

    // Wrong codes count like failed logins, per IP: 5 tries per 15 minutes.
    $key = hash('sha256', 'super-admin-setup|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    if (sleek_lockout_remaining($key) > 0) return 'locked';

    $onlyEmail = sleek_normalize_email((string) ($auth['initial_super_admin_email'] ?? ''));
    if (!hash_equals($expected, trim($code))
        || ($onlyEmail !== '' && !hash_equals($onlyEmail, sleek_normalize_email((string) $user['email'])))) {
        sleek_record_failure($key);
        return 'wrong';
    }

    $db = sleek_db();
    $db->beginTransaction();
    try {
        // Re-check inside the transaction so two simultaneous claims can't both win.
        $taken = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn() > 0;
        if ($taken) { $db->rollBack(); return 'taken'; }
        $db->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?")->execute([(int) $user['id']]);
        $db->commit();
        return 'ok';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sleek_update_user_role(int $userId, string $role): bool
{
    if (!in_array($role, ['user', 'admin'], true)) return false;
    $db = sleek_db();
    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $current = $stmt->fetchColumn();
    if ($current === false || $current === 'super_admin') return false;
    $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
    return true;
}

/** For login/register: someone already signed in goes straight to the dashboard. */
function require_guest(): void
{
    if (current_user() !== null) sleek_redirect('dashboard');
}

/** Rotate the session id and mark the user signed in. */
function sleek_login_user(int $id): void
{
    session_regenerate_id(true);   // defeats session fixation
    $_SESSION['uid'] = $id;
    unset($_SESSION['_csrf']);     // fresh token for the new session
}

function sleek_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

/** Where to send someone after they sign in. */
function sleek_after_login_url(): string
{
    $page = $_SESSION['intended'] ?? null;
    unset($_SESSION['intended']);
    return in_array($page, SLEEK_INTENDED_PAGES, true) ? $page : 'dashboard';
}

/* ================================================================ throttling */

function sleek_throttle_key(string $email): string
{
    return hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
}

/** Seconds until the lockout lifts, or 0 if this key may try again. */
function sleek_lockout_remaining(string $key): int
{
    $auth   = sleek_config('auth');
    $window = time() - $auth['lockout_seconds'];

    $stmt = sleek_db()->prepare('SELECT COUNT(*), MIN(attempted_at) FROM login_attempts WHERE throttle_key = ? AND attempted_at > ?');
    $stmt->execute([$key, $window]);
    [$count, $oldest] = $stmt->fetch(PDO::FETCH_NUM);

    if ((int) $count < $auth['max_attempts']) return 0;
    return max(1, ((int) $oldest + $auth['lockout_seconds']) - time());
}

function sleek_record_failure(string $key): void
{
    $db = sleek_db();
    $db->prepare('INSERT INTO login_attempts (throttle_key, attempted_at) VALUES (?, ?)')->execute([$key, time()]);

    // Opportunistic cleanup so the table never grows without bound.
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
           ->execute([time() - sleek_config('auth')['lockout_seconds']]);
    }
}

function sleek_clear_attempts(string $key): void
{
    sleek_db()->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$key]);
}

/* ===================================================================== login */

/**
 * @return array{ok: bool, error: ?string}
 */
function sleek_attempt_login(string $email, string $password): array
{
    $email = sleek_normalize_email($email);
    if ($email === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your email and password.'];
    }

    $key  = sleek_throttle_key($email);
    $wait = sleek_lockout_remaining($key);
    if ($wait > 0) {
        $mins = (int) ceil($wait / 60);
        return ['ok' => false, 'error' => "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.'];
    }

    $stmt = sleek_db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    // Always run exactly one verify, found or not — see SLEEK_DUMMY_HASH.
    $valid = password_verify($password, $row['password_hash'] ?? SLEEK_DUMMY_HASH) && $row;

    if (!$valid) {
        sleek_record_failure($key);
        // Same message either way: never reveal whether the email is registered.
        return ['ok' => false, 'error' => 'Those details don\'t match an account.'];
    }

    sleek_clear_attempts($key);

    // Upgrade the stored hash if PHP's default algorithm or cost has moved on.
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        sleek_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                  ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }

    sleek_login_user((int) $row['id']);
    return ['ok' => true, 'error' => null];
}

/* ============================================================ password rule */

/** The one password rule, shared by sign-up and reset. Null when acceptable. */
function sleek_password_error(string $password): ?string
{
    $auth = sleek_config('auth');
    if (strlen($password) < $auth['password_min']) {
        return "Use at least {$auth['password_min']} characters.";
    }
    if (strlen($password) > $auth['password_max']) {
        return "Keep your password to {$auth['password_max']} characters or fewer.";
    }
    return null;
}

/* ============================================================== registration */

/**
 * @return array{ok: bool, errors: array<string,string>}
 */
function sleek_register(string $name, string $email, string $password, bool $terms, string $refCode): array
{
    $name   = trim(preg_replace('/\s+/', ' ', $name));
    $email  = sleek_normalize_email($email);
    $errors = [];

    if ($name === '') {
        $errors['name'] = 'Enter your name.';
    } elseif (mb_strlen($name) > 100) {
        $errors['name'] = 'Keep your name under 100 characters.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (($pwError = sleek_password_error($password)) !== null) {
        $errors['password'] = $pwError;
    }

    if (!$terms) {
        $errors['terms'] = 'You need to accept the Terms and Privacy Policy.';
    }

    if ($errors) return ['ok' => false, 'errors' => $errors];

    $db = sleek_db();

    $stmt = $db->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'errors' => ['email' => 'An account with that email already exists. Log in instead?']];
    }

    // An unknown or malformed referral code is ignored, not an error — the person
    // signing up didn't type it and can't fix it.
    $referredBy = null;
    $refCode = strtoupper(trim($refCode));
    if ($refCode !== '' && preg_match('/^[A-Z0-9]{4,16}$/', $refCode)) {
        $stmt = $db->prepare('SELECT id FROM users WHERE referral_code = ?');
        $stmt->execute([$refCode]);
        $id = $stmt->fetchColumn();
        if ($id !== false) $referredBy = (int) $id;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Retry only on a referral-code collision; a duplicate email here means two
    // sign-ups raced past the check above, and the UNIQUE index settles it.
    for ($try = 0; $try < 5; $try++) {
        try {
            $db->prepare('INSERT INTO users (name, email, password_hash, credits, referral_code, referred_by, created_at)
                          VALUES (?, ?, ?, 0, ?, ?, ?)')
               ->execute([$name, $email, $hash, sleek_new_referral_code(), $referredBy, time()]);

            $userId = (int) $db->lastInsertId();
            sleek_login_user($userId);

            // Welcome email, sent once the dashboard is already on its way to them.
            sleek_after_response(function () use ($userId): void {
                require_once __DIR__ . '/emails.php';
                sleek_send_welcome_email($userId);
            });
            require_once __DIR__ . '/telegram.php';
            sleek_alert('signup', 'New registration', [
                'Name'        => $name,
                'Email'       => $email,
                'Referred by' => $referredBy ? sleek_alert_user($referredBy)['name'] : '',
            ], 'admin-user?id=' . $userId);
            return ['ok' => true, 'errors' => []];
        } catch (PDOException $e) {
            if (!sleek_is_duplicate($e)) throw $e;
            if (stripos($e->getMessage(), 'email') !== false) {
                return ['ok' => false, 'errors' => ['email' => 'An account with that email already exists. Log in instead?']];
            }
            // referral_code collision — loop and draw a new one
        }
    }

    throw new RuntimeException('Could not allocate a unique referral code.');
}

/** 8 characters, no 0/O/1/I so a code read aloud or retyped can't be misread. */
function sleek_new_referral_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}

/* ============================================================ password reset */

/**
 * Start a reset. Deliberately returns nothing: the form must answer identically
 * whether or not the email has an account, or it becomes a way to test addresses.
 */
function sleek_request_password_reset(string $email): void
{
    require_once __DIR__ . '/mailer.php';

    $email = sleek_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    // Per-IP cap, so one client can't use the form to flood many inboxes. Shares the
    // login_attempts table and limits; here every request counts, not just failures.
    $ipKey = hash('sha256', 'reset|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    if (sleek_lockout_remaining($ipKey) > 0) return;
    sleek_record_failure($ipKey);

    $db   = sleek_db();
    $auth = sleek_config('auth');

    $stmt = $db->prepare('SELECT id, name, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return;

    // Per-account cooldown: one email a minute, however many IPs ask for it.
    $stmt = $db->prepare('SELECT MAX(created_at) FROM password_resets WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    if ((int) $stmt->fetchColumn() > time() - $auth['reset_cooldown_seconds']) return;

    $link = sleek_issue_reset_link((int) $user['id'], (int) $auth['reset_ttl_seconds']);
    [$html, $text] = sleek_reset_email($user['name'], $link, intdiv($auth['reset_ttl_seconds'], 60));

    sleek_send_mail($user['email'], $user['name'], 'Reset your StreamLive AI password', $html, $text);
    require_once __DIR__ . '/telegram.php';
    sleek_alert('reset', 'Password reset requested', ['Account' => $user['name'] . ' <' . $user['email'] . '>'], 'admin-user?id=' . $user['id']);
}

/**
 * Make a fresh one-time password link for an account, valid $ttl seconds, and
 * return it. Any older link for the account stops working (newest wins). Only the
 * hash is stored, so a leaked database can't be turned into reset links.
 */
function sleek_issue_reset_link(int $userId, int $ttl): string
{
    $db    = sleek_db();
    $token = bin2hex(random_bytes(32));

    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $db->prepare('INSERT INTO password_resets (user_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
       ->execute([$userId, hash('sha256', $token), time(), time() + $ttl]);

    return sleek_url('reset-password?token=' . $token);
}

/** The account a reset token belongs to, if the token is live. */
function sleek_find_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $stmt = sleek_db()->prepare(
        'SELECT r.user_id, u.email FROM password_resets r
         JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ? AND r.expires_at > ?'
    );
    $stmt->execute([hash('sha256', $token), time()]);
    return $stmt->fetch() ?: null;
}

/**
 * @return array{ok: bool, errors: array<string,string>}
 */
function sleek_complete_reset(string $token, string $password, string $confirm): array
{
    $expired = ['ok' => false, 'errors' => ['token' => 'This reset link has expired or has already been used.']];

    $reset = sleek_find_reset($token);
    if ($reset === null) return $expired;

    if (($err = sleek_password_error($password)) !== null) {
        return ['ok' => false, 'errors' => ['password' => $err]];
    }
    if ($password !== $confirm) {
        return ['ok' => false, 'errors' => ['password_confirmation' => 'The two passwords don\'t match.']];
    }

    $db = sleek_db();
    $db->beginTransaction();

    // Claim the token by deleting it. Exactly one of two simultaneous submits of the
    // same link gets rowCount 1, so a link can never be spent twice.
    $claim = $db->prepare('DELETE FROM password_resets WHERE token_hash = ? AND expires_at > ?');
    $claim->execute([hash('sha256', $token), time()]);
    if ($claim->rowCount() !== 1) {
        $db->rollBack();
        return $expired;
    }

    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
       ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$reset['user_id']]);
    $db->commit();

    // Whoever just proved they own the inbox shouldn't stay locked out.
    sleek_clear_attempts(sleek_throttle_key($reset['email']));
    sleek_login_user((int) $reset['user_id']);

    return ['ok' => true, 'errors' => []];
}

/** @return array{0: string, 1: string} HTML body and plain-text alternative. */
function sleek_reset_email(string $name, string $link, int $minutes): array
{
    $first = e(explode(' ', trim($name))[0]);
    $url   = e($link);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#14171C;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#14171C;padding:40px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#1C2027;border:1px solid #2A2F38;border-radius:12px;">
        <tr><td style="padding:32px 32px 8px;font-family:Georgia,serif;font-size:20px;font-weight:600;color:#F5F6F7;">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#F2B705;margin-right:8px;vertical-align:middle;"></span>StreamLive AI
        </td></tr>
        <tr><td style="padding:16px 32px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#F5F6F7;">
          Hi {$first},
        </td></tr>
        <tr><td style="padding:8px 32px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#9AA3AF;">
          Someone asked to reset the password on your StreamLive AI account. If that was you, choose a new one below.
        </td></tr>
        <tr><td style="padding:28px 32px;">
          <a href="{$url}" style="display:inline-block;background:#F2B705;color:#14171C;font-family:Arial,sans-serif;font-size:15px;font-weight:600;text-decoration:none;padding:12px 24px;border-radius:8px;">Reset password</a>
        </td></tr>
        <tr><td style="padding:0 32px;font-family:Arial,sans-serif;font-size:13px;line-height:1.6;color:#9AA3AF;">
          The link works once and expires in {$minutes} minutes. If you didn't ask for this, ignore this email — your password won't change.
        </td></tr>
        <tr><td style="padding:20px 32px 32px;font-family:Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B727E;word-break:break-all;">
          Button not working? Paste this into your browser:<br><a href="{$url}" style="color:#F2B705;">{$url}</a>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $text = "Hi " . explode(' ', trim($name))[0] . ",\n\n"
          . "Someone asked to reset the password on your StreamLive AI account. If that was you, open this link to choose a new one:\n\n"
          . $link . "\n\n"
          . "The link works once and expires in {$minutes} minutes. If you didn't ask for this, ignore this email — your password won't change.\n\n"
          . "— StreamLive AI\n";

    return [$html, $text];
}
