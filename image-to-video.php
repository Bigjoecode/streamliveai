<?php
/**
 * Image to Video — turn a photo into a talking video.
 *   1. ElevenLabs speaks the script in the chosen voice (MP3).
 *   2. fal's Kling AI Avatar animates the photo to that audio (MP4, queued).
 * Rendering takes minutes, so this page only submits; the job completes later via
 * inc/fal.php as this page, Job History, or any page load checks on it.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/jobs.php';
require __DIR__ . '/inc/images.php';
require __DIR__ . '/inc/elevenlabs.php';
require __DIR__ . '/inc/fal.php';

const I2V_MAX_BYTES  = 10 * 1024 * 1024;
const I2V_SCRIPT_MIN = 30;    // shorter speaks in under 2 s, which the video model rejects
const I2V_SCRIPT_MAX = 700;   // roughly 45 s spoken; the model's limit is 60 s

$me   = require_auth();
$tool = sleek_tool('image-to-video');
$str  = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$ready = fn(): bool => sleek_elevenlabs_configured() && sleek_fal_configured();

if (sleek_is_post()) {
    if (sleek_request_too_large()) {
        sleek_flash('error', 'That photo is too large. The limit is 10 MB.');
        sleek_redirect('image-to-video');
    }
    csrf_verify();

    $script  = trim(str_replace("\r\n", "\n", $str('script')));
    $voiceId = $str('voice');
    $keep    = fn() => $_SESSION['i2v_old'] = ['script' => $script, 'voice' => $voiceId];

    if (!$ready()) {
        sleek_jobs_log('image-to-video blocked: ElevenLabs and/or fal api_key is empty');
        $keep();
        sleek_flash('error', 'Image to Video is temporarily unavailable. You haven\'t been charged.');
        sleek_redirect('image-to-video');
    }

    $img   = sleek_check_image_upload($_FILES['photo'] ?? null, I2V_MAX_BYTES);
    $voice = sleek_el_voice($voiceId);
    $error = match (true) {
        !$img['ok']                         => $img['error'],
        $voice === null                     => 'Pick a voice from the list.',
        mb_strlen($script) < I2V_SCRIPT_MIN => 'Write a little more — at least a full sentence (' . I2V_SCRIPT_MIN . ' characters).',
        mb_strlen($script) > I2V_SCRIPT_MAX => 'Keep the script under ' . I2V_SCRIPT_MAX . ' characters (about 45 seconds of speech).',
        !isset($_POST['consent'])           => 'Confirm you have permission to animate the person in this photo.',
        default                             => null,
    };
    if ($error !== null) {
        $keep();
        sleek_flash('error', $error);
        sleek_redirect('image-to-video');
    }

    $excerpt = mb_strlen($script) > 55 ? mb_substr($script, 0, 52) . '…' : $script;
    try {
        $jobId = sleek_job_start((int) $me['id'], 'image-to-video',
            $voice['name'] . ' — “' . str_replace("\n", ' ', $excerpt) . '”', time());
    } catch (SleekInsufficientCredits) {
        $keep();
        sleek_flash('error', "Image to Video costs {$tool['credits']} credits and your balance is too low.", ['buy' => true]);
        sleek_redirect('image-to-video');
    }

    ignore_user_abort(true);
    set_time_limit(180);

    try {
        $audio   = sleek_el_tts($voice['id'], $script);
        $seconds = sleek_mp3_128_seconds($audio['bytes']);
        if ($seconds < 2) {
            throw new DomainException('That script is too short once spoken. Add another sentence.');
        }
        if ($seconds > 60) {
            throw new DomainException('That script runs over 60 seconds once spoken. Shorten it a little.');
        }

        $sub = sleek_fal_submit(sleek_config('fal')['avatar_model'], [
            'image_url' => sleek_data_uri($img['bytes'], $img['mime']),
            'audio_url' => sleek_data_uri($audio['bytes'], 'audio/mpeg'),
        ]);
        sleek_async_record($jobId, 'fal', $sub);

        sleek_flash('success', 'Your video is rendering — usually a few minutes. You can leave this page; it will appear below and in Job History.');
    } catch (DomainException $e) {
        sleek_job_fail($jobId, $e->getMessage() . " Your {$tool['credits']} credits were refunded.", 'script length');
        $keep();
        sleek_flash('error', $e->getMessage() . " Your {$tool['credits']} credits were refunded.");
    } catch (Throwable $e) {
        sleek_job_fail($jobId, "We couldn't start that video. Your {$tool['credits']} credits were refunded.", $e->getMessage());
        $keep();
        sleek_flash('error', "We couldn't start that video. Your {$tool['credits']} credits were refunded — please try again.");
    }
    sleek_redirect('image-to-video');
}

$pageTitle = 'Image to Video';
$active    = 'overview';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';

$flash  = sleek_flash_take();
$old    = $_SESSION['i2v_old'] ?? ['script' => '', 'voice' => ''];
unset($_SESSION['i2v_old']);

$voices    = $ready() ? sleek_el_voices() : [];
$results   = sleek_user_jobs((int) $me['id'], 'image-to-video', 20);
$canAfford = $user['credits'] >= $tool['credits'];
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">
    <a href="dashboard" class="text-[13.5px] text-muted hover:text-paper">← All tools</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Image to Video</h1>
            <p class="mt-3 max-w-[60ch] text-[15px] text-muted"><?= e($tool['blurb']) ?> Videos render in the background in a few minutes.</p>
        </div>
        <span class="rounded-full border border-signal/30 bg-signal/[0.08] px-3 py-1 font-mono text-[13px] text-signal"><?= e($tool['price']) ?></span>
    </div>

    <?php sleek_render_flash($flash); ?>

    <?php if (!$ready()): ?>
        <div class="mt-6 rounded-lg border border-signal/40 bg-signal/10 px-4 py-3 text-[14.5px] text-paper">Image to Video is temporarily unavailable.</div>
    <?php endif; ?>

    <form method="POST" action="image-to-video" enctype="multipart/form-data" data-job-form
          class="mt-7 space-y-6 rounded-xl border border-border bg-surface p-5 sm:p-7">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= I2V_MAX_BYTES ?>">

        <div>
            <label for="photo" class="block text-[14.5px] text-muted">Photo</label>
            <label for="photo" data-drop
                   class="mt-3 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-border bg-ink/60 px-6 py-8 text-center transition-colors hover:border-signal/50">
                <span class="empty-dot" aria-hidden="true"><i></i></span>
                <span class="mt-4 text-[14.5px] text-paper" data-drop-label>Choose a clear, front-facing portrait</span>
                <span class="mt-1 text-[12.5px] text-muted">JPEG, PNG or WebP · at least 256×256 · up to 10 MB</span>
            </label>
            <input id="photo" name="photo" type="file" required class="sr-only" accept="image/jpeg,image/png,image/webp">
        </div>

        <div>
            <label for="voice" class="block text-[14.5px] text-muted">Voice</label>
            <div class="mt-2 flex gap-2">
                <select id="voice" name="voice" required <?= $voices ? '' : 'disabled' ?>
                        class="min-w-0 flex-1 rounded-lg border border-border bg-ink px-3.5 py-3 text-[14.5px] text-paper focus:border-signal/50 focus:outline-none">
                    <?php if (!$voices): ?>
                        <option value="">Voices unavailable</option>
                    <?php endif; ?>
                    <?php foreach ($voices as $v): ?>
                        <option value="<?= e($v['id']) ?>" data-preview="<?= e($v['preview_url']) ?>" <?= $old['voice'] === $v['id'] ? 'selected' : '' ?>>
                            <?= e($v['name']) ?><?= $v['description'] !== '' ? ' — ' . e($v['description']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" data-voice-preview <?= $voices ? '' : 'disabled' ?>
                        class="shrink-0 rounded-lg border border-border px-4 text-[13.5px] text-paper hover:border-signal/40 disabled:opacity-40">▶ Preview</button>
            </div>
        </div>

        <label class="block">
            <span class="flex items-baseline justify-between">
                <span class="text-[14.5px] text-muted">Script</span>
                <span class="font-mono text-[12px] text-muted"><span data-count><?= mb_strlen($old['script']) ?></span> / <?= I2V_SCRIPT_MAX ?></span>
            </span>
            <textarea name="script" rows="5" required minlength="<?= I2V_SCRIPT_MIN ?>" maxlength="<?= I2V_SCRIPT_MAX ?>" data-counted
                      placeholder="What should the person in the photo say?"
                      class="mt-2 w-full resize-y rounded-lg border border-border bg-ink px-4 py-3 text-[14.5px] leading-relaxed text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none"><?= e($old['script']) ?></textarea>
        </label>

        <label class="flex items-start gap-3 rounded-lg border border-border bg-ink/60 px-4 py-3.5 text-[13.5px] leading-relaxed text-muted">
            <input type="checkbox" name="consent" value="1" required class="mt-1">
            <span>This is me, or the person in the photo has given explicit permission for it to be animated. I won't use it to impersonate or mislead anyone. <span class="text-paper">Logged with this job.</span></span>
        </label>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-[13.5px] text-muted">
                Balance: <span class="font-mono <?= $canAfford ? 'text-paper' : 'text-tally' ?>"><?= number_format($user['credits']) ?> credits</span>
                <?php if (!$canAfford): ?> · <a href="credits-billing" class="text-signal underline">Buy credits</a><?php endif; ?>
            </p>
            <button type="submit" <?= $canAfford && $voices ? '' : 'disabled' ?>
                    class="btn-glow rounded-lg bg-signal px-6 py-3 text-[14.5px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none">
                Make video · <?= number_format($tool['credits']) ?> credits
            </button>
        </div>
    </form>

    <h2 class="mt-11 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">Your videos</h2>
    <?php if ($results): ?>
        <?php sleek_render_jobs($results, false); ?>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper">No videos yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Your talking videos will appear here when they finish rendering.</p>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
