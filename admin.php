<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/catalog.php';
require __DIR__ . '/inc/contact.php';
require_once __DIR__ . '/inc/mailer.php';
require __DIR__ . '/inc/admin-users.php';
require __DIR__ . '/inc/admin-layout.php';
$admin = require_super_admin();

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
                    sleek_redirect('admin?keys=saved');
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
            if (sleek_admin_secrets_write($saved)) sleek_redirect('admin?saved=mail#email');
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
        if ($problems) {
            $notice = 'Prices not saved. ' . implode(' ', $problems);
        } else {
            require_once __DIR__ . '/inc/settings.php';
            sleek_setting_set('packs', $packs);
            sleek_redirect('admin?saved=packs#pricing');
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
            sleek_redirect('admin?saved=site#site');
        }
    } elseif ($action === 'message_read' || $action === 'message_unread' || $action === 'message_delete') {
        $id = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            if ($action === 'message_delete') $db->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);
            else $db->prepare('UPDATE contact_messages SET read_at = ? WHERE id = ?')->execute([$action === 'message_read' ? time() : null, $id]);
        }
        sleek_redirect('admin#messages');
    }
}

if (sleek_is_post() && $notice !== null && !$noticeBad) {
    $noticeBad = !preg_match('/^(Test email sent|User role updated|Initial super admin verified)/', $notice);
}
if (($_GET['done'] ?? '') === 'deleted') $notice = 'Account deleted.';
if ($notice === null && ($flash = sleek_flash_take())) {
    $notice = $flash['message'];
    $noticeBad = $flash['type'] === 'error';
}
if (($_GET['keys'] ?? '') === 'saved') $notice = 'Provider settings saved. Existing values stay hidden.';
if (($_GET['saved'] ?? '') === 'packs') $notice = 'Prices saved. The pricing page, home page and billing page show them now.';
if (($_GET['saved'] ?? '') === 'site') $notice = 'Community and notification settings saved.';
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
$list = sleek_admin_list_users($q, (int) ($_GET['p'] ?? 1));
$pageUrl = fn(int $n) => 'admin?' . http_build_query(array_filter(['q' => $q, 'p' => $n > 1 ? $n : null])) . '#accounts';
$stats = [
    'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'admins' => (int) $db->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')")->fetchColumn(),
    'jobs' => (int) $db->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),
    'payments' => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'success'")->fetchColumn(),
];
$providers = [
    'Decart' => sleek_config('decart')['api_key'] !== '',
    'ElevenLabs' => sleek_config('elevenlabs')['api_key'] !== '',
    'fal.ai' => sleek_config('fal')['api_key'] !== '',
    'Flutterwave' => sleek_config('flutterwave')['secret_key'] !== '' && sleek_config('flutterwave')['webhook_hash'] !== '',
    'NOWPayments (crypto)' => sleek_config('nowpayments')['api_key'] !== '' && sleek_config('nowpayments')['ipn_secret'] !== '',
];

admin_layout_open('System control', $admin, $notice, $noticeBad);
?>
<section class="stats" aria-label="Totals">
<?php foreach ($stats as $label => $value): ?>
    <article class="card"><div class="muted"><?= e(ucfirst($label)) ?></div><div class="number"><?= number_format($value) ?></div></article>
<?php endforeach; ?>
</section>
<?php if (!$mailReady): ?><p class="notice bad" role="alert">Email is not set up, so password-reset, welcome and notification emails are not being sent. Fill in <a href="#email">Email (SMTP)</a>.</p><?php endif; ?>

<section class="card" id="accounts">
    <h2>Accounts</h2>
    <p class="muted"><?= number_format($list['total']) ?> account<?= $list['total'] === 1 ? '' : 's' ?><?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?>. Open one to edit it, change credits, send a password link or delete it.</p>

    <form method="get" action="admin#accounts" class="inline" role="search">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or email" aria-label="Search accounts">
        <button class="ghost">Search</button><?php if ($q !== ''): ?><a class="btn ghost" href="admin#accounts">Clear</a><?php endif; ?>
    </form>

    <details<?= $createErrors ? ' open' : '' ?> style="margin-top:12px">
        <summary class="btn">+ Add account</summary>
        <form method="post" class="form" autocomplete="off">
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

<section class="card" id="messages">
    <h2>Messages<?php if ($unread): ?><span class="pill"><?= $unread ?> new</span><?php endif; ?></h2>
    <p class="muted">From the Contact page. Reply by clicking the sender's email. Latest 100 shown.</p>
    <?php if (!$messages): ?><p class="muted">No messages yet.</p><?php endif; ?>
    <?php foreach ($messages as $m): $isUnread = $m['read_at'] === null; ?>
    <article class="msg<?= $isUnread ? ' unread' : '' ?>">
        <strong><?= e($m['name']) ?></strong> · <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: your message to ' . sleek_config('brand_name')) ?>"><?= e($m['email']) ?></a>
        <small>· <?= e(date('Y-m-d H:i', (int) $m['created_at'])) ?><?php if ($m['user_id']): ?> · <a href="admin-user?id=<?= (int) $m['user_id'] ?>">account #<?= (int) $m['user_id'] ?></a><?php endif; ?></small>
        <p><?= e($m['message']) ?></p>
        <div class="actions">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>"><button class="ghost" name="action" value="<?= $isUnread ? 'message_read' : 'message_unread' ?>"><?= $isUnread ? 'Mark read' : 'Mark unread' ?></button></form>
            <form method="post" class="inline" onsubmit="return confirm('Delete this message?')"><?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>"><button class="danger" name="action" value="message_delete">Delete</button></form>
        </div>
    </article>
    <?php endforeach; ?>
