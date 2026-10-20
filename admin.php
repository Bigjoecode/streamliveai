<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/catalog.php';
require __DIR__ . '/inc/contact.php';
require_once __DIR__ . '/inc/mailer.php';
require __DIR__ . '/inc/admin-users.php';
require __DIR__ . '/inc/admin-layout.php';
require_once __DIR__ . '/inc/telegram.php';
$admin = require_super_admin();

// Which admin page this request renders. The admin-*.php entry files set it;
// /admin itself is the Overview. Every page posts back to itself and shares the
// handlers below.
$adminView = $adminView ?? 'overview';
if (!isset(SLEEK_ADMIN_NAV[$adminView])) $adminView = 'overview';

/** storage/provider-secrets.json — private values layered over the config files. */
function sleek_admin_secrets_read(): array
{
    $f = __DIR__ . '/storage/provider-secrets.json';
    $saved = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    return is_array($saved) ? $saved : [];
}

function sleek_admin_secrets_write(array $saved): bool
{
    $f = __DIR__ . '/storage/provider-secrets.json';
    $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($f, $json . PHP_EOL, LOCK_EX) === false) return false;
    @chmod($f, 0600);
    return true;
}

/** Secrets travel only over HTTPS (or from the machine itself, for local setup). */
function sleek_admin_secure_channel(): bool
{
    return sleek_https() || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** Form field => [config section, key, label]. Every secret the admin can set. */
function sleek_admin_key_fields(): array
{
    return [
        'decart_key'          => ['decart',      'api_key',      'Decart key'],
        'elevenlabs_key'      => ['elevenlabs',  'api_key',      'ElevenLabs key'],
        'fal_key'             => ['fal',         'api_key',      'fal.ai key'],
        'flutterwave_key'     => ['flutterwave', 'secret_key',   'Flutterwave secret key'],
        'flutterwave_hash'    => ['flutterwave', 'webhook_hash', 'Flutterwave webhook secret hash'],
        'nowpayments_key'     => ['nowpayments', 'api_key',      'NOWPayments API key'],
        'nowpayments_ipn'     => ['nowpayments', 'ipn_secret',   'NOWPayments IPN secret'],
    ];
}
$db = sleek_db();
$notice = null;
$noticeBad = false;
$createErrors = [];
$tgFound = null;   // groups found by "Find my group", shown once
$createOld = ['name' => '', 'email' => '', 'role' => 'user', 'credits' => '0', 'welcome' => true];

if (sleek_is_post()) {
    csrf_verify();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'create_user') {
        $str = fn(string $k) => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        $createOld = ['name' => $str('name'), 'email' => $str('email'), 'role' => $str('role'), 'credits' => trim($str('credits')), 'welcome' => isset($_POST['welcome'])];
        $credits = $createOld['credits'] === '' ? 0 : filter_var($createOld['credits'], FILTER_VALIDATE_INT);
        if ($credits === false) {
            $createErrors = ['credits' => 'Starting credits must be a whole number.'];
        } else {
            $res = sleek_admin_create_user($createOld['name'], $createOld['email'], $str('password'), $createOld['role'], (int) $credits, $createOld['welcome']);
            if (!$res['errors']) sleek_redirect('admin-user?id=' . $res['id'] . '&done=created');
            $createErrors = $res['errors'];
        }
        $notice = 'Account not created — check the form below.';
        $noticeBad = true;
    } elseif ($action === 'promote_initial') {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $token = is_string($_POST['setup_token'] ?? null) ? $_POST['setup_token'] : '';
        $notice = sleek_claim_initial_super_admin($email, $token) ? 'Initial super admin verified and assigned.' : 'Email and setup token did not match, or the account does not exist.';
    } elseif ($action === 'set_role') {
        $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
        $notice = $id && sleek_update_user_role((int) $id, $role) ? 'User role updated.' : 'Could not update this role.';
    } elseif ($action === 'save_provider_keys') {
        if (!sleek_admin_secure_channel()) {
            $notice = 'Provider keys can only be saved over HTTPS or from this computer.';
        } else {
            $secretFile = __DIR__ . '/storage/provider-secrets.json';
            $saved = is_file($secretFile) ? json_decode((string) file_get_contents($secretFile), true) : [];
            if (!is_array($saved)) $saved = [];
            foreach (sleek_admin_key_fields() as $field => [$provider, $keyName]) {
                $newValue = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
                $clear = isset($_POST['clear_' . $field]);
                if ($clear) $saved[$provider][$keyName] = '';
                elseif ($newValue !== '') {
                    if (strlen($newValue) > 4096 || str_contains($newValue, "\0")) {
                        $notice = 'A key was too long or contained invalid characters; no changes were saved.';
                        break;
                    }
                    $saved[$provider][$keyName] = $newValue;
                }
            }
            if ($notice === null) {
                $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $written = $json === false ? false : file_put_contents($secretFile, $json . PHP_EOL, LOCK_EX);
                if ($written === false) $notice = 'Could not write the private provider settings file.';
                else {
                    @chmod($secretFile, 0600);
                    sleek_redirect('admin-providers?keys=saved');
                }
            }
        }
    } elseif ($action === 'save_mail') {
        $host = trim(is_string($_POST['mail_host'] ?? null) ? $_POST['mail_host'] : '');
        $port = filter_var($_POST['mail_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $enc = is_string($_POST['mail_encryption'] ?? null) ? $_POST['mail_encryption'] : '';
        $user = trim(is_string($_POST['mail_username'] ?? null) ? $_POST['mail_username'] : '');
        $pass = is_string($_POST['mail_password'] ?? null) ? $_POST['mail_password'] : '';
        $from = trim(is_string($_POST['mail_from_address'] ?? null) ? $_POST['mail_from_address'] : '');
        $fromName = trim(is_string($_POST['mail_from_name'] ?? null) ? $_POST['mail_from_name'] : '');
        if (!sleek_admin_secure_channel()) {
            $notice = 'Email settings can only be saved over HTTPS.';
        } elseif (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
            $notice = 'Email not saved: enter the SMTP server name, e.g. mail.yourdomain.com.';
        } elseif ($port === false) {
            $notice = 'Email not saved: the port must be a number (465 or 587 usually).';
        } elseif (!in_array($enc, ['ssl', 'tls', ''], true)) {
            $notice = 'Email not saved: pick an encryption option.';
        } elseif ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Email not saved: the sender address is not a valid email address.';
        } elseif (mb_strlen($fromName) > 80 || strlen($pass) > 1024 || str_contains($pass . $user, "\0")) {
            $notice = 'Email not saved: a value was too long or contained invalid characters.';
        } else {
            $saved = sleek_admin_secrets_read();
            $mail = is_array($saved['mail'] ?? null) ? $saved['mail'] : [];
            $mail = array_replace($mail, [
                'driver' => 'smtp', 'host' => $host, 'port' => (int) $port, 'encryption' => $enc,
                'username' => $user, 'from_address' => $from,
                'from_name' => $fromName !== '' ? $fromName : (string) sleek_config('brand_name'),
            ]);
            // Blank password keeps the saved one; "clear" removes it.
            if (isset($_POST['mail_password_clear'])) $mail['password'] = '';
            elseif ($pass !== '') $mail['password'] = $pass;
            $saved['mail'] = $mail;
            if (sleek_admin_secrets_write($saved)) sleek_redirect('admin-email?saved=mail');
            $notice = 'Could not write the private settings file.';
        }
    } elseif ($action === 'test_mail') {
        $to = trim(is_string($_POST['test_to'] ?? null) ? $_POST['test_to'] : '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Test not sent: enter a valid address to send it to.';
        } else {
            $brand = (string) sleek_config('brand_name');
            $sent = sleek_send_mail($to, $to, "{$brand} test email",
                '<p>This is a test from ' . e($brand) . '. Email is working — password-reset links will arrive like this one.</p>',
                "This is a test from {$brand}. Email is working — password-reset links will arrive like this one.");
            $notice = $sent
                ? "Test email sent to {$to}. If it isn't in the inbox within a minute, check spam."
                : 'Test email failed: ' . (sleek_mail_last_error() ?: 'see storage/mail-error.log');
        }
    } elseif ($action === 'save_telegram' || $action === 'tg_discover' || $action === 'tg_test') {
        $tokenIn = trim(is_string($_POST['tg_token'] ?? null) ? $_POST['tg_token'] : '');
        $chatIn  = trim(is_string($_POST['tg_chat'] ?? null) ? $_POST['tg_chat'] : '');
        $token   = $tokenIn !== '' ? $tokenIn : sleek_telegram_token();
        if ($tokenIn !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,80}$/', $tokenIn)) {
            $notice = 'That doesn\'t look like a bot token. Copy the whole token @BotFather gave you, e.g. 123456789:AAE…';
        } elseif ($chatIn !== '' && !preg_match('/^(-?\d{5,20}|@[A-Za-z0-9_]{5,64})$/', $chatIn)) {
            $notice = 'The group ID should be a number like -1001234567890 (use "Find my group"), or @channelname for a public channel.';
        } elseif (!sleek_admin_secure_channel()) {
            $notice = 'Telegram settings can only be saved over HTTPS.';
        } elseif ($action === 'tg_discover') {
            if ($token === '') {
                $notice = 'Paste the bot token first.';
            } else {
                [$tgFound, $err] = sleek_telegram_discover($token);
                if ($err !== '') { $notice = 'Telegram: ' . $err; $tgFound = null; }
                elseif (!$tgFound) $notice = 'No groups found yet. Add the bot to your group, send any message in the group (e.g. "hi"), then press Find my group again.';
                else { $notice = 'Found ' . count($tgFound) . ' group' . (count($tgFound) === 1 ? '' : 's') . ' — pick one below and save.'; $noticeBad = false; }
            }
        } else {
            // Save (and, for "test", save then send a sample alert).
            [$bot, $err] = $token !== '' ? sleek_telegram_whoami($token) : [null, ''];
            if ($token !== '' && $bot === null) {
                $notice = 'Telegram rejected the bot token: ' . $err;
            } else {
                $saved = sleek_admin_secrets_read();
                if (isset($_POST['tg_token_clear'])) $saved['telegram']['bot_token'] = '';
                elseif ($tokenIn !== '') $saved['telegram']['bot_token'] = $tokenIn;
                if (!sleek_admin_secrets_write($saved)) {
                    $notice = 'Could not write the private settings file.';
                } else {
                    sleek_setting_set('telegram_chat_id', $chatIn);
                    $on = is_array($_POST['tg_alerts'] ?? null) ? $_POST['tg_alerts'] : [];
                    sleek_setting_set('telegram_alerts', array_map(fn($k) => isset($on[$k]), array_combine(array_keys(SLEEK_ALERTS), array_keys(SLEEK_ALERTS))));
                    if ($action === 'tg_test') {
                        $useToken = isset($_POST['tg_token_clear']) ? '' : $token;
                        [$ok, $err] = sleek_telegram_send(sleek_alert_format('admin', 'Test alert', [
                            'From'   => (string) sleek_config('brand_name') . ' admin',
                            'Sent by' => $admin['email'],
                            'Status' => 'Telegram alerts are working',
                        ], 'admin'), $chatIn, $useToken);
                        $notice = $ok ? 'Test alert sent — check the group.' : 'Saved, but the test failed: ' . $err;
                        $noticeBad = !$ok;
                    } else {
                        sleek_redirect('admin-community?saved=telegram' . ($bot ? '&bot=' . rawurlencode($bot) : ''));
                    }
                }
            }
        }
    } elseif ($action === 'save_live') {
        require_once __DIR__ . '/inc/settings.php';
        $speed = ($_POST['live_speed'] ?? '') === 'fast' ? 'fast' : 'standard';
        sleek_setting_set('live_speed', $speed);
        sleek_redirect('admin-providers?saved=live');
    } elseif ($action === 'save_packs') {
        // Validate every pack first; save all or nothing.
        $in = is_array($_POST['packs'] ?? null) ? $_POST['packs'] : [];
        $packs = [];
        $problems = [];
        foreach (sleek_pack_defaults() as $key => $default) {
            $row = is_array($in[$key] ?? null) ? $in[$key] : [];
            $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
            $credits = filter_var($row['credits'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
            $priceRaw = trim(is_string($row['price'] ?? null) ? $row['price'] : '');
            $badge = trim(is_string($row['badge'] ?? null) ? $row['badge'] : '');
            $lines = preg_split('/\R/', is_string($row['features'] ?? null) ? $row['features'] : '') ?: [];
            $features = array_values(array_filter(array_map('trim', $lines), 'strlen'));
            $label = $name !== '' ? $name : ucfirst($key);
            if ($name === '' || mb_strlen($name) > 40) $problems[] = "{$label}: name is required (up to 40 characters).";
            if ($credits === false) $problems[] = "{$label}: credits must be a whole number of at least 1.";
            if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $priceRaw) || (float) $priceRaw < 1) $problems[] = "{$label}: price must be at least \$1, with up to 2 decimals (e.g. 12 or 12.50).";
            if (mb_strlen($badge) > 30) $problems[] = "{$label}: badge is limited to 30 characters.";
            if (count($features) > 8) $problems[] = "{$label}: at most 8 feature lines.";
            foreach ($features as $f) if (mb_strlen($f) > 80) { $problems[] = "{$label}: each feature line is limited to 80 characters."; break; }
            $packs[$key] = ['name' => $name, 'credits' => (int) $credits, 'price' => (float) $priceRaw, 'badge' => $badge, 'features' => $features];
        }
        $rateRaw = trim(is_string($_POST['ngn_rate'] ?? null) ? $_POST['ngn_rate'] : '');
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $rateRaw) || (float) $rateRaw < 1) $problems[] = 'Exchange rate: enter naira per $1, e.g. 1550.';
        if ($problems) {
            $notice = 'Prices not saved. ' . implode(' ', $problems);
        } else {
            require_once __DIR__ . '/inc/settings.php';
            sleek_setting_set('packs', $packs);
            sleek_setting_set('ngn_rate', (float) $rateRaw);
            sleek_redirect('admin-pricing?saved=packs');
        }
    } elseif ($action === 'save_site') {
        require_once __DIR__ . '/inc/settings.php';
        $telegram = sleek_normalize_telegram(is_string($_POST['telegram_url'] ?? null) ? $_POST['telegram_url'] : '');
        $notify = trim(is_string($_POST['contact_notify_email'] ?? null) ? $_POST['contact_notify_email'] : '');
        if ($telegram === null) {
            $notice = 'Telegram link not saved: use a t.me link (https://t.me/yourchannel) or @yourchannel.';
        } elseif ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Notification email not saved: that is not a valid email address.';
        } else {
            sleek_setting_set('telegram_url', $telegram);
            sleek_setting_set('contact_notify_email', $notify);
            sleek_redirect('admin-community?saved=site');
        }
    } elseif ($action === 'message_read' || $action === 'message_unread' || $action === 'message_delete') {
        $id = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            if ($action === 'message_delete') $db->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);
            else $db->prepare('UPDATE contact_messages SET read_at = ? WHERE id = ?')->execute([$action === 'message_read' ? time() : null, $id]);
        }
        sleek_redirect('admin-messages');
    }
}

