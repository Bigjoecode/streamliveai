<?php
/**
 * Livestream — real-time character swap from the webcam, powered by Decart.
 * The camera feed and reference photo go from the browser straight to Decart;
 * this page and livestream-api only handle the money and the short-lived token.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/livestream.php';

$me = require_auth();

$pageTitle = 'Livestream';
$active    = 'overview';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';

$tool    = sleek_tool('livestream');
$cfg     = sleek_config('decart');
$rate    = (int) $cfg['credits_per_second'];
$flash   = sleek_flash_take();
$open    = sleek_live_active((int) $me['id']);
$results = sleek_user_jobs((int) $me['id'], 'livestream', 20);
$minCost = min($cfg['session_minutes']) * 60 * $rate;
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10"
      data-live
      data-api="livestream-api"
      data-csrf="<?= e(csrf_token()) ?>"
      data-rate="<?= $rate ?>"
      data-model="<?= e($cfg['model']) ?>"
      data-balance="<?= (int) $user['credits'] ?>">

    <a href="dashboard" class="text-[13.5px] text-muted hover:text-paper">← All tools</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Livestream</h1>
            <p class="mt-3 max-w-[62ch] text-[15px] text-muted"><?= e($tool['blurb']) ?> Pick a reference photo, go live, and capture the output window in OBS or any streaming app.</p>
        </div>
        <span class="rounded-full border border-signal/30 bg-signal/[0.08] px-3 py-1 font-mono text-[13px] text-signal"><?= e($tool['price']) ?></span>
    </div>

    <?php sleek_render_flash($flash); ?>

    <?php if ($open): ?>
        <form method="POST" action="livestream-api"
              class="mt-6 flex flex-col gap-3 rounded-lg border border-signal/40 bg-signal/10 px-4 py-3 text-[14px] text-paper sm:flex-row sm:items-center sm:justify-between">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="end">
            <input type="hidden" name="job" value="<?= (int) $open['job_id'] ?>">
            <input type="hidden" name="seconds" value="0">
            <span>A live session you started <?= e(sleek_ago((int) $open['created_at'])) ?> is still open. If it isn't running anywhere, end it to settle your credits.</span>
            <button type="submit" class="shrink-0 rounded-lg border border-signal/50 px-4 py-2 text-[13.5px] font-medium text-signal hover:bg-signal/10">End that session</button>
        </form>
    <?php endif; ?>

    <div class="mt-7 grid grid-cols-1 gap-5 xl:grid-cols-5">

        <!-- ------------------------------------------------------ stage -->
        <section class="xl:col-span-3">
            <div class="relative aspect-[1088/624] w-full overflow-hidden rounded-xl border border-border bg-ink">
                <video id="liveOut" autoplay playsinline muted class="absolute inset-0 h-full w-full object-cover"></video>

                <div id="livePlaceholder" class="absolute inset-0 flex flex-col items-center justify-center px-6 text-center">
                    <span class="empty-dot" aria-hidden="true"><i></i></span>
                    <p class="mt-4 text-[14.5px] text-paper">Your transformed video appears here</p>
                    <p class="mt-1 text-[12.5px] text-muted">Set up on the right, then go live</p>
                </div>

                <span id="liveStatus" class="absolute left-3 top-3 hidden items-center gap-1.5 rounded-full bg-ink/80 px-2.5 py-1 font-mono text-[11px] tracking-wider text-muted backdrop-blur">
                    <span class="h-1.5 w-1.5 rounded-full bg-muted" data-dot></span><span data-text>IDLE</span>
                </span>
                <span id="liveClock" class="absolute right-3 top-3 hidden rounded-full bg-ink/80 px-2.5 py-1 font-mono text-[12px] text-paper backdrop-blur">0:00 left</span>

                <video id="liveSelf" autoplay playsinline muted
                       class="absolute bottom-3 right-3 hidden aspect-[1088/624] w-[22%] min-w-[96px] rounded-lg border border-white/15 object-cover shadow-lg [transform:scaleX(-1)]"></video>
            </div>

            <div id="liveControls" class="mt-3 hidden flex-col gap-3 sm:flex-row sm:items-center">
                <form id="liveRestyle" class="flex flex-1 gap-2">
                    <input name="prompt" maxlength="300" placeholder="Change the look while live, e.g. “wearing a red cape”"
                           class="min-w-0 flex-1 rounded-lg border border-border bg-ink px-3.5 py-2.5 text-[14px] text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none">
                    <button type="submit" class="shrink-0 rounded-lg border border-border px-4 py-2.5 text-[13.5px] text-paper hover:border-signal/40">Update</button>
                </form>
                <div class="flex gap-2">
                    <button type="button" id="liveFull" class="rounded-lg border border-border px-4 py-2.5 text-[13.5px] text-paper hover:border-signal/40">Fullscreen</button>
                    <button type="button" id="liveEnd" class="rounded-lg bg-tally px-4 py-2.5 text-[13.5px] font-semibold text-paper hover:brightness-110">End stream</button>
                </div>
            </div>

            <div id="liveMessage" role="status" class="mt-3 hidden rounded-lg border px-4 py-3 text-[14px] text-paper"></div>
        </section>

        <!-- ------------------------------------------------------ setup -->
        <form id="liveForm" class="space-y-5 rounded-xl border border-border bg-surface p-5 sm:p-6 xl:col-span-2" novalidate>
            <div>
                <label for="livePhoto" class="block text-[14.5px] text-muted">Reference photo</label>
                <label for="livePhoto" data-drop
                       class="mt-3 flex cursor-pointer items-center gap-4 rounded-lg border border-dashed border-border bg-ink/60 p-4 transition-colors hover:border-signal/50">
                    <img id="livePhotoPreview" alt="" class="hidden h-16 w-16 shrink-0 rounded-lg object-cover">
                    <span id="livePhotoDot" class="empty-dot shrink-0" aria-hidden="true"><i></i></span>
                    <span>
                        <span class="block text-[14px] text-paper" data-drop-label>Choose a clear, front-facing photo</span>
                        <span class="mt-1 block text-[12.5px] text-muted">Best results: sharp, well lit, head and shoulders like your camera shot, at least 512×512.</span>
                        <span class="mt-0.5 block text-[12px] text-muted">JPEG, PNG or WebP · at least 512×512 · under 5 MB</span>
                    </span>
                </label>
                <input id="livePhoto" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
            </div>

            <label class="block">
                <span class="block text-[14.5px] text-muted">Look <span class="text-[12.5px]">(optional)</span></span>
                <input id="livePrompt" maxlength="300" placeholder="e.g. in a studio with soft lighting"
                       class="mt-2 w-full rounded-lg border border-border bg-ink px-3.5 py-2.5 text-[14px] text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none">
            </label>

            <fieldset>
                <legend class="text-[14.5px] text-muted">Session length</legend>
                <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-3">
                    <?php foreach ($cfg['session_minutes'] as $i => $m): $cost = $m * 60 * $rate; $ok = $user['credits'] >= $cost; ?>
                        <label class="<?= $ok ? 'cursor-pointer' : 'cursor-not-allowed opacity-40' ?>">
                            <input type="radio" name="minutes" value="<?= $m ?>" class="peer sr-only" <?= $ok ? '' : 'disabled' ?> <?= $ok && $i === 0 ? 'checked' : '' ?>>
                            <span class="block rounded-lg border border-border bg-ink px-2 py-2 text-center transition-colors peer-checked:border-signal/60 peer-checked:bg-signal/10 peer-focus-visible:ring-2 peer-focus-visible:ring-signal/40">
                                <span class="block text-[14px] text-paper"><?= $m ?> min</span>
                                <span class="block font-mono text-[11px] text-muted"><?= number_format($cost) ?> cr</span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="mt-2 text-[12.5px] leading-relaxed text-muted">
                    Charged up front; the stream stops automatically at the limit.
                    <?php if ($cfg['refund_unused']): ?>End early and unused time is refunded.<?php else: ?>Unused time isn't refunded.<?php endif; ?>
                </p>
            </fieldset>

            <label class="flex items-start gap-3 rounded-lg border border-border bg-ink/60 px-4 py-3.5 text-[13px] leading-relaxed text-muted">
                <input type="checkbox" id="liveConsent" class="mt-1">
                <span>The face in my reference photo is mine, or I have that person's explicit permission to use it. I won't use this to impersonate or mislead anyone. <span class="text-paper">Logged with the session.</span></span>
            </label>

            <div class="flex flex-col gap-3">
                <p class="text-[13.5px] text-muted">
                    Balance: <span class="font-mono <?= $user['credits'] >= $minCost ? 'text-paper' : 'text-tally' ?>"><?= number_format($user['credits']) ?> credits</span>
                    <?php if ($user['credits'] < $minCost): ?> · <a href="credits-billing" class="text-signal underline">Buy credits</a><?php endif; ?>
                </p>
                <button type="submit" id="liveGo" disabled
                        class="btn-glow w-full rounded-lg bg-signal py-3 text-[14.5px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none">
                    Loading…
                </button>
                <p class="text-[12px] leading-relaxed text-muted">Your camera and reference photo go straight from this browser to our video partner, Decart — they never pass through StreamLive AI's servers.</p>
            </div>
        </form>
    </div>

    <h2 class="mt-11 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">Your sessions</h2>
    <?php if ($results): ?>
        <?php sleek_render_jobs($results, false); ?>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper">No live sessions yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Each stream you run will be listed here with its length and cost.</p>
        </section>
    <?php endif; ?>
</main>

<script type="module" src="assets/livestream.js?v=<?= (int) @filemtime(__DIR__ . '/assets/livestream.js') ?>"></script>
<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
