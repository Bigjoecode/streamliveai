<?php
/**
 * Generates sample logs in ops-dashboard/data/ so the dashboard renders before the
 * real exports arrive. Every file it writes is named sample-*; delete those and
 * drop your own in to switch over.
 *
 *   php ops-dashboard/tools/make-samples.php
 */
$dir = dirname(__DIR__) . '/data';
@mkdir($dir, 0777, true);
mt_srand(20260920);

// Midnight-aligned so every generated day is a whole daily bucket.
$days  = 30;
$start = strtotime('today') - ($days - 1) * 86400;

$paths = ['/' => 26, '/pricing' => 14, '/features' => 11, '/login' => 10, '/how-it-works' => 8,
          '/register' => 7, '/studio' => 9, '/api/generate' => 8, '/about' => 4, '/contact' => 3];
$uas = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/127.0 Safari/537.36',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
];
$weighted = function (array $w) {
    $t = array_sum($w); $r = mt_rand(1, $t);
    foreach ($w as $k => $v) { $r -= $v; if ($r <= 0) return $k; }
    return array_key_first($w);
};

/* ---- access log (Apache combined + response time) ---- */
$fh = fopen("$dir/sample-access.log", 'w');
for ($d = 0; $d < $days; $d++) {
    $day  = $start + $d * 86400;
    $base = 420 + (int) ($d * 11) + mt_rand(-60, 60);          // gentle growth
    if (date('N', $day) >= 6) $base = (int) ($base * 0.65);     // weekend dip
    for ($i = 0; $i < $base; $i++) {
        $hour = $weighted(array_combine(range(0, 23),
            [2,1,1,1,1,2,4,7,11,15,18,19,17,16,18,19,17,14,12,10,8,6,4,3]));
        $ts   = $day + $hour * 3600 + mt_rand(0, 3599);
        $path = $weighted($paths);
        $code = 200;
        $roll = mt_rand(1, 1000);
        if ($roll > 988)      $code = 500;
        elseif ($roll > 960)  $code = 404;
        elseif ($roll > 945)  $code = 302;
        elseif ($roll > 938)  $code = 401;
        if ($path === '/api/generate' && mt_rand(1, 100) > 92) $code = 503;
        $method = in_array($path, ['/login', '/register', '/api/generate'], true) && mt_rand(1, 3) === 1 ? 'POST' : 'GET';
        $rt = $path === '/api/generate' ? mt_rand(800, 6400) / 1000 : mt_rand(40, 900) / 1000;
        fprintf($fh, "%d.%d.%d.%d - - [%s] \"%s %s HTTP/1.1\" %d %d \"%s\" \"%s\" %.3f\n",
            mt_rand(41,197), mt_rand(0,255), mt_rand(0,255), mt_rand(1,254),
            date('d/M/Y:H:i:s O', $ts), $method, $path, $code, mt_rand(600, 48000),
            mt_rand(1,3) === 1 ? 'https://www.google.com/' : '-', $uas[array_rand($uas)], $rt);
    }
}
fclose($fh);

/* ---- laravel log ---- */
$errs = [
    ['ERROR',    'app',   'cURL error 28: Operation timed out after 30000 milliseconds'],
    ['ERROR',    'app',   'Undefined array key "media_id" in GenerationController.php:148'],
    ['WARNING',  'app',   'Render queue depth above threshold (queue=media, depth=64)'],
    ['ERROR',    'queue', 'Job App\Jobs\RenderVideo has been attempted too many times'],
    ['CRITICAL', 'app',   'SQLSTATE[HY000] [2002] Connection refused'],
    ['INFO',     'app',   'Scheduled credit reconciliation completed'],
    ['WARNING',  'auth',  'Throttled login attempt for user id 4412'],
    ['ERROR',    'billing','Paystack webhook signature mismatch — payload rejected'],
];
$fh = fopen("$dir/sample-laravel.log", 'w');
for ($d = 0; $d < $days; $d++) {
    $day = $start + $d * 86400;
    $n   = mt_rand(6, 26) + ($d > 22 ? 14 : 0);                 // a late-window incident
    for ($i = 0; $i < $n; $i++) {
        [$lvl, $chan, $msg] = $errs[array_rand($errs)];
        $ts = $day + mt_rand(0, 86399);
        fprintf($fh, "[%s] production.%s: %s\n", date('Y-m-d H:i:s', $ts), $lvl, $msg);
        if ($lvl === 'CRITICAL' || $lvl === 'ERROR') {
            fwrite($fh, "#0 /var/www/app/Http/Controllers/GenerationController.php(148): App\Services\Media->render()\n");
            fwrite($fh, "#1 {main}\n");
        }
    }
}
fclose($fh);

/* ---- billing csv ---- */
$plans = ['Free' => 0, 'Starter' => 4500, 'Pro' => 14500, 'Business' => 39000];
$fh = fopen("$dir/sample-billing.csv", 'w');
fputcsv($fh, ['created_at', 'user_email', 'plan', 'amount', 'currency', 'credits', 'payment_status']);
for ($d = 0; $d < $days; $d++) {
    $day = $start + $d * 86400;
    $n   = mt_rand(2, 9) + (int) ($d / 8);
    for ($i = 0; $i < $n; $i++) {
        $plan = $weighted(['Starter' => 48, 'Pro' => 34, 'Business' => 11, 'Free' => 7]);
        $amt  = $plans[$plan];
        $st   = $weighted(['success' => 92, 'failed' => 6, 'refunded' => 2]);
        fputcsv($fh, [
            date('Y-m-d H:i:s', $day + mt_rand(0, 86399)),
            'user' . mt_rand(1000, 1400) . '@example.com',
            $plan, $amt, 'NGN',
            $plan === 'Free' ? 20 : (int) ($amt / 45),
            $st,
        ]);
    }
}
fclose($fh);

/* ---- usage csv ---- */
$actions = ['image.generate' => 34, 'video.generate' => 19, 'voice.tts' => 14, 'upscale' => 11,
            'bg.remove' => 9, 'lipsync' => 7, 'export' => 6];
$fh = fopen("$dir/sample-usage.csv", 'w');
fputcsv($fh, ['created_at', 'user_email', 'event_type', 'credits_used', 'status']);
for ($d = 0; $d < $days; $d++) {
    $day = $start + $d * 86400;
    $n   = 60 + $d * 4 + mt_rand(-20, 20);
    for ($i = 0; $i < $n; $i++) {
        $a = $weighted($actions);
        fputcsv($fh, [
            date('Y-m-d H:i:s', $day + mt_rand(0, 86399)),
            'user' . mt_rand(1000, 1400) . '@example.com',
            $a,
            match ($a) { 'video.generate' => mt_rand(8, 30), 'lipsync' => mt_rand(6, 18), 'upscale' => mt_rand(2, 6), default => mt_rand(1, 4) },
            $weighted(['ok' => 94, 'failed' => 6]),
        ]);
    }
}
fclose($fh);

foreach (['sample-access.log','sample-laravel.log','sample-billing.csv','sample-usage.csv'] as $f) {
    printf("%-24s %8.1f KB\n", $f, filesize("$dir/$f") / 1024);
}
