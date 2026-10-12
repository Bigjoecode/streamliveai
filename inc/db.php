<?php
/** StreamLive AI database connection and idempotent schema migrations. */
declare(strict_types=1);

function sleek_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = sleek_config('db');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
        $m = $cfg['mysql'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $m['host'], $m['port'], $m['database']);
        $pdo = new PDO($dsn, $m['username'], $m['password'], $options);
    } else {
        $path = $cfg['sqlite_path'];
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    sleek_migrate($pdo);
    return $pdo;
}

function sleek_migrate(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec($mysql ? "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
        email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(24) NOT NULL DEFAULT 'user', credits INT NOT NULL DEFAULT 0,
        referral_code VARCHAR(16) NOT NULL, referred_by INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL,
        UNIQUE KEY users_email_unique (email), UNIQUE KEY users_referral_code_unique (referral_code),
        KEY users_referred_by_index (referred_by),
        CONSTRAINT users_referred_by_fk FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" : "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user', credits INTEGER NOT NULL DEFAULT 0,
        referral_code TEXT NOT NULL UNIQUE, referred_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
        created_at INTEGER NOT NULL
    )");

    if ($mysql) {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('role', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(24) NOT NULL DEFAULT 'user'");
    } else {
        $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('role', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
    }

    $tables = [
        'login_attempts' => $mysql ? "CREATE TABLE IF NOT EXISTS login_attempts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, throttle_key CHAR(64) NOT NULL, attempted_at INT UNSIGNED NOT NULL, KEY login_attempts_key_time (throttle_key, attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : 'CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, throttle_key TEXT NOT NULL, attempted_at INTEGER NOT NULL)',
        'password_resets' => $mysql ? 'CREATE TABLE IF NOT EXISTS password_resets (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, created_at INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL, UNIQUE KEY password_resets_token_unique (token_hash), KEY password_resets_user_index (user_id), CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : 'CREATE TABLE IF NOT EXISTS password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, token_hash TEXT NOT NULL UNIQUE, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)',
        'payments' => $mysql ? "CREATE TABLE IF NOT EXISTS payments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, reference VARCHAR(64) NOT NULL, pack_key VARCHAR(32) NOT NULL, credits INT UNSIGNED NOT NULL, amount_kobo BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', gateway_response VARCHAR(255) NULL, paid_at INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, UNIQUE KEY payments_reference_unique (reference), KEY payments_user_status (user_id,status), CONSTRAINT payments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : "CREATE TABLE IF NOT EXISTS payments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, reference TEXT NOT NULL UNIQUE, pack_key TEXT NOT NULL, credits INTEGER NOT NULL, amount_kobo INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', gateway_response TEXT NULL, paid_at INTEGER NULL, created_at INTEGER NOT NULL)",
        'credit_ledger' => $mysql ? 'CREATE TABLE IF NOT EXISTS credit_ledger (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, delta INT NOT NULL, balance_after INT NOT NULL, kind VARCHAR(24) NOT NULL, reference VARCHAR(64) NOT NULL, description VARCHAR(255) NOT NULL, created_at INT UNSIGNED NOT NULL, UNIQUE KEY credit_ledger_kind_reference (kind,reference), KEY credit_ledger_user_time (user_id,created_at), CONSTRAINT credit_ledger_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : 'CREATE TABLE IF NOT EXISTS credit_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, delta INTEGER NOT NULL, balance_after INTEGER NOT NULL, kind TEXT NOT NULL, reference TEXT NOT NULL, description TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE(kind,reference))',
        'jobs' => $mysql ? "CREATE TABLE IF NOT EXISTS jobs (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, tool VARCHAR(48) NOT NULL, status VARCHAR(24) NOT NULL, credits INT NOT NULL, title VARCHAR(255) NOT NULL, output_file VARCHAR(255) NULL, output_mime VARCHAR(128) NULL, error TEXT NULL, consent_at INT NULL, created_at INT UNSIGNED NOT NULL, completed_at INT UNSIGNED NULL, KEY jobs_user_time(user_id,created_at), KEY jobs_status_time(status,created_at), CONSTRAINT jobs_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : 'CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, tool TEXT NOT NULL, status TEXT NOT NULL, credits INTEGER NOT NULL, title TEXT NOT NULL, output_file TEXT NULL, output_mime TEXT NULL, error TEXT NULL, consent_at INTEGER NULL, created_at INTEGER NOT NULL, completed_at INTEGER NULL)',
        'live_sessions' => $mysql ? 'CREATE TABLE IF NOT EXISTS live_sessions (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, job_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, max_seconds INT NOT NULL, used_seconds INT NOT NULL DEFAULT 0, reported TINYINT NOT NULL DEFAULT 0, reserved_credits INT NOT NULL, charged_credits INT NULL, token_expires_at INT UNSIGNED NOT NULL, created_at INT UNSIGNED NOT NULL, last_seen_at INT UNSIGNED NULL, ended_at INT UNSIGNED NULL, UNIQUE KEY live_sessions_job_unique(job_id), KEY live_sessions_user(user_id), CONSTRAINT live_sessions_job_fk FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE, CONSTRAINT live_sessions_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : 'CREATE TABLE IF NOT EXISTS live_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL UNIQUE REFERENCES jobs(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, max_seconds INTEGER NOT NULL, used_seconds INTEGER NOT NULL DEFAULT 0, reported INTEGER NOT NULL DEFAULT 0, reserved_credits INTEGER NOT NULL, charged_credits INTEGER NULL, token_expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, last_seen_at INTEGER NULL, ended_at INTEGER NULL)',
        'async_jobs' => $mysql ? 'CREATE TABLE IF NOT EXISTS async_jobs (job_id INT UNSIGNED NOT NULL PRIMARY KEY, provider VARCHAR(32) NOT NULL, request_id VARCHAR(255) NOT NULL, status_url VARCHAR(512) NOT NULL, response_url VARCHAR(512) NOT NULL, provider_status VARCHAR(32) NULL, last_polled_at INT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL, CONSTRAINT async_jobs_job_fk FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : 'CREATE TABLE IF NOT EXISTS async_jobs (job_id INTEGER PRIMARY KEY REFERENCES jobs(id) ON DELETE CASCADE, provider TEXT NOT NULL, request_id TEXT NOT NULL, status_url TEXT NOT NULL, response_url TEXT NOT NULL, provider_status TEXT NULL, last_polled_at INTEGER NULL, created_at INTEGER NOT NULL)',
    ];
    foreach ($tables as $sql) $pdo->exec($sql);
}

function sleek_is_duplicate(PDOException $e): bool
{
    return $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE');
}
