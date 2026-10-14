<?php
/**
 * SleekAI — the public contact form. Messages land in the `contact_messages`
 * table, which the super admin reads at /admin → Messages. If a notify address
 * is set there, each new message is emailed to it too (best effort — the message
 * is saved whether or not mail goes out).
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const SLEEK_CONTACT_PER_HOUR = 5;   // per IP — enough for a real person, not a script

/**
 * Validate and store a message. Returns field => error for anything wrong; an
 * empty array means it was saved.
 */
function sleek_contact_submit(string $name, string $email, string $message, string $ip, ?int $userId): array
{
    $name    = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $email   = trim($email);
    $message = trim(str_replace("\r\n", "\n", $message));

    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) $errors['name'] = 'Enter your name (up to 100 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) $errors['email'] = 'Enter a valid email address.';
    if (mb_strlen($message) < 10) $errors['message'] = 'Tell us a little more — at least 10 characters.';
    elseif (mb_strlen($message) > 5000) $errors['message'] = 'Keep it under 5,000 characters.';
    if ($errors) return $errors;

    $db = sleek_db();
    $recent = $db->prepare('SELECT COUNT(*) FROM contact_messages WHERE ip = ? AND created_at > ?');
    $recent->execute([$ip, time() - 3600]);
    if ((int) $recent->fetchColumn() >= SLEEK_CONTACT_PER_HOUR) {
        return ['form' => 'You\'ve sent several messages already. Please wait an hour, or reach us on Telegram.'];
    }

    $db->prepare('INSERT INTO contact_messages (name, email, message, ip, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$name, $email, $message, $ip, $userId, time()]);

    sleek_contact_notify($name, $email, $message);
    return [];
}

function sleek_contact_notify(string $name, string $email, string $message): void
{
    $to = sleek_setting('contact_notify_email', '');
    if (!is_string($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    require_once __DIR__ . '/mailer.php';
    $brand   = (string) sleek_config('brand_name');
    $subject = "New contact message from {$name}";
    $html    = '<p><strong>' . e($name) . '</strong> &lt;' . e($email) . '&gt; wrote:</p>'
             . '<p style="white-space:pre-wrap">' . e($message) . '</p>'
             . '<p><a href="' . e(sleek_url('admin#messages')) . '">Open the inbox</a></p>';
    $text    = "{$name} <{$email}> wrote:\n\n{$message}\n\nInbox: " . sleek_url('admin#messages');
    sleek_send_mail($to, "{$brand} admin", $subject, $html, $text);
}

function sleek_contact_unread_count(): int
{
    return (int) sleek_db()->query('SELECT COUNT(*) FROM contact_messages WHERE read_at IS NULL')->fetchColumn();
}
