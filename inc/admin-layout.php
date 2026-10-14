<?php
/**
 * SleekAI — page shell for the super-admin area. One page per section, with:
 *   desktop (≥1024px)  a fixed sidebar: brand, sections with icons, account + sign out
 *   phone/tablet       a top bar (brand + page title) and a bottom tab bar with the
 *                      four busiest sections plus "More", which slides out the full menu
 * Forms post back to their own page. Tables marked .stack become cards under 720px
 * (each <td> needs a data-label). Tap targets are ≥44px; inputs are 16px so iOS
 * doesn't zoom.
 */

declare(strict_types=1);

/** key => [label, url, icon path(s)]. Order is the menu order. */
const SLEEK_ADMIN_NAV = [
    'overview'  => ['Overview',  'admin',           '<path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-4H4zM14 4v4h6V4z"/>'],
    'accounts'  => ['Accounts',  'admin-accounts',  '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18.5 14.8c1.6.8 2.6 2.4 3 5.2"/>'],
    'messages'  => ['Messages',  'admin-messages',  '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>'],
    'pricing'   => ['Pricing',   'admin-pricing',   '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>'],
    'community' => ['Community', 'admin-community', '<path d="M21.5 4.5 2.8 11.4c-.9.4-.8 1.2 0 1.5l4.6 1.4 1.8 5.6c.2.7.8.8 1.3.4l2.6-2.3 4.9 3.6c.7.5 1.4.2 1.6-.7l3.2-15c.2-1-.4-1.6-1.3-1.4zM7.4 14.3l10.2-6.6"/>'],
    'email'     => ['Email',     'admin-email',     '<path d="M4 4h16v16H4z" opacity="0"/><path d="M3 7.5 12 13l9-5.5M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>'],
    'providers' => ['Providers', 'admin-providers', '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.7-8.7M17 6l2.5 2.5M14.5 8.5 17 11"/>'],
];

/** The four sections on the phone's bottom bar; everything else is under More. */
const SLEEK_ADMIN_TABS = ['overview', 'accounts', 'messages', 'pricing'];

function admin_icon(string $key, string $class = 'ic'): string
{
    $paths = SLEEK_ADMIN_NAV[$key][2] ?? '';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

function admin_layout_open(string $title, array $admin, ?string $notice = null, bool $noticeIsError = false, string $active = 'overview'): void
{
    $brand  = (string) sleek_config('brand_name');
    $unread = function_exists('sleek_contact_unread_count') ? sleek_contact_unread_count() : 0;
    $badge  = fn(string $key) => $key === 'messages' && $unread > 0 ? '<span class="badge" aria-label="' . $unread . ' unread">' . ($unread > 99 ? '99+' : $unread) . '</span>' : '';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#14171c">
<title><?= e($title) ?> — <?= e($brand) ?> admin</title>
<style>
:root{--bg:#14171c;--card:#1c2027;--card2:#21262e;--line:#2c323b;--field:#454c57;--text:#f5f6f7;--muted:#a8aeb8;--faint:#6b727e;--gold:#f2b705;--goldwash:rgba(242,183,5,.12);--ok:#1f3326;--okline:#2f5a3c;--bad:#3a2626;--badline:#6b3a3a;--side:248px;--tabs:64px}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:var(--gold)}
h1,h2,h3{line-height:1.2;margin:0}
h1{font-family:Georgia,serif;font-size:26px}
h2{font-family:Georgia,serif;font-size:20px}
h3{font-size:15px;font-weight:600}
p{margin:.5em 0}
.muted,small{color:var(--muted)}
small{font-size:13px}
code{font-size:13px;background:var(--card2);padding:1px 6px;border-radius:6px}

/* ---- sidebar (desktop) ---- */
.side{display:none}
.brand{display:flex;align-items:center;gap:10px;font-family:Georgia,serif;font-size:18px;font-weight:600;color:var(--text);text-decoration:none}
.brand .dot{width:10px;height:10px;border-radius:50%;background:var(--gold);box-shadow:0 0 0 4px var(--goldwash)}
.brand small{display:block;font-family:Inter,system-ui,sans-serif;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);font-weight:500}
.nav{list-style:none;margin:0;padding:0;display:grid;gap:2px}
.nav a{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;color:var(--muted);text-decoration:none;font-weight:500;min-height:44px}
.nav a:hover{background:var(--card2);color:var(--text)}
.nav a[aria-current=page]{background:var(--goldwash);color:var(--gold)}
.ic{width:20px;height:20px;flex:0 0 auto}
.badge{margin-left:auto;background:var(--gold);color:var(--bg);border-radius:99px;font-size:11px;font-weight:700;padding:1px 7px;line-height:1.5}
.me{border-top:1px solid var(--line);padding-top:14px;display:grid;gap:8px;font-size:13px}
.me .who{display:flex;align-items:center;gap:10px;min-width:0}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--goldwash);color:var(--gold);display:grid;place-items:center;font-weight:700;flex:0 0 auto}
.me .who span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.me a,.me button.linkish{color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:8px;padding:6px 4px;background:none;border:0;font:inherit;font-size:13px;cursor:pointer;min-height:0;width:auto}
.me a:hover,.me button.linkish:hover{color:var(--text)}

