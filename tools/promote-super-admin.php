<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/promote-super-admin.php owner@example.com\n");
    exit(2);
}
$sessionDir = __DIR__ . '/../storage';
if (!is_dir($sessionDir)) mkdir($sessionDir, 0775, true);
session_save_path($sessionDir);
require __DIR__ . '/../inc/bootstrap.php';
$db = sleek_db();
$db->beginTransaction();
try {
    if ((int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn() > 0) {
        throw new RuntimeException('A super admin is already configured.');
    }
    $stmt = $db->prepare('UPDATE users SET role = \'super_admin\' WHERE email = ?');
    $stmt->execute([sleek_normalize_email($argv[1])]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('No matching account found; create and verify the owner account first.');
    $db->commit();
    fwrite(STDOUT, "Super admin assigned to the matching account.\n");
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
