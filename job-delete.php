<?php
/**
 * POST /job-delete — a signed-in user removes their own finished jobs and files.
 *   job=ID   one job        all=1   every finished job
 *   return   the page to go back to (tool pages or job-history only)
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/jobs.php';

$me = require_auth();
if (!sleek_is_post()) sleek_redirect('job-history');
csrf_verify();

$back = is_string($_POST['return'] ?? null) ? $_POST['return'] : '';
$back = in_array($back, ['job-history', 'voice-isolator', 'voice-cloning', 'ai-image-edit', 'image-to-video', 'livestream'], true) ? $back : 'job-history';

if (isset($_POST['all'])) {
    $n = sleek_delete_user_jobs((int) $me['id']);
    sleek_flash($n ? 'success' : 'info', $n
        ? 'Deleted ' . number_format($n) . ' job' . ($n === 1 ? '' : 's') . ' and their files. Anything still rendering was kept.'
        : 'There was nothing to delete — jobs still rendering can\'t be removed until they finish.');
} else {
    $id = filter_var($_POST['job'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $n  = $id ? sleek_delete_user_jobs((int) $me['id'], (int) $id) : 0;
    sleek_flash($n ? 'success' : 'error', $n ? 'Job deleted, along with its file.' : 'That job can\'t be deleted — it\'s still processing, or it\'s already gone.');
}
sleek_redirect($back);
