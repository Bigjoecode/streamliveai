<?php
/**
 * SleekAI — generation jobs.
 *
 * Lifecycle: sleek_job_start() creates the job and charges for it in one
 * transaction. The tool then runs, and ends in exactly one of sleek_job_complete()
 * or sleek_job_fail() — fail refunds. Both only act on a job still 'processing', so
 * a job can't be refunded twice or completed after it was refunded.
 *
 * Files: uploads and outputs live under storage/ (denied over HTTP). Names are
 * random; nothing from the client's filename ever reaches the filesystem. Outputs
 * are served only through media.php, which checks ownership.
 */

declare(strict_types=1);

require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/catalog.php';

/** An AI provider call failed (network, HTTP error, or an unusable response). */
final class SleekProviderError extends RuntimeException {}

/** File extension for each output type a tool can produce. */
const SLEEK_OUTPUT_EXT = [
    'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg',
    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
    'video/mp4' => 'mp4', 'video/webm' => 'webm',
];

/** A job stuck in 'processing' this long is presumed dead (PHP killed, server restarted). */
const SLEEK_JOB_STALE_SECONDS = 15 * 60;

/** Audio/video types the voice tools accept, sniffed from the file bytes, not trusted from the browser. */
const SLEEK_AUDIO_TYPES = [
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
    'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/vnd.wave' => 'wav',
    'audio/x-m4a' => 'm4a', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
    'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
    'audio/webm' => 'webm', 'video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
];

function sleek_storage_dir(string $sub): string
{
    $dir = __DIR__ . '/../storage/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function sleek_jobs_log(string $line): void
{
    file_put_contents(sleek_storage_dir('') . '/jobs.log', '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND | LOCK_EX);
}

/**
 * Build a multipart/form-data body by hand. PHP's curl can't send the same field
 * name twice (ElevenLabs takes several voice samples as repeated `files` parts),
 * and building it ourselves also lets a part come from memory rather than disk.
 *
 * @param list<array{name: string, value?: string, file?: string, bytes?: string, filename?: string, mime?: string}> $parts
 * @return array{0: string, 1: string} body and Content-Type header value
 */
function sleek_multipart(array $parts): array
{
    $boundary = '----sleekai' . bin2hex(random_bytes(12));
    $clean    = fn(string $s): string => str_replace(['"', "\r", "\n"], '', $s);
    $body     = '';
    foreach ($parts as $p) {
        $body .= "--{$boundary}\r\n";
        if (isset($p['file']) || isset($p['bytes'])) {
            $body .= 'Content-Disposition: form-data; name="' . $clean($p['name']) . '"; filename="' . $clean($p['filename'] ?? 'file') . "\"\r\n";
            $body .= 'Content-Type: ' . ($p['mime'] ?? 'application/octet-stream') . "\r\n\r\n";
            $body .= ($p['bytes'] ?? file_get_contents($p['file'])) . "\r\n";
        } else {
            $body .= 'Content-Disposition: form-data; name="' . $clean($p['name']) . "\"\r\n\r\n" . $p['value'] . "\r\n";
        }
    }
    $body .= "--{$boundary}--\r\n";
    return [$body, "multipart/form-data; boundary={$boundary}"];
}

/* ================================================================== uploads */

/**
 * PHP silently discards the ENTIRE form — CSRF token included — when a request is
 * bigger than post_max_size. Detect that first, or an oversized upload reads as
 * "session expired".
 */
function sleek_request_too_large(): bool
{
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    return $len > 0 && $len > sleek_ini_bytes((string) ini_get('post_max_size'));
}

function sleek_ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
    };
}

/**
 * Validate one uploaded file.
 *
 * @return array{ok: bool, error: ?string, path: ?string, mime: ?string, ext: ?string, name: ?string}
 */
