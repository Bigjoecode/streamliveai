<?php
/**
 * SleekAI — photo uploads for AI Image Edit and Image to Video.
 *
 * Every photo is checked for what it really is (bytes, not the file extension) and
 * for sane dimensions before anything is charged. With PHP's GD extension it is
 * also re-encoded before it leaves this server, which:
 *   - drops ALL metadata, including the GPS location phones embed in photos,
 *   - applies the EXIF rotation so a phone portrait isn't sent sideways,
 *   - scales it down to a sensible size for the provider.
 * Without GD, the photo is still validated but sent as uploaded (metadata and all).
 */

declare(strict_types=1);

const SLEEK_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const SLEEK_IMAGE_MAX_SIDE = 2048;
/** Refuse anything bigger than this many pixels — a tiny file can decode to gigabytes. */
const SLEEK_IMAGE_MAX_PIXELS = 40_000_000;

function sleek_images_can_normalize(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatefromstring');
}

/**
 * Validate an uploaded photo and prepare the bytes to send on.
 *
 * @return array{ok: bool, error: ?string, bytes: ?string, mime: ?string, width: int, height: int, name: ?string, cleaned: bool}
 */
function sleek_check_image_upload(mixed $file, int $maxBytes, int $minSide = 256): array
{
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'bytes' => null, 'mime' => null,
                                'width' => 0, 'height' => 0, 'name' => null, 'cleaned' => false];
    $mb = intdiv($maxBytes, 1024 * 1024);

    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) return $fail('Choose a photo to upload.');
    switch ($file['error']) {
        case UPLOAD_ERR_OK:        break;
        case UPLOAD_ERR_NO_FILE:   return $fail('Choose a photo to upload.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return $fail("That photo is too large. The limit is {$mb} MB.");
        case UPLOAD_ERR_PARTIAL:   return $fail('The upload was interrupted. Please try again.');
        default:                   return $fail('The upload failed on our side. Please try again.');
    }
    if (!is_uploaded_file($file['tmp_name'])) return $fail('The upload failed on our side. Please try again.');
    if ($file['size'] <= 0)                    return $fail('That file is empty.');
    if ($file['size'] > $maxBytes)             return $fail("That photo is too large. The limit is {$mb} MB.");

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(SLEEK_IMAGE_TYPES[$mime])) return $fail('That isn\'t a JPEG, PNG or WebP photo.');

    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[0] < 1 || $info[1] < 1) return $fail('That photo couldn\'t be read. Try saving it again as JPEG or PNG.');
    [$w, $h] = $info;
    if ($w * $h > SLEEK_IMAGE_MAX_PIXELS) return $fail('That photo has too many pixels. Keep it under 40 megapixels.');
    if (min($w, $h) < $minSide)           return $fail("That photo is too small. It needs to be at least {$minSide}×{$minSide} pixels.");

    $raw  = (string) $file['name'];
    $name = mb_check_encoding($raw, 'UTF-8') ? trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', $raw)) : '';
    $name = mb_substr($name !== '' ? $name : 'photo', 0, 120);

    $bytes = (string) file_get_contents($file['tmp_name']);
    $out   = ['ok' => true, 'error' => null, 'bytes' => $bytes, 'mime' => $mime, 'width' => $w, 'height' => $h, 'name' => $name, 'cleaned' => false];

    if (sleek_images_can_normalize()) {
        $n = sleek_normalize_image($bytes, $mime, $file['tmp_name']);
        if ($n === null) return $fail('That photo couldn\'t be read. Try saving it again as JPEG or PNG.');
        $out = array_merge($out, $n, ['cleaned' => true]);
    }
    return $out;
}

/**
 * Decode, fix rotation, scale down, re-encode as JPEG. The fresh encode carries
 * none of the original's metadata.
 *
 * @return array{bytes: string, mime: string, width: int, height: int}|null
 */
function sleek_normalize_image(string $bytes, string $mime, string $path): ?array
{
    $img = @imagecreatefromstring($bytes);
    if ($img === false) return null;

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated) $img = $rotated;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, SLEEK_IMAGE_MAX_SIDE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    // Flatten onto white: JPEG has no transparency, and black is the wrong default.
    $canvas = imagecreatetruecolor($nw, $nh);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($canvas, null, 90);
    $jpeg = (string) ob_get_clean();

    return ['bytes' => $jpeg, 'mime' => 'image/jpeg', 'width' => $nw, 'height' => $nh];
}

function sleek_data_uri(string $bytes, string $mime): string
{
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}
