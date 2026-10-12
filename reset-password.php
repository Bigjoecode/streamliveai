<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth-layout.php';

// The token sits in this page's URL. Send no Referer at all, so it can't leak to the
// font or Tailwind CDN the page loads.
header('Referrer-Policy: no-referrer');

$token = sleek_is_post()
    ? (is_string($_POST['token'] ?? null) ? $_POST['token'] : '')
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');

$errors = [];

if (sleek_is_post()) {
    csrf_verify();

    $result = sleek_complete_reset(
        $token,
        is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
        is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : ''
    );

    if ($result['ok']) sleek_redirect('dashboard');

    $errors = $result['errors'];
    http_response_code(422);
}

$valid = !isset($errors['token']) && sleek_find_reset($token) !== null;

auth_layout_open('Choose a new password', "Let's get you back in.");

$describe = fn(string $f): string => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . $f . '-error"' : '';
?>
                <h1 class="font-display text-3xl">Choose a new password</h1>

                <?php if (!$valid): ?>
                    <div role="alert" class="mt-6 rounded-md border border-tally/40 bg-tally/10 px-3 py-3 text-sm leading-relaxed text-paper">
                        This reset link has expired or has already been used.
                    </div>
                    <a href="forgot-password" class="btn-glow mt-6 block w-full rounded-md bg-signal py-3 text-center font-medium text-ink hover:brightness-95">Send a new link</a>
                <?php else: ?>
                    <p class="mt-2 text-sm text-muted">You'll be signed in as soon as it's saved.</p>

                    <form method="POST" action="reset-password" class="mt-8 space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">

                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">New password</span>
                            <input required minlength="8" maxlength="72" type="password" name="password" autocomplete="new-password" autofocus<?= $describe('password') ?>
                                   class="<?= auth_input_class($errors, 'password') ?>">
                            <?php auth_field_error($errors, 'password'); ?>
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-sm text-muted">Confirm new password</span>
                            <input required minlength="8" maxlength="72" type="password" name="password_confirmation" autocomplete="new-password"<?= $describe('password_confirmation') ?>
                                   class="<?= auth_input_class($errors, 'password_confirmation') ?>">
                            <?php auth_field_error($errors, 'password_confirmation'); ?>
                        </label>

                        <button type="submit" class="btn-glow w-full rounded-md bg-signal py-3 font-medium text-ink hover:brightness-95">Save password</button>
                    </form>
                <?php endif; ?>

                <p class="mt-6 text-sm text-muted">
                    <a href="login" class="text-paper underline">Back to log in</a>
                </p>
<?php auth_layout_close(); ?>
