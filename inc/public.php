<?php
/**
 * SleekAI — shared pieces of the public marketing pages (index, pricing, about…).
 * Pack prices and the Telegram link come from /admin, so these pages are PHP and
 * render them live; everything else on them is plain markup.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/settings.php';

/** Telegram's paper-plane mark, sized by the caller's class. */
function sleek_telegram_icon(string $class = 'h-4 w-4'): string
{
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.94 4.66 18.8 19.5c-.24 1.05-.86 1.31-1.74.81l-4.8-3.54-2.32 2.23c-.26.26-.47.47-.97.47l.35-4.9 8.92-8.06c.39-.35-.08-.54-.6-.19L6.62 13.27 1.88 11.8c-1.03-.32-1.05-1.03.21-1.52L20.6 3.14c.86-.32 1.61.19 1.34 1.52Z"/></svg>';
}

/**
 * Price cards, in the pricing page's markup. $keys limits and orders them
 * (the home page shows three); null shows every pack.
 */
function sleek_public_pack_cards(?array $keys = null): void
{
    $packs = sleek_packs();
    $keys ??= array_keys($packs);
    foreach ($keys as $key) {
        if (!isset($packs[$key])) continue;
        $p = $packs[$key];
        $featured = isset($p['badge']);
        ?>
        <div class="relative rounded-2xl border <?= $featured ? 'border-signal/50 p-7 bg-signal/[0.04]' : 'border-border p-7 glass' ?>">
            <?php if ($featured): ?>
                <span class="absolute -top-3 left-6 rounded-full bg-signal px-3 py-1 text-[10.5px] font-semibold text-ink"><?= e($p['badge']) ?></span>
            <?php endif; ?>
            <p class="font-mono text-xs text-signal"><?= number_format((int) $p['credits']) ?> credits</p>
            <p class="mt-2 font-display text-2xl"><?= e($p['name']) ?></p>
            <p class="mt-4 font-display text-3xl"><?= sleek_money(sleek_pack_cents($p), sleek_catalog_currency()) ?></p>
            <?php if ($p['features']): ?>
                <div class="mt-5 border-t border-border/70 pt-5">
                    <ul class="space-y-2 text-sm text-muted">
                        <?php foreach ($p['features'] as $f): ?>
                            <li><?= e($f) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="register" class="mt-6 block rounded-md <?= $featured ? 'bg-signal text-ink hover:brightness-95' : 'border border-border text-paper hover:border-signal/50' ?> py-2.5 text-center text-sm font-medium">Get started</a>
        </div>
        <?php
    }
}

/** The site footer, identical on every public page. */
function sleek_public_footer(): void
{
    $telegram = sleek_telegram_url();
    ?>
    <footer class="relative border-t border-border px-6 py-14 md:px-12">
    <div class="mx-auto grid max-w-6xl gap-10 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div>
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-signal tally-dot"></span>
                <span class="font-display text-lg tracking-tight">StreamLive AI</span>
            </div>
            <p class="mt-3 max-w-xs text-sm text-muted">
                One control room for powerful AI tools - build for creation, transformation, and real-time experiences.
            </p>
            <?php if ($telegram !== ''): ?>
                <a href="<?= e($telegram) ?>" target="_blank" rel="noopener" class="mt-5 inline-flex items-center gap-2 rounded-md border border-border px-3.5 py-2 text-sm text-paper hover:border-signal/50">
                    <?= sleek_telegram_icon() ?> Join us on Telegram
                </a>
            <?php endif; ?>
        </div>

        <div>
            <p class="font-mono text-xs uppercase tracking-wide text-muted">Product</p>
            <ul class="mt-3 space-y-2 text-sm text-muted">
                <li><a href="features" class="hover:text-paper">Features</a></li>
                <li><a href="how-it-works" class="hover:text-paper">How It Works</a></li>
                <li><a href="pricing" class="hover:text-paper">Pricing</a></li>
            </ul>
        </div>

        <div>
            <p class="font-mono text-xs uppercase tracking-wide text-muted">Company</p>
            <ul class="mt-3 space-y-2 text-sm text-muted">
                <li><a href="about" class="hover:text-paper">About Us</a></li>
                <li><a href="contact" class="hover:text-paper">Contact</a></li>
                <li><a href="register" class="hover:text-paper">Create account</a></li>
                <li><a href="login" class="hover:text-paper">Log in</a></li>
            </ul>
        </div>

        <div>
            <p class="font-mono text-xs uppercase tracking-wide text-muted">Trust</p>
            <ul class="mt-3 space-y-2 text-sm text-muted">
                <li>Consent-logged jobs</li>
                <li>No auto-renewal billing</li>
                <li><a href="terms" class="hover:text-paper">Terms of Service</a></li>
                <li><a href="privacy" class="hover:text-paper">Privacy Policy</a></li>
            </ul>
        </div>
    </div>

    <div class="mx-auto mt-12 max-w-6xl border-t border-border pt-6 text-sm text-muted">
        © <?= date('Y') ?> StreamLive AI. Built to make AI simpler, faster, and more accessible.
    </div>
</footer>
    <?php
}
