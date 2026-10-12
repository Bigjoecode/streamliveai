<?php
/**
 * SleekAI — what's for sale. The one place pack prices live: the billing page
 * displays them and buy.php charges them, so the two can never disagree, and the
 * amount charged never comes from the browser.
 */

declare(strict_types=1);

/** Credit packs, keyed by the id the buy form posts. Prices in whole naira. */
function sleek_packs(): array
{
    return [
        'starter'  => ['credits' =>    500, 'name' => 'Starter',  'price' =>  18000],
        'creator'  => ['credits' =>   1000, 'name' => 'Creator',  'price' =>  30000, 'badge' => 'Most popular'],
        'studio'   => ['credits' =>   2000, 'name' => 'Studio',   'price' =>  57000],
        'premium'  => ['credits' =>   4000, 'name' => 'Premium',  'price' => 112000],
        'pro'      => ['credits' =>   6000, 'name' => 'Pro',      'price' => 167000],
        'ultimate' => ['credits' =>  10000, 'name' => 'Ultimate', 'price' => 280000],
    ];
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