/* ---- top bar + bottom tabs (phone/tablet) ---- */
.topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;padding-top:max(10px,env(safe-area-inset-top));background:rgba(20,23,28,.92);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.topbar .page{font-weight:600;font-size:15px;color:var(--muted)}
.tabs{position:fixed;left:0;right:0;bottom:0;z-index:20;display:grid;grid-template-columns:repeat(5,1fr);background:rgba(28,32,39,.96);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-top:1px solid var(--line);padding-bottom:env(safe-area-inset-bottom)}
.tabs a,.tabs button{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:var(--tabs);color:var(--faint);text-decoration:none;font-size:11px;font-weight:600;background:none;border:0;border-radius:0;padding:6px 2px;cursor:pointer;width:auto;font-family:inherit}
.tabs a[aria-current=page]{color:var(--gold)}
.tabs .ic{width:22px;height:22px}
.tabs .badge{position:absolute;top:6px;left:calc(50% + 4px);margin:0}
.drawer{position:fixed;inset:0;z-index:30;display:none}
.drawer.open{display:block}
.drawer .scrim{position:absolute;inset:0;background:rgba(0,0,0,.55)}
.drawer .panel{position:absolute;left:0;right:0;bottom:0;max-height:85vh;overflow:auto;background:var(--card);border-top:1px solid var(--line);border-radius:18px 18px 0 0;padding:10px 16px calc(20px + env(safe-area-inset-bottom));animation:up .18s ease-out}
.drawer .grab{width:40px;height:4px;border-radius:4px;background:var(--field);margin:4px auto 12px}
@keyframes up{from{transform:translateY(24px);opacity:.4}to{transform:none;opacity:1}}
@media (prefers-reduced-motion:reduce){.drawer .panel{animation:none}}

