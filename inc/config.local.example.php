<?php
/**
 * Copy this file to config.local.php for local development.
 * Keep config.local.php private; inc/.htaccess denies web access.
 */
return [
    'app_url' => 'http://localhost/sleekai.com.ng',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/../storage/streamlive-local.sqlite',
    ],
    'auth' => [
        // Promote your own existing account from a local terminal:
        // php tools/promote-super-admin.php your-email@example.com
    ],
    'mail' => [
        'driver' => 'log',
        'from_name' => 'StreamLive AI',
    ],
    'flutterwave' => ['secret_key' => '', 'webhook_hash' => ''],
    'nowpayments' => ['api_key' => '', 'ipn_secret' => ''],
    'elevenlabs' => ['api_key' => ''],
    'decart' => ['api_key' => ''],
    'fal' => ['api_key' => ''],
];
