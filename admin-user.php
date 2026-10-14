<?php
/**
 * One account, for the super admin: edit details, adjust credits, email a password
 * link, resend the welcome email, delete — and see recent activity.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/mailer.php';
require __DIR__ . '/inc/contact.php';
require __DIR__ . '/inc/admin-users.php';
require __DIR__ . '/inc/admin-layout.php';
$admin = require_super_admin();

$id   = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$user = sleek_admin_find_user($id);
if (!$user) {
    sleek_flash('error', 'That account no longer exists.');
    sleek_redirect('admin-accounts');
}

$here      = 'admin-user?id=' . $id;
$errors    = [];
$notice    = null;
$noticeBad = false;
$old       = ['name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
$adj       = ['delta' => '', 'reason' => ''];

if (sleek_is_post()) {
    csrf_verify();
    $str    = fn(string $k) => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $action = $str('action');

    if ($action === 'update') {
        $old = ['name' => $str('name'), 'email' => $str('email'), 'role' => $str('role') ?: $user['role']];
        $errors = sleek_admin_update_user($id, $old['name'], $old['email'], $old['role'], $str('password'));
        if (!$errors) sleek_redirect($here . '&done=saved');
    } elseif ($action === 'credits') {
        $adj = ['delta' => trim($str('delta')), 'reason' => $str('reason')];
        $delta = filter_var($adj['delta'], FILTER_VALIDATE_INT);
        $errors = $delta === false
            ? ['delta' => 'Enter a whole number, e.g. 500 or -200.']
            : sleek_admin_adjust_credits($id, (int) $delta, $adj['reason'], $admin);
        if (!$errors) sleek_redirect($here . '&done=credits');
    } elseif ($action === 'send_reset') {
        $ttl  = (int) sleek_config('auth')['reset_ttl_seconds'];
        $link = sleek_issue_reset_link($id, $ttl);
        [$html, $text] = sleek_reset_email($user['name'], $link, intdiv($ttl, 60));
        $ok = sleek_send_mail($user['email'], $user['name'], 'Reset your ' . sleek_config('brand_name') . ' password', $html, $text);
        sleek_redirect($here . '&done=' . ($ok ? 'reset' : 'mailfail'));
    } elseif ($action === 'send_welcome') {
        require_once __DIR__ . '/inc/emails.php';
        sleek_redirect($here . '&done=' . (sleek_send_welcome_email($id) ? 'welcome' : 'mailfail'));
    } elseif ($action === 'delete') {
        if (sleek_normalize_email($str('confirm_email')) !== $user['email']) {
            $errors = ['confirm_email' => 'Type the account\'s email exactly to confirm.'];
        } else {
            $errors = sleek_admin_delete_user($id, $admin);
            if (!$errors) sleek_redirect('admin-accounts?done=deleted');
        }
    }
    if ($errors) {
        $notice = $errors['form'] ?? 'Not saved — see the highlighted field.';
        $noticeBad = true;
    }
}

$notice ??= match ($_GET['done'] ?? '') {
    'created'  => 'Account created.' . (sleek_mail_configured() ? '' : ' Email isn\'t set up, so no welcome email went out.'),
    'saved'    => 'Changes saved.',
    'credits'  => 'Credits updated.',
    'reset'    => 'Password link emailed to ' . $user['email'] . '.',
    'welcome'  => 'Welcome email sent to ' . $user['email'] . '.',
    'mailfail' => 'The email could not be sent: ' . (sleek_mail_last_error() ?: 'check Email settings.'),
    default    => null,
};
if (($_GET['done'] ?? '') === 'mailfail') $noticeBad = true;

$activity = sleek_admin_user_activity($id);
$isSuper  = $user['role'] === 'super_admin';
$isSelf   = (int) $user['id'] === (int) $admin['id'];
$referrer = $user['referred_by'] ? sleek_admin_find_user((int) $user['referred_by']) : null;

admin_layout_open($user['name'], $admin, $notice, $noticeBad, 'accounts');
?>
<p style="margin:4px 0 0"><a href="admin-accounts" style="text-decoration:none">← All accounts</a></p>
<?php
?>
<section class="card">
    <h2>Overview</h2>
    <dl class="kv">
        <dt>Email</dt><dd><a href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a></dd>
        <dt>Role</dt><dd><span class="role <?= e($user['role']) ?>"><?= e($isSuper ? 'Super admin' : ucfirst($user['role'])) ?></span></dd>
        <dt>Credits</dt><dd><strong><?= number_format((int) $user['credits']) ?></strong></dd>
        <dt>Joined</dt><dd><?= e(date('Y-m-d H:i', (int) $user['created_at'])) ?></dd>
        <dt>Jobs</dt><dd><?= (int) $activity['jobs']['total'] ?> (<?= (int) $activity['jobs']['done'] ?> completed)</dd>
        <dt>Referrals</dt><dd><?= $activity['referrals'] ?> joined with their code <code><?= e($user['referral_code']) ?></code></dd>
        <?php if ($referrer): ?><dt>Referred by</dt><dd><a href="admin-user?id=<?= (int) $referrer['id'] ?>"><?= e($referrer['name']) ?></a></dd><?php endif; ?>
    </dl>
</section>

<section class="card">
    <h2>Edit details</h2>
    <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid two">
            <label><span class="l">Name</span><input required maxlength="100" name="name" value="<?= e($old['name']) ?>"><?= admin_err($errors, 'name') ?></label>
            <label><span class="l">Email</span><input required type="email" maxlength="254" name="email" value="<?= e($old['email']) ?>"><?= admin_err($errors, 'email') ?></label>
            <label><span class="l">Role</span>
                <?php if ($isSuper): ?>
                    <input disabled value="Super admin (can't be changed here)">
                <?php else: ?>
                    <select name="role"><?php foreach (SLEEK_ADMIN_ROLES as $v => $l): ?><option value="<?= e($v) ?>"<?= $old['role'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <?php endif; ?>
                <?= admin_err($errors, 'role') ?></label>
            <label><span class="l">New password (optional)</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep it"><?= admin_err($errors, 'password') ?></label>
        </div>
        <div class="actions"><button>Save changes</button></div>
    </form>
</section>

<section class="card">
    <h2>Credits</h2>
    <p class="muted">Balance: <strong><?= number_format((int) $user['credits']) ?></strong>. Use a negative number to remove credits. The reason appears in their credit history.</p>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="credits"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid two">
            <label><span class="l">Amount (+ add, − remove)</span><input required type="number" step="1" name="delta" placeholder="e.g. 500 or -200" value="<?= e($adj['delta']) ?>"><?= admin_err($errors, 'delta') ?></label>
            <label><span class="l">Reason</span><input required maxlength="120" name="reason" placeholder="e.g. Refund for failed job" value="<?= e($adj['reason']) ?>"><?= admin_err($errors, 'reason') ?></label>
        </div>
        <div class="actions"><button>Apply</button></div>
    </form>
</section>

<section class="card">
    <h2>Emails</h2>
    <div class="actions" style="margin-top:8px">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="send_reset"><input type="hidden" name="id" value="<?= $id ?>"><button class="ghost">Email a password-reset link</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="send_welcome"><input type="hidden" name="id" value="<?= $id ?>"><button class="ghost">Resend welcome email</button></form>
    </div>
</section>

<section class="card">
    <h2>Recent credit history</h2>
    <?php if (!$activity['ledger']): ?><p class="muted">No credit movements yet.</p><?php else: ?>
    <div class="tablewrap"><table class="stack">
        <thead><tr><th>When</th><th>Change</th><th>Balance</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($activity['ledger'] as $l): ?>
            <tr>
                <td data-label="When"><?= e(date('Y-m-d H:i', (int) $l['created_at'])) ?></td>
                <td data-label="Change" class="<?= (int) $l['delta'] >= 0 ? 'plus' : 'minus' ?>"><?= (int) $l['delta'] >= 0 ? '+' : '' ?><?= number_format((int) $l['delta']) ?></td>
                <td data-label="Balance"><?= number_format((int) $l['balance_after']) ?></td>
                <td data-label="Description"><?= e($l['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Recent payments</h2>
    <?php if (!$activity['payments']): ?><p class="muted">No payments yet.</p><?php else: ?>
    <div class="tablewrap"><table class="stack">
        <thead><tr><th>When</th><th>Pack</th><th>Amount</th><th>Via</th><th>Status</th><th>Reference</th></tr></thead>
        <tbody>
        <?php foreach ($activity['payments'] as $pm): ?>
            <tr>
                <td data-label="When"><?= e(date('Y-m-d H:i', (int) $pm['created_at'])) ?></td>
                <td data-label="Pack"><?= e((sleek_pack($pm['pack_key'])['name'] ?? $pm['pack_key'])) ?> · <?= number_format((int) $pm['credits']) ?> cr</td>
                <td data-label="Amount"><?= sleek_money((int) $pm['amount_kobo'], (string) $pm['currency']) ?></td>
                <td data-label="Via"><?= e(sleek_admin_payment_method((string) $pm['provider'])) ?></td>
                <td data-label="Status"><?= e($pm['status']) ?></td>
                <td data-label="Reference"><code><?= e($pm['reference']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card" style="border-color:#6b3a3a">
    <h2>Delete account</h2>
    <?php if ($isSuper || $isSelf): ?>
        <p class="muted"><?= $isSuper ? 'The super admin account can\'t be deleted.' : 'You can\'t delete the account you\'re signed in with.' ?></p>
    <?php else: ?>
        <p class="muted">Removes the account with its payments, jobs and credit history. This can't be undone. Type <strong><?= e($user['email']) ?></strong> to confirm.</p>
        <form method="post" class="inline" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
            <input name="confirm_email" placeholder="<?= e($user['email']) ?>" aria-label="Type the email to confirm" value="">
            <button class="danger">Delete permanently</button>
        </form>
        <?= admin_err($errors, 'confirm_email') ?>
    <?php endif; ?>
</section>
<?php admin_layout_close(); ?>
