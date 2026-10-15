<?php require __DIR__ . '/inc/public.php'; ?>
<!DOCTYPE html>
<html lang="en">

<!-- Mirrored from sleekai.com.ng/terms by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 19 Sep 2026 16:15:05 GMT -->
<!-- Added by HTTrack --><meta http-equiv="content-type" content="text/html;charset=utf-8" /><!-- /Added by HTTrack -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service — StreamLive AI</title>

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
                        ink: '#14171C',
                        surface: '#1C2027',
                        border: '#2A2F38',
                        muted: '#9AA3AF',
                        paper: '#F5F6F7',
                        signal: '#F2B705',
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
        body { background-color: #14171C; }
        ::selection { background: #F2B705; color: #14171C; }
        .tally-dot { box-shadow: 0 0 0 3px rgba(242, 183, 5, 0.15); }
        .tally-dot.live { box-shadow: 0 0 0 4px rgba(229, 72, 77, 0.18); }

        /* --- Futuristic layer: ambient glow field ------------------------ */
        .glow-field { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
        .glow-orb { position: absolute; border-radius: 9999px; filter: blur(90px); animation: drift 22s ease-in-out infinite; }
        .glow-orb.a { width: 520px; height: 520px; top: -160px; left: -120px; background: radial-gradient(circle, rgba(242,183,5,0.20), transparent 70%); }
        .glow-orb.b { width: 460px; height: 460px; top: 20%; right: -160px; background: radial-gradient(circle, rgba(47,191,159,0.14), transparent 70%); animation-delay: -8s; }
        .glow-orb.c { width: 420px; height: 420px; bottom: -180px; left: 30%; background: radial-gradient(circle, rgba(242,183,5,0.10), transparent 70%); animation-delay: -14s; }
        @keyframes drift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -25px) scale(1.08); }
            66% { transform: translate(-25px, 20px) scale(0.94); }
        }
        @media (prefers-reduced-motion: reduce) { .glow-orb { animation: none; } }

        /* Faint circuit-grid texture */
        .grid-overlay {
            background-image:
                linear-gradient(rgba(242,183,5,0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(242,183,5,0.05) 1px, transparent 1px);
            background-size: 56px 56px;
            -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 100%);
            mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 100%);
        }

        /* Glass surface */
        .glass {
            background: rgba(28, 32, 39, 0.55);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .nav-blur {
            background: rgba(20, 23, 28, 0.72);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        /* Hover-glow card */
        .glow-card { box-shadow: 0 0 22px rgba(242, 183, 5, 0.06); transition: box-shadow 0.3s ease, border-color 0.3s ease, transform 0.3s ease; }
        .glow-card:hover { box-shadow: 0 0 44px rgba(242, 183, 5, 0.16); border-color: rgba(242, 183, 5, 0.35); transform: translateY(-3px); }

        /* Gradient headline text */
        .gradient-text {
            background: linear-gradient(120deg, #F5F6F7 20%, #F2B705 55%, #F5F6F7 85%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* Glowing button */
        .btn-glow { box-shadow: 0 0 22px rgba(242, 183, 5, 0.28); transition: box-shadow 0.25s ease, transform 0.15s ease; }
        .btn-glow:hover { box-shadow: 0 0 36px rgba(242, 183, 5, 0.48); transform: translateY(-1px); }

        /* Fade/rise-in on scroll */
        .reveal { opacity: 0; transform: translateY(16px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal.in { opacity: 1; transform: translateY(0); }

        /* Homepage hero: gradient headline treatment */
        .hero-shine {
            background: linear-gradient(115deg, #F2B705 0%, #FBDE7A 45%, #F2B705 100%);
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
            <span class="font-display text-lg tracking-tight">StreamLive AI</span>
        </a>

        <nav class="hidden items-center gap-7 text-sm text-muted md:flex">
                            <a href="./"
                   class="transition hover:text-paper ">
                    Home
                </a>
                            <a href="features"
                   class="transition hover:text-paper ">
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
                    <a href="features" class="whitespace-nowrap ">
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
        <div class="mx-auto max-w-3xl px-6 py-16 md:px-12">
    <p class="font-mono text-xs uppercase tracking-wide text-signal">Legal</p>
    <h1 class="mt-3 font-display text-4xl">Terms of Service</h1>
    <p class="mt-2 text-sm text-muted">Last updated: September 19, 2026</p>

    <div class="prose-legal mt-10 space-y-8 text-muted">
        <section>
            <h2 class="font-display text-xl text-paper">1. Who we are</h2>
            <p class="mt-2 leading-relaxed">
                StreamLive AI ("we", "us", "our") operates the StreamLive AI platform at sleekai.com.ng, providing AI-based
                media tools including face swap, voice cloning, image-to-video, AI image editing, text-to-speech,
                voice isolation, and voice changing ("the Services"). By creating an account or using the
                Services, you agree to these Terms.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">2. Eligibility</h2>
            <p class="mt-2 leading-relaxed">
                You must be at least 18 years old to use StreamLive AI. The Services involve recreating a person's
                face or voice, and we don't offer a version of this product for minors. By registering, you
                confirm you meet this age requirement.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">3. Consent and acceptable use</h2>
            <p class="mt-2 leading-relaxed">You agree that you will only use a face or voice in the Services if:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>it is your own face or voice, or</li>
                <li>you have the explicit, informed consent of the person it belongs to.</li>
            </ul>
            <p class="mt-3 leading-relaxed">You agree not to use StreamLive AI to:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>impersonate a real person without their knowledge or consent, including on live calls or streams;</li>
                <li>create sexual, intimate, or degrading content of any real person without their explicit consent;</li>
                <li>create content involving minors in any face-swapped, voice-cloned, or otherwise identity-altered context;</li>
                <li>harass, defame, defraud, or deceive any person or organization;</li>
                <li>violate any applicable law, including impersonation, fraud, or election-related misinformation laws.</li>
            </ul>
            <p class="mt-3 leading-relaxed">
                Jobs using a real face or voice require an explicit consent confirmation before they run. We
                operate a moderation queue and reserve the right to review, remove, or refuse to deliver any
                output, and to suspend or terminate accounts, at our discretion, where we believe these Terms
                have been violated.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">4. Credits and payment</h2>
            <p class="mt-2 leading-relaxed">
                Access to the Services is billed using credits, purchased in packs with no subscription and no
                expiry date. Livestream is metered per second rather than a flat rate. Payments are
                processed by Flutterwave (naira: card, bank transfer, USSD) and NOWPayments (cryptocurrency).
                Prices are listed in US dollars; naira payments are charged at the naira amount shown at
                checkout, and crypto payments in US dollars;
                we do not store your card details ourselves. Crypto payments are credited once the
                network confirms them, and must be sent in full — an underpaid crypto payment is not
                credited automatically; contact support with your payment reference. Credits
                already spent on a completed job are non-refundable. If a job fails after credits are debited,
                those credits are automatically returned to your balance.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">5. Referrals</h2>
            <p class="mt-2 leading-relaxed">
                If you refer another user who purchases a credit pack, you may receive a bonus credit percentage
                of their purchase, as shown in your dashboard. We reserve the right to adjust, suspend, or deny
                referral bonuses we reasonably believe involve abuse, self-referral through multiple accounts, or
                fraud.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">6. Third-party processing</h2>
            <p class="mt-2 leading-relaxed">
                Some Services are powered by third-party AI providers (currently including ElevenLabs for voice
                tools and Decart for livestream face swap). When you use those tools, the relevant input (e.g. a
                voice sample or reference photo) is sent to that provider to generate your result, subject to
                their own terms and processing practices in addition to ours.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">7. Ownership of your content</h2>
            <p class="mt-2 leading-relaxed">
                You retain ownership of what you upload and of the outputs you generate, subject to you having
                had the right to use the underlying face, voice, or image in the first place. You grant us a
                limited license to process your input solely to provide the Services to you.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">8. Disclaimers and limitation of liability</h2>
            <p class="mt-2 leading-relaxed">
                The Services are provided "as is." We do not guarantee that outputs will be error-free, available
                at all times, or fit for any particular purpose. To the maximum extent permitted by law, StreamLive AI
                is not liable for indirect, incidental, or consequential damages arising from your use of the
                Services, including misuse of generated content by you or any third party.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">9. Changes to these Terms</h2>
            <p class="mt-2 leading-relaxed">
                We may update these Terms from time to time. Continued use of the Services after an update
                constitutes acceptance of the revised Terms.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">10. Governing law</h2>
            <p class="mt-2 leading-relaxed">
                These Terms are governed by the laws of the Federal Republic of Nigeria, without regard to
                conflict-of-law principles.
            </p>
        </section>

        <section>
            <h2 class="font-display text-xl text-paper">11. Contact</h2>
            <p class="mt-2 leading-relaxed">
                Questions about these Terms can be sent to <a href="cdn-cgi/l/email-protection.html#d7bfb2bbbbb897a4bbb2b2bcb6bef9b4b8baf9b9b0" class="text-signal hover:underline"><span class="__cf_email__" data-cfemail="98f0fdf4f4f7d8ebf4fdfdf3f9f1b6fbf7f5b6f6ff">[email&#160;protected]</span></a>.
            </p>
        </section>
    </div>
</div>
    </main>
    <?php sleek_public_footer(); ?>
    </div>
        <script data-cfasync="false" src="cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script><script>
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

<!-- Mirrored from sleekai.com.ng/terms by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 19 Sep 2026 16:15:06 GMT -->
</html>