if (sleek_is_post() && $notice !== null && !$noticeBad) {
    $noticeBad = !preg_match('/^(Test email sent|Test alert sent|Found \d+ group|User role updated|Initial super admin verified)/', $notice);
}
if (($_GET['done'] ?? '') === 'deleted') $notice = 'Account deleted.';
if ($notice === null && ($flash = sleek_flash_take())) {
    $notice = $flash['message'];
    $noticeBad = $flash['type'] === 'error';
}
if (($_GET['keys'] ?? '') === 'saved') $notice = 'Provider settings saved. Existing values stay hidden.';
if (($_GET['saved'] ?? '') === 'packs') $notice = 'Prices saved. The pricing page, home page and billing page show them now.';
if (($_GET['saved'] ?? '') === 'site') $notice = 'Community and notification settings saved.';
if (($_GET['saved'] ?? '') === 'telegram') $notice = 'Telegram alerts saved' . (is_string($_GET['bot'] ?? null) && preg_match('/^@\w{3,64}$/', $_GET['bot']) ? ' — bot ' . $_GET['bot'] . ' is connected' : '') . '. Use "Send test alert" to check the group.';
if (($_GET['saved'] ?? '') === 'live') $notice = 'Livestream speed saved. New sessions use it.';
if (($_GET['saved'] ?? '') === 'mail') $notice = 'Email settings saved. Send a test below to confirm they work.';
$mailCfg = sleek_config('mail');
$mailReady = sleek_mail_configured();

