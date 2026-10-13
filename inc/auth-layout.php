<?php
/**
 * Split-screen shell shared by login.php and register.php — the same layout as the
 * mirrored login.html / register.html. Call auth_layout_open() before the form and
 * auth_layout_close() after it.
 */

function auth_layout_open(string $title, string $headline): void
{ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — StreamLive AI</title>

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
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body class="min-h-screen bg-ink font-sans text-paper antialiased">
    <div class="glow-field" aria-hidden="true">
        <div class="glow-orb a"></div>
        <div class="glow-orb b"></div>
        <div class="glow-orb c"></div>
    </div>

    <div class="relative z-10">
    <main class="flex min-h-screen">

        <div class="relative hidden overflow-hidden md:flex md:w-1/2 md:flex-col md:justify-between md:p-12 lg:p-16">
            <div class="grid-overlay absolute inset-0" aria-hidden="true"></div>
            <div class="relative">
                <a href="./" class="flex items-center gap-2">
                    <span class="tally-dot h-2.5 w-2.5 rounded-full bg-signal"></span>
                    <span class="font-display text-lg tracking-tight">StreamLive AI</span>
                </a>
            </div>

            <div class="relative">
                <p class="font-mono text-xs uppercase tracking-wide text-signal">Consent-logged · moderated · metered fairly</p>
                <h1 class="gradient-text mt-4 max-w-sm font-display text-4xl leading-[1.1] lg:text-5xl"><?= e($headline) ?></h1>
            </div>

            <div class="relative grid grid-cols-3 gap-3">
                <div class="glass rounded-lg p-4">
                    <p class="font-display text-xl">99.9%</p>
                    <p class="mt-1 text-[11px] text-muted">Uptime</p>
                </div>
                <div class="glass rounded-lg p-4">
                    <p class="font-display text-xl">100%</p>
                    <p class="mt-1 text-[11px] text-muted">Consent-logged</p>
                </div>
                <div class="glass rounded-lg p-4">
                    <p class="font-display text-xl">24/7</p>
                    <p class="mt-1 text-[11px] text-muted">Moderation</p>
                </div>
            </div>
        </div>

        <div class="flex w-full items-center justify-center px-5 py-16 sm:px-6 md:w-1/2">
            <div class="glass w-full max-w-sm rounded-xl p-6 sm:p-8">
                <div class="mb-6 flex items-center gap-2 md:hidden">
                    <a href="./" class="flex items-center gap-2">
                        <span class="tally-dot h-2.5 w-2.5 rounded-full bg-signal"></span>
                        <span class="font-display text-lg tracking-tight">StreamLive AI</span>
                    </a>
                </div>
<?php }

function auth_layout_close(): void
{ ?>
            </div>
        </div>
    </main>
    </div>
</body>
</html>
<?php }

/** Input classes, with the error border when this field failed validation. */
function auth_input_class(array $errors, string $field): string
{
    $base = 'w-full rounded-md border border-border bg-ink/60 px-3 py-2.5 text-sm text-paper transition focus:border-signal/50 focus:outline-none focus:ring-2 focus:ring-signal/40';
    return isset($errors[$field]) ? $base . ' field-error' : $base;
}

/** Inline error under a field, wired to the input with aria-describedby. */
function auth_field_error(array $errors, string $field): void
{
    if (!isset($errors[$field])) return; ?>
    <p id="<?= e($field) ?>-error" class="mt-1.5 text-xs text-tally"><?= e($errors[$field]) ?></p>
<?php }
