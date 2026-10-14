<?php
/**
 * SleekAI — account emails other than password reset (that one lives in auth.php).
 * Same look as the reset email: dark card, one gold button, plain-text twin.
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings.php';

/**
 * Welcome a new account. Pass $setPasswordLink when an admin created the account
 * without a password: the main button then sets one instead of opening the dashboard.
 */
function sleek_send_welcome_email(int $userId, ?string $setPasswordLink = null, int $linkMinutes = 0): bool
{
    $stmt = sleek_db()->prepare('SELECT id, name, email, referral_code FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return false;

    $brand = (string) sleek_config('brand_name');
    [$html, $text] = sleek_welcome_email($user, $setPasswordLink, $linkMinutes);
    return sleek_send_mail($user['email'], $user['name'], "Welcome to {$brand}", $html, $text);
}

/** @return array{0:string,1:string} [html, text] */
function sleek_welcome_email(array $user, ?string $setPasswordLink = null, int $linkMinutes = 0): array
{
    $brand     = (string) sleek_config('brand_name');
    $firstRaw  = explode(' ', trim((string) $user['name']))[0];
    $first     = e($firstRaw);
    $brandH    = e($brand);
    $dashboard = sleek_url('dashboard');
    $pricing   = sleek_url('credits-billing');
    $referral  = sleek_url('register?ref=' . $user['referral_code']);
    $telegram  = sleek_telegram_url();
    $contact   = sleek_url('contact');

    [$ctaUrl, $ctaLabel] = $setPasswordLink !== null
        ? [$setPasswordLink, 'Set your password']
        : [$dashboard, 'Open your dashboard'];
    $cta = e($ctaUrl);

    $setNote = $setPasswordLink !== null
        ? "<tr><td style=\"padding:0 32px 8px;font-family:Arial,sans-serif;font-size:13px;line-height:1.6;color:#9AA3AF;\">Your account was created for you. Choose a password with the button above — the link works once and expires in {$linkMinutes} minutes. After that, use “Forgot password” on the login page.</td></tr>"
        : '';
    $telegramRow = $telegram !== ''
        ? '<tr><td style="padding:0 32px 8px;font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#9AA3AF;">Updates, tips and support: <a href="' . e($telegram) . '" style="color:#F2B705;">join us on Telegram</a>.</td></tr>'
        : '';

    $p = 'padding:8px 32px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#9AA3AF;';
    $li = 'margin:0 0 6px;';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#14171C;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#14171C;padding:40px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#1C2027;border:1px solid #2A2F38;border-radius:12px;">
        <tr><td style="padding:32px 32px 8px;font-family:Georgia,serif;font-size:20px;font-weight:600;color:#F5F6F7;">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#F2B705;margin-right:8px;vertical-align:middle;"></span>{$brandH}
        </td></tr>
        <tr><td style="padding:16px 32px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#F5F6F7;">
          Welcome, {$first}!
        </td></tr>
        <tr><td style="{$p}">
          Your {$brandH} account is ready. Everything runs on credits — buy a pack once, no subscription, and spend them on any tool:
        </td></tr>
        <tr><td style="{$p}">
          <ul style="margin:8px 0 0;padding-left:20px;">
            <li style="{$li}"><strong style="color:#F5F6F7;">Livestream</strong> — real-time AI character swap from your webcam</li>
            <li style="{$li}"><strong style="color:#F5F6F7;">Voice Cloning</strong> and <strong style="color:#F5F6F7;">Voice Isolator</strong></li>
            <li style="{$li}"><strong style="color:#F5F6F7;">Image to Video</strong> and <strong style="color:#F5F6F7;">AI Image Edit</strong></li>
          </ul>
        </td></tr>
        <tr><td style="padding:24px 32px;">
          <a href="{$cta}" style="display:inline-block;background:#F2B705;color:#14171C;font-family:Arial,sans-serif;font-size:15px;font-weight:600;text-decoration:none;padding:12px 24px;border-radius:8px;">{$ctaLabel}</a>
        </td></tr>
        {$setNote}
        <tr><td style="padding:0 32px 8px;font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#9AA3AF;">
          Ready to start? <a href="{$pricing}" style="color:#F2B705;">Get credits</a>. Invite friends with your referral link and earn a bonus on their first purchase:<br>
          <a href="{$referral}" style="color:#F2B705;word-break:break-all;">{$referral}</a>
        </td></tr>
        {$telegramRow}
        <tr><td style="padding:20px 32px 32px;font-family:Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B727E;">
          You're getting this because an account was created with this email address. Questions? Reach us on our <a href="{$contact}" style="color:#9AA3AF;">contact page</a>.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $text = "Welcome, {$firstRaw}!\n\n"
          . "Your {$brand} account is ready. Everything runs on credits — buy a pack once, no subscription, and spend them on any tool: Livestream, Voice Cloning, Voice Isolator, Image to Video and AI Image Edit.\n\n"
          . ($setPasswordLink !== null
              ? "Your account was created for you. Set your password here (works once, expires in {$linkMinutes} minutes):\n{$setPasswordLink}\n\n"
              : "Open your dashboard: {$dashboard}\n\n")
          . "Get credits: {$pricing}\n"
          . "Your referral link (earn a bonus on friends' first purchase): {$referral}\n"
          . ($telegram !== '' ? "Join us on Telegram: {$telegram}\n" : '')
          . "\n— {$brand}\n";

    return [$html, $text];
}