require_once __DIR__ . '/inc/settings.php';
$packs = sleek_packs();
$telegramUrl = sleek_telegram_url();
$notifyEmail = (string) sleek_setting('contact_notify_email', '');
$messages = $db->query('SELECT * FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 100')->fetchAll();
$unread = sleek_contact_unread_count();

$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$providers = [
    'Decart' => sleek_config('decart')['api_key'] !== '',
    'ElevenLabs' => sleek_config('elevenlabs')['api_key'] !== '',
    'fal.ai' => sleek_config('fal')['api_key'] !== '',
    'Flutterwave' => sleek_config('flutterwave')['secret_key'] !== '' && sleek_config('flutterwave')['webhook_hash'] !== '',
    'NOWPayments (crypto)' => sleek_config('nowpayments')['api_key'] !== '' && sleek_config('nowpayments')['ipn_secret'] !== '',
];

if ($adminView === 'accounts') {
    $list = sleek_admin_list_users($q, (int) ($_GET['p'] ?? 1));
    $pageUrl = fn(int $n) => 'admin-accounts?' . http_build_query(array_filter(['q' => $q, 'p' => $n > 1 ? $n : null]));
}

if ($adminView === 'overview') {
    $now = time();
    $count = fn(string $sql, array $args = []) => (function () use ($db, $sql, $args) {
        $st = $db->prepare($sql); $st->execute($args); return (int) $st->fetchColumn();
    })();
    $ov = [
        'users'   => $count('SELECT COUNT(*) FROM users'),
        'new7'    => $count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$now - 7 * 86400]),
        'paid'    => $count("SELECT COUNT(*) FROM payments WHERE status = 'success'"),
        'paid30'  => $count("SELECT COUNT(*) FROM payments WHERE status = 'success' AND paid_at >= ?", [$now - 30 * 86400]),
        'jobs'    => $count('SELECT COUNT(*) FROM jobs'),
        'jobs7'   => $count('SELECT COUNT(*) FROM jobs WHERE created_at >= ?', [$now - 7 * 86400]),
        'credits' => $count('SELECT COALESCE(SUM(credits), 0) FROM users'),
    ];
    // Revenue per currency (older rows may be naira); shown as "$X + ₦Y".
    $revenue = function (int $since) use ($db): string {
        $st = $db->prepare("SELECT currency, SUM(amount_kobo) AS total FROM payments WHERE status = 'success' AND paid_at >= ? GROUP BY currency");
        $st->execute([$since]);
        $parts = array_map(fn($r) => sleek_money((int) $r['total'], (string) $r['currency']), $st->fetchAll());
        return $parts ? implode(' + ', $parts) : sleek_money(0, sleek_catalog_currency());
    };
    $ov['revenue']   = $revenue(0);
    $ov['revenue30'] = $revenue($now - 30 * 86400);

    // Sign-ups per day for the last 14 days, bucketed in PHP so it's the same on MySQL and SQLite.
    $days = [];
    for ($d = 13; $d >= 0; $d--) $days[date('Y-m-d', strtotime("-{$d} days", $now))] = 0;
    $st = $db->prepare('SELECT created_at FROM users WHERE created_at >= ?');
    $st->execute([strtotime(array_key_first($days))]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $k = date('Y-m-d', (int) $t);
        if (isset($days[$k])) $days[$k]++;
    }
    $chartMax  = max(1, ...array_values($days));
    $chartTop  = $chartMax <= 4 ? 4 : (int) (ceil($chartMax / 5) * 5);   // a clean gridline value
    $signups14 = array_sum($days);

    $recentUsers = $db->query('SELECT id, name, email, created_at FROM users ORDER BY id DESC LIMIT 6')->fetchAll();
    $recentPayments = $db->query('SELECT p.user_id, p.pack_key, p.amount_kobo, p.currency, p.status, p.provider, p.created_at, u.name
                                  FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 6')->fetchAll();
    $unreadMessages = $db->query('SELECT id, name, message, created_at FROM contact_messages WHERE read_at IS NULL ORDER BY id DESC LIMIT 3')->fetchAll();

    $packsSaved = sleek_setting('packs', null) !== null;
    $checklist = [
        ['Email (SMTP) is set up', $mailReady, 'admin-email', 'Set up'],
        ['Naira payments (Flutterwave) connected', $providers['Flutterwave'], 'admin-providers', 'Connect'],
        ['Crypto payments (NOWPayments) connected', $providers['NOWPayments (crypto)'], 'admin-providers', 'Connect'],
        ['Telegram channel added', $telegramUrl !== '', 'admin-community', 'Add'],
        ['Telegram admin alerts connected', sleek_telegram_ready(), 'admin-community', 'Connect'],
        ['Pack prices reviewed', $packsSaved, 'admin-pricing', 'Review'],
    ];
    $todo = count(array_filter($checklist, fn($c) => !$c[1]));
}

