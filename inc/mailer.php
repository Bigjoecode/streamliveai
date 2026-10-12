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
        $mail->setFrom($cfg['from_address'], $cfg['from_name']);
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
        return false;
    }
}

/** Append to a file in storage/ (web-denied), creating it if needed. */
function sleek_storage_append(string $file, string $line): void
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $file, $line, FILE_APPEND | LOCK_EX);
}
