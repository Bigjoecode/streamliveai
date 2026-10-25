<?php require __DIR__ . '/inc/public.php'; ?>
<!DOCTYPE html>
<html lang="en">

<!-- Mirrored from sleekai.com.ng/features by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 19 Sep 2026 16:15:04 GMT -->
<!-- Added by HTTrack --><meta http-equiv="content-type" content="text/html;charset=utf-8" /><!-- /Added by HTTrack -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Features — LucaAi</title>

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
                        ink: '#0A1733',
                        surface: '#112450',
                        border: '#1F3A6E',
                        muted: '#A7B7D6',
                        paper: '#FFFFFF',
                        signal: '#3B8BFF',
                        tally: '#E5484D',
                        reel: '#2FBF9F'
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
    <style>
        body { background-color: #0A1733; }
        ::selection { background: #3B8BFF; color: #0A1733; }
        .tally-dot { box-shadow: 0 0 0 3px rgba(59, 139, 255, 0.15); }
        .tally-dot.live { box-shadow: 0 0 0 4px rgba(229, 72, 77, 0.18); }

        /* --- Futuristic layer: ambient glow field ------------------------ */
        .glow-field { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
        .glow-orb { position: absolute; border-radius: 9999px; filter: blur(90px); animation: drift 22s ease-in-out infinite; }
        .glow-orb.a { width: 520px; height: 520px; top: -160px; left: -120px; background: radial-gradient(circle, rgba(59,139,255,0.20), transparent 70%); }
        .glow-orb.b { width: 460px; height: 460px; top: 20%; right: -160px; background: radial-gradient(circle, rgba(47,191,159,0.14), transparent 70%); animation-delay: -8s; }
        .glow-orb.c { width: 420px; height: 420px; bottom: -180px; left: 30%; background: radial-gradient(circle, rgba(59,139,255,0.10), transparent 70%); animation-delay: -14s; }
        @keyframes drift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -25px) scale(1.08); }
            66% { transform: translate(-25px, 20px) scale(0.94); }
        }
        @media (prefers-reduced-motion: reduce) { .glow-orb { animation: none; } }

        /* Faint circuit-grid texture */
        .grid-overlay {
            background-image:
                linear-gradient(rgba(59,139,255,0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59,139,255,0.05) 1px, transparent 1px);
            background-size: 56px 56px;
            -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 100%);
            mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 100%);
        }

        /* Glass surface */
        .glass {
            background: rgba(17, 36, 80, 0.55);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .nav-blur {
            background: rgba(10, 23, 51, 0.72);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        /* Hover-glow card */
        .glow-card { box-shadow: 0 0 22px rgba(59, 139, 255, 0.06); transition: box-shadow 0.3s ease, border-color 0.3s ease, transform 0.3s ease; }
        .glow-card:hover { box-shadow: 0 0 44px rgba(59, 139, 255, 0.16); border-color: rgba(59, 139, 255, 0.35); transform: translateY(-3px); }

        /* Gradient headline text */
        .gradient-text {
            background: linear-gradient(120deg, #FFFFFF 20%, #3B8BFF 55%, #FFFFFF 85%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* Glowing button */
        .btn-glow { box-shadow: 0 0 22px rgba(59, 139, 255, 0.28); transition: box-shadow 0.25s ease, transform 0.15s ease; }
        .btn-glow:hover { box-shadow: 0 0 36px rgba(59, 139, 255, 0.48); transform: translateY(-1px); }

        /* Fade/rise-in on scroll */
        .reveal { opacity: 0; transform: translateY(16px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal.in { opacity: 1; transform: translateY(0); }

        /* Homepage hero: gradient headline treatment */
        .hero-shine {
            background: linear-gradient(115deg, #3B8BFF 0%, #8FBFFF 45%, #3B8BFF 100%);
            -webkit-background-clip: text;
            background-clip: text;
        }

        /* Homepage hero: floating tool-preview cards, ambient drift matching .glow-orb's existing motion language */
        .hero-card { animation: hero-float 6.5s ease-in-out infinite; }
        .hero-card-a { animation-duration: 6.5s; }
        .hero-card-c { animation-duration: 5.8s; animation-delay: -4s; }
        @keyframes hero-float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-14px); }
        }
        /* hero-card-b needs translateX + rotate baked into its own keyframes
           (not the shared .hero-card transform) — a CSS animation's
           transform fully replaces any transform set elsewhere rather than
           combining with it, so its resting rotation has to live here too. */
        .hero-card-b { animation: hero-float-b 7s ease-in-out infinite -2s; }
        @keyframes hero-float-b {
            0%, 100% { transform: translateX(-50%) rotate(-1deg) translateY(0); }
            50% { transform: translateX(-50%) rotate(-1deg) translateY(-14px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-card, .hero-card-b { animation: none; transform: translateY(0); }
            .hero-card-b { transform: translateX(-50%) rotate(-1deg); }
        }

        /* Homepage hero: "now live" status pill */
        .live-pulse .h-1\.5 { animation: live-pulse-dot 1.8s ease-in-out infinite; }
        @keyframes live-pulse-dot {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) { .live-pulse .h-1\.5 { animation: none; } }
    </style>
    </head>
<body class="min-h-screen bg-ink font-sans text-paper antialiased">
    <div class="glow-field" aria-hidden="true">
        <div class="glow-orb a"></div>
        <div class="glow-orb b"></div>
        <div class="glow-orb c"></div>
    </div>
    <div class="relative z-10">
            <header class="nav-blur sticky top-0 z-20 border-b border-border">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4 md:px-12">
        <a href="./" class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full bg-signal tally-dot"></span>
            <span class="font-display text-lg tracking-tight">LucaAi</span>
        </a>

        <nav class="hidden items-center gap-7 text-sm text-muted md:flex">
                            <a href="./"
                   class="transition hover:text-paper ">
                    Home
                </a>
                            <a href="features"
                   class="transition hover:text-paper text-paper">
                    Features
                </a>
                            <a href="how-it-works"
                   class="transition hover:text-paper ">
                    How It Works
                </a>
                            <a href="pricing"
                   class="transition hover:text-paper ">
                    Pricing
                </a>
                            <a href="about"
                   class="transition hover:text-paper ">
                    About Us
                </a>
                    </nav>

        <div class="flex items-center gap-4">
            <a href="login" class="hidden text-sm text-muted hover:text-paper sm:inline">Log in</a>
            <a href="register" class="btn-glow rounded-sm bg-signal px-4 py-2 text-sm font-medium text-ink hover:brightness-95">
                Create account
            </a>
        </div>
    </div>

    
    <nav class="flex items-center gap-5 overflow-x-auto border-t border-border px-6 py-2.5 text-xs text-muted md:hidden">
                    <a href="./" class="whitespace-nowrap ">
                Home
            </a>
                    <a href="features" class="whitespace-nowrap text-signal">
                Features
            </a>
                    <a href="how-it-works" class="whitespace-nowrap ">
                How It Works
            </a>
                    <a href="pricing" class="whitespace-nowrap ">
                Pricing
            </a>
                    <a href="about" class="whitespace-nowrap ">
                About Us
            </a>
            </nav>
</header>
    <main>
        
<section class="relative overflow-hidden px-6 pb-4 pt-20 md:px-12 md:pt-28">
    <div class="pointer-events-none absolute -right-40 -top-36 h-[520px] w-[520px] rounded-full bg-signal/[0.16] blur-[60px]" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-2xl">
        <h1 class="font-display text-5xl font-semibold leading-[1.05] tracking-tight text-paper md:text-6xl">
            Everything you can do with LucaAi
        </h1>
        <p class="mt-5 max-w-lg text-lg text-muted">
            Powerful AI tools for creation, transformation, and real-time experiences — all in one platform.
        </p>
    </div>
</section>


<section class="relative px-6 py-16 md:px-12">
    <div class="mx-auto max-w-5xl space-y-4">
        
        <div class="grid gap-8 rounded-2xl border border-signal/25 bg-signal/[0.04] p-8 md:grid-cols-[1fr_1.3fr] md:items-center">
            <div>
                <p class="font-mono text-xs text-signal">Livestream</p>
                <h2 class="mt-2 font-display text-3xl">Livestream</h2>
                <p class="mt-3 text-muted">Transform your live camera feed in real time and bring AI-powered effects to your live experience.</p>
            </div>
            <ul class="space-y-3 text-sm text-paper">
                                    <li class="flex items-center gap-3"><span class="h-1.5 w-1.5 rounded-full bg-reel"></span>Real-time processing</li>
                                    <li class="flex items-center gap-3"><span class="h-1.5 w-1.5 rounded-full bg-reel"></span>Phone and laptop compatible</li>
                                    <li class="flex items-center gap-3"><span class="h-1.5 w-1.5 rounded-full bg-reel"></span>Metered per second</li>
                            </ul>
        </div>

                    <div class="glass grid gap-6 rounded-2xl p-7 md:grid-cols-[1fr_1.3fr] md:items-center">
                <div>
                    <p class="font-mono text-xs text-muted">Audio</p>
                    <h2 class="mt-2 font-display text-2xl">Voice Isolator</h2>
                    <p class="mt-2.5 text-sm text-muted">Strip background noise from any recording — an interview, a voice memo, a noisy call — keeping just the speech behind it.</p>
                </div>
                <ul class="space-y-2 text-sm text-muted">
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>MP3, WAV, MP4 input</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Fast turnaround</li>
                                    </ul>
            </div>
                    <div class="glass grid gap-6 rounded-2xl p-7 md:grid-cols-[1fr_1.3fr] md:items-center">
                <div>
                    <p class="font-mono text-xs text-muted">Audio</p>
                    <h2 class="mt-2 font-display text-2xl">Voice Cloning</h2>
                    <p class="mt-2.5 text-sm text-muted">Clone a voice from a short sample, then generate speech from any script using the cloned voice.</p>
                </div>
                <ul class="space-y-2 text-sm text-muted">
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Short sample required</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Any script, any length</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Natural AI voice generation</li>
                                    </ul>
            </div>
                    <div class="glass grid gap-6 rounded-2xl p-7 md:grid-cols-[1fr_1.3fr] md:items-center">
                <div>
                    <p class="font-mono text-xs text-muted">Video</p>
                    <h2 class="mt-2 font-display text-2xl">Image to Video</h2>
                    <p class="mt-2.5 text-sm text-muted">Turn a still image into a short animated video, with an optional motion prompt to describe how it should move.</p>
                </div>
                <ul class="space-y-2 text-sm text-muted">
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Optional motion description</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Short-form video output</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Simple image-to-video workflow</li>
                                    </ul>
            </div>
                    <div class="glass grid gap-6 rounded-2xl p-7 md:grid-cols-[1fr_1.3fr] md:items-center">
                <div>
                    <p class="font-mono text-xs text-muted">Image</p>
                    <h2 class="mt-2 font-display text-2xl">AI Image Edit</h2>
                    <p class="mt-2.5 text-sm text-muted">Describe your edit in plain language and transform any photo — from backgrounds and lighting to objects and style.</p>
                </div>
                <ul class="space-y-2 text-sm text-muted">
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Plain-language instructions</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Edit backgrounds, objects, lighting and more</li>
                                            <li class="flex items-center gap-2.5"><span class="h-1 w-1 rounded-full bg-border"></span>Fast turnaround</li>
                                    </ul>
            </div>
            </div>
</section>


<section class="relative border-t border-border px-6 py-24 md:px-12">
    <div class="mx-auto max-w-6xl">
        <h2 class="font-display text-3xl md:text-4xl">One platform. One credit system.</h2>
        <div class="mt-10 grid gap-4 md:grid-cols-2">
                            <div class="glass glow-card rounded-lg p-6">
                    <h3 class="font-display text-lg">Use what you need</h3>
                    <p class="mt-2 text-sm text-muted">Access all your AI tools from one platform without switching between different services.</p>
                </div>
                            <div class="glass glow-card rounded-lg p-6">
                    <h3 class="font-display text-lg">Credits across every tool</h3>
                    <p class="mt-2 text-sm text-muted">Purchase credits once and use them across the services you need.</p>
                </div>
                            <div class="glass glow-card rounded-lg p-6">
                    <h3 class="font-display text-lg">Pay for what you use</h3>
                    <p class="mt-2 text-sm text-muted">Credits are deducted based on the job or service you use, with Livestream metered every 2 seconds.</p>
                </div>
                            <div class="glass glow-card rounded-lg p-6">
                    <h3 class="font-display text-lg">Built for any device</h3>
                    <p class="mt-2 text-sm text-muted">Use LucaAi from your phone or laptop and access your tools wherever you work.</p>
                </div>
                    </div>
    </div>
</section>

<section class="relative border-t border-border px-6 py-20 text-center md:px-12">
    <h2 class="font-display text-3xl md:text-4xl">Ready to see it running?</h2>
    <div class="mt-6 flex justify-center gap-4">
        <a href="register" class="btn-glow rounded-md bg-signal px-7 py-3 font-medium text-ink hover:brightness-95">Start creating</a>
        <a href="how-it-works" class="rounded-md border border-border px-7 py-3 font-medium text-paper transition hover:border-signal/50">See how it works</a>
    </div>
</section>
    </main>
    <?php sleek_public_footer(); ?>
    </div>
        <script>
        // Lightweight reveal-on-scroll for elements marked .reveal — degrades
        // gracefully (everything's just visible) if IntersectionObserver isn't available.
        if ('IntersectionObserver' in window) {
            document.addEventListener('DOMContentLoaded', function () {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('in');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15 });
                document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
            });
        } else {
            document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('in'); });
        }
    </script>
<script type="module" src="https://static.cloudflareinsights.com/beacon.min.js/v31edd6df95cf4e85bb4c19e7a9bdbcba1788362987495" integrity="sha512-iIg7k2xntmwu6/uSb5tpc/hySgZc4eoL31yB29W6tJFo2akwjPWcEqnCEdJvGexCL0KEQwVYv5BlowfhVz26hg==" data-cf-beacon='{"version":"2024.11.0","token":"97ad44cc531f410e9330a892a6c3f2cf","r":1,"spa":2}' crossorigin="anonymous"></script>
</body>

<!-- Mirrored from sleekai.com.ng/features by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 19 Sep 2026 16:15:04 GMT -->
</html>
