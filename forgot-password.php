<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth-layout.php';

require_guest();

$sent  = false;
$email = '';

if (sleek_is_post()) {
    csrf_verify();

    $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
    sleek_request_password_reset($email);

    // Shown whatever happened — see sleek_request_password_reset().
    $sent = true;
}

$minutes = intdiv(sleek_config('auth')['reset_ttl_seconds'], 60);

auth_layout_open('Reset your password', "Let's get you back in.");
?>
                <h1 class="font-display text-3xl">Reset your password</h1>

                <?php if ($sent): ?>
                    <div role="status" class="mt-6 rounded-md border border-reel/40 bg-reel/10 px-3 py-3 text-sm leading-relaxed text-paper">
                        If an account exists for <span class="font-medium"><?= e(sleek_normalize_email($email)) ?></span>,
                        a reset link is on its way. It expires in <?= $minutes ?> minutes.
                    </div>
                    <p class="mt-4 text-sm text-muted">Nothing arrived? Check spam, or wait a minute and try again.</p>

                    <a href="forgot-password" class="mt-6 inline-block text-sm text-paper underline">Send another link</a>
                <?php else: ?>
                    <p class="mt-2 text-sm text-muted">Enter your email and we'll send you a reset link.</p>

                    <form method="POST" action="forgot-password" class="mt-8 space-y-4">
                        <?= csrf_field() ?>

                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Email</span>
                            <input required type="email" name="email" value="<?= e($email) ?>" autocomplete="email" autofocus
                                   class="<?= auth_input_class([], 'email') ?>">
                        </label>

                        <button type="submit" class="btn-glow w-full rounded-md bg-signal py-3 font-medium text-ink hover:brightness-95">Send reset link</button>
                    </form>
                <?php endif; ?>

                <p class="mt-6 text-sm text-muted">
                    Remembered it?
                    <a href="login" class="text-paper underline">Log in</a>
                </p>
<?php auth_layout_close(); ?>
