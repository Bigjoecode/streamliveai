<?php
/**
 * Livestream — real-time face swap from the webcam. Engines: XMAX X2.1 / X2.0 and
 * Decart Lucy (fast mode). The camera feed and reference photo go from the browser
 * straight to the provider; this page and livestream-api handle the money, the
 * short-lived keys, group-call rooms and Telegram clips.
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
$engines = sleek_live_engines();
$canCall = sleek_daily_configured();

// Background presets: the label users see => what it adds to the prompt.
$backgrounds = [
    'office'         => ['Modern Office',  'in a modern office with glass walls and soft daylight'],
    'bedroom'        => ['Cozy Bedroom',   'in a cozy bedroom with warm lamp light'],
    'studio'         => ['Podcast Studio', 'in a podcast studio with microphones and acoustic panels'],
    'beach'          => ['Beach Sunset',   'on a beach at golden-hour sunset'],
    'city'           => ['City Skyline',   'in front of a city skyline at dusk'],
    'nature'         => ['Forest',         'in a green forest with soft sunlight'],
    'city_street'    => ['City Street',    'on a busy city street'],
    'private_jet'    => ['Private Jet',    'inside a private jet cabin with cream leather seats'],
    'airport_lounge' => ['Airport Lounge', 'in an airport lounge with large windows'],
    'space_station'  => ['Space Station',  'inside a space station with Earth visible through the window'],
    'hospital_ward'  => ['Hospital Ward',  'in a hospital ward'],
];
$chips = ['soft studio light', 'sharp focus', 'warm lighting'];

$step = fn(int $n) => '<span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-signal/60 font-mono text-[12px] text-signal">' . $n . '</span>';
$chip = 'flex items-center gap-2.5 rounded-lg border border-border bg-ink/60 px-3 py-2.5';
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10"
      data-live
      data-api="livestream-api"
      data-net="livestream-net"
      data-csrf="<?= e(csrf_token()) ?>"
      data-rate="<?= $rate ?>"
      data-balance="<?= (int) $user['credits'] ?>"
      data-call="<?= $canCall ? '1' : '0' ?>"
      data-name="<?= e((string) $me['name']) ?>">

    <a href="dashboard" class="text-[13.5px] text-muted hover:text-paper">← All tools</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="rounded-full border border-signal/40 bg-signal/10 px-2.5 py-0.5 font-mono text-[11px] uppercase tracking-wider text-signal">Livestream</span>
            <h1 class="mt-3 font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Go live as someone else.</h1>
            <p class="mt-3 max-w-[62ch] text-[15px] text-muted">Uses <?= $rate ?> credits per second. Your camera streams directly to the AI provider, where it's transformed and returned live in real time — without passing through our servers.</p>
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

    <?php if (!$engines): ?>
        <p class="mt-6 rounded-lg border border-tally/40 bg-tally/10 px-4 py-3 text-[14px] text-paper">Livestream is temporarily unavailable. Please check back shortly.</p>
    <?php endif; ?>

    <div class="mt-7 grid grid-cols-1 gap-5 xl:grid-cols-5">

        <!-- ------------------------------------------------------ setup -->
        <form id="liveForm" class="order-2 space-y-6 rounded-xl border border-border bg-surface p-5 sm:p-6 xl:order-1 xl:col-span-2" novalidate>

            <div>
                <p class="flex items-center gap-2.5 text-[15px] font-medium text-paper"><?= $step(1) ?> Source face</p>
                <label for="livePhoto" data-drop
                       class="mt-3 flex cursor-pointer items-center gap-4 rounded-lg border border-dashed border-border bg-ink/60 p-4 transition-colors hover:border-signal/50">
                    <img id="livePhotoPreview" alt="" class="hidden h-16 w-16 shrink-0 rounded-lg object-cover">
                    <span id="livePhotoDot" class="empty-dot shrink-0" aria-hidden="true"><i></i></span>
                    <span>
                        <span class="block text-[14px] text-paper" data-drop-label>Tap to upload face image</span>
                        <span class="mt-1 block text-[12.5px] text-muted">Sharp, well lit, front-facing, at least 512×512.</span>
                        <span class="mt-0.5 block text-[12px] text-muted">JPG, PNG, WEBP · Max 10MB</span>
                    </span>
                </label>
                <input id="livePhoto" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
            </div>

            <div>
                <p class="flex items-center justify-between">
                    <span class="flex items-center gap-2.5 text-[15px] font-medium text-paper"><?= $step(2) ?> Style prompt</span>
                    <span class="font-mono text-[12px] text-muted"><span id="livePromptCount">0</span> / 150</span>
                </p>
                <textarea id="livePrompt" maxlength="150" rows="2" placeholder="e.g. warm lighting, cinematic look, slight film grain"
                          class="mt-3 w-full resize-y rounded-lg border border-border bg-ink px-3.5 py-2.5 text-[14px] text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none"></textarea>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php foreach ($chips as $c): ?>
                        <button type="button" data-chip="<?= e($c) ?>" class="rounded-full border border-border px-3 py-1 text-[12.5px] text-muted hover:border-signal/50 hover:text-paper">+ <?= e($c) ?></button>
                    <?php endforeach; ?>
                </div>
                <p class="mt-2 text-[12px] text-muted">Extra styling only. It adds detail on top of the face swap and cannot replace it.</p>
            </div>

            <div>
                <p class="flex items-center justify-between">
                    <span class="flex items-center gap-2.5 text-[15px] font-medium text-paper"><?= $step(3) ?> Background</span>
                    <button type="button" id="liveBgToggle" role="switch" aria-checked="false" aria-label="Replace background"
                            class="relative h-6 w-11 rounded-full border border-border bg-ink transition-colors aria-checked:border-signal/60 aria-checked:bg-signal/30">
                        <span class="absolute left-0.5 top-0.5 h-[18px] w-[18px] rounded-full bg-muted transition-transform" data-knob></span>
                    </button>
                </p>
                <div id="liveBgGrid" class="mt-3 hidden grid-cols-2 gap-2 sm:grid-cols-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="background" value="none" class="peer sr-only" checked>
                        <span class="block rounded-lg border border-border bg-ink px-2 py-2 text-center text-[13px] text-paper peer-checked:border-signal/60 peer-checked:bg-signal/10">None</span>
                    </label>
                    <?php foreach ($backgrounds as $k => [$label, $prompt]): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="background" value="<?= e($k) ?>" data-prompt="<?= e($prompt) ?>" class="peer sr-only">
                            <span class="block rounded-lg border border-border bg-ink px-2 py-2 text-center text-[13px] text-paper peer-checked:border-signal/60 peer-checked:bg-signal/10"><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <p class="flex items-center gap-2.5 text-[15px] font-medium text-paper"><?= $step(4) ?> AI model</p>
                <div class="mt-3 grid grid-cols-2 gap-2 <?= count($engines) > 2 ? 'sm:grid-cols-3 xl:grid-cols-2 2xl:grid-cols-3' : '' ?>">
                    <?php $first = true; foreach ($engines as $id => $en): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="engine" value="<?= e($id) ?>" data-provider="<?= e($en['provider']) ?>" class="peer sr-only" <?= $first ? 'checked' : '' ?>>
                            <span class="block rounded-lg border border-border bg-ink px-3 py-2.5 peer-checked:border-signal/70 peer-checked:bg-signal/10">
                                <span class="block font-display text-[16px] font-semibold text-paper"><?= e($en['label']) ?></span>
                                <span class="block text-[12px] text-muted"><?= e($en['note']) ?></span>
                            </span>
                        </label>
                    <?php $first = false; endforeach; ?>
                </div>
                <p class="mt-2 text-[12px] text-muted">All cost the same per second. If one does not start for you, switch to another and try again.</p>
            </div>

            <fieldset>
                <legend class="flex items-center gap-2.5 text-[15px] font-medium text-paper"><?= $step(5) ?> Session length</legend>
                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-3">
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

            <div>
                <p class="text-[15px] font-medium text-paper">Consent</p>
                <label class="mt-3 flex items-start gap-3 rounded-lg border border-border bg-ink/60 px-4 py-3.5 text-[13px] leading-relaxed text-muted">
                    <input type="checkbox" id="liveConsent" class="mt-1">
                    <span>I have the right to use this face — it's mine, or I have that person's explicit permission — and I agree it will be processed by an AI provider for this live session. I won't use it to impersonate or deceive anyone. <span class="text-paper">Logged with the session.</span></span>
                </label>
            </div>

            <div class="flex flex-col gap-3">
                <p class="text-[13.5px] text-muted">
                    Balance: <span class="font-mono <?= $user['credits'] >= $minCost ? 'text-paper' : 'text-tally' ?>"><?= number_format($user['credits']) ?> credits</span>
                    <?php if ($user['credits'] < $minCost): ?> · <a href="credits-billing" class="text-signal underline">Buy credits</a><?php endif; ?>
                </p>
                <button type="submit" id="liveGo" disabled
                        class="btn-glow w-full rounded-lg bg-signal py-3 text-[14.5px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none">
                    Loading…
                </button>
            </div>
        </form>

        <!-- ------------------------------------------------------ stage -->
        <section class="order-1 space-y-4 xl:order-2 xl:col-span-3">
            <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
                <p class="text-[15px] font-medium text-paper">Live output</p>
                <div id="liveStage" class="relative mt-3 aspect-video w-full overflow-hidden rounded-lg border border-border bg-ink">
                    <video id="liveOut" autoplay playsinline muted class="absolute inset-0 h-full w-full object-cover"></video>

                    <div id="livePlaceholder" class="absolute inset-0 flex flex-col items-center justify-center px-6 text-center">
                        <span class="empty-dot" aria-hidden="true"><i></i></span>
                        <p class="mt-4 text-[14.5px] text-paper">Upload a source face to preview</p>
                        <p class="mt-1 text-[12.5px] text-muted">The AI swaps your webcam feed with the uploaded face once you go live.</p>
                    </div>

                    <span id="liveStatus" class="absolute left-3 top-3 hidden items-center gap-1.5 rounded-full bg-ink/80 px-2.5 py-1 font-mono text-[11px] tracking-wider text-muted backdrop-blur">
                        <span class="h-1.5 w-1.5 rounded-full bg-muted" data-dot></span><span data-text>IDLE</span>
                    </span>
                    <span id="liveQuality" class="absolute bottom-3 left-3 hidden items-center gap-1.5 rounded-full bg-ink/80 px-2.5 py-1 font-mono text-[11px] text-paper backdrop-blur" aria-live="polite"></span>
                    <span id="liveClock" class="absolute right-3 top-3 hidden rounded-full bg-ink/80 px-2.5 py-1 font-mono text-[12px] text-paper backdrop-blur">0:00 left</span>

                    <video id="liveSelf" autoplay playsinline muted
                           class="absolute bottom-3 right-3 hidden aspect-video w-[22%] min-w-[96px] rounded-lg border border-white/15 object-cover shadow-lg [transform:scaleX(-1)]"></video>

                    <div id="liveBar" class="absolute inset-x-0 bottom-0 hidden items-center justify-center gap-2 bg-gradient-to-t from-ink/90 to-transparent px-3 pb-3 pt-8">
                        <button type="button" id="liveMic" aria-pressed="false" title="Microphone (group call)" class="rounded-full border border-white/20 bg-ink/70 px-3 py-2 text-[12.5px] text-paper">Mic off</button>
                        <button type="button" id="liveCam" aria-pressed="true" title="Camera" class="rounded-full border border-white/20 bg-ink/70 px-3 py-2 text-[12.5px] text-paper">Camera on</button>
                        <button type="button" id="liveFull" title="Fullscreen" class="rounded-full border border-white/20 bg-ink/70 px-3 py-2 text-[12.5px] text-paper">Fullscreen</button>
                        <button type="button" id="liveEnd" class="rounded-full bg-tally px-4 py-2 text-[12.5px] font-semibold text-paper hover:brightness-110">End session</button>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <div class="<?= $chip ?>"><span class="text-[13px]">📷</span><span><span class="block text-[13px] text-paper">Camera</span><span id="chipCam" class="block text-[12px] text-signal">Needs permission</span></span></div>
                    <button type="button" id="chipNetBtn" class="<?= $chip ?> text-left hover:border-signal/40"><span class="text-[13px]">📶</span><span><span class="block text-[13px] text-paper">Network</span><span id="chipNet" class="block text-[12px] text-muted">Tap to test</span></span></button>
                    <div class="<?= $chip ?>"><span class="text-[13px]">🛡</span><span><span class="block text-[13px] text-paper">Consent</span><span id="chipConsent" class="block text-[12px] text-muted">Not confirmed yet</span></span></div>
                </div>

                <form id="liveRestyle" class="mt-3 hidden gap-2">
                    <input name="prompt" maxlength="150" placeholder="Change the style while live, e.g. “cinematic, warm light”"
                           class="min-w-0 flex-1 rounded-lg border border-border bg-ink px-3.5 py-2.5 text-[14px] text-paper placeholder:text-muted/60 focus:border-signal/50 focus:outline-none">
                    <button type="submit" class="shrink-0 rounded-lg border border-border px-4 py-2.5 text-[13.5px] text-paper hover:border-signal/40">Update</button>
                </form>

                <div id="liveMessage" role="status" class="mt-3 hidden rounded-lg border px-4 py-3 text-[14px] text-paper"></div>
            </div>

            <!-- Telegram -->
            <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[15px] font-medium text-paper">Telegram video note</p>
                        <p class="mt-1 text-[12.5px] text-muted">Record up to 60 seconds of your live output and send it to your own Telegram.</p>
                    </div>
                    <button type="button" id="tgConnect" class="rounded-lg border border-border px-3.5 py-2 text-[13px] text-paper hover:border-signal/40">Connect Telegram</button>
                </div>
                <p id="tgState" class="mt-3 hidden text-[12.5px] text-muted"></p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" id="tgRec" disabled class="rounded-lg bg-tally/90 px-3.5 py-2 text-[13px] font-medium text-paper disabled:opacity-40">Record video note</button>
                    <button type="button" id="tgStop" disabled class="rounded-lg border border-border px-3.5 py-2 text-[13px] text-paper disabled:opacity-40">Stop</button>
                    <button type="button" id="tgSend" disabled class="rounded-lg bg-signal px-3.5 py-2 text-[13px] font-semibold text-ink disabled:opacity-40">Send to Telegram</button>
                    <button type="button" id="tgDiscard" disabled class="rounded-lg border border-border px-3.5 py-2 text-[13px] text-paper disabled:opacity-40">Discard</button>
                </div>
                <video id="tgPreview" controls playsinline class="mt-3 hidden w-40 rounded-full border border-border"></video>
            </div>

            <!-- Group call -->
            <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
                <p class="text-[15px] font-medium text-paper">Group call</p>
                <p class="mt-1 text-[12.5px] text-muted">Create a link so others can join you live, like a video call.</p>
                <?php if (!$canCall): ?>
                    <p class="mt-3 text-[12.5px] text-muted">Group calls aren't switched on yet.</p>
                <?php endif; ?>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <button type="button" id="callStart" disabled class="shrink-0 rounded-lg border border-border px-3.5 py-2 text-[13px] text-paper hover:border-signal/40 disabled:opacity-40">Create call link</button>
                    <input id="callLink" readonly placeholder="Go live first, then create a link" class="min-w-0 flex-1 rounded-lg border border-border bg-ink px-3 py-2 font-mono text-[12.5px] text-paper placeholder:text-muted/60">
                    <button type="button" id="callCopy" disabled class="shrink-0 rounded-lg border border-border px-3.5 py-2 text-[13px] text-paper disabled:opacity-40">Copy</button>
                </div>
                <p id="callCount" class="mt-2 text-[12.5px] text-muted"></p>
                <div id="callTiles" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4"></div>
            </div>

            <!-- Network test -->
            <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[15px] font-medium text-paper">Network test</p>
                    <button type="button" id="netRun" class="rounded-lg border border-border px-3.5 py-2 text-[13px] text-paper hover:border-signal/40">Run test</button>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg border border-border bg-ink/60 px-2 py-2"><span class="block text-[11.5px] text-muted">Download</span><span id="netDown" class="font-mono text-[14px] text-paper">—</span></div>
                    <div class="rounded-lg border border-border bg-ink/60 px-2 py-2"><span class="block text-[11.5px] text-muted">Latency</span><span id="netLat" class="font-mono text-[14px] text-paper">—</span></div>
                    <div class="rounded-lg border border-border bg-ink/60 px-2 py-2"><span class="block text-[11.5px] text-muted">Rating</span><span id="netRate" class="font-mono text-[14px] text-paper">—</span></div>
                </div>
                <p id="netNote" class="mt-2 text-[12px] text-muted">Checks the connection to our servers — a quick guide to whether your line can carry a live stream.</p>
            </div>
        </section>
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