$ago = function (int $t): string {
    $d = time() - $t;
    if ($d < 60) return 'just now';
    if ($d < 3600) return intdiv($d, 60) . 'm ago';
    if ($d < 86400) return intdiv($d, 3600) . 'h ago';
    if ($d < 86400 * 30) return intdiv($d, 86400) . 'd ago';
    return date('Y-m-d', $t);
};

$titles = ['overview' => 'Overview', 'accounts' => 'Accounts', 'messages' => 'Messages', 'pricing' => 'Pricing',
           'community' => 'Community & notifications', 'email' => 'Email (SMTP)', 'providers' => 'Provider connections'];
admin_layout_open($titles[$adminView], $admin, $notice, $noticeBad, $adminView);
?>
<?php if (!$mailReady && $adminView !== 'email'): ?><p class="notice bad" role="alert">Email isn't set up, so welcome, password-reset and notification emails aren't being sent. <a href="admin-email">Set up email</a>.</p><?php endif; ?>

<?php if ($adminView === 'overview'): ?>
<p class="muted" style="margin-top:4px">Good to see you, <?= e(explode(' ', trim((string) $admin['name']))[0]) ?>. Here's how <?= e((string) sleek_config('brand_name')) ?> is doing.</p>

<section class="stats" aria-label="Key numbers">
    <a class="stat" href="admin-accounts"><div class="label">Users</div><div class="value"><?= number_format($ov['users']) ?></div><div class="hint">+<?= number_format($ov['new7']) ?> this week</div></a>
    <div class="stat"><div class="label">Revenue, 30 days</div><div class="value"><?= $ov['revenue30'] ?></div><div class="hint"><?= number_format($ov['paid30']) ?> paid order<?= $ov['paid30'] === 1 ? '' : 's' ?></div></div>
    <div class="stat"><div class="label">Revenue, all time</div><div class="value"><?= $ov['revenue'] ?></div><div class="hint"><?= number_format($ov['paid']) ?> paid order<?= $ov['paid'] === 1 ? '' : 's' ?></div></div>
    <div class="stat"><div class="label">Jobs run</div><div class="value"><?= number_format($ov['jobs']) ?></div><div class="hint">+<?= number_format($ov['jobs7']) ?> this week</div></div>
    <div class="stat"><div class="label">Credits held</div><div class="value"><?= number_format($ov['credits']) ?></div><div class="hint">unspent, across users</div></div>
    <a class="stat" href="admin-messages"><div class="label">Unread messages</div><div class="value"><?= number_format($unread) ?></div><div class="hint">from the Contact page</div></a>
