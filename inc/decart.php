<?php
/**
 * SleekAI — Decart, shared by Livestream (realtime, inc/livestream.php) and
 * AI Image Edit:
 *
 *   POST /v1/generate/{image_model}   multipart: prompt, data (image), resolution,
 *                                     enhance_prompt  -> image bytes (synchronous)
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/images.php';

function sleek_decart_configured(): bool
{
    return sleek_config('decart')['api_key'] !== '';
}

/**
 * Edit a photo with a plain-language instruction.
 *
 * @return array{bytes: string, mime: string}
 */
function sleek_decart_edit_image(string $bytes, string $mime, string $instruction): array
{
    $cfg = sleek_config('decart');
    if ($cfg['api_key'] === '') throw new SleekProviderError('Decart API key is not set.');

    $ext = SLEEK_OUTPUT_EXT[$mime] ?? 'jpg';
    [$body, $ct] = sleek_multipart([
        ['name' => 'prompt', 'value' => $instruction],
        ['name' => 'data', 'bytes' => $bytes, 'filename' => "photo.{$ext}", 'mime' => $mime],
        ['name' => 'resolution', 'value' => '720p'],
        ['name' => 'enhance_prompt', 'value' => 'true'],
    ]);

    $ch = curl_init(rtrim($cfg['api_base'], '/') . '/v1/generate/' . rawurlencode($cfg['image_model']));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . $cfg['api_key'], 'Content-Type: ' . $ct, 'Accept: image/*'],
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $outCt  = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    $err    = curl_error($ch);

    if ($raw === false) throw new SleekProviderError("Could not reach Decart: {$err}");
    if ($status < 200 || $status >= 300) {
        $j = json_decode((string) $raw, true);
        $msg = is_array($j) ? (string) ($j['message'] ?? $j['error'] ?? $j['detail'] ?? json_encode($j)) : mb_substr(strip_tags((string) $raw), 0, 200);
        throw new SleekProviderError("Decart image edit → HTTP {$status}: {$msg}");
    }

    // Trust the bytes, not the header: confirm it's really an image we can serve.
    $sniffed = (new finfo(FILEINFO_MIME_TYPE))->buffer((string) $raw) ?: '';
    if (!isset(SLEEK_IMAGE_TYPES[$sniffed])) {
        throw new SleekProviderError("Decart returned '{$outCt}' that isn't an image (" . strlen((string) $raw) . ' bytes).');
    }
    return ['bytes' => (string) $raw, 'mime' => $sniffed];
}
