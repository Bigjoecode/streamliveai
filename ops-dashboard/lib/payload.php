<?php
/**
 * SleekAI dashboard — builds the payload the front end charts.
 * One place decides what every panel shows; index.php only renders.
 */

require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/metrics.php';

/** Resolve the active window from the query string, clamped to the data we have. */
function sleek_resolve_range(array $q, array $streams): array
{
    $all = [];
    foreach ($streams as $rows) {
        if ($rows) { $all[] = $rows[0]['ts']; $all[] = end($rows)['ts']; }
    }
    if (!$all) { $now = time(); return [$now - 30 * 86400, $now, '30d']; }

    $min = min($all); $max = max($all);
    $range = $q['range'] ?? '30d';

    if ($range === 'custom' && !empty($q['from']) && !empty($q['to'])) {
        $f = sleek_ts($q['from'] . ' 00:00:00');
        $t = sleek_ts($q['to'] . ' 23:59:59');
        if ($f && $t && $f <= $t) return [$f, $t, 'custom'];
    }
    if ($range === 'all') return [sleek_snap_start($min, 'day'), $max, 'all'];

    /* Snap the window start to a bucket boundary. Without this the first bucket
       holds a partial slice of data and the chart opens on a dip that is an
       artifact of where the window happened to land, not a real drop. */
    $days = match ($range) { '24h' => 1, '7d' => 7, '90d' => 90, default => 30 };
    if ($days <= 1) {
        $from = sleek_snap_start($max - 23 * 3600, 'hour');
    } else {
        $from = sleek_snap_start($max - ($days - 1) * 86400, 'day');
    }
    return [max($from, sleek_snap_start($min, $days <= 1 ? 'hour' : 'day')), $max, $range];
}

/** Round a timestamp down to the start of its hour or day. */
function sleek_snap_start(int $ts, string $unit): int
{
    return $unit === 'hour'
        ? (int) strtotime(date('Y-m-d H:00:00', $ts))
        : (int) strtotime(date('Y-m-d 00:00:00', $ts));
}