</section>

<div class="grid2">
    <section class="card">
        <div class="card-head"><h2>Sign-ups, last 14 days</h2><span class="muted"><?= number_format($signups14) ?> total</span></div>
        <div class="chart" id="signups">
            <div class="bars" role="img" aria-label="Daily sign-ups for the last 14 days; table follows">
                <div class="gl" style="top:0"><span><?= $chartTop ?></span></div>
                <div class="gl" style="top:50%"><span><?= $chartTop / 2 == (int) ($chartTop / 2) ? (int) ($chartTop / 2) : '' ?></span></div>
                <?php foreach ($days as $day => $n): $label = date('D j M', strtotime($day)); ?>
                    <div class="day<?= $n === 0 ? ' zero' : '' ?>" tabindex="0" data-tip="<?= e($label) ?>" data-n="<?= $n ?>"><i style="height:<?= round($n / $chartTop * 100, 1) ?>%"></i></div>
                <?php endforeach; ?>
            </div>
            <div class="axis" aria-hidden="true"><?php foreach (array_keys($days) as $day): ?><span><?= date('j', strtotime($day)) ?></span><?php endforeach; ?></div>
            <table class="sr"><caption>Sign-ups per day</caption><tbody><?php foreach ($days as $day => $n): ?><tr><th scope="row"><?= e(date('D j M', strtotime($day))) ?></th><td><?= $n ?></td></tr><?php endforeach; ?></tbody></table>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Setup</h2><span class="muted"><?= $todo === 0 ? 'All done' : $todo . ' to do' ?></span></div>
        <ul class="todo">
            <?php foreach ($checklist as [$label, $done, $href, $cta]): ?>
                <li class="<?= $done ? 'done' : 'open' ?>"><a href="<?= e($href) ?>"><span class="mark" aria-hidden="true"><?= $done ? '✓' : '' ?></span><span class="t"><?= e($label) ?></span><span class="sr"><?= $done ? '(done)' : '(not done)' ?></span><?php if (!$done): ?><span class="go"><?= e($cta) ?> →</span><?php endif; ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <div class="card-head"><h2>Newest accounts</h2><a href="admin-accounts">All accounts →</a></div>
        <?php if (!$recentUsers): ?><p class="muted">No accounts yet.</p><?php else: ?>
        <ul class="list">
            <?php foreach ($recentUsers as $u): ?>
                <li><div class="main"><a href="admin-user?id=<?= (int) $u['id'] ?>"><?= e($u['name']) ?></a><small><?= e($u['email']) ?></small></div><small class="end"><?= e($ago((int) $u['created_at'])) ?></small></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Latest payments</h2></div>
        <?php if (!$recentPayments): ?><p class="muted">No payments yet. They'll appear here as soon as someone buys a pack.</p><?php else: ?>
        <ul class="list">
            <?php foreach ($recentPayments as $pm): ?>
                <li><div class="main"><a href="admin-user?id=<?= (int) $pm['user_id'] ?>"><?= e($pm['name']) ?></a><small><?= e(sleek_pack($pm['pack_key'])['name'] ?? $pm['pack_key']) ?> · <?= e(sleek_admin_payment_method((string) $pm['provider'])) ?> · <?= e($ago((int) $pm['created_at'])) ?></small></div>
                    <div class="end"><div><?= sleek_money((int) $pm['amount_kobo'], (string) $pm['currency']) ?></div><span class="status <?= e($pm['status']) ?>"><?= e($pm['status'] === 'success' ? 'Paid' : ucfirst((string) $pm['status'])) ?></span></div></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>

<?php if ($unreadMessages): ?>
<section class="card">
    <div class="card-head"><h2>Unread messages</h2><a href="admin-messages">Open inbox →</a></div>
    <ul class="list">
        <?php foreach ($unreadMessages as $m): ?>
            <li><div class="main"><a href="admin-messages"><?= e($m['name']) ?></a><small><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $m['message']), 0, 90, '…')) ?></small></div><small class="end"><?= e($ago((int) $m['created_at'])) ?></small></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<script>
