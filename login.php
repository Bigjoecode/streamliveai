<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth-layout.php';

require_guest();

$error = null;
$email = '';

if (sleek_is_post()) {
    csrf_verify();

    $email  = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
    $pass   = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $result = sleek_attempt_login($email, $pass);

    if ($result['ok']) sleek_redirect(sleek_after_login_url());

    $error = $result['error'];
    http_response_code(422);
}

auth_layout_open('Log in', 'Welcome back to the feed.');
?>
                <h1 class="font-display text-3xl">Log in</h1>
                <p class="mt-2 text-sm text-muted">Good to see you again.</p>

                <?php if ($error): ?>
                    <div role="alert" class="mt-6 rounded-md border border-tally/40 bg-tally/10 px-3 py-2.5 text-sm text-paper">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login" class="mt-6 space-y-4">
                    <?= csrf_field() ?>

                    <label class="block">
                        <span class="mb-1 block text-sm text-muted">Email</span>
                        <input required type="email" name="email" value="<?= e($email) ?>"
                               autocomplete="email" autofocus
                               class="<?= auth_input_class([], 'email') ?>">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm text-muted">Password</span>
                        <input required type="password" name="password" autocomplete="current-password"
                               class="<?= auth_input_class([], 'password') ?>">
                    </label>

                    <div class="text-right">
                        <a href="forgot-password" class="text-xs text-muted hover:text-signal">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn-glow w-full rounded-md bg-signal py-3 font-medium text-ink hover:brightness-95">Log in</button>
                </form>

                <p class="mt-6 text-sm text-muted">
                    No account yet?
                    <a href="register" class="text-paper underline">Create one</a>
                </p>
<?php auth_layout_close(); ?>
