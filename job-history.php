<?php
$pageTitle = 'Job history';
$active    = 'jobs';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';
$flash = sleek_flash_take();
$finished = count(array_filter($jobs, fn($j) => $j['status'] !== 'processing'));
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Job history</h1>
        <?php if ($finished > 0): ?>
            <form method="POST" action="job-delete" class="m-0"
                  onsubmit="return confirm('Delete all <?= $finished ?> finished job<?= $finished === 1 ? '' : 's' ?> and their files? Jobs still rendering are kept. This can't be undone.')">
                <?= csrf_field() ?>
                <input type="hidden" name="all" value="1">
                <input type="hidden" name="return" value="job-history">
                <button type="submit" class="rounded-lg border border-border px-3.5 py-2 text-[13.5px] text-muted transition-colors hover:border-tally/50 hover:text-tally">Clear history</button>
            </form>
        <?php endif; ?>
    </div>

    <?php sleek_render_flash($flash); ?>
    <p class="mt-3 text-[13px] text-muted">Deleting a job removes it and its file for good. Your credits and billing history aren't affected.</p>

    <?php if ($jobs): ?>
        <div class="mt-3"><?php sleek_render_jobs($jobs, true); ?></div>
    <?php else: ?>
        <section class="mt-8 flex flex-col items-center justify-center rounded-xl border border-border bg-surface px-6 py-14 text-center sm:py-16">
            <span class="empty-dot" aria-hidden="true"><i></i></span>
            <p class="mt-6 font-display text-[20px] font-semibold text-paper sm:text-[22px]">No jobs yet</p>
            <p class="mt-2.5 text-[14.5px] text-muted">Anything you generate will show up here.</p>
            <a href="dashboard" class="mt-4 text-[14.5px] font-medium text-signal hover:underline">Browse tools</a>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/dashboard-foot.php'; ?>
