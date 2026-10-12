<?php
/**
 * GET /job-status?job=ID — where a job stands, for pages waiting on a render.
 * For a job queued at a provider it also nudges it along (throttled in
 * sleek_async_poll), which is what completes it when no one else is looking.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/fal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    exit('{"ok":false}');
}

$jobId = filter_var($_GET['job'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$stmt  = sleek_db()->prepare('SELECT id, status FROM jobs WHERE id = ? AND user_id = ?');
$stmt->execute([$jobId ?: 0, $user['id']]);
$job = $stmt->fetch();
if (!$job) {
    http_response_code(404);
    exit('{"ok":false}');
}

$status = $job['status'] === 'processing' ? sleek_async_poll((int) $job['id']) : $job['status'];
if ($status === 'unknown') $status = $job['status'];   // not an async job

echo json_encode(['ok' => true, 'status' => $status]);
