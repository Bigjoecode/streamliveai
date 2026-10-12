<?php
/**
 * SleekAI — ElevenLabs client (Voice Isolator, Voice Cloning).
 *
 *   POST   /v1/audio-isolation              multipart `audio`          -> audio bytes
 *   POST   /v1/voices/add                   multipart `name`, `files`  -> {voice_id}
 *   POST   /v1/text-to-speech/{voice_id}    JSON {text, model_id}      -> audio bytes
 *   DELETE /v1/voices/{voice_id}
 */

declare(strict_types=1);

require_once __DIR__ . '/jobs.php';

function sleek_elevenlabs_configured(): bool
{
    return sleek_config('elevenlabs')['api_key'] !== '';
}

/**
 * @return array{status: int, body: string, mime: string}
 */
function sleek_el_request(string $method, string $path, ?string $body = null, ?string $contentType = null, int $timeout = 240): array
{
    $cfg = sleek_config('elevenlabs');
    if ($cfg['api_key'] === '') throw new SleekProviderError('ElevenLabs API key is not set in inc/config.php.');

    $headers = ['xi-api-key: ' . $cfg['api_key'], 'Accept: */*'];
    if ($contentType !== null) $headers[] = 'Content-Type: ' . $contentType;

    $ch = curl_init(rtrim($cfg['api_base'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime   = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    $err    = curl_error($ch);

    if ($raw === false) throw new SleekProviderError("Could not reach ElevenLabs: {$err}");

    if ($status < 200 || $status >= 300) {
        throw new SleekProviderError("ElevenLabs {$method} {$path} → HTTP {$status}: " . sleek_el_error_message((string) $raw));
    }
    return ['status' => $status, 'body' => (string) $raw, 'mime' => $mime];
}

/** Pull a readable message out of ElevenLabs' error shapes. */
function sleek_el_error_message(string $raw): string
{
    $j = json_decode($raw, true);
    if (is_array($j)) {
        $d = $j['detail'] ?? $j;
        if (is_string($d)) return $d;
        if (isset($d['message']) && is_string($d['message'])) return $d['message'];
        if (isset($d[0]['msg']) && is_string($d[0]['msg'])) return $d[0]['msg'];   // validation errors
    }
    return mb_substr(trim(strip_tags($raw)), 0, 200) ?: 'no details';
}

/** Anything claiming to be audio, from a provider that answers audio/*. */
function sleek_el_audio(array $res): array
{
    if (!str_starts_with($res['mime'], 'audio/') || $res['body'] === '') {
        throw new SleekProviderError("Expected audio from ElevenLabs, got '{$res['mime']}' (" . strlen($res['body']) . ' bytes).');
    }
    return ['bytes' => $res['body'], 'mime' => $res['mime']];
}

/* ------------------------------------------------------------------ tools */

/** @return array{bytes: string, mime: string} */
function sleek_el_isolate(string $path, string $mime, string $filename): array
{
    [$body, $ct] = sleek_multipart([['name' => 'audio', 'file' => $path, 'filename' => $filename, 'mime' => $mime]]);
    return sleek_el_audio(sleek_el_request('POST', '/v1/audio-isolation', $body, $ct));
}

/**
 * Instant voice clone from one or more samples.
 *
 * @param list<array{path: string, mime: string, name: string}> $samples
 */
function sleek_el_add_voice(string $name, array $samples): string
{
    $parts = [['name' => 'name', 'value' => $name], ['name' => 'remove_background_noise', 'value' => 'true']];
    foreach ($samples as $s) {
        $parts[] = ['name' => 'files', 'file' => $s['path'], 'filename' => $s['name'], 'mime' => $s['mime']];
    }
    [$body, $ct] = sleek_multipart($parts);

    $res  = sleek_el_request('POST', '/v1/voices/add', $body, $ct, 120);
    $json = json_decode($res['body'], true);
    $id   = is_array($json) ? (string) ($json['voice_id'] ?? '') : '';
    if ($id === '') throw new SleekProviderError('ElevenLabs created no voice_id.');
    return $id;
}

/** @return array{bytes: string, mime: string} */
function sleek_el_tts(string $voiceId, string $text): array
{
    $body = json_encode([
        'text'     => $text,
        'model_id' => sleek_config('elevenlabs')['tts_model'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $path = '/v1/text-to-speech/' . rawurlencode($voiceId) . '?output_format=mp3_44100_128';
    return sleek_el_audio(sleek_el_request('POST', $path, $body, 'application/json'));
}

/** Remove a cloned voice. Best-effort: logged, never thrown. */
function sleek_el_delete_voice(string $voiceId): void
{
    try {
        sleek_el_request('DELETE', '/v1/voices/' . rawurlencode($voiceId), null, null, 30);
    } catch (Throwable $e) {
        sleek_jobs_log("could not delete ElevenLabs voice {$voiceId}: " . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ voices */

/**
 * The account's ready-made voices, for "pick a voice". Fetched from ElevenLabs
 * (GET /v1/voices) rather than hardcoded, because they retire and add voices; the
 * list is cached for 12 hours so page loads don't call out. Cloned voices are
 * excluded — Voice Cloning deletes its voices after each job anyway.
 *
 * @return list<array{id: string, name: string, description: string, preview_url: string}>
 */
function sleek_el_voices(): array
{
    $cache = sleek_storage_dir('cache') . '/elevenlabs-voices.json';
    if (is_file($cache) && filemtime($cache) > time() - 12 * 3600) {
        $list = json_decode((string) file_get_contents($cache), true);
        if (is_array($list) && $list) return $list;
    }
    if (!sleek_elevenlabs_configured()) return [];

    try {
        $res  = sleek_el_request('GET', '/v1/voices', null, null, 20);
        $json = json_decode($res['body'], true);
    } catch (Throwable $e) {
        sleek_jobs_log('could not list ElevenLabs voices: ' . $e->getMessage());
        return [];
    }

    $all = is_array($json['voices'] ?? null) ? $json['voices'] : [];
    $premade = array_values(array_filter($all, fn($v) => ($v['category'] ?? '') === 'premade'));
    $pool = $premade ?: array_values(array_filter($all, fn($v) => ($v['category'] ?? '') !== 'cloned'));

    $list = [];
    foreach ($pool as $v) {
        if (!is_string($v['voice_id'] ?? null) || !is_string($v['name'] ?? null)) continue;
        $labels = is_array($v['labels'] ?? null) ? $v['labels'] : [];
        $bits = array_filter([$labels['gender'] ?? null, $labels['accent'] ?? null, $labels['description'] ?? $labels['descriptive'] ?? null,
                              $labels['use_case'] ?? $labels['use case'] ?? null], 'is_string');
        $list[] = [
            'id'          => $v['voice_id'],
            'name'        => $v['name'],
            'description' => implode(', ', $bits),
            'preview_url' => is_string($v['preview_url'] ?? null) && str_starts_with($v['preview_url'], 'https://') ? $v['preview_url'] : '',
        ];
    }
    usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    if ($list) file_put_contents($cache, json_encode($list), LOCK_EX);
    return $list;
}

function sleek_el_voice(string $id): ?array
{
    foreach (sleek_el_voices() as $v) if ($v['id'] === $id) return $v;
    return null;
}

/** Length of mp3_44100_128 audio: a constant 128 kbit/s, so 16,000 bytes per second. */
function sleek_mp3_128_seconds(string $bytes): float
{
    return strlen($bytes) / 16000;
}
