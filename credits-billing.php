<?php
$pageTitle = 'Credits & billing';
$active    = 'billing';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require_once __DIR__ . '/inc/payments.php';

// Only methods with keys set get a button. With none set, show the card button
// anyway — buy explains that payments are unavailable instead of a blank card.
$methods = sleek_payment_methods_available() ?: array_slice(sleek_payment_methods(), 0, 1, true);

$flash = sleek_flash_take();

$statusPill = [
    'success' => ['Paid',        'border-reel/35 bg-reel/10 text-reel'],
    'failed'  => ['Failed',      'border-tally/35 bg-tally/10 text-tally'],
    'pending' => ['In progress', 'border-signal/35 bg-signal/10 text-signal'],
];
$flashStyle = [
    'success' => 'border-reel/40 bg-reel/10',
    'error'   => 'border-tally/40 bg-tally/10',
    'info'    => 'border-signal/40 bg-signal/10',
];
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">

    <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Credits &amp; billing</h1>

    <?php if ($flash): ?>
        <div role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"
             class="mt-6 rounded-lg border <?= $flashStyle[$flash['type']] ?? $flashStyle['info'] ?> px-4 py-3 text-[14.5px] text-paper">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- credit packs -->
    <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($packs as $key => $pack): ?>
            <article class="pack-card<?= isset($pack['badge']) ? ' featured' : '' ?> rounded-xl border border-border bg-surface p-6 sm:p-7">
                <?php if (isset($pack['badge'])): ?>
                    <span class="pack-badge"><?= e($pack['badge']) ?></span>
                <?php endif; ?>
                <p class="font-mono text-[13px] text-signal"><?= number_format($pack['credits']) ?> credits</p>
                <h2 class="mt-3 font-display text-[22px] font-semibold text-paper"><?= e($pack['name']) ?></h2>
                <p class="mt-3 font-display text-[28px] font-semibold leading-none text-paper"><?= sleek_money($pack['price'] * 100, sleek_catalog_currency()) ?></p>
                <form method="POST" action="buy" data-buy class="mt-6 space-y-2.5">
                    <?= csrf_field() ?>
                    <input type="hidden" name="pack" value="<?= e($key) ?>">
                    <?php $first = true; foreach ($methods as $mKey => $m): ?>
                        <button type="submit" name="method" value="<?= e($mKey) ?>" data-busy="<?= e($m['busy']) ?>" data-label="<?= e($m['label']) ?>"
                                class="<?= $first ? 'btn-glow bg-signal text-ink' : 'border border-border bg-transparent text-paper hover:border-signal/60' ?> w-full rounded-lg py-3 text-[14.5px] font-semibold disabled:cursor-wait disabled:opacity-70"><?= e($m['label']) ?></button>
                    <?php $first = false; endforeach; ?>
                </form>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- transactions -->
    <h2 class="mt-12 font-display text-[22px] font-semibold tracking-tight text-paper sm:text-[24px]">Recent transactions</h2>

    <?php if ($transactions): ?>
        <div class="mt-5 overflow-x-auto rounded-xl border border-border bg-surface">
            <table class="w-full min-w-[560px] text-[14px]">
                <thead>
                    <tr class="border-b border-border text-left text-[12px] uppercase tracking-wide text-muted">
                        <th class="px-5 py-3 font-medium">Pack</th>
                        <th class="px-5 py-3 font-medium">Amount</th>
                        <th class="px-5 py-3 font-medium">Credits</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): [$label, $pill] = $statusPill[$tx['status']] ?? [$tx['status'], 'border-border text-muted']; ?>
                        <tr class="border-b border-border last:border-0">
                            <td class="px-5 py-3.5 text-paper"><?= e($tx['pack']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-muted"><?= $tx['amount'] ?></td>
                            <td class="px-5 py-3.5 font-mono <?= $tx['status'] === 'success' ? 'text-signal' : 'text-muted line-through decoration-muted/50' ?>">+<?= number_format($tx['credits']) ?></td>
                            <td class="px-5 py-3.5"><span class="rounded-full border px-2.5 py-0.5 text-[12px] <?= $pill ?>"><?= e($label) ?></span></td>
                            <td class="px-5 py-3.5 text-muted"><?= e($tx['when']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <section class="mt-5 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-12 text-center sm:py-14">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper sm:text-[22px]">No transactions yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Buy a credit pack above to see it here.</p>
        </section>
    <?php endif; ?>
</main>

<script>
    // One click, one checkout: lock the pack's buttons while we hand off to the
    // provider. submitter says which button was pressed (its name/value still post).
    document.querySelectorAll('form[data-buy]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            var pressed = ev.submitter;
            setTimeout(function () {   // after the browser has read the pressed button's value
                f.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                if (pressed) pressed.textContent = pressed.dataset.busy;
            }, 0);
        });
    });
    // Coming back from checkout restores this page from cache with the buttons
    // still disabled — put them back.
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) return;
        document.querySelectorAll('form[data-buy] button').forEach(function (b) {
            b.disabled = false;
            b.textContent = b.dataset.label;
        });
    });
</script>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
