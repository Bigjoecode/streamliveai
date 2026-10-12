<?php
/**
 * SleekAI — fal.ai queue (Image to Video's talking-photo step) and the async job
 * machinery any provider-queued tool uses.
 *
 *   POST {queue_base}/{model}      JSON input -> {request_id, status_url, response_url}
 *   GET  status_url                -> {status: IN_QUEUE | IN_PROGRESS | COMPLETED}
 *   GET  response_url              -> model output, e.g. {video: {url}, duration}
 *   Auth: "Authorization: Key <key>"
 *
 * There's no worker process on XAMPP, so jobs advance when someone looks: the tool
 * page and Job History poll job-status, and every signed-in page load sweeps a few
 * stale ones. The finished file is copied into storage/outputs, so results don't
 * depend on fal's CDN keeping it.
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

/** Give up on (and refund) a queued job after this long. */
const SLEEK_ASYNC_MAX_SECONDS = 30 * 60;
/** Minimum gap between provider checks for one job, however many tabs are polling. */
const SLEEK_ASYNC_POLL_EVERY = 5;
const SLEEK_VIDEO_MAX_BYTES = 200 * 1024 * 1024;

/** A finished file that must not be accepted (wrong host, too big, wrong type). Retrying won't help. */
final class SleekOutputRejected extends RuntimeException {}

function sleek_fal_configured(): bool
{
    return sleek_config('fal')['api_key'] !== '';
}

/**
 * @return array{status: int, json: ?array, raw: string}
 */
function sleek_fal_http(string $method, string $url, ?array $body = null, int $timeout = 30): array
{
    $headers = ['Authorization: Key ' . sleek_config('fal')['api_key'], 'Accept: application/json'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    if ($raw === false) throw new SleekProviderError("Could not reach fal: {$err}");

    $json = json_decode((string) $raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw];
}

function sleek_fal_error(array $res): string
{
    $j = $res['json'];
    if (is_array($j)) {
        $d = $j['detail'] ?? $j['error'] ?? $j['message'] ?? null;
        if (is_string($d)) return $d;
        if (is_array($d) && isset($d[0]['msg'])) return (string) $d[0]['msg'];
        if (is_array($d)) return json_encode($d);
    }
    return mb_substr(trim(strip_tags($res['raw'])), 0, 200) ?: 'no details';
}

/** A URL fal handed back must point at fal's own queue host, not anywhere else. */
function sleek_fal_same_host(string $url): bool
{
    return parse_url($url, PHP_URL_HOST) === parse_url(sleek_config('fal')['queue_base'], PHP_URL_HOST);
}

/**
 * Queue a request.
 *
 * @return array{request_id: string, status_url: string, response_url: string}
 */
function sleek_fal_submit(string $model, array $input): array
{
    $cfg = sleek_config('fal');
    if ($cfg['api_key'] === '') throw new SleekProviderError('fal API key is not set.');

    $res = sleek_fal_http('POST', rtrim($cfg['queue_base'], '/') . '/' . $model, $input, 120);
    $j   = $res['json'];
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($j) || empty($j['request_id'])) {
        throw new SleekProviderError("fal submit → HTTP {$res['status']}: " . sleek_fal_error($res));
    }
    foreach (['status_url', 'response_url'] as $k) {
        if (!is_string($j[$k] ?? null) || !sleek_fal_same_host($j[$k])) {
            throw new SleekProviderError("fal returned an unexpected {$k}.");
        }
    }
    return ['request_id' => (string) $j['request_id'], 'status_url' => $j['status_url'], 'response_url' => $j['response_url']];
}

function sleek_async_record(int $jobId, string $provider, array $sub): void
{
    sleek_db()->prepare('INSERT INTO async_jobs (job_id, provider, request_id, status_url, response_url, provider_status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)')
              ->execute([$jobId, $provider, $sub['request_id'], $sub['status_url'], $sub['response_url'], 'IN_QUEUE', time()]);
}

/**
 * Advance one queued job: ask fal how it's doing and, when it's done, fetch the
 * video and complete the job (or fail and refund it). Returns the job's status.
 */
