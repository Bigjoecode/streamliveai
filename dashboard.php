<?php
$pageTitle = 'Dashboard';
$active    = 'overview';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
?>

<!-- ---------------------------------------------------------------- hero -->
<section class="relative overflow-hidden border-b border-border">
    <div class="hero-wash absolute inset-0" aria-hidden="true"></div>
    <div class="hero-grid absolute inset-0" aria-hidden="true"></div>

    <div class="relative flex flex-col gap-7 px-5 py-8 sm:px-8 sm:py-9 lg:flex-row lg:items-end lg:justify-between lg:px-10">
        <div>
            <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-signal"><?= e(sleek_greeting()) ?></p>
            <h1 class="mt-3 font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">
                Hey, <span class="text-signal"><?= e($user['first_name']) ?>.</span>
            </h1>
            <p class="mt-3.5 max-w-[46ch] text-[14.5px] text-muted lg:max-w-none">
                <?php if ($user['jobs_total'] === 0): ?>
                    You haven't generated anything yet — pick a tool to get started.
                <?php else: ?>
                    <?= number_format($user['jobs_total']) ?> generations so far. Pick a tool to keep going.
                <?php endif; ?>
            </p>
        </div>

        <div class="lg:text-right">
            <p class="text-[12.5px] text-muted">Balance</p>
            <p class="font-display text-[34px] font-semibold leading-none text-signal sm:text-[40px]"><?= number_format($user['credits']) ?></p>
            <p class="mt-1.5 text-[12.5px] text-muted">credits</p>
        </div>
    </div>
</section>

