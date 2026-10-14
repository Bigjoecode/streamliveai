<?php
/**
 * SleekAI — account management for the super admin (/admin → Accounts and
 * /admin-user). Every function validates, then returns field => error (empty on
 * success). Credits only ever move through the ledger, so a balance edited here
 * still has a history that explains it.
 *
 * Guard rails: nobody can be made super admin from here (that's /admin-setup, or a
 * transfer), the super admin's role can't be changed, and the super admin — or the
 * admin doing the deleting — can't be deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/credits.php';

const SLEEK_ADMIN_ROLES = ['user' => 'User', 'admin' => 'Admin'];

function sleek_admin_find_user(int $id): ?array
{
    $stmt = sleek_db()->prepare('SELECT id, name, email, credits, role, referral_code, referred_by, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * One page of accounts, newest first, optionally filtered by name/email.
 * @return array{rows: array, total: int, pages: int, page: int}
 */
function sleek_admin_list_users(string $q, int $page, int $perPage = 25): array
{
    $db    = sleek_db();
    $where = '';
    $args  = [];
    $q     = trim($q);
    if ($q !== '') {
        // '!' as the LIKE escape: it means the same on MySQL and SQLite; backslash doesn't.
        $where = "WHERE name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!'";
        $like  = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $args  = [$like, $like];
    }
    $count = $db->prepare("SELECT COUNT(*) FROM users {$where}");
    $count->execute($args);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);

    $stmt = $db->prepare("SELECT id, name, email, credits, role, created_at FROM users {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($args);
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** Shared checks for create/update. */
function sleek_admin_user_errors(string $name, string $email, string $role, ?int $exceptId): array
{
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) $errors['name'] = 'Enter a name (up to 100 characters).';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $stmt = sleek_db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $other = $stmt->fetchColumn();
        if ($other !== false && (int) $other !== $exceptId) $errors['email'] = 'Another account already uses that email.';
    }
    if (!isset(SLEEK_ADMIN_ROLES[$role])) $errors['role'] = 'Pick a role.';
    return $errors;
}

/**
 * Create an account. Blank password: the account gets an unusable random one and
 * the welcome email carries a set-password link instead.
 *
 * @return array{errors: array, id: ?int}
 */
function sleek_admin_create_user(string $name, string $email, string $password, string $role, int $credits, bool $sendWelcome): array
{
    $name  = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    $email = sleek_normalize_email($email);

    $errors = sleek_admin_user_errors($name, $email, $role, null);
    if ($password !== '' && ($pw = sleek_password_error($password)) !== null) $errors['password'] = $pw;
    if ($credits < 0 || $credits > 10000000) $errors['credits'] = 'Starting credits must be between 0 and 10,000,000.';
    if ($errors) return ['errors' => $errors, 'id' => null];

    $db   = sleek_db();
    $hash = password_hash($password !== '' ? $password : bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $id = null;
    for ($try = 0; $try < 5 && $id === null; $try++) {
        try {
            $db->prepare('INSERT INTO users (name, email, password_hash, credits, referral_code, role, created_at) VALUES (?, ?, ?, 0, ?, ?, ?)')
               ->execute([$name, $email, $hash, sleek_new_referral_code(), $role, time()]);
            $id = (int) $db->lastInsertId();
        } catch (PDOException $e) {
            if (!sleek_is_duplicate($e)) throw $e;
            if (stripos($e->getMessage(), 'email') !== false) return ['errors' => ['email' => 'Another account already uses that email.'], 'id' => null];
        }
    }
    if ($id === null) throw new RuntimeException('Could not allocate a unique referral code.');

    if ($credits > 0) sleek_credit($id, $credits, 'admin_grant', 'create:' . $id, 'Starting credits (added by admin)');

    if ($sendWelcome) {
        require_once __DIR__ . '/emails.php';
        $ttl  = 72 * 3600;   // longer than a reset link: they may not check mail today
        $link = $password === '' ? sleek_issue_reset_link($id, $ttl) : null;
        sleek_send_welcome_email($id, $link, intdiv($ttl, 60));
    }
    return ['errors' => [], 'id' => $id];
}

/** Edit name, email, role, and optionally set a new password. */
function sleek_admin_update_user(int $id, string $name, string $email, string $role, string $newPassword): array
{
    $user = sleek_admin_find_user($id);
    if (!$user) return ['form' => 'That account no longer exists.'];

    $name  = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    $email = sleek_normalize_email($email);
    $isSuper = $user['role'] === 'super_admin';
    if ($isSuper) $role = 'user';   // placeholder for validation; the stored role stays

    $errors = sleek_admin_user_errors($name, $email, $role, $id);
    if ($newPassword !== '' && ($pw = sleek_password_error($newPassword)) !== null) $errors['password'] = $pw;
    if ($errors) return $errors;

    $db = sleek_db();
    if ($isSuper) {
        $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]);
    } else {
        $db->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?')->execute([$name, $email, $role, $id]);
    }
    if ($newPassword !== '') {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
        $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$id]);   // old links die with the old password
    }
    return [];
}

