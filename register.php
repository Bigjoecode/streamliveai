<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth-layout.php';

require_guest();

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$errors = [];
$old = ['name' => '', 'email' => ''];
$refCode = sleek_is_post() ? $str('ref_code') : (is_string($_GET['ref'] ?? null) ? $_GET['ref'] : '');
$refCode = preg_replace('/[^A-Za-z0-9]/', '', $refCode);

if (sleek_is_post()) {
    csrf_verify();
    $old = ['name' => $str('name'), 'email' => $str('email')];
    $result = sleek_register($old['name'], $old['email'], $str('password'), isset($_POST['terms']), $refCode);
    if ($result['ok']) sleek_redirect('dashboard');
    $errors = $result['errors'];
    http_response_code(422);
}

auth_layout_open('Create account', 'One control room for synthetic media.');
$describe = fn(string $f): string => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . $f . '-error"' : '';
?>
                <h1 class="font-display text-3xl">Create your account</h1>
                <p class="mt-2 text-sm text-muted">Register an account and start creating with StreamLive AI</p>
                <?php if ($errors): ?>
                    <div role="alert" class="mt-6 rounded-md border border-tally/40 bg-tally/10 px-3 py-2.5 text-sm text-paper">
                        Please fix the <?= count($errors) === 1 ? 'field' : 'fields' ?> marked below.
                    </div>
                <?php endif; ?>
                <form method="POST" action="register" class="mt-6 space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="ref_code" value="<?= e($refCode) ?>">
                    <label class="block"><span class="mb-1 block text-sm text-muted">Name</span>
                        <input required name="name" value="<?= e($old['name']) ?>" maxlength="100" autocomplete="name"<?= $describe('name') ?> class="<?= auth_input_class($errors, 'name') ?>">
                        <?php auth_field_error($errors, 'name'); ?>
                    </label>
                    <label class="block"><span class="mb-1 block text-sm text-muted">Email</span>
                        <input required type="email" name="email" value="<?= e($old['email']) ?>" maxlength="254" autocomplete="email"<?= $describe('email') ?> class="<?= auth_input_class($errors, 'email') ?>">
                        <?php auth_field_error($errors, 'email'); ?>
                    </label>
                    <label class="block"><span class="mb-1 block text-sm text-muted">Password</span>
                        <input required minlength="8" maxlength="72" type="password" name="password" autocomplete="new-password"<?= $describe('password') ?> class="<?= auth_input_class($errors, 'password') ?>">
                        <?php auth_field_error($errors, 'password'); ?>
                    </label>
                    <div><label class="flex items-start gap-2 text-xs text-muted">
                        <input required type="checkbox" name="terms" value="1" class="mt-0.5"<?= $describe('terms') ?>><span>I agree to the <a href="terms" target="_blank" rel="noopener" class="text-paper underline">Terms of Service</a> and <a href="privacy" target="_blank" rel="noopener" class="text-paper underline">Privacy Policy</a>.</span>
                    </label><?php auth_field_error($errors, 'terms'); ?></div>
                    <button type="submit" class="btn-glow w-full rounded-md bg-signal py-3 font-medium text-ink hover:brightness-95">Create account</button>
                </form>
                <p class="mt-6 text-sm text-muted">Already have an account? <a href="login" class="text-paper underline">Log in</a></p>
<?php auth_layout_close(); ?>
