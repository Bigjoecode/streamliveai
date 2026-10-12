<?php
/**
 * SleekAI dashboard — log ingest layer.
 *
 * Drop files in dashboard/data/. Everything is auto-detected; nothing here
 * assumes a particular export tool. Four normalised streams come out:
 *
 *   access  {ts, ip, method, path, status, bytes, referer, ua, rt}
 *   error   {ts, level, channel, message, trace}
 *   usage   {ts, user, action, units, status}
 *   billing {ts, user, plan, amount, currency, credits, status}
 */

const SLEEK_STREAMS = ['access', 'error', 'usage', 'billing'];

/* ------------------------------------------------------------------ files */

function sleek_data_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($p)) continue;
        if (preg_match('/^(README|\.)/i', $f)) continue;
        $out[] = $p;
    }
    return $out;
}

/** Read a file line-by-line, transparently handling .gz. */
function sleek_lines(string $path): Generator
{
    $gz = preg_match('/\.gz$/i', $path);
    $h  = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
    if (!$h) return;
    while (($line = ($gz ? gzgets($h) : fgets($h))) !== false) {
        yield rtrim($line, "\r\n");
    }
    $gz ? gzclose($h) : fclose($h);
}

/** First N non-blank lines — used for sniffing. */
function sleek_head(string $path, int $n = 40): array
{
    $out = [];
    foreach (sleek_lines($path) as $l) {
        if (trim($l) === '') continue;
        $out[] = $l;
        if (count($out) >= $n) break;
    }
    return $out;
}

/* --------------------------------------------------------------- detection */

/**
 * Sniff a file's format from its first lines. Returns one of:
 * access | laravel | csv | json | jsonl | unknown
 */
function sleek_detect(string $path): string
{
    $head = sleek_head($path, 25);
    if (!$head) return 'unknown';
    $sample = implode("\n", $head);

    // Laravel / monolog:  [2026-09-20 12:48:01] production.ERROR: msg
    $laravel = 0;
    foreach ($head as $l) {
        if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}.*?\]\s+[\w\-]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\b/i', $l)) {
            $laravel++;
        }
    }
    if ($laravel >= 1) return 'laravel';

    // Apache/Nginx combined:  ip - - [date] "GET /x HTTP/1.1" 200 1234 "ref" "ua"
    $access = 0;
    foreach ($head as $l) {
        if (preg_match('/^\S+\s+\S+\s+\S+\s+\[[^\]]+\]\s+"[A-Z]+ /', $l)) $access++;
    }
    if ($access >= 1) return 'access';

    // JSON array / JSON Lines
    $t = ltrim($sample);
    if ($t !== '' && $t[0] === '[') return 'json';
    $jsonl = 0;
    foreach (array_slice($head, 0, 5) as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) $jsonl++;
    }
    if ($jsonl >= 1 && $jsonl === min(5, count($head))) return 'jsonl';
    if ($t !== '' && $t[0] === '{') return 'json';

    // Delimited text with a header row
    if (sleek_sniff_delim($head[0]) !== null) return 'csv';

    return 'unknown';
}

/** Guess a delimiter from a header line; null if it doesn't look delimited. */
function sleek_sniff_delim(string $line): ?string
{
    $best = null; $bestN = 1;
    foreach ([',', ";", "\t", '|'] as $d) {
        $n = count(str_getcsv($line, $d));
        if ($n > $bestN) { $bestN = $n; $best = $d; }
    }
    return $bestN >= 2 ? $best : null;
}

/* ----------------------------------------------------------------- parsers */

/** Apache/Nginx combined (+ optional trailing request time). */
function sleek_parse_access(string $path): array
{
    $rows = []; $bad = 0;
    $re = '/^(?P<ip>\S+)\s+\S+\s+(?P<user>\S+)\s+\[(?P<ts>[^\]]+)\]\s+'
        . '"(?P<method>[A-Z]+)\s+(?P<path>[^"\s]*)(?:\s+[^"]*)?"\s+'
        . '(?P<status>\d{3})\s+(?P<bytes>\d+|-)'
        . '(?:\s+"(?P<referer>[^"]*)"\s+"(?P<ua>[^"]*)")?'
        . '(?:\s+(?P<rt>[\d.]+))?/';

    foreach (sleek_lines($path) as $line) {
        if (trim($line) === '') continue;
        if (!preg_match($re, $line, $m)) { $bad++; continue; }
        $ts = sleek_ts($m['ts']);
        if ($ts === null) { $bad++; continue; }
        $rows[] = [
            'ts'      => $ts,
            'ip'      => $m['ip'],
            'method'  => $m['method'],
            'path'    => sleek_norm_path($m['path']),
            'status'  => (int) $m['status'],
            'bytes'   => $m['bytes'] === '-' ? 0 : (int) $m['bytes'],
            'referer' => $m['referer'] ?? '',
            'ua'      => $m['ua'] ?? '',
            'rt'      => isset($m['rt']) && $m['rt'] !== '' ? (float) $m['rt'] : null,
        ];
    }
    return ['rows' => $rows, 'skipped' => $bad];
}

