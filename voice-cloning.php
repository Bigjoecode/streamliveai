<?php
/**
 * Voice Cloning — clone a voice from 1–3 samples, then have it speak a script.
 * Powered by ElevenLabs instant voice cloning + text-to-speech.
 *
 * Consent is required and recorded on the job (jobs.consent_at). The cloned voice
 * exists on ElevenLabs only for the length of the request: it is deleted as soon
 * as the audio is generated, whether or not generation succeeded. Samples are
 * never stored — they're read from PHP's temp files and discarded.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/jobs.php';
require __DIR__ . '/inc/elevenlabs.php';

const CLONE_SAMPLE_MAX_BYTES = 10 * 1024 * 1024;
const CLONE_MAX_SAMPLES      = 3;
const CLONE_SCRIPT_MAX       = 2500;

$me   = require_auth();
$tool = sleek_tool('voice-cloning');

$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';

if (sleek_is_post()) {
    if (sleek_request_too_large()) {
        sleek_flash('error', 'Those samples are too large together. Keep each under 10 MB.');
        sleek_redirect('voice-cloning');
    }
    csrf_verify();

    $voiceName = mb_substr(trim(preg_replace('/\s+/', ' ', $str('voice_name'))), 0, 60);
    $script    = trim(str_replace("\r\n", "\n", $str('script')));
    $errors    = [];

    $files = sleek_files_list($_FILES['samples'] ?? null);
    $samples = [];
    if (!$files) {
        $errors[] = 'Add at least one voice sample.';
    } elseif (count($files) > CLONE_MAX_SAMPLES) {
        $errors[] = 'Use at most ' . CLONE_MAX_SAMPLES . ' samples.';
    } else {
        foreach ($files as $f) {
            $up = sleek_check_audio_upload($f, CLONE_SAMPLE_MAX_BYTES);
            if (!$up['ok']) { $errors[] = $up['error']; break; }
            $samples[] = ['path' => $up['path'], 'mime' => $up['mime'], 'name' => 'sample-' . (count($samples) + 1) . '.' . $up['ext']];
        }
    }

    if ($script === '') {
        $errors[] = 'Write the script you want the voice to say.';
    } elseif (mb_strlen($script) > CLONE_SCRIPT_MAX) {
        $errors[] = 'Keep the script under ' . number_format(CLONE_SCRIPT_MAX) . ' characters.';
    }

    if (!isset($_POST['consent'])) {
        $errors[] = 'Confirm that you have the speaker\'s permission to clone this voice.';
    }

    if ($errors) {
        // Keep what they typed; files can't be kept across a redirect.
        $_SESSION['clone_old'] = ['voice_name' => $voiceName, 'script' => $script];
        sleek_flash('error', $errors[0]);
        sleek_redirect('voice-cloning');
    }

    if (!sleek_elevenlabs_configured()) {
        sleek_jobs_log('voice-cloning blocked: ElevenLabs api_key is empty in inc/config.php');
        $_SESSION['clone_old'] = ['voice_name' => $voiceName, 'script' => $script];
        sleek_flash('error', 'Voice Cloning is temporarily unavailable. You haven\'t been charged.');
        sleek_redirect('voice-cloning');
    }

    $excerpt = mb_strlen($script) > 60 ? mb_substr($script, 0, 57) . '…' : $script;
    $title   = ($voiceName !== '' ? $voiceName : 'Cloned voice') . ' — “' . str_replace("\n", ' ', $excerpt) . '”';

    try {
        $jobId = sleek_job_start((int) $me['id'], 'voice-cloning', $title, time());
    } catch (SleekInsufficientCredits) {
        $_SESSION['clone_old'] = ['voice_name' => $voiceName, 'script' => $script];
        sleek_flash('error', "Voice Cloning costs {$tool['credits']} credits and your balance is too low.", ['buy' => true]);
        sleek_redirect('voice-cloning');
    }

    ignore_user_abort(true);
    set_time_limit(300);

    $voiceId = null;
    try {
        // The provider-side name carries no user data — just our job number.
        $voiceId = sleek_el_add_voice("LucaAi job {$jobId}", $samples);
        $out     = sleek_el_tts($voiceId, $script);
        if (sleek_job_complete($jobId, $out['bytes'], $out['mime'])) {
            sleek_flash('success', 'Done — your cloned voice is ready below.');
        } else {
            sleek_flash('error', 'That took too long, so your credits were refunded. Please try again.');
        }
    } catch (Throwable $e) {
        sleek_job_fail($jobId, "We couldn't clone that voice. Your {$tool['credits']} credits were refunded.", $e->getMessage());
        $_SESSION['clone_old'] = ['voice_name' => $voiceName, 'script' => $script];
        sleek_flash('error', "We couldn't clone that voice. Your {$tool['credits']} credits were refunded — try a longer, clearer sample.");
    } finally {
        if ($voiceId !== null) sleek_el_delete_voice($voiceId);
    }
    sleek_redirect('voice-cloning');
}


$pageTitle = 'Voice Cloning';
$active    = 'overview';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';

$flash = sleek_flash_take();
$old   = $_SESSION['clone_old'] ?? ['voice_name' => '', 'script' => ''];
unset($_SESSION['clone_old']);

$results   = sleek_user_jobs((int) $me['id'], 'voice-cloning', 20);
$canAfford = $user['credits'] >= $tool['credits'];
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">
    <a href="dashboard" class="text-[13.5px] text-muted hover:text-paper">← All tools</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Voice Cloning</h1>
            <p class="mt-3 max-w-[60ch] text-[15px] text-muted"><?= e($tool['blurb']) ?> A minute or two of clean speech gives the best match.</p>
        </div>
        <span class="rounded-full border border-signal/30 bg-signal/[0.08] px-3 py-1 font-mono text-[13px] text-signal"><?= e($tool['price']) ?></span>
    </div>

    <?php sleek_render_flash($flash); ?>

    <form method="POST" action="voice-cloning" enctype="multipart/form-data" data-job-form
          class="mt-7 space-y-6 rounded-xl border border-border bg-surface p-5 sm:p-7">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= CLONE_SAMPLE_MAX_BYTES ?>">

        <div>
            <label for="samples" class="block text-[14.5px] text-muted">Voice samples</label>
            <label for="samples" data-drop
                   class="mt-3 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-border bg-ink/60 px-6 py-8 text-center transition-colors hover:border-signal/50">
                <span class="empty-dot" aria-hidden="true"><i></i></span>
                <span class="mt-4 text-[14.5px] text-paper" data-drop-label>Choose up to <?= CLONE_MAX_SAMPLES ?> files, or drop them here</span>
                <span class="mt-1 text-[12.5px] text-muted">Only one speaker, no music · MP3, WAV, M4A… · up to 10 MB each</span>
            </label>
            <input id="samples" name="samples[]" type="file" multiple required class="sr-only"
                   accept="audio/*,video/mp4,video/webm,video/quicktime">
        </div>

        <label class="block">
            <span class="block text-[14.5px] text-muted">Voice name <span class="text-[12.5px]">(optional)</span></span>
            <input name="voice_name" maxlength="60" value="<?= e($old['voice_name']) ?>" placeholder="e.g. My narration voice"
                   class="mt-2 w-full rounded-lg border border-border bg-ink px-4 py-3 text-[14.5px] text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none">
        </label>

        <label class="block">
            <span class="flex items-baseline justify-between">
                <span class="text-[14.5px] text-muted">Script</span>
                <span class="font-mono text-[12px] text-muted"><span data-count><?= mb_strlen($old['script']) ?></span> / <?= number_format(CLONE_SCRIPT_MAX) ?></span>
            </span>
            <textarea name="script" rows="6" required maxlength="<?= CLONE_SCRIPT_MAX ?>" data-counted
                      placeholder="What should the cloned voice say?"
                      class="mt-2 w-full resize-y rounded-lg border border-border bg-ink px-4 py-3 text-[14.5px] leading-relaxed text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none"><?= e($old['script']) ?></textarea>
        </label>

        <label class="flex items-start gap-3 rounded-lg border border-border bg-ink/60 px-4 py-3.5 text-[13.5px] leading-relaxed text-muted">
            <input type="checkbox" name="consent" value="1" required class="mt-1">
            <span>This is my voice, or I have the speaker's explicit permission to clone it. I won't use it to impersonate or mislead anyone. <span class="text-paper">Your confirmation is logged with this job.</span></span>
        </label>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-[13.5px] text-muted">
                Balance: <span class="font-mono <?= $canAfford ? 'text-paper' : 'text-tally' ?>"><?= number_format($user['credits']) ?> credits</span>
                <?php if (!$canAfford): ?> · <a href="credits-billing" class="text-signal underline">Buy credits</a><?php endif; ?>
            </p>
            <button type="submit" <?= $canAfford ? '' : 'disabled' ?>
                    class="btn-glow rounded-lg bg-signal px-6 py-3 text-[14.5px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none">
                Clone &amp; speak · <?= number_format($tool['credits']) ?> credits
            </button>
        </div>
    </form>

    <h2 class="mt-11 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">Your results</h2>
    <?php if ($results): ?>
        <?php sleek_render_jobs($results, false); ?>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper">No cloned audio yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Everything your cloned voices say will appear here.</p>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