function sleek_check_audio_upload(mixed $file, int $maxBytes): array
{
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'path' => null, 'mime' => null, 'ext' => null, 'name' => null];
    $mb   = intdiv($maxBytes, 1024 * 1024);

    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) return $fail('Choose an audio file to upload.');

    switch ($file['error']) {
        case UPLOAD_ERR_OK:        break;
        case UPLOAD_ERR_NO_FILE:   return $fail('Choose an audio file to upload.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return $fail("That file is too large. The limit is {$mb} MB.");
        case UPLOAD_ERR_PARTIAL:   return $fail('The upload was interrupted. Please try again.');
        default:                   return $fail('The upload failed on our side. Please try again.');
    }

    if (!is_uploaded_file($file['tmp_name'])) return $fail('The upload failed on our side. Please try again.');
    if ($file['size'] <= 0)                    return $fail('That file is empty.');
    if ($file['size'] > $maxBytes)             return $fail("That file is too large. The limit is {$mb} MB.");

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(SLEEK_AUDIO_TYPES[$mime])) {
        return $fail('That doesn\'t look like an audio file. Upload MP3, WAV, M4A, OGG, FLAC, or a video with sound (MP4, WebM, MOV).');
    }

    // Keep the original name only as display text, cleaned and shortened. (The /u
    // regex returns null on invalid UTF-8, so check first rather than warn mid-request.)
    $raw  = (string) $file['name'];
    $name = mb_check_encoding($raw, 'UTF-8') ? trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', $raw)) : '';
    $name = $name !== '' ? $name : 'audio';
    $name = mb_substr($name, 0, 120);

    return ['ok' => true, 'error' => null, 'path' => $file['tmp_name'], 'mime' => $mime, 'ext' => SLEEK_AUDIO_TYPES[$mime], 'name' => $name];
}

/** Normalise PHP's $_FILES['x'] for a multiple-file input into a list of single-file arrays. */
function sleek_files_list(mixed $f): array
{
    if (!is_array($f) || !isset($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
            'error' => $f['error'][$i], 'size' => $f['size'][$i],
        ];
    }
    return $out;
}

/* ================================================================ lifecycle */

/**
 * Create a job and charge for it, atomically. Throws SleekInsufficientCredits
 * (nothing is created) if the balance can't cover it.
 */
