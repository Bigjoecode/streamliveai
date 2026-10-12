<?php
/**
 * SleekAI dashboard — aggregation. Turns normalised stream rows into the
 * buckets, KPIs and top-N lists the charts consume.
 */

/** Bucket width that keeps a window readable: <=2d hourly, <=90d daily, else weekly. */
function sleek_interval(?int $from, ?int $to): string
{
    if ($from === null || $to === null) return 'day';
    $span = max(1, $to - $from);
    if ($span <= 2 * 86400)  return 'hour';
    if ($span <= 90 * 86400) return 'day';
    return 'week';
}

function sleek_bucket_key(int $ts, string $interval): string
{
    return match ($interval) {
        'hour' => date('Y-m-d H:00', $ts),
        'week' => date('Y-m-d', strtotime('monday this week', $ts)),
        default => date('Y-m-d', $ts),
    };
}

function sleek_bucket_step(string $interval): int
{
    return match ($interval) { 'hour' => 3600, 'week' => 604800, default => 86400 };
}

/** Every bucket label between two timestamps, so gaps render as zero not a jump. */
function sleek_bucket_labels(int $from, int $to, string $interval): array
{
    $labels = []; $step = sleek_bucket_step($interval);
    $cur = match ($interval) {
        'hour' => strtotime(date('Y-m-d H:00', $from)),
        'week' => strtotime('monday this week', $from),
        default => strtotime(date('Y-m-d', $from)),
    };
    $guard = 0;
    while ($cur <= $to && $guard++ < 5000) {
        $labels[] = sleek_bucket_key($cur, $interval);
        $cur += $step;
    }
    return $labels ?: [sleek_bucket_key($from, $interval)];
}

/**
 * Group rows into a time series.
 *
 * $valueFn  row => number contributed (default 1, i.e. a count)
 * $groupFn  row => series name (default null, i.e. a single series)
 */
function sleek_series(
    array $rows,
    array $labels,
    string $interval,
    ?callable $valueFn = null,
    ?callable $groupFn = null
): array {
    $index = array_flip($labels);
    $out   = [];
    foreach ($rows as $r) {
        $k = sleek_bucket_key($r['ts'], $interval);
        if (!isset($index[$k])) continue;
        $g = $groupFn ? (string) $groupFn($r) : 'all';
        if (!isset($out[$g])) $out[$g] = array_fill(0, count($labels), 0);
        $out[$g][$index[$k]] += $valueFn ? $valueFn($r) : 1;
    }
    return $out;
}

/** Top N by summed value, remainder folded into "Other" — never a 9th hue. */
function sleek_topn(array $rows, callable $keyFn, int $n = 8, ?callable $valueFn = null): array
{
    $agg = [];
    foreach ($rows as $r) {
        $k = (string) $keyFn($r);
        if ($k === '') continue;
        $agg[$k] = ($agg[$k] ?? 0) + ($valueFn ? $valueFn($r) : 1);
    }
    arsort($agg);
    if (count($agg) <= $n) return $agg;
    $top   = array_slice($agg, 0, $n, true);
    $other = array_sum(array_slice($agg, $n, null, true));
    if ($other > 0) $top['Other'] = $other;
    return $top;
}

/** HTTP status -> reserved status slot. Always paired with a text label in the UI. */
function sleek_status_class(int $code): string
{
    if ($code >= 500) return '5xx';
    if ($code >= 400) return '4xx';
    if ($code >= 300) return '3xx';
    return '2xx';
}

/** Log level -> reserved status slot. */
function sleek_level_class(string $level): string
{
    return match (strtoupper($level)) {
        'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'critical',
        'WARNING', 'NOTICE' => 'warning',
        default => 'good',
    };
}

/** Percent change between two numbers, null when there's no baseline. */
function sleek_delta(float $now, float $prev): ?float
{
    if ($prev <= 0.0) return null;
    return (($now - $prev) / $prev) * 100;
}

/** Split a window into current and preceding period of equal length. */
function sleek_prev_window(array $rows, int $from, int $to): array
{
    $span = max(1, $to - $from);
    return sleek_window($rows, $from - $span, $from - 1);
}