<!-- ------------------------------------------------------------- content -->
<main class="px-5 pb-16 pt-8 sm:px-8 lg:px-10">

    <?php foreach ($toolGroups as $groupName => $tools): ?>
        <h2 class="<?= $groupName === array_key_first($toolGroups) ? '' : 'mt-8 ' ?>font-display text-[19px] font-semibold tracking-tight text-paper">
            <?= e($groupName) ?>
        </h2>

        <div class="mt-3.5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($tools as $tool): $tag = $tool['href'] ? 'a' : 'article'; ?>
                <<?= $tag ?><?= $tool['href'] ? ' href="' . e($tool['href']) . '"' : '' ?>
                    class="tool-card<?= !empty($tool['featured']) ? ' featured' : '' ?> block rounded-xl border border-border bg-surface p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-display text-[17px] font-semibold text-paper"><?= e($tool['name']) ?></h3>
                        <?php if (!empty($tool['live'])): ?>
                            <span class="flex shrink-0 items-center gap-1.5 font-mono text-[10px] tracking-wider text-reel">
                                <span class="h-1.5 w-1.5 rounded-full bg-reel" aria-hidden="true"></span>LIVE
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="mt-2.5 text-[13.5px] leading-relaxed text-muted"><?= e($tool['blurb']) ?></p>
                    <p class="mt-4 font-mono text-[13px] text-signal"><?= e($tool['price']) ?></p>
                </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <!-- usage + jobs -->
    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section class="rounded-xl border border-border bg-surface p-5 sm:p-6 lg:col-span-2">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-[15px] font-medium text-paper">Usage</h2>
                <span class="rounded-full border border-signal/30 bg-signal/[0.09] px-2.5 py-1 font-mono text-[11px] text-signal"><?= $usedPercent ?>% used</span>
            </div>

            <div class="mt-5 h-1.5 w-full overflow-hidden rounded-full bg-white/[0.06]"
                 role="progressbar" aria-valuenow="<?= $usedPercent ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Credits used">
                <div class="h-full rounded-full bg-signal" style="width:<?= $usedPercent ?>%"></div>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-6">
                <div>
                    <p class="text-[13px] text-muted">Credits remaining</p>
                    <p class="mt-1.5 font-display text-[26px] font-semibold leading-none text-signal"><?= number_format($user['credits']) ?></p>
                </div>
                <div>
                    <p class="text-[13px] text-muted">All-time used</p>
                    <p class="mt-1.5 font-display text-[26px] font-semibold leading-none text-paper"><?= number_format($user['all_time_used']) ?></p>
                </div>
            </div>
        </section>

        <?php $circ = 276.5; ?>
        <section class="flex flex-col items-center justify-center rounded-xl border border-border bg-surface p-6">
            <div class="relative h-[86px] w-[86px]">
                <svg class="h-full w-full -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
                    <circle cx="50" cy="50" r="44" fill="none" stroke="rgba(255,255,255,0.09)" stroke-width="5"/>
                    <circle cx="50" cy="50" r="44" fill="none" stroke="#F2B705" stroke-width="5" stroke-linecap="round"
                            stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= round($circ * (1 - $jobsPercent), 1) ?>"/>
                </svg>
                <span class="absolute inset-0 flex items-center justify-center font-display text-[26px] font-semibold text-paper"><?= number_format($user['jobs_completed']) ?></span>
            </div>
            <p class="mt-4 text-[13px] text-muted">of <?= number_format($user['jobs_total']) ?> jobs completed</p>
        </section>
    </div>

    <!-- referral + buy -->
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="rounded-xl border border-border bg-surface p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <h2 class="font-display text-[17px] font-semibold text-paper">Referral program</h2>
                <svg class="h-[18px] w-[18px] shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                    <path d="M10 13.5a4 4 0 0 0 5.7.4l2.6-2.6a4 4 0 0 0-5.7-5.7l-1.3 1.3"/>
                    <path d="M14 10.5a4 4 0 0 0-5.7-.4l-2.6 2.6a4 4 0 1 0 5.7 5.7l1.3-1.3"/>
                </svg>
            </div>
            <p class="mt-2.5 text-[13.5px] leading-relaxed text-muted">Earn 5% when someone you referred makes their first credit purchase.</p>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <code class="font-mono text-[15px] tracking-wide text-signal"><?= e($user['referral_code']) ?></code>
                <p class="text-[13px] text-muted"><?= number_format($user['referred_count']) ?> referred · <?= number_format($user['referral_earned']) ?> credits earned</p>
            </div>

            <a href="referrals" class="mt-5 inline-block text-[13.5px] font-medium text-signal hover:underline">View referral dashboard →</a>
        </section>

        <section class="rounded-xl border border-border bg-surface p-5 sm:p-6">
            <h2 class="font-display text-[17px] font-semibold text-paper">Need more credits?</h2>
            <p class="mt-2.5 text-[13.5px] leading-relaxed text-muted">No subscription — buy a pack, spend it whenever, no expiry.</p>
            <a href="credits-billing" class="btn-glow mt-5 inline-block rounded-lg bg-signal px-5 py-2.5 text-[14px] font-semibold text-ink">Buy credits</a>
        </section>
    </div>

    <!-- recent activity -->
    <div class="mt-9 flex items-center justify-between gap-4">
        <h2 class="font-display text-[19px] font-semibold tracking-tight text-paper">Recent activity</h2>
        <a href="job-history" class="shrink-0 text-[13.5px] font-medium text-signal hover:underline">View all jobs</a>
    </div>

    <?php if ($activity): ?>
        <ul class="mt-3.5 space-y-3">
            <?php foreach ($activity as $item): ?>
                <li class="flex items-center gap-4 rounded-xl border border-border bg-surface px-4 py-4 sm:px-5">
                    <?php if ($item['positive']): ?>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-reel/25 bg-reel/[0.12] font-mono text-[15px] text-reel" aria-hidden="true">+</span>
                    <?php else: ?>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-border bg-white/[0.04] font-mono text-[15px] text-muted" aria-hidden="true">−</span>
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                        <p class="text-[14.5px] font-medium text-paper"><?= e($item['label']) ?></p>
                        <p class="mt-0.5 text-[13px] text-muted"><?= e($item['detail']) ?></p>
                    </div>
                    <time class="shrink-0 text-[13px] text-muted"><?= e($item['when']) ?></time>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <section class="mt-3.5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper">Nothing here yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Your generations and purchases will show up here.</p>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