function sleek_job_start(int $userId, string $toolKey, string $title, ?int $consentAt = null, ?int $credits = null): int
{
    $tool    = sleek_tool($toolKey) ?? throw new InvalidArgumentException("Unknown tool {$toolKey}.");
    $credits ??= (int) $tool['credits'];   // metered tools (Livestream) pass their own amount
    $db      = sleek_db();

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO jobs (user_id, tool, status, credits, title, consent_at, created_at)
                      VALUES (?, ?, 'processing', ?, ?, ?, ?)")
           ->execute([$userId, $toolKey, $credits, mb_substr($title, 0, 255), $consentAt, time()]);
        $jobId = (int) $db->lastInsertId();

        sleek_debit($userId, $credits, 'job_spend', "job:{$jobId}", $tool['name']);

        $db->commit();
        return $jobId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Store the output and mark the job done. Returns false if the job was no longer running. */
function sleek_job_complete(int $jobId, string $bytes, string $mime): bool
{
    $ext  = SLEEK_OUTPUT_EXT[$mime] ?? 'bin';
    $file = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = sleek_storage_dir('outputs') . '/' . $file;

    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($path);
        throw new RuntimeException("Could not write output for job #{$jobId}.");
    }

    $stmt = sleek_db()->prepare("UPDATE jobs SET status = 'completed', output_file = ?, output_mime = ?, completed_at = ?
                                 WHERE id = ? AND status = 'processing'");
    $stmt->execute([$file, $mime, time(), $jobId]);

    if ($stmt->rowCount() !== 1) {
        // Swept as dead and refunded while we were still working — the refund stands.
        @unlink($path);
        sleek_jobs_log("job #{$jobId} finished after being swept; output discarded");
        return false;
    }
    sleek_job_alert($jobId, 'job', 'Job completed');
    return true;
}

/** Telegram alert for a finished or failed tool job. */
function sleek_job_alert(int $jobId, string $type, string $headline, string $why = ''): void
{
    require_once __DIR__ . '/telegram.php';
    $st = sleek_db()->prepare('SELECT j.user_id, j.tool, j.title, j.credits, u.name, u.email FROM jobs j JOIN users u ON u.id = j.user_id WHERE j.id = ?');
    $st->execute([$jobId]);
    $j = $st->fetch();
    if (!$j || $j['tool'] === 'livestream') return;   // livestream has its own alerts
    sleek_alert($type, $headline, [
        'Tool'    => sleek_tool($j['tool'])['name'] ?? $j['tool'],
        'Job'     => '#' . $jobId . ' — ' . $j['title'],
        'Account' => $j['name'] . ' <' . $j['email'] . '>',
        'Credits' => number_format((int) $j['credits']) . ($type === 'job_failed' ? ' (refunded)' : ''),
        'Reason'  => $why,
    ], 'admin-user?id=' . $j['user_id']);
}

/** Mark the job failed and refund it. Safe to call twice; only the first refunds. */
function sleek_job_fail(int $jobId, string $publicError, string $detail = ''): void
{
    $db = sleek_db();
    $db->beginTransaction();
    try {
        $claim = $db->prepare("UPDATE jobs SET status = 'failed', error = ?, completed_at = ? WHERE id = ? AND status = 'processing'");
        $claim->execute([mb_substr($publicError, 0, 255), time(), $jobId]);

        if ($claim->rowCount() === 1) {
            $stmt = $db->prepare('SELECT user_id, credits, tool FROM jobs WHERE id = ?');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            sleek_credit((int) $job['user_id'], (int) $job['credits'], 'job_refund', "job:{$jobId}",
                'Refund — ' . (sleek_tool($job['tool'])['name'] ?? $job['tool']));
            $failedNow = true;
        }
        $db->commit();
        if (!empty($failedNow)) sleek_job_alert($jobId, 'job_failed', 'Job failed — credits refunded', $publicError . ($detail !== '' ? ' | ' . $detail : ''));
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    sleek_jobs_log("job #{$jobId} failed: {$publicError}" . ($detail !== '' ? " | {$detail}" : ''));
}

/** Fail-and-refund any job left 'processing' past the stale limit. Cheap; run on page loads. */
function sleek_jobs_sweep(): void
{
    // Livestreams and provider-queued (async) jobs legitimately run longer and settle
    // by their own rules below; everything else is dead after SLEEK_JOB_STALE_SECONDS.
    $own   = array_keys(array_filter(sleek_tools(), fn($t) => $t['href'] === 'livestream' || !empty($t['async'])));
    $marks = implode(',', array_fill(0, count($own), '?'));
    $stmt  = sleek_db()->prepare("SELECT id FROM jobs WHERE status = 'processing' AND tool NOT IN ({$marks}) AND created_at < ?");
    $stmt->execute([...$own, time() - SLEEK_JOB_STALE_SECONDS]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        sleek_job_fail((int) $id, 'This job didn\'t finish. Your credits were refunded.', 'swept as stale');
    }

    require_once __DIR__ . '/livestream.php';
    sleek_live_sweep();

    require_once __DIR__ . '/fal.php';
    sleek_async_sweep();
}

/** A user's jobs, newest first, optionally for one tool. */
function sleek_user_jobs(int $userId, ?string $tool = null, int $limit = 50): array
{
    $sql = 'SELECT id, tool, status, credits, title, output_file, output_mime, error, created_at, completed_at FROM jobs WHERE user_id = ?';
    $args = [$userId];
    if ($tool !== null) { $sql .= ' AND tool = ?'; $args[] = $tool; }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(200, $limit));

    $stmt = sleek_db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}