(function () {
    // Hover/focus tooltip for the sign-ups bars. The whole day column is the hit area.
    var chart = document.getElementById('signups'); if (!chart) return;
    var tip = document.createElement('div'); tip.className = 'tip'; tip.hidden = true; chart.appendChild(tip);
    function show(el) {
        var n = +el.dataset.n, r = el.getBoundingClientRect(), c = chart.getBoundingClientRect(), bar = el.firstElementChild.getBoundingClientRect();
        tip.innerHTML = '<b>' + n + '</b> sign-up' + (n === 1 ? '' : 's') + ' · ' + el.dataset.tip;
        tip.hidden = false;
        var x = r.left - c.left + r.width / 2, half = tip.offsetWidth / 2;
        tip.style.left = Math.max(half, Math.min(c.width - half, x)) + 'px';
        tip.style.top = (Math.min(bar.top, r.bottom - 2) - c.top) + 'px';
    }
    chart.querySelectorAll('.day').forEach(function (d) {
        d.addEventListener('mouseenter', function () { show(d); });
        d.addEventListener('focus', function () { show(d); });
        d.addEventListener('mouseleave', function () { tip.hidden = true; });
        d.addEventListener('blur', function () { tip.hidden = true; });
    });
})();
</script>
<?php endif; ?>

<?php if ($adminView === 'accounts'): ?>
<section class="card">
    <p class="muted" style="margin-top:0"><?= number_format($list['total']) ?> account<?= $list['total'] === 1 ? '' : 's' ?><?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?>. Open one to edit it, change credits, send a password link or delete it.</p>

    <form method="get" action="admin-accounts" class="inline" role="search">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or email" aria-label="Search accounts">
        <button class="ghost">Search</button><?php if ($q !== ''): ?><a class="btn ghost" href="admin-accounts">Clear</a><?php endif; ?>
    </form>

    <details<?= $createErrors ? ' open' : '' ?> style="margin-top:12px">
        <summary class="btn">+ Add account</summary>
        <form method="post" action="admin-accounts" class="form" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_user">
            <?php if (isset($createErrors['form'])): ?><span class="err"><?= e($createErrors['form']) ?></span><?php endif; ?>
            <div class="form-grid two">
                <label><span class="l">Name</span><input required maxlength="100" name="name" value="<?= e($createOld['name']) ?>"><?= admin_err($createErrors, 'name') ?></label>
                <label><span class="l">Email</span><input required type="email" maxlength="254" name="email" value="<?= e($createOld['email']) ?>"><?= admin_err($createErrors, 'email') ?></label>
                <label><span class="l">Password (optional)</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Blank: they set their own via email"><?= admin_err($createErrors, 'password') ?></label>
                <label><span class="l">Role</span><select name="role"><?php foreach (SLEEK_ADMIN_ROLES as $v => $l): ?><option value="<?= e($v) ?>"<?= $createOld['role'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= admin_err($createErrors, 'role') ?></label>
                <label><span class="l">Starting credits</span><input type="number" min="0" step="1" name="credits" value="<?= e($createOld['credits']) ?>"><?= admin_err($createErrors, 'credits') ?></label>
            </div>
            <label class="check"><input type="checkbox" name="welcome" value="1"<?= $createOld['welcome'] ? ' checked' : '' ?>> Send the welcome email (includes a set-password link if you left the password blank)</label>
            <div class="actions"><button>Create account</button></div>
        </form>
    </details>

    <?php if (!$list['rows']): ?>
        <p class="muted" style="margin-top:12px">No accounts found.</p>
    <?php else: ?>
    <div class="tablewrap">
        <table class="stack">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Credits</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $u): ?>
                <tr>
                    <td data-label="Name"><a href="admin-user?id=<?= (int) $u['id'] ?>"><?= e($u['name']) ?></a></td>
                    <td data-label="Email"><?= e($u['email']) ?></td>
                    <td data-label="Role"><span class="role <?= e($u['role']) ?>"><?= e($u['role'] === 'super_admin' ? 'Super admin' : ucfirst($u['role'])) ?></span></td>
                    <td data-label="Credits"><?= number_format((int) $u['credits']) ?></td>
                    <td data-label="Joined"><?= e(date('Y-m-d', (int) $u['created_at'])) ?></td>
                    <td class="full"><a class="btn ghost" href="admin-user?id=<?= (int) $u['id'] ?>" style="width:100%">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($list['pages'] > 1): ?>
        <nav class="pager" aria-label="Accounts pages">
            <?php if ($list['page'] > 1): ?><a class="btn ghost" href="<?= e($pageUrl($list['page'] - 1)) ?>">← Newer</a><?php endif; ?>
            <span class="muted">Page <?= $list['page'] ?> of <?= $list['pages'] ?></span>
            <?php if ($list['page'] < $list['pages']): ?><a class="btn ghost" href="<?= e($pageUrl($list['page'] + 1)) ?>">Older →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($adminView === 'messages'): ?>
<section class="card">
    <p class="muted" style="margin-top:0"><?= $unread ? $unread . ' unread. ' : '' ?>From the Contact page. Reply by clicking the sender's email. Latest 100 shown.</p>
    <?php if (!$messages): ?><p class="muted">No messages yet.</p><?php endif; ?>
    <?php foreach ($messages as $m): $isUnread = $m['read_at'] === null; ?>
    <article class="msg<?= $isUnread ? ' unread' : '' ?>">
        <strong><?= e($m['name']) ?></strong> · <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: your message to ' . sleek_config('brand_name')) ?>"><?= e($m['email']) ?></a>
        <small>· <?= e(date('Y-m-d H:i', (int) $m['created_at'])) ?><?php if ($m['user_id']): ?> · <a href="admin-user?id=<?= (int) $m['user_id'] ?>">account #<?= (int) $m['user_id'] ?></a><?php endif; ?></small>
        <p><?= e($m['message']) ?></p>
        <div class="actions">
            <form method="post" action="admin-messages" class="inline"><?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>"><button class="ghost" name="action" value="<?= $isUnread ? 'message_read' : 'message_unread' ?>"><?= $isUnread ? 'Mark read' : 'Mark unread' ?></button></form>
            <form method="post" action="admin-messages" class="inline" onsubmit="return confirm('Delete this message?')"><?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>"><button class="danger" name="action" value="message_delete">Delete</button></form>
        </div>
    </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($adminView === 'pricing'): ?>
