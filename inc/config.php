<?php
/**
 * StreamLive AI — app configuration.
 *
 * This file holds secrets (SMTP password, DB password). It sits in inc/, which is
 * denied over HTTP by inc/.htaccess — keep it there.
 */

return [
    /*
     * The public address of the site, with no trailing slash. Used to build links
     * that leave the site — password-reset emails and referral links.
     *
     * Never derived from the request's Host header: an attacker can forge that on a
     * "forgot password" request and have the victim's reset link point at their own
     * server (password-reset poisoning).
     */
    'app_url' => 'http://localhost/sleekai.com.ng',
    'brand_name' => 'StreamLive AI',

    'db' => [
        // 'mysql' (XAMPP's MariaDB) or 'sqlite' (no server needed). For MySQL: start it in the XAMPP
        // Control Panel, create an empty `sleekai` database, set this to 'mysql'.
        // Tables are created on the first request either way.
        'driver' => 'mysql',

        // Kept under storage/, which ships with a deny-all .htaccess.
        'sqlite_path' => __DIR__ . '/../storage/sleekai.sqlite',

        'mysql' => [
            'host'     => '127.0.0.1',
            'port'     => 3307,   // this XAMPP's my.ini runs MySQL on 3307, not the default 3306
            'database' => 'sleekai',
            'username' => 'root',
            'password' => '',
        ],
    ],

    'mail' => [
        /*
         * 'smtp' sends through the server below via PHPMailer.
         * 'log'  builds the exact same message with PHPMailer but appends it to
         *        storage/mail.log instead of sending — handy on a machine with no
         *        mail account, since reset links can be copied out of the log.
         */
        'driver' => 'smtp',

        // Fill these in from your mail provider (cPanel mailbox, Gmail app password,
        // Zoho, Mailgun, Brevo, …). 587 + 'tls' is the usual pairing; 465 + 'ssl'
        // is the other.
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'encryption' => 'tls',      // 'tls' (STARTTLS), 'ssl', or '' for none
        'username'   => '',
        'password'   => '',

        // '' means no-reply@<the app_url domain>. The super admin can set all of
        // this at /admin → Email instead of editing files.
        'from_address' => '',
        'from_name'    => 'StreamLive AI',
    ],

    'flutterwave' => [
        // Naira payments: packs are charged in ₦ at the rate set in /admin-pricing.
        // From app.flutterwave.com → Settings → API Keys.
        // FLWSECK_TEST-… while testing (no real money moves), FLWSECK-… in production.
        'secret_key' => '',

        // Settings → Webhooks: URL {app_url}/flutterwave-webhook, plus a "Secret hash"
        // you make up. Put that same hash here — Flutterwave echoes it in verif-hash.
        'webhook_hash' => '',

        'api_base'        => 'https://api.flutterwave.com',
        'payment_options' => 'card,banktransfer,ussd',
    ],

    'nowpayments' => [
        // Crypto. From account.nowpayments.io → Settings → Payments → API keys.
        'api_key' => '',

        // Same page → "Instant payment notifications" → generate IPN secret key.
        // Shown once; required, or crypto payments could never be confirmed.
        'ipn_secret' => '',

        'api_base' => 'https://api.nowpayments.io',   // sandbox: https://api-sandbox.nowpayments.io

        // true: the buyer pays NOWPayments' network/service fee on top of the price.
        'fee_paid_by_user' => false,
    ],

    'elevenlabs' => [
        // From elevenlabs.io → Profile → API Keys. Powers Voice Isolator and Voice
        // Cloning (as named in the site's Privacy Policy).
        'api_key' => '',

        'api_base'  => 'https://api.elevenlabs.io',
        'tts_model' => 'eleven_multilingual_v2',
    ],

    'decart' => [
        // From platform.decart.ai → API keys (starts with dct_). Powers Livestream.
        // It never reaches the browser: the server mints short-lived client tokens.
        'api_key' => '',

        'api_base'    => 'https://api.decart.ai',
        'model'       => 'lucy-2.1',       // Livestream: Decart's documented model for reference-image character swap
        'image_model' => 'lucy-image-2',   // AI Image Edit: POST /v1/generate/{image_model}

        'credits_per_second'     => 2,
        'bill_increment_seconds' => 2,        // "metered every 2 seconds"
        'session_minutes'        => [1, 5, 10, 15, 30],

        /*
         * Livestream is prepaid: the chosen session length is charged up front and
         * Decart itself cuts the stream off at that length (maxSessionDuration), so
         * nobody can stream time they haven't paid for.
         *
         * true  = when a stream ends early, refund the unused time. Fair, and matches
         *         "billed per second" — but "time used" is reported by the viewer's
         *         browser, which a technical user can falsify to claw credits back.
         * false = no refunds for unused time. Nothing to game; less forgiving.
         */
        'refund_unused' => true,
    ],

    'fal' => [
        // From fal.ai → Dashboard → Keys. Powers Image to Video (the talking-photo
        // step; the voice comes from ElevenLabs).
        'api_key' => '',

        'queue_base' => 'https://queue.fal.run',

        // Kling AI Avatar v2 Standard: ~$0.056 per second of video. Swapping in a
        // pricier model (OmniHuman ~$0.16/s) can cost more than the 200 credits
        // charged — check the per-second price against your credit price first.
        'avatar_model' => 'fal-ai/kling-video/ai-avatar/v2/standard',

        // Finished videos are copied off fal's CDN into storage/outputs. Only these
        // hosts (or subdomains of them) are fetched from, over https.
        'download_hosts' => ['fal.media', 'fal.run', 'fal.ai'],
    ],

    'referrals' => [
        // Credits a referrer earns, as a percentage of the credits in their
        // referral's first purchase. Rounded down.
        'bonus_percent' => 5,
    ],

    'auth' => [
        // Set to the owner's exact email in config.local.php before first registration.
        'initial_super_admin_email' => '',
        'super_admin_setup_token' => '',
        // Failed logins allowed per email+IP before a temporary lockout.
        'max_attempts'    => 5,
        'lockout_seconds' => 15 * 60,

        // bcrypt silently ignores everything past 72 bytes, so reject longer
        // passwords rather than let two different ones hash identically.
        'password_min' => 8,
        'password_max' => 72,

        // Password-reset links stop working after this long, and a new one can't
        // be requested for the same email more often than this.
        'reset_ttl_seconds'      => 60 * 60,
        'reset_cooldown_seconds' => 60,
    ],
];
