<?php
/**
 * SleekAI — page shell for the super-admin pages (/admin, /admin-user). Mobile
 * first: one column on phones, a sticky section menu that scrolls sideways, tap
 * targets of at least 44px, and tables marked .stack turn into cards under 720px
 * (each <td> needs a data-label).
 */

declare(strict_types=1);

/** Section anchors on /admin, in page order. */
const SLEEK_ADMIN_SECTIONS = [
    'accounts' => 'Accounts',
    'messages' => 'Messages',
    'pricing'  => 'Pricing',
    'site'     => 'Community',
    'email'    => 'Email',
    'keys'     => 'Providers',
];

function admin_layout_open(string $title, array $admin, ?string $notice = null, bool $noticeIsError = false, bool $onIndex = true): void
{
    $brand = (string) sleek_config('brand_name');
    $base  = $onIndex ? '' : 'admin';   // section links point back at /admin from other pages
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> — <?= e($brand) ?></title>
<style>
:root{--bg:#14171c;--card:#1c2027;--line:#303640;--field:#454c57;--text:#f5f6f7;--muted:#a8aeb8;--gold:#f2b705;--ok:#263327;--bad:#3a2626}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:var(--gold)}
h1,h2,h3{font-family:Georgia,serif;line-height:1.2;margin:0}
h1{font-size:26px}h2{font-size:21px}h3{font-size:17px}
p{margin:.5em 0}
main{max-width:1180px;margin:0 auto;padding:16px 16px 48px}
.top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:10px 16px;padding-top:8px}
.top .links{display:flex;gap:16px;flex-wrap:wrap}
.muted,small{color:var(--muted)}
small{font-size:13px}
.adminnav{position:sticky;top:0;z-index:5;background:var(--bg);border-bottom:1px solid var(--line);margin:16px -16px 0;padding:0 16px}
.adminnav ul{display:flex;gap:4px;list-style:none;margin:0;padding:8px 0;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.adminnav ul::-webkit-scrollbar{display:none}
.adminnav a{display:block;white-space:nowrap;padding:8px 12px;border-radius:99px;color:var(--text);text-decoration:none;border:1px solid var(--line)}
.adminnav a:hover{border-color:var(--gold)}
section[id]{scroll-margin-top:64px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-top:16px;min-width:0}
.card>h2{margin-bottom:4px}
.notice{padding:12px 14px;background:var(--ok);border-radius:8px;margin:16px 0 0;overflow-wrap:anywhere}
.notice.bad{background:var(--bad)}
.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px}
.stats .card{margin:0}
.number{font-size:26px;font-weight:700}
label{display:grid;gap:4px;min-width:0}
label>span.l{font-size:13px;color:var(--muted)}
input,select,textarea,button{font:inherit;font-size:16px;color:var(--text);background:var(--bg);border:1px solid var(--field);border-radius:8px;padding:10px 12px;min-height:44px;width:100%;min-width:0}
input[type=checkbox]{width:auto;min-height:0}
textarea{resize:vertical}
button,.btn{cursor:pointer;background:var(--gold);color:var(--bg);font-weight:600;border-color:var(--gold);width:auto;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;padding:10px 16px;border-radius:8px;min-height:44px}
button.ghost,.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--field)}
button.danger{background:transparent;color:#ff8a8a;border:1px solid #6b3a3a}
.check{display:flex;align-items:center;gap:8px;font-size:14px;color:var(--muted)}
.form{display:grid;gap:12px;margin-top:12px}
.form-grid{display:grid;gap:12px}
.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.inline{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0}
.inline>input,.inline>select{flex:1 1 180px;width:auto}
.err{color:#ff8a8a;font-size:13px}
details>summary{cursor:pointer;list-style:none}
details>summary::-webkit-details-marker{display:none}
.tablewrap{overflow-x:auto;margin-top:12px}
table{border-collapse:collapse;width:100%}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
td{overflow-wrap:anywhere}
.role{display:inline-block;border:1px solid var(--field);border-radius:99px;padding:0 8px;font-size:12px}
.role.super_admin{border-color:var(--gold);color:var(--gold)}
.pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.pill{background:var(--gold);color:var(--bg);border-radius:99px;padding:1px 8px;font-size:12px;font-weight:700;margin-left:6px;font-family:Inter,system-ui,sans-serif;vertical-align:middle}
.packs{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:12px 0}
.pack{display:grid;gap:8px;margin:0;border:1px solid var(--line);border-radius:12px;padding:12px}
.pack legend{color:var(--muted);padding:0 4px}
.keys{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));margin-top:12px}
.keys .card{margin:0}
.msg{border-top:1px solid var(--line);padding:14px 0}
.msg.unread{border-left:3px solid var(--gold);padding-left:12px}
.msg p{white-space:pre-wrap;overflow-wrap:anywhere}
.kv{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:8px 0}
.kv dt{color:var(--muted)}.kv dd{margin:0;overflow-wrap:anywhere}
.plus{color:#7ee2a8}.minus{color:#ff8a8a}
@media (max-width:719px){
  table.stack thead{display:none}
  table.stack,table.stack tbody,table.stack tr,table.stack td{display:block;width:100%}
  table.stack tr{border:1px solid var(--line);border-radius:10px;padding:6px 12px;margin-bottom:10px}
  table.stack td{display:flex;justify-content:space-between;gap:12px;border:0;padding:6px 0;text-align:right}
  table.stack td::before{content:attr(data-label);color:var(--muted);text-align:left;flex:0 0 auto}
  table.stack td.full,table.stack td:first-child{display:block;text-align:left}
  table.stack td.full::before,table.stack td:first-child::before{display:none}
  table.stack td:first-child{font-weight:600;font-size:16px;padding-top:8px}
  .inline>button{flex:1 1 auto}
}
@media (min-width:640px){
  main{padding:24px 24px 64px}
  .adminnav{margin:20px -24px 0;padding:0 24px}
  .card{padding:20px}
  .stats{grid-template-columns:repeat(4,minmax(0,1fr))}
  .form-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
  .form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
  h1{font-size:30px}
}
</style>
</head>
<body>
<main>
<div class="top">
    <div>
        <p class="muted" style="margin:0"><?= e($brand) ?> / Administration</p>
        <h1><?= e($title) ?></h1>
        <p class="muted" style="margin:4px 0 0">Signed in as <?= e($admin['email']) ?> · Super admin</p>
    </div>
    <div class="links"><?php if (!$onIndex): ?><a href="admin">← Admin home</a><?php endif; ?><a href="dashboard">User dashboard</a></div>
</div>
<nav class="adminnav" aria-label="Admin sections"><ul>
    <?php foreach (SLEEK_ADMIN_SECTIONS as $id => $label): ?>
        <li><a href="<?= $base ?>#<?= $id ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul></nav>
<?php if ($notice): ?><p class="notice<?= $noticeIsError ? ' bad' : '' ?>" role="<?= $noticeIsError ? 'alert' : 'status' ?>"><?= e($notice) ?></p><?php endif; ?>
    <?php
}

function admin_layout_close(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** Inline field error, if any. */
function admin_err(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="err">' . e($errors[$field]) . '</span>' : '';
}
