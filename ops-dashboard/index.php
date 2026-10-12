<?php
/**
 * StreamLive AI — operations dashboard.
 * Reads whatever is in ops-dashboard/data/ and charts it. See data/README.md.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../inc/bootstrap.php';
require_super_admin();
require_once __DIR__ . '/lib/payload.php';

$ingest  = sleek_ingest(__DIR__ . '/data', __DIR__ . '/cache');
$payload = sleek_build_payload($ingest, $_GET);
$meta    = $payload['meta'];

$RANGES = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => 'All'];

// e() is provided by the authenticated application bootstrap.

/** One chart card. The body is filled by assets/app.js. */
function panel(string $id, string $title, string $sub, string $span = 'half'): void { ?>
  <article class="card panel span-<?= e($span) ?>" data-panel="<?= e($id) ?>">
    <header class="panel-head">
      <div>
        <h3><?= e($title) ?></h3>
        <p class="sub"><?= e($sub) ?></p>
      </div>
      <button type="button" class="panel-toggle" aria-pressed="false">Table</button>
    </header>
    <div class="panel-body"><canvas></canvas></div>
    <div class="panel-table" role="region" aria-label="<?= e($title) ?> as a table"></div>
  </article>
<?php }

function kpi_tile(array $k, string $currency): void
{
    $v = $k['value'];
    $shown = match ($k['fmt']) {
        'money' => $currency . ' ' . number_format($v),
        'pct'   => number_format($v, 2) . '%',
        default => number_format($v),
    };
    $d = $k['delta'];
    $cls = 'flat';
    if ($d !== null && abs($d) >= 0.05) {
        $rising = $d > 0;
        $good   = ($k['good'] === 'up') === $rising;
        $cls    = $good ? 'good' : 'bad';
    }
    ?>
    <div class="card kpi">
      <p class="kpi-label"><?= e($k['label']) ?></p>
      <p class="kpi-value"><?= e($shown) ?></p>
      <p class="kpi-delta <?= e($cls) ?>">
        <?php if ($d === null): ?>
          <span class="muted">no prior period</span>
        <?php else: ?>
          <span class="arrow" aria-hidden="true"><?= $d > 0 ? '▲' : ($d < 0 ? '▼' : '■') ?></span>
          <?= e(sprintf('%+.1f%%', $d)) ?>
          <span class="muted">vs previous</span>
        <?php endif; ?>
      </p>
    </div>
<?php }
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>StreamLive AI — Operations</title>
<link rel="icon" type="image/png" sizes="32x32" href="../favicon-32x32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  color-scheme: dark;

  /* Surfaces & ink — lifted from the marketing site so the two read as one product */
  --surface-0:  #14171C;
  --surface-1:  #1C2027;
  --surface-2:  #232833;
  --border:     rgba(255, 255, 255, 0.08);
  --text-1:     #F5F6F7;
  --text-2:     #A8AEB8;
  --text-3:     #6B727E;
  --accent:     #F2B705;   /* brand amber — UI chrome only, never a series */

  /* Categorical series. Validated as a set against #14171C:
     worst adjacent CVD ΔE 13.0, worst adjacent normal-vision ΔE 19.3, all ≥3:1. */
  --s1: #C98500;
  --s2: #D55181;
  --s3: #008300;
  --s4: #9085E9;
  --s5: #E66767;
  --s6: #3987E5;

  /* Reserved status slots — always shipped with a text label, never colour alone. */
  --st-good:    #199E70;
  --st-warn:    #C98500;
  --st-crit:    #E5484D;
  --st-neutral: #6B727E;

  --grid:           rgba(255, 255, 255, 0.06);
  --crosshair:      rgba(242, 183, 5, 0.35);
  --tooltip-bg:     #232833;
  --tooltip-border: rgba(255, 255, 255, 0.12);
}

* { box-sizing: border-box; }
body {
  margin: 0;
  background: var(--surface-0);
  color: var(--text-1);
  font-family: Inter, system-ui, sans-serif;
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
::selection { background: var(--accent); color: var(--surface-0); }
h1, h2, h3 { font-family: Fraunces, Georgia, serif; font-weight: 600; margin: 0; }
a { color: var(--accent); }

.wrap { max-width: 1400px; margin: 0 auto; padding: 0 16px 64px; }

/* ---------------------------------------------------------------- header */
.topbar {
  position: sticky; top: 0; z-index: 20;
  background: rgba(20, 23, 28, 0.82);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border);
}
.topbar-in {
  max-width: 1400px; margin: 0 auto; padding: 14px 16px;
  display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between;
}
.brand { display: flex; align-items: baseline; gap: 10px; }
.brand h1 { font-size: 19px; letter-spacing: -0.01em; }
.brand .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 3px rgba(242,183,5,0.15); }
.brand .tag { color: var(--text-3); font-size: 12px; font-family: 'JetBrains Mono', monospace; }