function sleek_async_poll(int $jobId): string
{
    $db   = sleek_db();
    $stmt = $db->prepare('SELECT a.*, j.status AS job_status, j.created_at AS job_created FROM async_jobs a
                          JOIN jobs j ON j.id = a.job_id WHERE a.job_id = ?');
    $stmt->execute([$jobId]);
    $a = $stmt->fetch();
    if (!$a) return 'unknown';
    if ($a['job_status'] !== 'processing') return $a['job_status'];

    // Throttle: only one caller per SLEEK_ASYNC_POLL_EVERY seconds talks to fal.
    $now   = time();
    $claim = $db->prepare('UPDATE async_jobs SET last_polled_at = ?
                           WHERE job_id = ? AND (last_polled_at IS NULL OR last_polled_at <= ?)');
    $claim->execute([$now, $jobId, $now - SLEEK_ASYNC_POLL_EVERY]);
    if ($claim->rowCount() !== 1) return 'processing';

    if ($now - (int) $a['job_created'] > SLEEK_ASYNC_MAX_SECONDS) {
        sleek_job_fail($jobId, 'This took too long to render. Your credits were refunded.', "fal {$a['request_id']} timed out");
        return 'failed';
    }

    try {
        $st = sleek_fal_http('GET', $a['status_url'], null, 20);
        if ($st['status'] === 404) {
            sleek_job_fail($jobId, 'The video service lost this job. Your credits were refunded.', "fal status 404 for {$a['request_id']}");
            return 'failed';
        }
        $status = is_array($st['json']) ? (string) ($st['json']['status'] ?? '') : '';
        if ($status !== 'COMPLETED') {
            if ($status !== '') $db->prepare('UPDATE async_jobs SET provider_status = ? WHERE job_id = ?')->execute([$status, $jobId]);
            return 'processing';   // queued, running, or a hiccup — try again next poll
        }

        $res = sleek_fal_http('GET', $a['response_url'], null, 30);
        $url = is_array($res['json']) ? ($res['json']['video']['url'] ?? null) : null;
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_string($url)) {
            sleek_job_fail($jobId, 'The video couldn\'t be made from that photo. Your credits were refunded — try a clear, front-facing portrait.',
                "fal result HTTP {$res['status']}: " . sleek_fal_error($res));
            return 'failed';
        }

        $file = sleek_fetch_output($url, SLEEK_VIDEO_MAX_BYTES, 'video/');
        return sleek_job_complete($jobId, $file['bytes'], $file['mime']) ? 'completed' : 'failed';
    } catch (SleekOutputRejected $e) {
        sleek_job_fail($jobId, 'The finished video couldn\'t be saved. Your credits were refunded.', $e->getMessage());
        return 'failed';
    } catch (SleekProviderError $e) {
        sleek_jobs_log("poll job #{$jobId}: " . $e->getMessage());   // network blip: next poll retries
        return 'processing';
    }
}

/**
 * Copy a finished file off the provider's CDN. https only (plain http is allowed
 * for loopback, i.e. local development), only from configured hosts, size-capped,
 * and the bytes must actually be of the expected type.
 *
 * @return array{bytes: string, mime: string}
 */
function sleek_fetch_output(string $url, int $maxBytes, string $mimePrefix): array
{
    $p    = parse_url($url);
    $host = strtolower((string) ($p['host'] ?? ''));
    $loop = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

    if (($p['scheme'] ?? '') !== 'https' && !(($p['scheme'] ?? '') === 'http' && $loop)) {
        throw new SleekOutputRejected("Output URL isn't https: {$url}");
    }
    $allowed = false;
    foreach (sleek_config('fal')['download_hosts'] as $h) {
        $h = strtolower($h);
        if ($host === $h || str_ends_with($host, '.' . $h)) { $allowed = true; break; }
    }
    if (!$allowed) throw new SleekOutputRejected("Output host not allowed: {$host}");

    $received = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => false,
        CURLOPT_CONNECTTIMEOUT   => 15,
        CURLOPT_TIMEOUT          => 180,
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_XFERINFOFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes, &$received) {
            $received = $dlNow;
            return ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0;   // non-zero aborts
        },
    ]);
    $bytes  = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);

    if ($received > $maxBytes) throw new SleekOutputRejected('Output file is larger than allowed.');
    if ($bytes === false)      throw new SleekProviderError("Output download failed: {$err}");   // network: retry
    if ($status >= 400 && $status < 500) throw new SleekOutputRejected("Output download → HTTP {$status}");
    if ($status !== 200)       throw new SleekProviderError("Output download → HTTP {$status}");  // 5xx etc.: retry

    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer((string) $bytes) ?: '';
    if (!str_starts_with($mime, $mimePrefix) || !isset(SLEEK_OUTPUT_EXT[$mime])) {
        throw new SleekOutputRejected("Output isn't a {$mimePrefix}* file (got '{$mime}').");
    }
    return ['bytes' => (string) $bytes, 'mime' => $mime];
}

/**
 * Nudge a few waiting jobs along on each signed-in page load, and refund async
 * jobs that were charged but never reached the provider (e.g. PHP died mid-submit).
 */
function sleek_async_sweep(int $limit = 3): void
{
    $db  = sleek_db();
    $now = time();

    $stmt = $db->prepare("SELECT a.job_id FROM async_jobs a JOIN jobs j ON j.id = a.job_id
                          WHERE j.status = 'processing' AND (a.last_polled_at IS NULL OR a.last_polled_at < ?)
                          ORDER BY a.last_polled_at IS NULL DESC, a.last_polled_at ASC LIMIT " . max(1, $limit));
    $stmt->execute([$now - 30]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) sleek_async_poll((int) $jobId);

    $async = array_keys(array_filter(sleek_tools(), fn($t) => !empty($t['async'])));
    if (!$async) return;
    $marks = implode(',', array_fill(0, count($async), '?'));
    $stmt = $db->prepare("SELECT j.id FROM jobs j LEFT JOIN async_jobs a ON a.job_id = j.id
                          WHERE j.status = 'processing' AND j.tool IN ({$marks}) AND a.job_id IS NULL AND j.created_at < ?");
    $stmt->execute([...$async, $now - SLEEK_JOB_STALE_SECONDS]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
        sleek_job_fail((int) $jobId, 'This job didn\'t start. Your credits were refunded.', 'async job never submitted');
    }
}
