<?php
/**
 * One-time super-admin setup. Sign in with your own account, enter the setup code
 * that was placed privately on the server, and that account becomes super admin.
 * Once a super admin exists this page does nothing — further changes are made by
 * the super admin from /admin.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth-layout.php';

$user = require_auth();

if (($user['role'] ?? '') === 'super_admin') sleek_redirect('admin');

$error = null;
if (sleek_is_post()) {
    csrf_verify();
    $result = sleek_claim_first_super_admin($user, is_string($_POST['code'] ?? null) ? $_POST['code'] : '');
    if ($result === 'ok') sleek_redirect('admin');
    $error = match ($result) {
        'locked' => 'Too many wrong codes. Wait 15 minutes and try again.',
        'wrong'  => 'That code isn\'t right for this account.',
        default  => null,   // 'taken' / 'disabled' fall through to the closed state below
    };
    if ($error !== null) http_response_code(422);
}

$open = strlen(trim((string) (sleek_config('auth')['super_admin_setup_token'] ?? ''))) >= 24 && !sleek_super_admin_exists();

auth_layout_open('Admin setup', 'One-time setup.');
?>
                <h1 class="font-display text-3xl">Admin setup</h1>

                <?php if (!$open): ?>
                    <div role="status" class="mt-6 rounded-md border border-border bg-ink/60 px-3 py-3 text-sm leading-relaxed text-paper">
                        Setup is closed. The site already has a super admin, or no setup code has been issued.
                    </div>
                <?php else: ?>
                    <p class="mt-2 text-sm text-muted">
                        Signed in as <span class="text-paper"><?= e($user['email']) ?></span>. Enter the setup code to make
                        this account the site's super admin. It works once.
                    </p>

                    <?php if ($error): ?>
                        <div role="alert" class="mt-6 rounded-md border border-tally/40 bg-tally/10 px-3 py-3 text-sm text-paper"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="admin-setup" class="mt-8 space-y-4" autocomplete="off">
                        <?= csrf_field() ?>
                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Setup code</span>
                            <input required type="password" name="code" maxlength="200" autocomplete="off" autofocus
                                   class="w-full rounded-md border border-border bg-ink/60 px-3 py-2.5 text-sm text-paper">
                        </label>
                        <button type="submit" class="btn-glow w-full rounded-md bg-signal py-3 font-medium text-ink hover:brightness-95">Make me super admin</button>
                    </form>
                <?php endif; ?>

                <p class="mt-6 text-sm text-muted">
                    <a href="dashboard" class="text-paper underline">Back to dashboard</a>
                </p>
<?php auth_layout_close(); ?>