.ranges { display: flex; flex-wrap: wrap; gap: 4px; background: var(--surface-1); border: 1px solid var(--border); border-radius: 10px; padding: 4px; }
.ranges a {
  padding: 6px 12px; border-radius: 7px; font-size: 12.5px; font-weight: 500;
  color: var(--text-2); text-decoration: none; white-space: nowrap;
  transition: background .15s ease, color .15s ease;
}
.ranges a:hover { color: var(--text-1); background: var(--surface-2); }
.ranges a[aria-current="true"] { background: var(--accent); color: var(--surface-0); font-weight: 600; }

/* ---------------------------------------------------------------- notices */
.notice {
  display: flex; gap: 12px; align-items: flex-start;
  margin: 20px 0 0; padding: 14px 16px; border-radius: 12px;
  border: 1px solid var(--border); background: var(--surface-1);
}
.notice.warn { border-color: rgba(242, 183, 5, 0.35); background: rgba(242, 183, 5, 0.07); }
.notice h2 { font-size: 15px; margin-bottom: 4px; }
.notice p { margin: 0; color: var(--text-2); font-size: 13px; }
.notice code, code.inline {
  font-family: 'JetBrains Mono', monospace; font-size: 12px;
  background: var(--surface-2); padding: 1px 6px; border-radius: 5px; color: var(--text-1);
}

/* ------------------------------------------------------------------ grid */
.section-head { margin: 34px 0 14px; display: flex; align-items: baseline; gap: 12px; }
.section-head h2 { font-size: 17px; }
.section-head span { color: var(--text-3); font-size: 12.5px; }

.grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 14px; }
.card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 14px; }
.span-full { grid-column: span 12; }
.span-two-thirds { grid-column: span 8; }
.span-half { grid-column: span 6; }
.span-third { grid-column: span 4; }

/* ------------------------------------------------------------------ KPIs */
.kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-top: 22px; }
.kpi { padding: 16px 18px; }
.kpi-label { margin: 0 0 8px; color: var(--text-3); font-size: 11.5px; font-weight: 500; letter-spacing: .04em; text-transform: uppercase; }
.kpi-value { margin: 0; font-family: 'JetBrains Mono', monospace; font-size: 25px; font-weight: 500; letter-spacing: -0.02em; line-height: 1.15; }
.kpi-delta { margin: 8px 0 0; font-size: 12px; font-family: 'JetBrains Mono', monospace; color: var(--text-2); display: flex; align-items: center; gap: 5px; }
.kpi-delta .arrow { font-size: 9px; }
.kpi-delta.good { color: var(--st-good); }
.kpi-delta.bad  { color: var(--st-crit); }
.kpi-delta .muted { color: var(--text-3); font-family: Inter, sans-serif; }

/* ---------------------------------------------------------------- panels */
.panel { display: flex; flex-direction: column; }
.panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 18px 6px; }
.panel-head h3 { font-size: 15px; }
.panel-head .sub { margin: 3px 0 0; color: var(--text-3); font-size: 12px; }
.panel-toggle {
  flex-shrink: 0; background: var(--surface-2); color: var(--text-2);
  border: 1px solid var(--border); border-radius: 7px;
  padding: 4px 10px; font-size: 11.5px; font-family: Inter, sans-serif; cursor: pointer;
  transition: color .15s ease, border-color .15s ease;
}
.panel-toggle:hover { color: var(--text-1); border-color: rgba(242, 183, 5, 0.4); }
.panel-toggle:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.panel-body { position: relative; height: 260px; padding: 6px 12px 14px; }
.span-full .panel-body { height: 300px; }
.panel-table { display: none; max-height: 320px; overflow: auto; padding: 0 18px 16px; }
.panel.show-table .panel-body { display: none; }
.panel.show-table .panel-table { display: block; }
.empty { color: var(--text-3); font-size: 13px; text-align: center; padding: 90px 0; margin: 0; }

.dv-table { width: 100%; border-collapse: collapse; font-family: 'JetBrains Mono', monospace; font-size: 12px; }
.dv-table th, .dv-table td { text-align: right; padding: 6px 8px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.dv-table th:first-child, .dv-table td:first-child { text-align: left; white-space: normal; word-break: break-word; }
.dv-table th { position: sticky; top: 0; background: var(--surface-1); color: var(--text-3); font-weight: 500; font-family: Inter, sans-serif; }
.dv-table td { color: var(--text-2); }

/* --------------------------------------------------------------- sources */
.sources { margin-top: 34px; padding: 16px 18px; }
.sources h2 { font-size: 15px; margin-bottom: 10px; }
.sources table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.sources th, .sources td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--border); }
.sources th { color: var(--text-3); font-weight: 500; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; }
.sources td { color: var(--text-2); font-family: 'JetBrains Mono', monospace; }
.sources td:first-child { color: var(--text-1); }
.pill { display: inline-block; padding: 1px 8px; border-radius: 20px; font-size: 11px; border: 1px solid var(--border); background: var(--surface-2); }
.pill.unknown { border-color: rgba(229, 72, 77, 0.4); color: var(--st-crit); }

