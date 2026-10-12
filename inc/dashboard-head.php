<?php
/**
 * Opens every signed-in page. Expects $pageTitle and $active (nav key) to be set
 * before this is included. The auth guard runs here, before any output, so an
 * anonymous visitor is redirected rather than shown a half-rendered page.
 */
require_once __DIR__ . '/bootstrap.php';

// Refund any job that died mid-run — before the user row is loaded, so a refund
// made here already shows in this page's balance.
require_once __DIR__ . '/jobs.php';
sleek_jobs_sweep();

$authUser = require_auth();

require_once __DIR__ . '/dashboard-data.php';

$pageTitle = $pageTitle ?? 'Dashboard';
$active    = $active ?? 'overview';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — StreamLive AI</title>

    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="favicon-192x192.png">
    <link rel="shortcut icon" href="favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com/">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&amp;family=Inter:wght@400;500;600&amp;family=JetBrains+Mono:wght@400;500&amp;display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com/"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink: '#14171C', surface: '#1C2027', border: '#2A2F38', muted: '#9AA3AF',
                        paper: '#F5F6F7', signal: '#F2B705', tally: '#E5484D', reel: '#2FBF9F'
                    },
                    fontFamily: {
                        display: ['Fraunces', 'Georgia', 'serif'],
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'ui-monospace', 'monospace']
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="assets/dashboard.css">
</head>

<body class="bg-ink font-sans text-paper antialiased">
