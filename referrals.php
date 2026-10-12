<?php
$pageTitle = 'Referrals';
$active    = 'referral';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';

// Points at this app's register page, which reads ?ref= and credits the referrer.
$refLink = sleek_url('register?ref=' . $user['referral_code']);
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">

    <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Referrals</h1>
    <p class="mt-4 max-w-[580px] text-[15.5px] leading-relaxed text-muted">
        Share your link. When someone you referred buys a credit pack, you get 5% of that purchase
        added straight to your balance — automatically, the first time they buy.
    </p>

    <!-- stats -->
    <div class="mt-7 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <section class="rounded-xl border border-border bg-surface p-6 sm:p-7">
            <p class="text-[14.5px] text-muted">People you've referred</p>
            <p class="mt-4 font-display text-[30px] font-semibold leading-none text-paper sm:text-[34px]"><?= number_format($user['referred_count']) ?></p>
        </section>
        <section class="rounded-xl border border-border bg-surface p-6 sm:p-7">
            <p class="text-[14.5px] text-muted">Total bonus earned</p>
            <p class="mt-4 font-display text-[30px] font-semibold leading-none text-signal sm:text-[34px]"><?= number_format($user['referral_earned']) ?> credits</p>
        </section>
    </div>

    <!-- link -->
    <section class="mt-5 rounded-xl border border-border bg-surface p-6 sm:p-7">
        <label for="refLink" class="block text-[14.5px] text-muted">Your referral link</label>

        <div class="mt-3 flex flex-col gap-3 sm:flex-row">
            <input id="refLink" type="text" readonly value="<?= e($refLink) ?>"
                   class="min-w-0 flex-1 rounded-lg border border-border bg-ink px-4 py-3 text-[14.5px] text-paper focus:border-signal/50 focus:outline-none">
            <button type="button" data-copy="#refLink"
                    class="btn-glow shrink-0 rounded-lg bg-signal px-6 py-3 text-[14.5px] font-semibold text-ink">Copy link</button>
        </div>

        <p class="mt-3.5 text-[13.5px] text-muted">Your code: <span class="font-mono text-signal"><?= e($user['referral_code']) ?></span></p>
    </section>

    <!-- referred people -->
    <h2 class="mt-11 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">People you've referred</h2>

    <?php if ($referrals): ?>
        <ul class="mt-5 space-y-3">
            <?php foreach ($referrals as $ref): ?>
                <li class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-surface px-4 py-4 sm:px-5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[14.5px] font-medium text-paper"><?= e($ref['name']) ?></p>
                        <p class="mt-0.5 text-[13px] text-muted"><?= e($ref['when']) ?></p>
                    </div>
                    <?php if ($ref['earned'] > 0): ?>
                        <span class="shrink-0 font-mono text-[13px] text-signal">+<?= number_format($ref['earned']) ?> credits</span>
                    <?php else: ?>
                        <span class="shrink-0 text-[13px] text-muted">No purchase yet</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center sm:py-14">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper sm:text-[22px]">No referrals yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Once someone signs up through your link, they'll show up here.</p>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