@media (max-width: 1100px) {
  .kpis { grid-template-columns: repeat(3, 1fr); }
  .span-half, .span-third, .span-two-thirds { grid-column: span 12; }
}
@media (max-width: 620px) {
  .kpis { grid-template-columns: repeat(2, 1fr); }
  .kpi-value { font-size: 21px; }
  .panel-body { height: 230px; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-in">
    <div class="brand">
      <span class="dot" aria-hidden="true"></span>
      <h1>StreamLive AI</h1>
      <span class="tag">operations</span>
    </div>
    <nav class="ranges" aria-label="Date range">
      <?php foreach ($RANGES as $key => $label): ?>
        <a href="?range=<?= e($key) ?>" <?= $meta['range'] === $key ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<main class="wrap">

<?php if ($meta['empty']): ?>
  <div class="notice warn">
    <div>
      <h2>No log data yet</h2>
      <p>
        Drop your exports into <code>ops-dashboard/data/</code> and reload — formats are detected
        automatically. See <code>ops-dashboard/data/README.md</code> for what each stream expects.
        To see the dashboard working first, run <code>php ops-dashboard/tools/make-samples.php</code>.
      </p>
    </div>
  </div>
<?php else: ?>

  <?php if ($meta['samples']): ?>
    <div class="notice warn">
      <div>
        <h2>Showing generated sample data</h2>
        <p>
          Every file in <code>ops-dashboard/data/</code> is a <code>sample-*</code> fixture. Delete them and drop
          your real exports in the same folder to switch over — nothing else needs changing.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <section class="kpis" aria-label="Headline metrics">
    <?php foreach ($payload['kpis'] as $k) kpi_tile($k, $meta['currency']); ?>
  </section>

  <div class="section-head">
    <h2>Traffic</h2>
    <span><?= number_format($meta['counts']['access']) ?> requests in range</span>
  </div>
  <div class="grid">
    <?php
      panel('traffic',   'Requests over time',   'Every request in the access log, bucketed by ' . $meta['interval'], 'full');
      panel('statusMix', 'Response status mix',  'Stacked by HTTP status class');
      panel('topPaths',  'Most requested paths', 'Numeric path segments folded to :id');
      panel('slowest',   'Slowest endpoints',    'p95 response time, paths with 20+ requests');
      panel('topActions','Credits by feature',   'Total credits consumed per action');
    ?>
  </div>

  <div class="section-head">
    <h2>Reliability</h2>
    <span><?= number_format($meta['counts']['error']) ?> log entries in range</span>
  </div>
  <div class="grid">
    <?php
      panel('levelMix',  'Log volume by severity', 'Stacked by log level', 'two-thirds');
      panel('topErrors', 'Most frequent errors',   'ERROR and above, grouped by message', 'third');
    ?>
  </div>

  <div class="section-head">
    <h2>Revenue &amp; credits</h2>
    <span><?= number_format($meta['counts']['billing']) ?> billing rows · <?= number_format($meta['counts']['usage']) ?> usage rows</span>
  </div>
  <div class="grid">
    <?php
      panel('revenue',         'Revenue over time',   'Successful payments only, in ' . $meta['currency'], 'full');
      panel('revByPlan',       'Revenue by plan',     'Stacked; plans past the top 4 fold into Other');
      panel('planSplit',       'Total by plan',       'Successful payments across the whole range');
      panel('creditsByAction', 'Credit burn by feature', 'Stacked; features past the top 4 fold into Other');
    ?>
  </div>

<?php endif; ?>

  <section class="card sources">
    <h2>Data sources</h2>
    <?php if (!$meta['files']): ?>
      <p style="color:var(--text-2);margin:0">
        Nothing in <code class="inline">ops-dashboard/data/</code> yet.
      </p>
    <?php else: ?>
      <table>
        <thead><tr><th>File</th><th>Detected</th><th>Stream</th><th>Rows</th><th>Unparsed</th><th>Size</th></tr></thead>
        <tbody>
          <?php foreach ($meta['files'] as $f): ?>
            <tr>
              <td><?= e($f['name']) ?></td>
              <td><span class="pill <?= $f['format'] === 'unknown' ? 'unknown' : '' ?>"><?= e($f['format']) ?></span></td>
              <td><?= e($f['stream'] ?? '—') ?></td>
              <td><?= number_format($f['count']) ?></td>
              <td><?= $f['skipped'] ? number_format($f['skipped']) : '—' ?></td>
              <td><?= number_format($f['bytes'] / 1024, 1) ?> KB</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="color:var(--text-3);font-size:12px;margin:12px 0 0">
        Window <?= e(date('j M Y', $meta['from'])) ?> – <?= e(date('j M Y', $meta['to'])) ?>
        · <?= e($meta['interval']) ?> buckets · built <?= e($meta['generated']) ?>.
        Parsed files are cached in <code class="inline">ops-dashboard/cache/</code>; delete it to force a re-parse.
      </p>
    <?php endif; ?>
  </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>window.SLEEK = <?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
