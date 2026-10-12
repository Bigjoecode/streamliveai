<?php
$pageTitle = 'Job history';
$active    = 'jobs';
require __DIR__ . '/inc/dashboard-head.php';
require __DIR__ . '/inc/dashboard-sidebar.php';
require __DIR__ . '/inc/job-list.php';
?>

<main class="px-5 pb-16 pt-9 sm:px-8 sm:pt-12 lg:px-10">

    <h1 class="font-display text-[30px] font-semibold leading-none tracking-tight text-paper sm:text-[36px] lg:text-[40px]">Job history</h1>

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