<section class="card">
    <p class="muted" style="margin-top:0">Credit packs are priced in US dollars. Crypto is charged in dollars; <strong>Pay with Naira</strong> (Flutterwave: card, bank transfer, USSD) charges the dollar price × your exchange rate, rounded to the nearest ₦100. Changes show at once on the pricing page, the home page (first three packs) and Credits &amp; billing. A payment already started keeps its price. A pack with a badge (e.g. “Most popular”) is highlighted.</p>
    <form method="post" action="admin-pricing"><?= csrf_field() ?><input type="hidden" name="action" value="save_packs">
        <div class="form-grid two" style="margin-top:12px">
            <label><span class="l">Exchange rate — naira per $1</span><input required inputmode="decimal" pattern="\d{1,6}(\.\d{1,2})?" name="ngn_rate" value="<?= e(rtrim(rtrim(number_format(sleek_ngn_rate(), 2, '.', ''), '0'), '.')) ?>"><small>Update it when the dollar moves. Today: $1 = <?= sleek_money((int) round(sleek_ngn_rate() * 100), 'NGN') ?>.</small></label>
        </div>
        <div class="packs">
        <?php foreach ($packs as $key => $p): ?>
            <fieldset class="pack"><legend><?= e($key) ?></legend>
                <label><span class="l">Name</span><input required maxlength="40" name="packs[<?= e($key) ?>][name]" value="<?= e($p['name']) ?>"></label>
                <div class="form-grid two">
                    <label><span class="l">Credits</span><input required type="number" min="1" step="1" name="packs[<?= e($key) ?>][credits]" value="<?= (int) $p['credits'] ?>"></label>
                    <label><span class="l">Price (USD)</span><input required inputmode="decimal" pattern="\d{1,6}(\.\d{1,2})?" name="packs[<?= e($key) ?>][price]" value="<?= e(rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.')) ?>"></label>
                </div>
                <small>Naira price now: <?= sleek_money(sleek_pack_ngn_kobo($p), 'NGN') ?></small>
                <label><span class="l">Badge (optional)</span><input maxlength="30" name="packs[<?= e($key) ?>][badge]" value="<?= e($p['badge'] ?? '') ?>"></label>
                <label><span class="l">Features, one per line</span><textarea rows="3" name="packs[<?= e($key) ?>][features]"><?= e(implode("\n", $p['features'])) ?></textarea></label>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <button>Save prices</button>
    </form>
</section>
<?php endif; ?>

<?php if ($adminView === 'community'): ?>
<section class="card">
    <form method="post" action="admin-community" class="form" style="margin-top:0"><?= csrf_field() ?><input type="hidden" name="action" value="save_site">
        <label><span class="l">Telegram channel</span><input name="telegram_url" placeholder="https://t.me/yourchannel or @yourchannel" value="<?= e($telegramUrl) ?>"><small>Shown in the footer of every public page, on the Contact page, in the dashboard sidebar and in welcome emails. Until set, the dashboard button opens the Contact page.</small></label>
        <label><span class="l">Email new contact messages to (optional)</span><input type="email" name="contact_notify_email" placeholder="you@example.com" value="<?= e($notifyEmail) ?>"><small>Messages are always kept under Messages; this also emails each one.</small></label>
        <div class="actions"><button>Save</button></div>
    </form>
</section>
<?php endif; ?>

<?php if ($adminView === 'community'): $tgChat = sleek_telegram_chat(); $tgHasToken = sleek_telegram_token() !== ''; ?>
<section class="card">
    <h3>Telegram admin alerts</h3>
    <p class="muted">Every sign-up, payment, message, job and admin action posted to your Telegram group, as it happens. Status: <strong><?= sleek_telegram_ready() ? 'connected' : 'not connected' ?></strong>.</p>
    <ol class="muted" style="margin:8px 0 0;padding-left:20px">
        <li>In Telegram, message <strong>@BotFather</strong> → <code>/newbot</code> → copy the token it gives you.</li>
        <li>Add your new bot to your alerts group (as an admin, so it can post), then send any message in the group, e.g. “hi”.</li>
        <li>Paste the token below and press <strong>Find my group</strong>, pick the group, save, then <strong>Send test alert</strong>.</li>
    </ol>
    <form method="post" action="admin-community" class="form" autocomplete="off"><?= csrf_field() ?>
        <div class="form-grid two">
            <label><span class="l">Bot token</span><input type="password" name="tg_token" autocomplete="new-password" placeholder="<?= $tgHasToken ? 'Saved — leave blank to keep it' : '123456789:AAE…' ?>"><span class="check"><input type="checkbox" name="tg_token_clear" value="1"> Clear saved token</span></label>
            <label><span class="l">Group ID</span><input name="tg_chat" placeholder="-1001234567890" value="<?= e($tgChat) ?>"><small>Use “Find my group”, or @channelname for a public channel.</small></label>
        </div>
        <?php if ($tgFound): ?>
            <fieldset class="pack"><legend>Groups the bot can see — pick one</legend>
                <?php foreach ($tgFound as $c): ?>
                    <label class="check"><input type="radio" name="tg_pick" value="<?= e($c['id']) ?>" onclick="this.form.tg_chat.value=this.value"> <?= e($c['title']) ?> <small>(<?= e($c['type']) ?>, <?= e($c['id']) ?>)</small></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <fieldset class="pack"><legend>Send alerts for</legend>
            <?php foreach (SLEEK_ALERTS as $key => [$label, $emoji]): ?>
                <label class="check"><input type="checkbox" name="tg_alerts[<?= e($key) ?>]" value="1"<?= sleek_alert_enabled($key) ? ' checked' : '' ?>> <?= $emoji ?> <?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="actions">
            <button name="action" value="save_telegram">Save</button>
            <button class="ghost" name="action" value="tg_discover">Find my group</button>
            <button class="ghost" name="action" value="tg_test">Send test alert</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($adminView === 'email'): ?>