/** Laravel / monolog. Continuation lines fold into the previous entry's trace. */
function sleek_parse_laravel(string $path): array
{
    $rows = []; $bad = 0; $cur = null;
    $re = '/^\[(?P<ts>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+'
        . '(?P<chan>[\w\-]+)\.(?P<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s*'
        . '(?P<msg>.*)$/i';

    foreach (sleek_lines($path) as $line) {
        if (preg_match($re, $line, $m)) {
            if ($cur) $rows[] = $cur;
            $ts = sleek_ts($m['ts']);
            if ($ts === null) { $bad++; $cur = null; continue; }
            $cur = [
                'ts'      => $ts,
                'level'   => strtoupper($m['level']),
                'channel' => strtolower($m['chan']),
                'message' => sleek_clean_msg($m['msg']),
                'trace'   => '',
            ];
        } elseif ($cur !== null && trim($line) !== '') {
            if (strlen($cur['trace']) < 4000) $cur['trace'] .= $line . "\n";
        }
    }
    if ($cur) $rows[] = $cur;
    return ['rows' => $rows, 'skipped' => $bad];
}

/** Strip the trailing JSON context blob and collapse whitespace. */
function sleek_clean_msg(string $msg): string
{
    $msg = preg_replace('/\s*\{"exception":.*$/s', '', $msg);
    $msg = preg_replace('/\s*\[\]\s*\[\]\s*$/', '', $msg);
    return trim(preg_replace('/\s+/', ' ', $msg));
}

/** Query string off, trailing slash off, numeric/uuid ids folded to :id. */
function sleek_norm_path(string $p): string
{
    $p = explode('?', $p, 2)[0];
    if ($p === '') $p = '/';
    if (strlen($p) > 1) $p = rtrim($p, '/');
    $p = preg_replace('#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#i', '/:id', $p);
    $p = preg_replace('#/\d+#', '/:id', $p);
    return $p === '' ? '/' : $p;
}

/** Parse a timestamp in any of the shapes these logs use. Returns unix ts. */
function sleek_ts($v): ?int
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $n = (float) $v;
        if ($n > 1e12) $n /= 1000;          // milliseconds
        return ($n > 946684800 && $n < 4102444800) ? (int) $n : null;
    }
    $v = trim((string) $v);
    // Apache: 20/Sep/2026:12:48:01 +0100
    if (preg_match('#^(\d{2})/(\w{3})/(\d{4}):(\d{2}):(\d{2}):(\d{2})\s*([+\-]\d{4})?$#', $v, $m)) {
        $v = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}" . ($m[7] ? " {$m[7]}" : '');
    }
    $t = strtotime($v);
    return ($t !== false && $t > 946684800 && $t < 4102444800) ? $t : null;
}

/* ------------------------------------------------- tabular (CSV / JSON) ---- */

/** Canonical field => header aliases. First match wins, longest alias first. */
function sleek_field_aliases(): array
{
    return [
        'ts'       => ['created_at','createdat','created','date','datetime','timestamp','time','occurred_at','paid_at','processed_at','logged_at','when'],
        'user'     => ['user_email','user_id','userid','customer_id','customer','account_id','account','username','user','email'],
        'amount'   => ['amount_paid','gross_amount','amount','total','revenue','price','value','charge','paid'],
        'currency' => ['currency','curr','ccy'],
        'credits'  => ['credits_used','credit_delta','credits','credit','tokens','units','quantity','qty'],
        'plan'     => ['plan_name','plan','tier','package','subscription'],
        'status'   => ['payment_status','http_status','status_code','status','state','result','outcome','code'],
        'action'   => ['event_type','event','action','operation','feature','tool','job_type','type','model'],
        'level'    => ['log_level','severity','level'],
        'message'  => ['message','msg','error','exception','description','detail'],
        'method'   => ['http_method','method','verb'],
        'path'     => ['path','url','uri','route','endpoint','request'],
        'ip'       => ['ip_address','remote_addr','client_ip','ip'],
        'bytes'    => ['response_size','bytes_sent','bytes','size'],
        'ua'       => ['user_agent','useragent','agent','ua'],
        'channel'  => ['channel','service','source','app'],
    ];
}