/* ---- content ---- */
.content{max-width:1120px;margin:0 auto;padding:20px 16px calc(var(--tabs) + 32px + env(safe-area-inset-bottom))}
.head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:4px}
.head .sub{margin:4px 0 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:16px;min-width:0}
.card>h2,.card>h3{margin-bottom:4px}
.card-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.card-head a{font-size:14px;text-decoration:none}
.notice{padding:12px 14px;background:var(--ok);border:1px solid var(--okline);border-radius:10px;margin:16px 0 0;overflow-wrap:anywhere}
.notice.bad{background:var(--bad);border-color:var(--badline)}
.grid2{display:grid;gap:16px}
.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;min-width:0;text-decoration:none;color:inherit;display:block}
a.stat:hover{border-color:var(--field)}
.stat .label{font-size:13px;color:var(--muted)}
.stat .value{font-size:26px;font-weight:600;letter-spacing:-.01em;margin-top:2px;overflow-wrap:anywhere}
.stat .hint{font-size:12px;color:var(--faint)}
label{display:grid;gap:4px;min-width:0}
label>span.l{font-size:13px;color:var(--muted)}
input,select,textarea,button{font:inherit;font-size:16px;color:var(--text);background:var(--bg);border:1px solid var(--field);border-radius:10px;padding:10px 12px;min-height:44px;width:100%;min-width:0}
input[type=checkbox]{width:auto;min-height:0;accent-color:var(--gold)}
input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid var(--gold);outline-offset:2px}
textarea{resize:vertical}
button,.btn{cursor:pointer;background:var(--gold);color:var(--bg);font-weight:600;border:1px solid var(--gold);width:auto;display:inline-flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;padding:10px 16px;border-radius:10px;min-height:44px;font-size:15px}
button.ghost,.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--field)}
button.danger{background:transparent;color:#ff8a8a;border:1px solid var(--badline)}
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
tbody tr:last-child td{border-bottom:0}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600}
td{overflow-wrap:anywhere}
.role{display:inline-block;border:1px solid var(--field);border-radius:99px;padding:0 8px;font-size:12px;white-space:nowrap}
.role.super_admin{border-color:var(--gold);color:var(--gold)}
.status{display:inline-block;border-radius:99px;padding:0 8px;font-size:12px;border:1px solid var(--field);white-space:nowrap}
.status.success{border-color:var(--okline);color:#8fe0aa}
.status.failed{border-color:var(--badline);color:#ff9d9d}
.pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.packs{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:12px 0}
.pack{display:grid;gap:8px;margin:0;border:1px solid var(--line);border-radius:12px;padding:12px}
.pack legend{color:var(--muted);padding:0 4px}
.keys{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));margin-top:12px}
.keys .card{margin:0}
.msg{border-top:1px solid var(--line);padding:14px 0}
.msg:first-of-type{border-top:0}
.msg.unread{border-left:3px solid var(--gold);padding-left:12px}
.msg p{white-space:pre-wrap;overflow-wrap:anywhere}
.kv{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:8px 0}
.kv dt{color:var(--muted)}.kv dd{margin:0;overflow-wrap:anywhere}
.plus{color:#8fe0aa}.minus{color:#ff9d9d}
.list{list-style:none;margin:8px 0 0;padding:0}
.list li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--line);min-width:0}
.list li:first-child{border-top:0}
.list .main{min-width:0}
.list .main a,.list .main span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.list .main small{display:block}
.list .end{text-align:right;flex:0 0 auto}
.todo{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:8px}
.todo li a{display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:var(--text);min-height:44px}
.todo li a:hover{border-color:var(--field)}
.todo .mark{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;flex:0 0 auto;font-size:13px;font-weight:700}
.todo .done .mark{background:var(--ok);color:#8fe0aa;border:1px solid var(--okline)}
.todo .open .mark{border:1.5px dashed var(--field);color:var(--faint)}
.todo .done span.t{color:var(--muted)}
.todo .go{margin-left:auto;color:var(--gold);font-size:13px;font-weight:600;white-space:nowrap}

/* sign-ups chart: single series, so no legend — the title names it */
.chart{position:relative;margin-top:12px}
.bars{display:grid;grid-template-columns:repeat(14,1fr);align-items:end;gap:0;height:150px;border-bottom:1px solid var(--line);position:relative;margin-right:28px}
.bars .gl{position:absolute;left:0;right:0;border-top:1px dashed var(--line);pointer-events:none}
.bars .gl span{position:absolute;right:-28px;width:24px;top:-8px;font-size:11px;color:var(--faint);text-align:left}
.day{height:100%;display:flex;align-items:flex-end;justify-content:center;padding:0 1px;cursor:default;outline:none;position:relative}
.day i{display:block;width:100%;max-width:24px;background:var(--gold);border-radius:4px 4px 0 0;min-height:0}
.day.zero i{background:var(--line);height:2px!important;border-radius:2px}
.day:hover i,.day:focus i{filter:brightness(1.15)}
.axis{display:grid;grid-template-columns:repeat(14,1fr);margin:6px 28px 0 0;font-size:11px;color:var(--faint);text-align:center}
.tip{position:absolute;z-index:3;pointer-events:none;background:var(--card2);border:1px solid var(--field);border-radius:8px;padding:6px 10px;font-size:13px;white-space:nowrap;transform:translate(-50%,-100%);margin-top:-8px;box-shadow:0 6px 20px rgba(0,0,0,.35)}
.tip b{color:var(--text)}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}

