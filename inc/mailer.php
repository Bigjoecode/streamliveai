<?php
/**
 * SleekAI — outgoing mail, via PHPMailer.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Send one message. Returns false on failure rather than throwing: callers like the
 * forgot-password form must answer the same way whether or not mail went out, so
 * the failure is written to storage/mail-error.log for whoever runs the site.
 */
function sleek_send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $cfg  = sleek_config('mail');
    $mail = new PHPMailer(true);

    try {
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        // PHPMailer otherwise builds the Message-ID and SMTP greeting from SERVER_NAME,
        // which Apache takes from the request's (forgeable) Host header.
        $mail->Hostname = parse_url(sleek_config('app_url'), PHP_URL_HOST) ?: 'localhost';
        $mail->setFrom(sleek_mail_from_address(), $cfg['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $text;

        if ($cfg['driver'] === 'log') {
            // Build the real MIME message, just don't hand it to a server.
            $mail->preSend();
            sleek_storage_append('mail.log', sprintf(
                "===== %s  to %s =====\n%s\n\n",
                date('Y-m-d H:i:s'), $toEmail, $mail->getSentMIMEMessage()
            ));
            return true;
        }

        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = (int) $cfg['port'];
        $mail->Timeout    = 15;   // a dead host shouldn't hang the request for minutes
        $mail->SMTPAuth   = $cfg['username'] !== '';
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = match ($cfg['encryption']) {
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        if ($cfg['encryption'] === '') $mail->SMTPAutoTLS = false;

        $mail->send();
        return true;
    } catch (MailException $e) {
        sleek_storage_append('mail-error.log', sprintf(
            "[%s] to %s — %s\n", date('Y-m-d H:i:s'), $toEmail, $mail->ErrorInfo ?: $e->getMessage()
        ));
        require_once __DIR__ . '/telegram.php';
        sleek_alert('mail_failed', 'Email failed to send', ['To' => $toEmail, 'Subject' => $subject, 'Error' => $mail->ErrorInfo ?: $e->getMessage()], 'admin-email');
        return false;
    }
}

/** The sender address: the configured one, else no-reply@ the site's own domain. */
function sleek_mail_from_address(): string
{
    $from = (string) (sleek_config('mail')['from_address'] ?? '');
    if ($from !== '') return $from;
    $host = (string) parse_url(sleek_config('app_url'), PHP_URL_HOST);
    return 'no-reply@' . preg_replace('/^www\./', '', $host !== '' ? $host : 'localhost');
}

/**
 * False while mail still points at the placeholder host — every reset email would
 * fail. The admin page warns about it.
 */
function sleek_mail_configured(): bool
{
    $cfg = sleek_config('mail');
    if (($cfg['driver'] ?? 'smtp') === 'log') return true;
    $host = (string) ($cfg['host'] ?? '');
    return $host !== '' && $host !== 'smtp.example.com';
}

/** The newest line of storage/mail-error.log, for the admin page. */
function sleek_mail_last_error(): string
{
    $f = __DIR__ . '/../storage/mail-error.log';
    if (!is_file($f)) return '';
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return (string) end($lines);
}

/** Append to a file in storage/ (web-denied), creating it if needed. */
function sleek_storage_append(string $file, string $line): void
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $file, $line, FILE_APPEND | LOCK_EX);
}