function sleek_build_payload(array $ingest, array $q): array
{
    $streams = $ingest['streams'];
    [$from, $to, $range] = sleek_resolve_range($q, $streams);
    $interval = sleek_interval($from, $to);
    $labels   = sleek_bucket_labels($from, $to, $interval);

    $cur = $prev = [];
    foreach (SLEEK_STREAMS as $s) {
        $cur[$s]  = sleek_window($streams[$s], $from, $to);
        $prev[$s] = sleek_prev_window($streams[$s], $from, $to);
    }

    $ok      = fn(array $r) => in_array($r['status'], ['success', 'ok', 'paid', 'completed'], true);
    $revenue = fn(array $rows) => array_sum(array_map(fn($r) => $ok($r) ? $r['amount'] : 0, $rows));
    $is5xx   = fn(array $r) => $r['status'] >= 500;
    $isErr   = fn(array $r) => in_array($r['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true);

    /* ------------------------------------------------------------- KPIs */
    $reqNow  = count($cur['access']);     $reqPrev = count($prev['access']);
    $revNow  = $revenue($cur['billing']); $revPrev = $revenue($prev['billing']);
    $errNow  = count(array_filter($cur['access'], $is5xx));
    $errPrev = count(array_filter($prev['access'], $is5xx));
    $creNow  = array_sum(array_column($cur['usage'], 'units'));
    $crePrev = array_sum(array_column($prev['usage'], 'units'));
    $appNow  = count(array_filter($cur['error'], $isErr));
    $appPrev = count(array_filter($prev['error'], $isErr));

    $rateNow  = $reqNow  ? $errNow  / $reqNow  * 100 : 0.0;
    $ratePrev = $reqPrev ? $errPrev / $reqPrev * 100 : 0.0;

    $users = array_unique(array_merge(
        array_column($cur['usage'], 'user'),
        array_column($cur['billing'], 'user')
    ));
    $usersPrev = array_unique(array_merge(
        array_column($prev['usage'], 'user'),
        array_column($prev['billing'], 'user')
    ));

    $currency = $cur['billing'] ? ($cur['billing'][0]['currency'] ?: 'NGN') : 'NGN';

    $kpis = [
        ['key' => 'revenue',   'label' => 'Revenue',        'value' => $revNow,
         'unit' => $currency, 'fmt' => 'money', 'good' => 'up',
         'delta' => sleek_delta($revNow, $revPrev)],
        ['key' => 'requests',  'label' => 'Requests',       'value' => $reqNow,
         'unit' => '', 'fmt' => 'int', 'good' => 'up',
         'delta' => sleek_delta($reqNow, $reqPrev)],
        ['key' => 'users',     'label' => 'Active users',   'value' => count($users),
         'unit' => '', 'fmt' => 'int', 'good' => 'up',
         'delta' => sleek_delta(count($users), count($usersPrev))],
        ['key' => 'credits',   'label' => 'Credits used',   'value' => $creNow,
         'unit' => '', 'fmt' => 'int', 'good' => 'up',
         'delta' => sleek_delta($creNow, $crePrev)],
        ['key' => 'errorrate', 'label' => '5xx error rate', 'value' => $rateNow,
         'unit' => '%', 'fmt' => 'pct', 'good' => 'down',
         'delta' => sleek_delta($rateNow, $ratePrev)],
        ['key' => 'apperrors', 'label' => 'App errors',     'value' => $appNow,
         'unit' => '', 'fmt' => 'int', 'good' => 'down',
         'delta' => sleek_delta($appNow, $appPrev)],
    ];

    /* ------------------------------------------------------------ panels */
    $statusMix = sleek_series($cur['access'], $labels, $interval, null,
        fn($r) => sleek_status_class($r['status']));
    $levelMix = sleek_series($cur['error'], $labels, $interval, null,
        fn($r) => sleek_level_class($r['level']));
    $revByPlan = sleek_series($cur['billing'], $labels, $interval,
        fn($r) => $ok($r) ? $r['amount'] : 0, fn($r) => $r['plan']);
    $creditsByAction = sleek_series($cur['usage'], $labels, $interval,
        fn($r) => $r['units'], fn($r) => $r['action']);

    return [
        'meta' => [
            'from'      => $from,
            'to'        => $to,
            'range'     => $range,
            'interval'  => $interval,
            'currency'  => $currency,
            'generated' => date('Y-m-d H:i'),
            'files'     => $ingest['files'],
            'counts'    => array_map('count', $cur),
            'empty'     => array_sum(array_map('count', $cur)) === 0,
            'samples'   => sleek_all_samples($ingest['files']),
        ],
        'kpis'   => $kpis,
        'labels' => $labels,
        'panels' => [
            'traffic'         => ['series' => sleek_series($cur['access'], $labels, $interval)],
            'statusMix'       => ['series' => sleek_order_keys($statusMix, ['2xx', '3xx', '4xx', '5xx'])],
            'levelMix'        => ['series' => sleek_order_keys($levelMix, ['good', 'warning', 'critical'])],
            'revenue'         => ['series' => sleek_series($cur['billing'], $labels, $interval,
                                      fn($r) => $ok($r) ? $r['amount'] : 0)],
            'revByPlan'       => ['series' => sleek_cap_series($revByPlan, 4)],
            'creditsByAction' => ['series' => sleek_cap_series($creditsByAction, 4)],
            'topPaths'        => ['data' => sleek_topn($cur['access'], fn($r) => $r['path'], 8)],
            'topErrors'       => ['data' => sleek_topn(array_filter($cur['error'], $isErr),
                                      fn($r) => $r['message'], 8)],
            'topActions'      => ['data' => sleek_topn($cur['usage'], fn($r) => $r['action'], 8,
                                      fn($r) => $r['units'])],
            'slowest'         => ['data' => sleek_p95_by_path($cur['access'], 8)],
            'planSplit'       => ['data' => sleek_topn(array_filter($cur['billing'], $ok),
                                      fn($r) => $r['plan'], 6, fn($r) => $r['amount'])],
        ],
    ];
}

/** True when every parsed file is one of the generated samples. */
function sleek_all_samples(array $files): bool
{
    $real = array_filter($files, fn($f) => !str_starts_with($f['name'], 'sample-'));
    return $files && !$real;
}

/** Force a fixed key order so a slot's colour never shifts between renders. */
function sleek_order_keys(array $series, array $order): array
{
    $out = [];
    foreach ($order as $k) if (isset($series[$k])) $out[$k] = $series[$k];
    foreach ($series as $k => $v) if (!isset($out[$k])) $out[$k] = $v;
    return $out;
}

/** Keep the N biggest series; the rest sum into "Other" — never a 9th hue. */
function sleek_cap_series(array $series, int $n): array
{
    // A series that is zero everywhere draws no mark — a legend entry for it is noise.
    $series = array_filter($series, fn($vals) => array_sum($vals) > 0);
    if (!$series) return $series;
    uasort($series, fn($a, $b) => array_sum($b) <=> array_sum($a));
    if (count($series) <= $n) return $series;

    $top   = array_slice($series, 0, $n, true);
    $rest  = array_slice($series, $n, null, true);
    $other = array_fill(0, count(reset($series)), 0);
    foreach ($rest as $vals) foreach ($vals as $i => $v) $other[$i] += $v;
    if (array_sum($other) > 0) $top['Other'] = $other;
    return $top;
}

/** p95 response time per path, for paths with enough traffic to mean anything. */
function sleek_p95_by_path(array $rows, int $n): array
{
    $by = [];
    foreach ($rows as $r) {
        if ($r['rt'] === null) continue;
        $by[$r['path']][] = $r['rt'];
    }
    $out = [];
    foreach ($by as $path => $times) {
        if (count($times) < 20) continue;
        sort($times);
        $out[$path] = (int) round($times[(int) floor(0.95 * (count($times) - 1))] * 1000);
    }
    arsort($out);
    return array_slice($out, 0, $n, true);
}
