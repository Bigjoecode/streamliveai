<?php
/**
 * Serve a job's output to its owner — the only way out of storage/outputs/.
 *   /media?job=12             inline, for <audio> players (supports Range, so seeking works)
 *   /media?job=12&download=1  as an attachment
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/jobs.php';

$user  = require_auth();
$jobId = filter_var($_GET['job'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$job = null;
if ($jobId) {
    // Scoped to the signed-in user: someone else's job id is simply "not found".
    $stmt = sleek_db()->prepare("SELECT id, tool, output_file, output_mime FROM jobs
                                 WHERE id = ? AND user_id = ? AND status = 'completed'");
    $stmt->execute([$jobId, $user['id']]);
    $job = $stmt->fetch();
}

$path = $job && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,4}$/', (string) $job['output_file'])
    ? sleek_storage_dir('outputs') . '/' . $job['output_file']
    : null;

if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

$size = filesize($path);
$mime = $job['output_mime'] ?: 'application/octet-stream';
$ext  = pathinfo($path, PATHINFO_EXTENSION);

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline')
    . '; filename="sleekai-' . $job['tool'] . '-' . $job['id'] . '.' . $ext . '"');

// Single byte range, e.g. "bytes=1000-" or "bytes=1000-1999".
$start = 0;
$end   = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {                     // suffix: last N bytes
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') $end = min((int) $m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fh = fopen($path, 'rb');
fseek($fh, $start);
while ($length > 0 && !feof($fh)) {
    $chunk = fread($fh, min(8192, $length));
    if ($chunk === false) break;
    echo $chunk;
    $length -= strlen($chunk);
}
fclose($fh);