/** Add (positive) or remove (negative) credits, with a reason kept in the ledger. */
function sleek_admin_adjust_credits(int $id, int $delta, string $reason, array $admin): array
{
    $reason = trim($reason);
    if ($delta === 0 || abs($delta) > 10000000) return ['delta' => 'Enter a non-zero amount, e.g. 500 or -200.'];
    if ($reason === '' || mb_strlen($reason) > 120) return ['reason' => 'Give a short reason (up to 120 characters) — it shows in their credit history.'];
    if (!sleek_admin_find_user($id)) return ['form' => 'That account no longer exists.'];

    $ref  = 'adj:' . bin2hex(random_bytes(8));
    $note = $reason . ' (admin)';
    try {
        $delta > 0
            ? sleek_credit($id, $delta, 'admin_adjust', $ref, $note)
            : sleek_debit($id, -$delta, 'admin_adjust', $ref, $note);
    } catch (SleekInsufficientCredits) {
        return ['delta' => 'They don\'t have that many credits to remove.'];
    }
    sleek_payments_log_admin("CREDITS #{$id} {$delta} by admin #{$admin['id']}: {$reason}");
    return [];
}

/** Delete an account and everything hanging off it (payments, jobs, ledger cascade). */
function sleek_admin_delete_user(int $id, array $admin): array
{
    $user = sleek_admin_find_user($id);
    if (!$user) return ['form' => 'That account no longer exists.'];
    if ($user['role'] === 'super_admin') return ['form' => 'The super admin account can\'t be deleted.'];
    if ((int) $user['id'] === (int) $admin['id']) return ['form' => 'You can\'t delete the account you\'re signed in with.'];

    sleek_db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    sleek_payments_log_admin("DELETED account #{$id} <{$user['email']}> by admin #{$admin['id']}");
    return [];
}

/** Recent ledger entries, payments and job counts for the account page. */
function sleek_admin_user_activity(int $id): array
{
    $db = sleek_db();
    $ledger = $db->prepare('SELECT delta, balance_after, kind, description, created_at FROM credit_ledger WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $ledger->execute([$id]);
    $payments = $db->prepare('SELECT provider, reference, pack_key, credits, amount_kobo, currency, status, created_at FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $payments->execute([$id]);
    $jobs = $db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done FROM jobs WHERE user_id = ?");
    $jobs->execute([$id]);
    $refs = $db->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
    $refs->execute([$id]);
    return [
        'ledger'    => $ledger->fetchAll(),
        'payments'  => $payments->fetchAll(),
        'jobs'      => $jobs->fetch() ?: ['total' => 0, 'done' => 0],
        'referrals' => (int) $refs->fetchColumn(),
    ];
}

/** Admin actions on money and accounts go in storage/admin.log too. */
function sleek_payments_log_admin(string $line): void
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/admin.log', '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND | LOCK_EX);
}
