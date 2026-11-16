<?php
/**
 * Livestream network test, called by assets/livestream.js before going live.
 *   ?t=ping      tiny reply, timed by the browser for latency
 *   ?t=download  ~1.5 MB of incompressible bytes, timed for download speed
 * Signed-in users only; never cached, never compressed.
 */
require __DIR__ . '/inc/bootstrap.php';

if (current_user() === null) {
    http_response_code(401);
    exit;
}

header('Cache-Control: no-store, no-transform');
header('X-Content-Type-Options: nosniff');

if (($_GET['t'] ?? '') === 'download') {
    $bytes = 1536 * 1024;
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $bytes);
    $chunk = random_bytes(64 * 1024);
    for ($sent = 0; $sent < $bytes; $sent += strlen($chunk)) echo $chunk;
    exit;
}

header('Content-Type: text/plain');
echo 'ok';
