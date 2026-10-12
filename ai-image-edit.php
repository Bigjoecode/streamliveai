<?php
/**
 * AI Image Edit — edit a photo with a plain-language instruction.
 * Powered by Decart's image model (the same provider and key as Livestream).
 * The upload is validated and, with GD, re-encoded without metadata before it
 * leaves this server; it is never stored here.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/jobs.php';
require __DIR__ . '/inc/images.php';
require __DIR__ . '/inc/decart.php';

const EDIT_MAX_BYTES       = 10 * 1024 * 1024;
const EDIT_INSTRUCTION_MAX = 500;

$me   = require_auth();
$tool = sleek_tool('ai-image-edit');
$str  = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';

if (sleek_is_post()) {
    if (sleek_request_too_large()) {
        sleek_flash('error', 'That photo is too large. The limit is 10 MB.');
        sleek_redirect('ai-image-edit');
    }
    csrf_verify();

    $instruction = trim(preg_replace('/\s+/', ' ', $str('instruction')));
    $keep = fn() => $_SESSION['edit_old'] = ['instruction' => $instruction];

    $img = sleek_check_image_upload($_FILES['photo'] ?? null, EDIT_MAX_BYTES);
    $error = match (true) {
        !$img['ok']                                => $img['error'],
        mb_strlen($instruction) < 3                => 'Describe the edit you want, e.g. “make the sky a sunset”.',
        mb_strlen($instruction) > EDIT_INSTRUCTION_MAX => 'Keep the instruction under ' . EDIT_INSTRUCTION_MAX . ' characters.',
        !isset($_POST['consent'])                  => 'Confirm you have the right to edit this photo.',
        default                                    => null,
    };
    if ($error !== null) {
        $keep();
        sleek_flash('error', $error);
        sleek_redirect('ai-image-edit');
    }

    if (!sleek_decart_configured()) {
        sleek_jobs_log('ai-image-edit blocked: Decart api_key is empty');
        $keep();
        sleek_flash('error', 'AI Image Edit is temporarily unavailable. You haven\'t been charged.');
        sleek_redirect('ai-image-edit');
    }

    $title = mb_strlen($instruction) > 70 ? mb_substr($instruction, 0, 67) . '…' : $instruction;
    try {
        $jobId = sleek_job_start((int) $me['id'], 'ai-image-edit', "“{$title}”", time());
    } catch (SleekInsufficientCredits) {
        $keep();
        sleek_flash('error', "AI Image Edit costs {$tool['credits']} credits and your balance is too low.", ['buy' => true]);
        sleek_redirect('ai-image-edit');
    }

    ignore_user_abort(true);
    set_time_limit(240);

    try {
        $out = sleek_decart_edit_image($img['bytes'], $img['mime'], $instruction);
        if (sleek_job_complete($jobId, $out['bytes'], $out['mime'])) {
            sleek_flash('success', 'Done — your edited photo is ready below.');
        } else {
            sleek_flash('error', 'That took too long, so your credits were refunded. Please try again.');
        }
    } catch (Throwable $e) {
        sleek_job_fail($jobId, "We couldn't edit that photo. Your {$tool['credits']} credits were refunded.", $e->getMessage());
        $keep();
        sleek_flash('error', "We couldn't edit that photo. Your {$tool['credits']} credits were refunded — try rewording the instruction.");
    }
    sleek_redirect('ai-image-edit');
}

$pageTitle = 'AI Image Edit';
$active    = 'overview';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';

$flash = sleek_flash_take();
$old   = $_SESSION['edit_old'] ?? ['instruction' => ''];
unset($_SESSION['edit_old']);

$results   = sleek_user_jobs((int) $me['id'], 'ai-image-edit', 20);
$canAfford = $user['credits'] >= $tool['credits'];
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">
    <a href="dashboard" class="text-[13.5px] text-muted hover:text-paper">← All tools</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">AI Image Edit</h1>
            <p class="mt-3 max-w-[60ch] text-[15px] text-muted"><?= e($tool['blurb']) ?> Say what should change — the rest of the photo stays as it is.</p>
        </div>
        <span class="rounded-full border border-signal/30 bg-signal/[0.08] px-3 py-1 font-mono text-[13px] text-signal"><?= e($tool['price']) ?></span>
    </div>

    <?php sleek_render_flash($flash); ?>

    <form method="POST" action="ai-image-edit" enctype="multipart/form-data" data-job-form
          class="mt-7 space-y-6 rounded-xl border border-border bg-surface p-5 sm:p-7">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= EDIT_MAX_BYTES ?>">

        <div>
            <label for="photo" class="block text-[14.5px] text-muted">Photo</label>
            <label for="photo" data-drop
                   class="mt-3 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-border bg-ink/60 px-6 py-8 text-center transition-colors hover:border-signal/50">
                <span class="empty-dot" aria-hidden="true"><i></i></span>
                <span class="mt-4 text-[14.5px] text-paper" data-drop-label>Choose a photo, or drop it here</span>
                <span class="mt-1 text-[12.5px] text-muted">JPEG, PNG or WebP · at least 256×256 · up to 10 MB</span>
            </label>
            <input id="photo" name="photo" type="file" required class="sr-only" accept="image/jpeg,image/png,image/webp">
        </div>

        <label class="block">
            <span class="flex items-baseline justify-between">
                <span class="text-[14.5px] text-muted">What should change?</span>
                <span class="font-mono text-[12px] text-muted"><span data-count><?= mb_strlen($old['instruction']) ?></span> / <?= EDIT_INSTRUCTION_MAX ?></span>
            </span>
            <textarea name="instruction" rows="3" required maxlength="<?= EDIT_INSTRUCTION_MAX ?>" data-counted
                      placeholder="e.g. Replace the background with a beach at sunset"
                      class="mt-2 w-full resize-y rounded-lg border border-border bg-ink px-4 py-3 text-[14.5px] leading-relaxed text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none"><?= e($old['instruction']) ?></textarea>
        </label>

        <label class="flex items-start gap-3 rounded-lg border border-border bg-ink/60 px-4 py-3.5 text-[13.5px] leading-relaxed text-muted">
            <input type="checkbox" name="consent" value="1" required class="mt-1">
            <span>I have the right to edit this photo, and anyone recognisable in it has agreed. I won't use the result to impersonate or mislead anyone. <span class="text-paper">Logged with this job.</span></span>
        </label>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-[13.5px] text-muted">
                Balance: <span class="font-mono <?= $canAfford ? 'text-paper' : 'text-tally' ?>"><?= number_format($user['credits']) ?> credits</span>
                <?php if (!$canAfford): ?> · <a href="credits-billing" class="text-signal underline">Buy credits</a><?php endif; ?>
            </p>
            <button type="submit" <?= $canAfford ? '' : 'disabled' ?>
                    class="btn-glow rounded-lg bg-signal px-6 py-3 text-[14.5px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none">
                Edit photo · <?= number_format($tool['credits']) ?> credits
            </button>
        </div>
    </form>

    <h2 class="mt-11 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">Your results</h2>
    <?php if ($results): ?>
        <?php sleek_render_jobs($results, false); ?>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper">No edits yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Your edited photos will appear here.</p>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