/** Map a header row onto canonical fields. Returns [canonical => column index]. */
function sleek_map_headers(array $headers): array
{
    $norm = [];
    foreach ($headers as $i => $h) {
        $norm[$i] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $h)));
    }
    $map = [];
    foreach (sleek_field_aliases() as $field => $aliases) {
        foreach ($aliases as $a) {
            foreach ($norm as $i => $h) {
                if (in_array($i, $map, true)) continue;   // one column, one field
                if ($h === $a) { $map[$field] = $i; continue 3; }
            }
        }
        // second pass: substring match, so "stripe_amount_cents" still lands
        foreach ($aliases as $a) {
            foreach ($norm as $i => $h) {
                if (in_array($i, $map, true)) continue;
                if (str_contains($h, $a)) { $map[$field] = $i; continue 3; }
            }
        }
    }
    return $map;
}

/** Read a delimited file into [headers, rows]. */
function sleek_read_csv(string $path): array
{
    $headers = null; $rows = []; $delim = ',';
    foreach (sleek_lines($path) as $line) {
        if ($headers === null) {
            if (trim($line) === '') continue;
            $line  = preg_replace('/^\xEF\xBB\xBF/', '', $line);   // strip BOM
            $delim = sleek_sniff_delim($line) ?? ',';
            $headers = str_getcsv($line, $delim);
            continue;
        }
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line, $delim);
    }
    return [$headers ?? [], $rows];
}

/** Read JSON array / JSON Lines into a list of assoc rows. */
function sleek_read_json(string $path): array
{
    $raw = '';
    foreach (sleek_lines($path) as $l) $raw .= $l . "\n";
    $raw = trim($raw);

    $d = json_decode($raw, true);
    if (is_array($d)) {
        // {"data":[...]} / {"records":[...]} wrappers
        if (!array_is_list($d)) {
            foreach (['data','records','rows','items','results','logs'] as $k) {
                if (isset($d[$k]) && is_array($d[$k]) && array_is_list($d[$k])) return $d[$k];
            }
            return [$d];
        }
        return $d;
    }
    // JSON Lines
    $out = [];
    foreach (explode("\n", $raw) as $l) {
        if (trim($l) === '') continue;
        $r = json_decode($l, true);
        if (is_array($r)) $out[] = $r;
    }
    return $out;
}

/** Union of keys across assoc rows, so ragged JSON still yields a header set. */
function sleek_json_headers(array $rows): array
{
    $keys = [];
    foreach (array_slice($rows, 0, 200) as $r) {
        if (!is_array($r)) continue;
        foreach (array_keys($r) as $k) $keys[$k] = true;
    }
    return array_keys($keys);
}

/** Decide which stream a mapped tabular file belongs to. */
function sleek_classify(array $map, array $headers): string
{
    $hay = strtolower(implode(' ', array_map('strval', $headers)));

    if (isset($map['amount'])) return 'billing';
    if (isset($map['plan']) && (isset($map['credits']) || isset($map['currency']))) return 'billing';
    if (str_contains($hay, 'invoice') || str_contains($hay, 'payment') || str_contains($hay, 'subscription')) return 'billing';
    if (isset($map['level'])) return 'error';
    if (isset($map['path']) && (isset($map['method']) || isset($map['ip']) || isset($map['bytes']))) return 'access';
    if (isset($map['action']) || isset($map['credits'])) return 'usage';
    if (isset($map['message'])) return 'error';
    if (isset($map['path'])) return 'access';
    return 'usage';
}

/** "$1,234.50" / "1234" / 1234 cents -> float. */
function sleek_money($v, string $header = ''): float
{
    if (is_numeric($v)) $n = (float) $v;
    else {
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);
        $n = $s === '' || $s === '-' ? 0.0 : (float) $s;
    }
    if (preg_match('/cent|minor|kobo/i', $header)) $n /= 100;
    return $n;
}

