<?php
require __DIR__ . '/inc/bootstrap.php';
$admin = require_super_admin();
$db = sleek_db();
$notice = null;

if (sleek_is_post()) {
    csrf_verify();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'promote_initial') {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $token = is_string($_POST['setup_token'] ?? null) ? $_POST['setup_token'] : '';
        $notice = sleek_claim_initial_super_admin($email, $token) ? 'Initial super admin verified and assigned.' : 'Email and setup token did not match, or the account does not exist.';
    } elseif ($action === 'set_role') {
        $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
        $notice = $id && sleek_update_user_role((int) $id, $role) ? 'User role updated.' : 'Could not update this role.';
    } elseif ($action === 'save_provider_keys') {
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (!$isLocal && !$isHttps) {
            $notice = 'Provider keys can only be saved over HTTPS or from this computer.';
        } else {
            $secretFile = __DIR__ . '/storage/provider-secrets.json';
            $saved = is_file($secretFile) ? json_decode((string) file_get_contents($secretFile), true) : [];
            if (!is_array($saved)) $saved = [];
            $fields = ['decart' => 'api_key', 'elevenlabs' => 'api_key', 'fal' => 'api_key', 'paystack' => 'secret_key'];
            foreach ($fields as $provider => $keyName) {
                $newValue = is_string($_POST[$provider . '_key'] ?? null) ? trim($_POST[$provider . '_key']) : '';
                $clear = isset($_POST['clear_' . $provider]);
                if ($clear) $saved[$provider][$keyName] = '';
                elseif ($newValue !== '') {
                    if (strlen($newValue) > 4096 || str_contains($newValue, "\0")) {
                        $notice = 'A key was too long or contained invalid characters; no changes were saved.';
                        break;
                    }
                    $saved[$provider][$keyName] = $newValue;
                }
            }
            if ($notice === null) {
                $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $written = $json === false ? false : file_put_contents($secretFile, $json . PHP_EOL, LOCK_EX);
                if ($written === false) $notice = 'Could not write the private provider settings file.';
                else {
                    @chmod($secretFile, 0600);
                    sleek_redirect('admin?keys=saved');
                }
            }
        }
    }
}

if (($_GET['keys'] ?? '') === 'saved') $notice = 'Provider settings saved. Existing values stay hidden.';

$users = $db->query('SELECT id,name,email,credits,role,created_at FROM users ORDER BY id DESC LIMIT 200')->fetchAll();
$stats = [
    'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'admins' => (int) $db->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')")->fetchColumn(),
    'jobs' => (int) $db->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),
    'payments' => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'paid'")->fetchColumn(),
];
$providers = [
    'Decart' => sleek_config('decart')['api_key'] !== '',
    'ElevenLabs' => sleek_config('elevenlabs')['api_key'] !== '',
    'fal.ai' => sleek_config('fal')['api_key'] !== '',
    'Paystack' => sleek_config('paystack')['secret_key'] !== '',
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Admin — <?= e((string) sleek_config('brand_name')) ?></title><style>
*{box-sizing:border-box}body{margin:0;background:#14171c;color:#f5f6f7;font:15px Inter,system-ui,sans-serif}main{max-width:1180px;margin:auto;padding:32px 20px}h1,h2{font-family:Georgia,serif}a{color:#f2b705}.top{display:flex;justify-content:space-between;align-items:center;gap:16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:24px 0}.card{background:#1c2027;border:1px solid #303640;border-radius:12px;padding:18px}.number{font-size:28px;font-weight:700}.muted{color:#a8aeb8}.notice{padding:12px;background:#263327;border-radius:8px;margin:16px 0}form{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}input,select,button{background:#14171c;color:#f5f6f7;border:1px solid #454c57;border-radius:6px;padding:9px}button{cursor:pointer;background:#f2b705;color:#14171c;font-weight:600}table{border-collapse:collapse;width:100%;min-width:720px}th,td{text-align:left;padding:11px;border-bottom:1px solid #303640}.overflow{overflow:auto}.setup{margin:20px 0}small{color:#a8aeb8}@media(max-width:600px){main{padding:20px 12px}.top{align-items:flex-start}}
</style></head><body><main>
<div class="top"><div><p class="muted"><?= e((string) sleek_config('brand_name')) ?> / Administration</p><h1>System control</h1><p class="muted">Signed in as <?= e($admin['email']) ?> · Super admin</p></div><a href="dashboard">User dashboard</a></div>
<?php if ($notice): ?><p class="notice" role="status"><?= e($notice) ?></p><?php endif; ?>
<section class="grid"><?php foreach ($stats as $label => $value): ?><article class="card"><div class="muted"><?= e(ucfirst($label)) ?></div><div class="number"><?= number_format($value) ?></div></article><?php endforeach; ?></section>
<section class="card"><h2>Provider connections</h2><p class="muted">Keys are stored privately on this server. Saved values are never shown here. Leave a field blank to keep its current value; use Clear to remove it.</p><div class="grid"><?php foreach ($providers as $name => $connected): ?><article class="card"><strong><?= e($name) ?></strong><p class="muted"><?= $connected ? 'Key configured' : 'Key missing' ?></p></article><?php endforeach; ?></div><form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_provider_keys"><?php foreach (['Decart'=>'decart','ElevenLabs'=>'elevenlabs','fal.ai'=>'fal','Paystack'=>'paystack'] as $label=>$key): ?><label class="card"><strong><?= e($label) ?> key</strong><input type="password" name="<?= e($key) ?>_key" autocomplete="new-password" placeholder="<?= $providers[$label] ? 'Leave blank to keep current key' : 'Paste key for this local server' ?>"><span><input type="checkbox" name="clear_<?= e($key) ?>" value="1"> Clear saved key</span></label><?php endforeach; ?><button>Save provider keys</button></form></section>
<section class="card setup"><h2>Initial super admin setup</h2><p class="muted">Use the exact owner email and setup token configured privately in inc/config.local.php. This can transfer the super admin role to the verified owner account.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="promote_initial"><input required type="email" name="email" placeholder="Configured owner email"><input required type="password" name="setup_token" placeholder="Private setup token"><button>Verify and assign</button></form></section>
<section class="card"><h2>Accounts</h2><p class="muted">Showing latest <?= count($users) ?> of <?= number_format($stats['users']) ?> accounts. The super admin role cannot be changed from the table.</p><div class="overflow"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Credits</th><th>Joined</th><th>Change role</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?= e($user['name']) ?></td><td><?= e($user['email']) ?></td><td><?= e($user['role']) ?></td><td><?= number_format((int) $user['credits']) ?></td><td><?= e(date('Y-m-d', (int) $user['created_at'])) ?></td><td><?php if ($user['role'] !== 'super_admin'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><select name="role"><option value="user"<?= $user['role'] === 'user' ? ' selected' : '' ?>>User</option><option value="admin"<?= $user['role'] === 'admin' ? ' selected' : '' ?>>Admin</option></select><button>Save</button></form><?php else: ?><small>Protected</small><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section></main></body></html>
