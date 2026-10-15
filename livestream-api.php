<?php
/**
 * Livestream control endpoint, called by assets/livestream.js.
 *   action=start  charge the chosen length, mint a Decart client token
 *   action=tick   report seconds streamed so far
 *   action=end    report final seconds and settle (refund unused time)
 * Answers JSON when format=json; a plain form POST of action=end (the "end it"
 * button for a session left open elsewhere) redirects back with a flash instead.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/livestream.php';

$wantsJson = ($_POST['format'] ?? '') === 'json';

$reply = function (int $status, array $body) use ($wantsJson): never {
    if (!$wantsJson) {
        sleek_flash($status < 400 ? 'success' : 'error', (string) ($body['message'] ?? $body['error'] ?? 'Done.'));
        sleek_redirect('livestream');
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (!sleek_is_post()) {
    http_response_code(405);
    exit;
}

$user = current_user();
if ($user === null) {
    $wantsJson ? $reply(401, ['ok' => false, 'error' => 'Your session ended. Log in again.']) : sleek_redirect('login');
}
csrf_verify();

$uid   = (int) $user['id'];
$int   = fn(string $k): int => (int) (filter_var($_POST[$k] ?? null, FILTER_VALIDATE_INT) ?: 0);
$rate  = (int) sleek_config('decart')['credits_per_second'];

switch ($_POST['action'] ?? '') {
    case 'start':
        sleek_jobs_sweep();   // settle anything expired before checking "already live"

        if (!sleek_decart_configured()) {
            sleek_jobs_log('livestream blocked: Decart api_key is empty in inc/config.php');
            $reply(503, ['ok' => false, 'error' => 'Livestream is temporarily unavailable. You haven\'t been charged.']);
        }
        if (!isset($_POST['consent'])) {
            $reply(422, ['ok' => false, 'error' => 'Confirm you have permission to use the face in your reference photo.']);
        }

        $minutes = $int('minutes');
        try {
            $s = sleek_live_start($uid, $minutes, time());
        } catch (InvalidArgumentException $e) {
            $reply(422, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (SleekInsufficientCredits) {
            $reply(402, ['ok' => false, 'buy' => true,
                'error' => "{$minutes} minutes costs " . number_format($minutes * 60 * $rate) . ' credits — more than your balance.']);
        } catch (SleekLiveBusy $e) {
            $reply(409, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (SleekProviderError $e) {
            $reply(502, ['ok' => false, 'error' => 'Livestream couldn\'t start right now. Your credits were refunded — please try again.']);
        }

        $reply(200, ['ok' => true, 'job' => $s['job'], 'token' => $s['token'], 'model' => $s['model'], 'speed' => $s['speed'], 'maxSeconds' => $s['max_seconds']]);

    case 'tick':
        $s = sleek_live_report($int('job'), $uid, $int('seconds'));
        if ($s === null) $reply(404, ['ok' => false, 'error' => 'No such session.']);
        $reply(200, [
            'ok'        => true,
            'ended'     => $s['status'] !== 'processing',
            'used'      => (int) $s['used_seconds'],
            'remaining' => max(0, (int) $s['max_seconds'] - (int) $s['used_seconds']),
        ]);

    case 'end':
        $jobId = $int('job');
        $s = sleek_live_report($jobId, $uid, $int('seconds'));
        if ($s === null) $reply(404, ['ok' => false, 'error' => 'No such session.']);

        $r = sleek_live_settle($jobId) ?? (function () use ($jobId) {
            // Already settled (e.g. the beacon and the button both fired) — report what stands.
            $stmt = sleek_db()->prepare('SELECT ls.used_seconds AS used, ls.charged_credits AS charged, ls.reserved_credits AS reserved, j.status
                                         FROM live_sessions ls JOIN jobs j ON j.id = ls.job_id WHERE ls.job_id = ?');
            $stmt->execute([$jobId]);
            $row = $stmt->fetch();
            return ['status' => $row['status'], 'used' => (int) $row['used'], 'charged' => (int) $row['charged'],
                    'refunded' => (int) $row['reserved'] - (int) $row['charged']];
        })();

        $msg = $r['status'] === 'failed'
            ? 'The stream didn\'t start, so your ' . number_format($r['refunded']) . ' credits were refunded.'
            : 'Stream ended after ' . sleek_duration_label($r['used']) . ' — ' . number_format($r['charged']) . ' credits used'
              . ($r['refunded'] > 0 ? ', ' . number_format($r['refunded']) . ' refunded.' : '.');

        $reply(200, ['ok' => true, 'message' => $msg] + $r);

    default:
        $reply(400, ['ok' => false, 'error' => 'Unknown action.']);
}