/** Turn a tabular file into normalised rows for one stream. */
function sleek_parse_tabular(string $path, string $format): array
{
    if ($format === 'csv') {
        [$headers, $raw] = sleek_read_csv($path);
        $assoc = false;
    } else {
        $raw     = sleek_read_json($path);
        $headers = sleek_json_headers($raw);
        $assoc   = true;
    }
    if (!$headers) return ['rows' => [], 'skipped' => 0, 'stream' => 'usage'];

    $map    = sleek_map_headers($headers);
    $stream = sleek_classify($map, $headers);
    $get = function ($row, string $field) use ($map, $headers, $assoc) {
        if (!isset($map[$field])) return null;
        $i = $map[$field];
        if ($assoc) {
            $k = $headers[$i] ?? null;
            return $k !== null && array_key_exists($k, $row) ? $row[$k] : null;
        }
        return $row[$i] ?? null;
    };
    $hdrOf = fn(string $f) => isset($map[$f]) ? (string) ($headers[$map[$f]] ?? '') : '';

    $rows = []; $bad = 0;
    foreach ($raw as $r) {
        if (!is_array($r)) { $bad++; continue; }
        $ts = sleek_ts($get($r, 'ts'));
        if ($ts === null) { $bad++; continue; }

        if ($stream === 'billing') {
            $rows[] = [
                'ts'       => $ts,
                'user'     => (string) ($get($r, 'user') ?? 'unknown'),
                'plan'     => (string) ($get($r, 'plan') ?? 'unknown'),
                'amount'   => sleek_money($get($r, 'amount') ?? 0, $hdrOf('amount')),
                'currency' => strtoupper((string) ($get($r, 'currency') ?? 'NGN')),
                'credits'  => (float) preg_replace('/[^0-9.\-]/', '', (string) ($get($r, 'credits') ?? 0)),
                'status'   => strtolower((string) ($get($r, 'status') ?? 'unknown')),
            ];
        } elseif ($stream === 'error') {
            $rows[] = [
                'ts'      => $ts,
                'level'   => strtoupper((string) ($get($r, 'level') ?? 'ERROR')),
                'channel' => strtolower((string) ($get($r, 'channel') ?? 'app')),
                'message' => sleek_clean_msg((string) ($get($r, 'message') ?? '')),
                'trace'   => '',
            ];
        } elseif ($stream === 'access') {
            $rows[] = [
                'ts'      => $ts,
                'ip'      => (string) ($get($r, 'ip') ?? ''),
                'method'  => strtoupper((string) ($get($r, 'method') ?? 'GET')),
                'path'    => sleek_norm_path((string) ($get($r, 'path') ?? '/')),
                'status'  => (int) ($get($r, 'status') ?? 0),
                'bytes'   => (int) ($get($r, 'bytes') ?? 0),
                'referer' => '',
                'ua'      => (string) ($get($r, 'ua') ?? ''),
                'rt'      => null,
            ];
        } else {
            $rows[] = [
                'ts'     => $ts,
                'user'   => (string) ($get($r, 'user') ?? 'unknown'),
                'action' => (string) ($get($r, 'action') ?? $get($r, 'path') ?? 'unknown'),
                'units'  => (float) preg_replace('/[^0-9.\-]/', '', (string) ($get($r, 'credits') ?? 1)),
                'status' => strtolower((string) ($get($r, 'status') ?? 'ok')),
            ];
        }
    }
    return ['rows' => $rows, 'skipped' => $bad, 'stream' => $stream, 'map' => array_keys($map)];
}

/* -------------------------------------------------------------- top level */

/** Parse one file, memoised on (path, mtime, size). */
function sleek_parse_file(string $path, string $cacheDir): array
{
    $key = md5($path . '|' . filemtime($path) . '|' . filesize($path));
    $cf  = $cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
    if (is_file($cf)) {
        $c = @unserialize(file_get_contents($cf));
        if (is_array($c)) return $c;
    }

    $format = sleek_detect($path);
    switch ($format) {
        case 'access':  $r = sleek_parse_access($path);  $r['stream'] = 'access'; break;
        case 'laravel': $r = sleek_parse_laravel($path); $r['stream'] = 'error';  break;
        case 'csv':
        case 'json':
        case 'jsonl':   $r = sleek_parse_tabular($path, $format === 'csv' ? 'csv' : 'json'); break;
        default:        $r = ['rows' => [], 'skipped' => 0, 'stream' => null];
    }
    $r['format'] = $format;
    $r['name']   = basename($path);
    $r['bytes']  = filesize($path);

    if (is_dir($cacheDir) && is_writable($cacheDir)) @file_put_contents($cf, serialize($r));
    return $r;
}

/** Parse every file in the data dir and merge into the four streams. */
function sleek_ingest(string $dataDir, string $cacheDir): array
{
    $streams = array_fill_keys(SLEEK_STREAMS, []);
    $files   = [];

    foreach (sleek_data_files($dataDir) as $path) {
        $r = sleek_parse_file($path, $cacheDir);
        $files[] = [
            'name'    => $r['name'],
            'format'  => $r['format'],
            'stream'  => $r['stream'],
            'count'   => count($r['rows']),
            'skipped' => $r['skipped'],
            'bytes'   => $r['bytes'],
        ];
        if ($r['stream'] && isset($streams[$r['stream']])) {
            foreach ($r['rows'] as $row) $streams[$r['stream']][] = $row;
        }
    }

    foreach ($streams as $k => $_) {
        usort($streams[$k], fn($a, $b) => $a['ts'] <=> $b['ts']);
    }
    usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

    return ['streams' => $streams, 'files' => $files];
}

/** Drop rows outside [from, to]; both unix ts, either may be null. */
function sleek_window(array $rows, ?int $from, ?int $to): array
{
    if ($from === null && $to === null) return $rows;
    $out = [];
    foreach ($rows as $r) {
        if ($from !== null && $r['ts'] < $from) continue;
        if ($to   !== null && $r['ts'] > $to)   continue;
        $out[] = $r;
    }
    return $out;
}
