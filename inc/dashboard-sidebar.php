<?php
/**
 * Sidebar + topbar. Below lg the sidebar is off-canvas and the topbar button
 * slides it in over a backdrop; from lg up it is docked and the button collapses it.
 */

$navItems = [
    'overview' => ['Overview',          'dashboard'],
    'jobs'     => ['Job History',       'job-history'],
    'billing'  => ['Credits &amp; Billing', 'credits-billing'],
    'referral' => ['Referrals',         'referrals'],
];
if (($authUser['role'] ?? 'user') === 'super_admin') {
    $navItems['admin'] = ['System Admin', 'admin'];
}
?>

<!-- Backdrop: only ever visible while the mobile drawer is open -->
<div id="navOverlay" class="nav-overlay" hidden></div>

<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-[272px] flex-col border-r border-border bg-ink">
    <div class="flex h-[72px] shrink-0 items-center gap-2.5 px-6">
        <span class="tally-dot h-2 w-2 rounded-full bg-signal" aria-hidden="true"></span>
        <a href="index.html" class="font-display text-[19px] font-semibold tracking-tight text-paper">StreamLive AI</a>
    </div>

    <nav class="px-4 pt-3" aria-label="Dashboard">
        <?php foreach ($navItems as $key => [$label, $href]): ?>
            <a href="<?= $href ?>"
               class="nav-link<?= $active === $key ? ' is-active' : '' ?>"
               <?= $active === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="mx-6 my-5 border-t border-border"></div>

    <div class="space-y-3 px-4 pb-6">
        <a href="<?= e($site['telegram_url']) ?>" class="flex items-center justify-center gap-2.5 rounded-xl border border-[#29A9EB]/35 bg-[#29A9EB]/[0.07] px-4 py-3 text-[14px] font-medium text-paper transition-colors hover:bg-[#29A9EB]/[0.12]">
            <svg class="h-[18px] w-[18px] text-[#29A9EB]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm4.64 6.8-1.55 7.33c-.12.52-.42.65-.86.4l-2.37-1.75-1.15 1.1c-.13.13-.24.24-.48.24l.17-2.43 4.42-4c.2-.17-.04-.26-.3-.1l-5.46 3.44-2.35-.74c-.51-.16-.52-.51.11-.76l9.17-3.54c.43-.15.8.1.66.8Z"/>
            </svg>
            Join our Telegram
        </a>
        <a href="contact.html" class="flex items-center justify-center gap-2.5 rounded-xl border border-border px-4 py-3 text-[14px] font-medium text-paper transition-colors hover:border-signal/35">
            <svg class="h-[18px] w-[18px] text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.3 2.4c-.6.2-.9.7-.9 1.3v.4" stroke-linecap="round"/><circle cx="12" cy="16.8" r=".9" fill="currentColor" stroke="none"/>
            </svg>
            Contact Support
        </a>
        <form method="POST" action="logout">
            <?= csrf_field() ?>
            <button type="submit" class="flex w-full items-center justify-center rounded-xl px-4 py-2.5 text-[14px] text-muted transition-colors hover:text-paper">Sign out</button>
        </form>
    </div>
</aside>

<div id="shell">

    <header class="sticky top-0 z-30 flex h-[72px] items-center justify-between gap-3 border-b border-border bg-ink/85 px-4 backdrop-blur sm:px-6">
        <div class="flex min-w-0 items-center gap-3 sm:gap-4">
            <button id="navToggle" type="button" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="sidebar"
                    class="shrink-0 rounded-lg border border-border p-2 text-muted transition-colors hover:border-signal/35 hover:text-paper">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M10 4v16"/>
                </svg>
            </button>
            <p class="hidden truncate text-[14.5px] text-muted sm:block">
                Signed in as <span class="font-medium text-paper"><?= e($user['name']) ?></span>
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            <span class="rounded-lg border border-signal/30 bg-signal/[0.07] px-3 py-2 font-mono text-[12.5px] text-signal sm:px-3.5 sm:text-[13px]">
                <?= number_format($user['credits']) ?> credits
            </span>
            <a href="credits-billing" class="btn-glow rounded-lg bg-signal px-3.5 py-2.5 text-[13.5px] font-semibold text-ink sm:px-5 sm:text-[14px]">
                Buy<span class="hidden sm:inline"> Credits</span>
            </a>
        </div>
    </header>
