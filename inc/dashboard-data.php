<?php
/**
 * SleekAI dashboard — everything the signed-in pages render. $authUser comes from
 * require_auth() in dashboard-head.php; the rest is either derived from the database
 * or static catalogue data. Empty arrays render their page's empty state.
 */

declare(strict_types=1);

/** Time-of-day greeting, matching the live dashboard's "GOOD EVENING". */
function sleek_greeting(): string
{
    $h = (int) date('G');
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    return 'Good evening';
}

/** Naira amount, formatted the way the billing page shows it. */
function sleek_naira(int|float $amount): string
{
    return '&#8358;' . number_format((float) $amount);
}

/** "3 days ago" style age for a unix timestamp. */
function sleek_ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return ($m = intdiv($d, 60)) . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    if ($d < 86400)  return ($h = intdiv($d, 3600)) . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    if ($d < 604800) return ($n = intdiv($d, 86400)) . ' day' . ($n === 1 ? '' : 's') . ' ago';
    return date('j M Y', $ts);
}

/* -------------------------------------------------------------- the user */

require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/catalog.php';

$db = sleek_db();

// People who signed up with this user's code, with the bonus each one earned them.
// String concatenation differs: CONCAT() in MySQL, || in SQLite (where MySQL reads || as OR).
$refKey = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "CONCAT('user:', u.id)" : "('user:' || u.id)";
$stmt = $db->prepare(
    "SELECT u.name, u.created_at, COALESCE(l.delta, 0) AS earned
     FROM users u
     LEFT JOIN credit_ledger l
            ON l.user_id = ? AND l.kind = 'referral_bonus' AND l.reference = {$refKey}
     WHERE u.referred_by = ?
     ORDER BY u.created_at DESC"
);
$stmt->execute([$authUser['id'], $authUser['id']]);
$referralRows = $stmt->fetchAll();

$totals = sleek_credit_totals((int) $authUser['id']);

// Failed jobs are refunded, so they don't count toward the total either.
$stmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'completed'), 0) AS completed FROM jobs WHERE user_id = ? AND status <> 'failed'");
$stmt->execute([$authUser['id']]);
$jobCounts = array_map('intval', $stmt->fetch());

$user = [
    'name'            => $authUser['name'],
    'first_name'      => explode(' ', trim($authUser['name']))[0],
    'email'           => $authUser['email'],
    'credits'         => (int) $authUser['credits'],
    'all_time_used'   => $totals['used'],
    'jobs_completed'  => $jobCounts['completed'],
    'jobs_total'      => $jobCounts['total'],
    'referral_code'   => $authUser['referral_code'],
    'referred_count'  => count($referralRows),
    'referral_earned' => $totals['referral_earned'],
];

$site = [
    'telegram_url' => '#',
];

/* ------------------------------------------------------------- catalogue */

/** Tool cards, grouped exactly as the overview lays them out (source: inc/catalog.php). */
$toolGroups = [];
foreach (sleek_tools() as $key => $t) $toolGroups[$t['group']][$key] = $t + ['live' => true];

/** Credit packs shown on Credits & billing, keyed by the id the buy form posts. */
$packs = sleek_packs();

/* ---------------------------------------------------------------- history */

// Overview "Recent activity": the latest balance changes of any kind.
$stmt = $db->prepare('SELECT delta, kind, description, created_at FROM credit_ledger
                      WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5');
$stmt->execute([$authUser['id']]);
$activity = array_map(fn(array $r) => [
    'label'    => match ($r['kind']) {
        'purchase'       => 'Purchase',
        'referral_bonus' => 'Referral bonus',
        'job_spend'      => $r['description'],
        'job_refund'     => $r['description'],
        default          => 'Adjustment',
    },
    'detail'   => ($r['delta'] > 0 ? '+' : '−') . number_format(abs((int) $r['delta'])) . ' credits',
    'positive' => $r['delta'] > 0,
    'when'     => sleek_ago((int) $r['created_at']),
], $stmt->fetchAll());

// Credits & billing "Recent transactions": completed and failed payments, plus
// checkouts still in progress. Checkouts abandoned over an hour ago are left out.
$stmt = $db->prepare("SELECT pack_key, credits, amount_kobo, status, created_at, paid_at FROM payments
                      WHERE user_id = ? AND (status <> 'pending' OR created_at > ?)
                      ORDER BY created_at DESC, id DESC LIMIT 20");
$stmt->execute([$authUser['id'], time() - 3600]);
$transactions = array_map(fn(array $r) => [
    'pack'    => (sleek_pack($r['pack_key'])['name'] ?? ucfirst($r['pack_key'])) . ' pack',
    'amount'  => intdiv((int) $r['amount_kobo'], 100),
    'credits' => (int) $r['credits'],
    'status'  => $r['status'],
    'when'    => sleek_ago((int) ($r['paid_at'] ?: $r['created_at'])),
], $stmt->fetchAll());

require_once __DIR__ . '/jobs.php';
$jobs = sleek_user_jobs((int) $authUser['id'], null, 100);

$referrals = array_map(fn(array $r) => [
    'name'   => $r['name'],
    'when'   => 'Joined ' . sleek_ago((int) $r['created_at']),
    'earned' => (int) $r['earned'],
], $referralRows);

/* Derived — kept here so no page recomputes it inconsistently. */
$creditsTotal = $user['credits'] + $user['all_time_used'];
$usedPercent  = $creditsTotal > 0 ? (int) round($user['all_time_used'] / $creditsTotal * 100) : 0;
$jobsPercent  = $user['jobs_total'] > 0 ? $user['jobs_completed'] / $user['jobs_total'] : 0.0;