@media (max-width:719px){
  table.stack thead{display:none}
  table.stack,table.stack tbody,table.stack tr,table.stack td{display:block;width:100%}
  table.stack tr{border:1px solid var(--line);border-radius:12px;padding:6px 12px;margin-bottom:10px}
  table.stack td{display:flex;justify-content:space-between;gap:12px;border:0;padding:6px 0;text-align:right}
  table.stack td::before{content:attr(data-label);color:var(--muted);text-align:left;flex:0 0 auto}
  table.stack td.full,table.stack td:first-child{display:block;text-align:left}
  table.stack td.full::before,table.stack td:first-child::before{display:none}
  table.stack td:first-child{font-weight:600;font-size:16px;padding-top:8px}
  .inline>button{flex:1 1 auto}
  .axis span:nth-child(even){visibility:hidden}
}
@media (min-width:640px){
  .content{padding:28px 24px calc(var(--tabs) + 40px)}
  .card{padding:20px}
  .stats{grid-template-columns:repeat(3,minmax(0,1fr))}
  .form-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
  .form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
  h1{font-size:30px}
}
@media (min-width:1024px){
  .topbar,.tabs,.drawer{display:none!important}
  .side{display:flex;flex-direction:column;gap:20px;position:fixed;inset:0 auto 0 0;width:var(--side);padding:22px 14px;background:var(--card);border-right:1px solid var(--line);overflow-y:auto}
  .side .nav{flex:1}
  .shell{margin-left:var(--side)}
  .content{padding:32px 32px 56px}
  .grid2{grid-template-columns:repeat(2,minmax(0,1fr))}
  .grid2 .card{margin-top:0;display:flex;flex-direction:column}
  .grid2 .chart{flex:1;display:flex;flex-direction:column}
  .grid2 .bars{flex:1;height:auto;min-height:150px}
  .grid2{margin-top:16px}
  .stats{grid-template-columns:repeat(6,minmax(0,1fr))}
}
</style>
</head>
<body>
<?php
    $nav = function (bool $withBadges = true) use ($active, $badge): void {
        echo '<ul class="nav">';
        foreach (SLEEK_ADMIN_NAV as $key => [$label, $href]) {
            $cur = $key === $active ? ' aria-current="page"' : '';
            echo '<li><a href="' . e($href) . '"' . $cur . '>' . admin_icon($key) . '<span>' . e($label) . '</span>' . ($withBadges ? $badge($key) : '') . '</a></li>';
        }
        echo '</ul>';
    };
    $initial = mb_strtoupper(mb_substr(trim((string) $admin['name']), 0, 1)) ?: 'A';
    $me = function () use ($admin, $initial): void { ?>
        <div class="me">
            <div class="who"><span class="avatar" aria-hidden="true"><?= e($initial) ?></span><span><strong><?= e($admin['name']) ?></strong><br><small><?= e($admin['email']) ?></small></span></div>
            <a href="dashboard"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to the app</a>
            <form method="POST" action="logout" style="margin:0"><?= csrf_field() ?><button type="submit" class="linkish"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/></svg>Sign out</button></form>
        </div>
    <?php };
?>
<aside class="side" aria-label="Admin navigation">
    <a class="brand" href="admin"><span class="dot"></span><span><?= e($brand) ?><small>Admin</small></span></a>
    <nav aria-label="Admin sections"><?php $nav(); ?></nav>
    <?php $me(); ?>
</aside>

<header class="topbar">
    <a class="brand" href="admin"><span class="dot"></span><span><?= e($brand) ?><small>Admin</small></span></a>
    <span class="page"><?= e(SLEEK_ADMIN_NAV[$active][0] ?? $title) ?></span>
</header>

<nav class="tabs" aria-label="Admin sections">
    <?php foreach (SLEEK_ADMIN_TABS as $key): [$label, $href] = SLEEK_ADMIN_NAV[$key]; ?>
        <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= admin_icon($key) ?><span><?= e($label) ?></span><?= $badge($key) ?></a>
    <?php endforeach; ?>
    <button type="button" id="more" aria-controls="drawer" aria-expanded="false"<?= in_array($active, SLEEK_ADMIN_TABS, true) ? '' : ' style="color:var(--gold)"' ?>>
        <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span>More</span>
    </button>
</nav>

<div class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="All admin sections">
    <div class="scrim" data-close></div>
    <div class="panel">
        <div class="grab" aria-hidden="true"></div>
        <nav aria-label="All admin sections"><?php $nav(); ?></nav>
        <div style="margin-top:14px"><?php $me(); ?></div>
    </div>
</div>

<div class="shell">
<main class="content">
<div class="head">
    <div>
        <h1><?= e($title) ?></h1>
    </div>
</div>
<?php if ($notice): ?><p class="notice<?= $noticeIsError ? ' bad' : '' ?>" role="<?= $noticeIsError ? 'alert' : 'status' ?>"><?= e($notice) ?></p><?php endif; ?>
    <?php
}

function admin_layout_close(): void
{
    ?>
</main>
</div>
<script>
(function () {
    var more = document.getElementById('more'), drawer = document.getElementById('drawer');
    if (!more || !drawer) return;
    function set(open) {
        drawer.classList.toggle('open', open);
        more.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) { var a = drawer.querySelector('a'); if (a) a.focus(); } else { more.focus(); }
    }
    more.addEventListener('click', function () { set(!drawer.classList.contains('open')); });
    drawer.addEventListener('click', function (e) { if (e.target.hasAttribute('data-close')) set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('open')) set(false); });
})();
</script>
</body>
</html>
    <?php
}

/** Inline field error, if any. */
function admin_err(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<span class="err">' . e($errors[$field]) . '</span>' : '';
}
