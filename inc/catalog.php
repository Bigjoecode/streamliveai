<?php
/**
 * SleekAI — what's for sale. The one place pack prices live: the billing page
 * displays them and buy.php charges them, so the two can never disagree, and the
 * amount charged never comes from the browser.
 */

declare(strict_types=1);

/** The currency every pack is priced and charged in. */
function sleek_catalog_currency(): string
{
    return 'USD';
}

/**
 * The packs as first shipped. The super admin edits them at /admin → Pricing;
 * what they save (the `packs` setting) is laid over these. The keys are fixed —
 * they're what the buy form posts and what old payments point at.
 */
function sleek_pack_defaults(): array
{
    return [
        'starter'  => ['credits' =>    500, 'name' => 'Starter',  'price' =>  12, 'badge' => '', 'features' => ['All 5 tools', 'Consent-logged jobs', 'Email support']],
        'creator'  => ['credits' =>   1000, 'name' => 'Creator',  'price' =>  20, 'badge' => 'Most popular', 'features' => ['All 5 tools', 'Priority queue', 'Priority support']],
        'studio'   => ['credits' =>   2000, 'name' => 'Studio',   'price' =>  38, 'badge' => '', 'features' => ['All 5 tools', 'Team seats', 'Dedicated support']],
        'premium'  => ['credits' =>   4000, 'name' => 'Premium',  'price' =>  75, 'badge' => '', 'features' => ['All 5 tools', 'Team seats', 'Priority support']],
        'pro'      => ['credits' =>   6000, 'name' => 'Pro',      'price' => 110, 'badge' => '', 'features' => ['All 5 tools', 'Team seats', 'Dedicated support']],
        'ultimate' => ['credits' =>  10000, 'name' => 'Ultimate', 'price' => 185, 'badge' => '', 'features' => ['All 5 tools', 'Unlimited team seats', 'Dedicated support']],
    ];
}

/**
 * Credit packs, keyed by the id the buy form posts. 'price' is US dollars and may
 * have cents (12.5); charge it with sleek_pack_cents(). Empty badges are dropped.
 */
function sleek_packs(): array
{
    require_once __DIR__ . '/settings.php';
    $saved = sleek_setting('packs', []);
    $packs = [];
    foreach (sleek_pack_defaults() as $key => $pack) {
        $over = is_array($saved[$key] ?? null) ? $saved[$key] : [];
        $pack = array_replace($pack, array_intersect_key($over, $pack));
        if (($pack['badge'] ?? '') === '') unset($pack['badge']);
        $packs[$key] = $pack;
    }
    return $packs;
}

/**
 * Money in minor units (cents, kobo), formatted the way the billing page shows it:
 * $12, $12.50, ₦18,000. Returns HTML-safe text.
 */
function sleek_money(int $minor, string $currency): string
{
    $symbol = ['USD' => '$', 'NGN' => '&#8358;'][strtoupper($currency)] ?? e(strtoupper($currency)) . ' ';
    $amount = $minor / 100;
    return $symbol . number_format($amount, $minor % 100 === 0 ? 0 : 2);
}

/** A pack's price in cents — the only form money is charged or compared in. */
function sleek_pack_cents(array $pack): int
{
    return (int) round(((float) $pack['price']) * 100);
}

function sleek_pack(string $key): ?array
{
    return sleek_packs()[$key] ?? null;
}

/**
 * Generation tools, in dashboard order. 'credits' is what a job is charged; 'href'
 * is set only for tools that are actually wired up — the rest render as cards
 * without a link.
 */
function sleek_tools(): array
{
    return [
        'livestream' => [
            'group' => 'Visual & Video', 'name' => 'Livestream', 'featured' => true,
            'blurb' => 'Real-time full body swap for streaming, billed per second.',
            'price' => '2 credits / sec', 'credits' => 2, 'href' => 'livestream',
        ],
        'image-to-video' => [
            'group' => 'Visual & Video', 'name' => 'Image to Video',
            'blurb' => 'Turn a photo into a talking video — upload a photo, write a script, pick a voice.',
            'price' => '200 credits', 'credits' => 200, 'href' => 'image-to-video',
            'async' => true,   // rendered in fal's queue; settles via inc/fal.php
        ],
        'ai-image-edit' => [
            'group' => 'Visual & Video', 'name' => 'AI Image Edit',
            'blurb' => 'Edit any photo with a plain-language instruction.',
            'price' => '50 credits', 'credits' => 50, 'href' => 'ai-image-edit',
        ],
        'voice-cloning' => [
            'group' => 'Voice Lab', 'name' => 'Voice Cloning',
            'blurb' => 'Clone a voice from a sample, then speak any script.',
            'price' => '100 credits', 'credits' => 100, 'href' => 'voice-cloning',
        ],
        'voice-isolator' => [
            'group' => 'Voice Lab', 'name' => 'Voice Isolator',
            'blurb' => 'Strip background noise, keep just the speech.',
            'price' => '50 credits', 'credits' => 50, 'href' => 'voice-isolator',
        ],
    ];
}

function sleek_tool(string $key): ?array
{
    return sleek_tools()[$key] ?? null;
}
