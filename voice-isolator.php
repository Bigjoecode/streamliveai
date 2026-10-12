<?php
/**
 * Voice Isolator — upload audio (or a video with sound), get back just the speech.
 * Powered by ElevenLabs audio isolation. The upload is never stored: it is read
 * straight from PHP's temp file and discarded when the request ends.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/jobs.php';
require __DIR__ . '/inc/elevenlabs.php';

const ISOLATOR_MAX_BYTES = 25 * 1024 * 1024;

$me   = require_auth();
$tool = sleek_tool('voice-isolator');

if (sleek_is_post()) {
    if (sleek_request_too_large()) {
        sleek_flash('error', 'That file is too large. The limit is 25 MB.');
        sleek_redirect('voice-isolator');
    }
    csrf_verify();

    $up = sleek_check_audio_upload($_FILES['audio'] ?? null, ISOLATOR_MAX_BYTES);
    if (!$up['ok']) {
        sleek_flash('error', $up['error']);
        sleek_redirect('voice-isolator');
    }

    if (!sleek_elevenlabs_configured()) {
        sleek_jobs_log('voice-isolator blocked: ElevenLabs api_key is empty in inc/config.php');
        sleek_flash('error', 'Voice Isolator is temporarily unavailable. You haven\'t been charged.');
        sleek_redirect('voice-isolator');
    }

    try {
        $jobId = sleek_job_start((int) $me['id'], 'voice-isolator', $up['name']);
    } catch (SleekInsufficientCredits) {
        sleek_flash('error', "Voice Isolator costs {$tool['credits']} credits and your balance is too low.", ['buy' => true]);
        sleek_redirect('voice-isolator');
    }

    // Charged — now see it through even if the browser gives up waiting.
    ignore_user_abort(true);
    set_time_limit(300);

    try {
        $out = sleek_el_isolate($up['path'], $up['mime'], 'input.' . $up['ext']);
        if (sleek_job_complete($jobId, $out['bytes'], $out['mime'])) {
            sleek_flash('success', 'Done — your isolated audio is ready below.');
        } else {
            sleek_flash('error', 'That took too long, so your credits were refunded. Please try again.');
        }
    } catch (Throwable $e) {
        sleek_job_fail($jobId, "We couldn't process that file. Your {$tool['credits']} credits were refunded.", $e->getMessage());
        sleek_flash('error', "We couldn't process that file. Your {$tool['credits']} credits were refunded — please try a different file.");
    }
    sleek_redirect('voice-isolator');
}


$pageTitle = 'Voice Isolator';
$active    = 'overview';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';

$flash     = sleek_flash_take();
$results   = sleek_user_jobs((int) $me['id'], 'voice-isolator', 20);
$canAfford = $user['credits'] >= $tool['credits'];
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">
    <a href="dashboard" class="text-[13.5px] text-muted hover:text-paper">← All tools</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Voice Isolator</h1>
            <p class="mt-3 max-w-[60ch] text-[15px] text-muted"><?= e($tool['blurb']) ?> Upload a recording or a video with sound — you get back clean speech as an MP3.</p>
        </div>
        <span class="rounded-full border border-signal/30 bg-signal/[0.08] px-3 py-1 font-mono text-[13px] text-signal"><?= e($tool['price']) ?></span>
    </div>

    <?php sleek_render_flash($flash); ?>

    <form method="POST" action="voice-isolator" enctype="multipart/form-data" data-job-form
          class="mt-7 rounded-xl border border-border bg-surface p-5 sm:p-7">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= ISOLATOR_MAX_BYTES ?>">

        <label for="audio" class="block text-[14.5px] text-muted">Audio or video file</label>
        <label for="audio" data-drop
               class="mt-3 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-border bg-ink/60 px-6 py-10 text-center transition-colors hover:border-signal/50">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <span class="mt-4 text-[14.5px] text-paper" data-drop-label>Choose a file, or drop it here</span>
            <span class="mt-1 text-[12.5px] text-muted">MP3, WAV, M4A, OGG, FLAC, MP4, WebM or MOV · up to 25 MB</span>
        </label>
        <input id="audio" name="audio" type="file" required class="sr-only"
               accept="audio/*,video/mp4,video/webm,video/quicktime">

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-[13.5px] text-muted">
                Balance: <span class="font-mono <?= $canAfford ? 'text-paper' : 'text-tally' ?>"><?= number_format($user['credits']) ?> credits</span>
                <?php if (!$canAfford): ?> · <a href="credits-billing" class="text-signal underline">Buy credits</a><?php endif; ?>
            </p>
            <button type="submit" <?= $canAfford ? '' : 'disabled' ?>
                    class="btn-glow rounded-lg bg-signal px-6 py-3 text-[14.5px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none">
                Isolate voice · <?= number_format($tool['credits']) ?> credits
            </button>
        </div>
    </form>

    <h2 class="mt-11 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">Your results</h2>
    <?php if ($results): ?>
        <?php sleek_render_jobs($results, false); ?>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper">Nothing isolated yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Your cleaned-up audio will appear here.</p>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