<section class="card">
    <p class="muted" style="margin-top:0">Used for welcome emails, password-reset links and contact notifications. On Namecheap: create a mailbox in cPanel → Email Accounts, then use server <strong>mail.yourdomain</strong>, port <strong>465</strong>, SSL, and the full mailbox address and password. Status: <strong><?= $mailReady ? 'configured' : 'not set up' ?></strong>. Sends from <?= e(sleek_mail_from_address()) ?>.</p>
    <form method="post" action="admin-email" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_mail">
        <div class="form-grid three">
            <label><span class="l">SMTP server</span><input required name="mail_host" placeholder="mail.streamliveai.art" value="<?= e($mailReady ? (string) $mailCfg['host'] : '') ?>"></label>
            <label><span class="l">Port</span><input required type="number" min="1" max="65535" name="mail_port" value="<?= (int) ($mailReady ? $mailCfg['port'] : 465) ?>"></label>
            <label><span class="l">Encryption</span><select name="mail_encryption"><?php foreach (['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', '' => 'None'] as $v => $l): ?><option value="<?= e($v) ?>"<?= ($mailReady ? $mailCfg['encryption'] : 'ssl') === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="form-grid two">
            <label><span class="l">Username (usually the full mailbox address)</span><input name="mail_username" value="<?= e((string) $mailCfg['username']) ?>"></label>
            <label><span class="l">Password</span><input type="password" name="mail_password" autocomplete="new-password" placeholder="<?= ($mailCfg['password'] ?? '') !== '' ? 'Leave blank to keep current password' : 'Mailbox password' ?>"><span class="check"><input type="checkbox" name="mail_password_clear" value="1"> Clear saved password</span></label>
            <label><span class="l">Sender address (optional)</span><input type="email" name="mail_from_address" placeholder="<?= e(sleek_mail_from_address()) ?>" value="<?= e((string) $mailCfg['from_address']) ?>"><small>Same domain as the mailbox, or it lands in spam.</small></label>
            <label><span class="l">Sender name</span><input name="mail_from_name" maxlength="80" value="<?= e((string) $mailCfg['from_name']) ?>"></label>
        </div>
        <div class="actions"><button>Save email settings</button></div>
    </form>
</section>
<section class="card">
    <h3>Send a test</h3>
    <form method="post" action="admin-email" class="inline" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="test_mail"><input type="email" required name="test_to" placeholder="Send a test to…" value="<?= e($admin['email']) ?>" aria-label="Test email address"><button class="ghost">Send test email</button></form>
    <?php if (($lastMailError = sleek_mail_last_error()) !== ''): ?><p style="overflow-wrap:anywhere"><small>Last send failure: <?= e($lastMailError) ?></small></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($adminView === 'providers'): require_once __DIR__ . '/inc/livestream.php'; $liveSpeed = sleek_live_speed(); ?>
<section class="card">
    <h3>Livestream</h3>
    <p class="muted">Model: <strong><?= e((string) sleek_config('decart')['model']) ?></strong> (Decart). Fast mode cuts latency so the stream follows movement more tightly, but Decart bills it at <strong>2×</strong> the standard per-second rate — check your Decart balance and your Livestream credit price before turning it on.</p>
    <form method="post" action="admin-providers" class="form" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="save_live">
        <label class="check"><input type="radio" name="live_speed" value="standard"<?= $liveSpeed === 'standard' ? ' checked' : '' ?>> Standard</label>
        <label class="check"><input type="radio" name="live_speed" value="fast"<?= $liveSpeed === 'fast' ? ' checked' : '' ?>> Fast — lowest latency (2× Decart cost)</label>
        <div class="actions"><button>Save</button></div>
    </form>
</section>
<section class="stats" aria-label="Connection status">
    <?php foreach ($providers as $name => $connected): ?>
        <div class="stat"><div class="label"><?= e($name) ?></div><div class="value" style="font-size:16px;color:<?= $connected ? '#8fe0aa' : 'var(--muted)' ?>"><?= $connected ? '✓ Configured' : 'Keys missing' ?></div></div>
    <?php endforeach; ?>
</section>
<section class="card">
    <p class="muted" style="margin-top:0">Keys are stored privately on this server and never shown again. Leave a field blank to keep its current value; tick Clear to remove it.</p>
    <form method="post" action="admin-providers" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_provider_keys">
        <div class="keys">
        <?php foreach (sleek_admin_key_fields() as $field => [$section, $keyName, $label]): $isSet = (sleek_config($section)[$keyName] ?? '') !== ''; ?>
            <label class="card"><strong><?= e($label) ?></strong><input type="password" name="<?= e($field) ?>" autocomplete="new-password" placeholder="<?= $isSet ? 'Leave blank to keep current value' : 'Not set — paste it here' ?>"><span class="check"><input type="checkbox" name="clear_<?= e($field) ?>" value="1"> Clear saved value</span></label>
        <?php endforeach; ?>
        </div>
        <div class="actions" style="margin-top:12px"><button>Save provider keys</button></div>
    </form>
</section>
<?php endif; ?>
<?php admin_layout_close(); ?>