</section>

<section class="card" id="pricing">
    <h2>Pricing</h2>
    <p class="muted">Credit packs, priced in US dollars. Changes show at once on the pricing page, the home page (first three packs) and Credits &amp; billing. A payment already started keeps its price. A pack with a badge (e.g. “Most popular”) is highlighted.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_packs">
        <div class="packs">
        <?php foreach ($packs as $key => $p): ?>
            <fieldset class="pack"><legend><?= e($key) ?></legend>
                <label><span class="l">Name</span><input required maxlength="40" name="packs[<?= e($key) ?>][name]" value="<?= e($p['name']) ?>"></label>
                <div class="form-grid two">
                    <label><span class="l">Credits</span><input required type="number" min="1" step="1" name="packs[<?= e($key) ?>][credits]" value="<?= (int) $p['credits'] ?>"></label>
                    <label><span class="l">Price (USD)</span><input required inputmode="decimal" pattern="\d{1,6}(\.\d{1,2})?" name="packs[<?= e($key) ?>][price]" value="<?= e(rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.')) ?>"></label>
                </div>
                <label><span class="l">Badge (optional)</span><input maxlength="30" name="packs[<?= e($key) ?>][badge]" value="<?= e($p['badge'] ?? '') ?>"></label>
                <label><span class="l">Features, one per line</span><textarea rows="3" name="packs[<?= e($key) ?>][features]"><?= e(implode("\n", $p['features'])) ?></textarea></label>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <button>Save prices</button>
    </form>
</section>

<section class="card" id="site">
    <h2>Community &amp; notifications</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="save_site">
        <label><span class="l">Telegram channel</span><input name="telegram_url" placeholder="https://t.me/yourchannel or @yourchannel" value="<?= e($telegramUrl) ?>"><small>Shown in the footer of every public page, on the Contact page, in the dashboard sidebar and in welcome emails. Until set, the dashboard button opens the Contact page.</small></label>
        <label><span class="l">Email new contact messages to (optional)</span><input type="email" name="contact_notify_email" placeholder="you@example.com" value="<?= e($notifyEmail) ?>"><small>Messages are always kept in Messages above; this also emails each one.</small></label>
        <div class="actions"><button>Save</button></div>
    </form>
</section>

<section class="card" id="email">
    <h2>Email (SMTP)</h2>
    <p class="muted">Used for welcome emails, password-reset links and contact notifications. On Namecheap: create a mailbox in cPanel → Email Accounts, then use server <strong>mail.yourdomain</strong>, port <strong>465</strong>, SSL, and the full mailbox address and password. Status: <strong><?= $mailReady ? 'configured' : 'not set up' ?></strong>. Sends from <?= e(sleek_mail_from_address()) ?>.</p>
    <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_mail">
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
    <form method="post" class="inline" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="test_mail"><input type="email" required name="test_to" placeholder="Send a test to…" value="<?= e($admin['email']) ?>" aria-label="Test email address"><button class="ghost">Send test email</button></form>
    <?php if (($lastMailError = sleek_mail_last_error()) !== ''): ?><p style="overflow-wrap:anywhere"><small>Last send failure: <?= e($lastMailError) ?></small></p><?php endif; ?>
</section>

<section class="card" id="keys">
    <h2>Provider connections</h2>
    <p class="muted">Keys are stored privately on this server and never shown again. Leave a field blank to keep its current value; tick Clear to remove it.</p>
    <div class="stats" style="margin-top:12px">
    <?php foreach ($providers as $name => $connected): ?>
        <article class="card"><strong><?= e($name) ?></strong><p class="muted" style="margin:4px 0 0"><?= $connected ? 'Configured' : 'Keys missing' ?></p></article>
    <?php endforeach; ?>
    </div>
    <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_provider_keys">
        <div class="keys">
        <?php foreach (sleek_admin_key_fields() as $field => [$section, $keyName, $label]): $isSet = (sleek_config($section)[$keyName] ?? '') !== ''; ?>
            <label class="card"><strong><?= e($label) ?></strong><input type="password" name="<?= e($field) ?>" autocomplete="new-password" placeholder="<?= $isSet ? 'Leave blank to keep current value' : 'Not set — paste it here' ?>"><span class="check"><input type="checkbox" name="clear_<?= e($field) ?>" value="1"> Clear saved value</span></label>
        <?php endforeach; ?>
        </div>
        <div class="actions" style="margin-top:12px"><button>Save provider keys</button></div>
    </form>
</section>
<?php admin_layout_close(); ?>
