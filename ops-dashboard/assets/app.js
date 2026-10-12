/* SleekAI dashboard — chart layer.
 *
 * Every chart is built from the same spec shape so colour, mark geometry and
 * the hover layer stay identical across panels. Series colours come from the
 * CSS custom properties in index.php, never from literals here.
 */

const CSS = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

const TOKENS = {
  get surface() { return CSS('--surface-1'); },
  get text1()   { return CSS('--text-1'); },
  get text2()   { return CSS('--text-2'); },
  get text3()   { return CSS('--text-3'); },
  get grid()    { return CSS('--grid'); },
  get series()  { return [1, 2, 3, 4, 5, 6].map((i) => CSS(`--s${i}`)); },
  status: {
    '2xx': () => CSS('--st-good'),
    '3xx': () => CSS('--st-neutral'),
    '4xx': () => CSS('--st-warn'),
    '5xx': () => CSS('--st-crit'),
    good: () => CSS('--st-good'),
    warning: () => CSS('--st-warn'),
    critical: () => CSS('--st-crit'),
  },
};

const STATUS_LABEL = {
  '2xx': '2xx success', '3xx': '3xx redirect', '4xx': '4xx client error', '5xx': '5xx server error',
  good: 'Info', warning: 'Warning', critical: 'Error / critical',
};

/* ------------------------------------------------------------- formatting */

const fmtInt = (n) => Math.round(n).toLocaleString();
const fmtCompact = (n) =>
  Math.abs(n) >= 1000 ? new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(n) : fmtInt(n);
const fmtMoney = (n, cur) => `${cur} ${Math.abs(n) >= 100000 ? fmtCompact(n) : fmtInt(n)}`;
const fmtMs = (n) => (n >= 1000 ? `${(n / 1000).toFixed(2)} s` : `${Math.round(n)} ms`);

function fmtValue(n, kind, cur) {
  if (kind === 'money') return fmtMoney(n, cur);
  if (kind === 'ms') return fmtMs(n);
  if (kind === 'pct') return `${n.toFixed(2)}%`;
  return fmtInt(n);
}

/** Bucket key -> short axis label. */
function axisLabel(key, interval) {
  const d = new Date(key.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return key;
  if (interval === 'hour') return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

/* ------------------------------------------------- crosshair hover layer */

const crosshair = {
  id: 'crosshair',
  afterDatasetsDraw(chart) {
    const active = chart.tooltip?.getActiveElements?.() ?? [];
    if (!active.length) return;
    const { ctx, chartArea } = chart;
    const x = active[0].element.x;
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(x, chartArea.top);
    ctx.lineTo(x, chartArea.bottom);
    ctx.lineWidth = 1;
    ctx.strokeStyle = CSS('--crosshair');
    ctx.stroke();
    ctx.restore();
  },
};

/* ------------------------------------------------------- shared defaults */

function baseOptions({ interval, valueKind, currency, stacked = false, horizontal = false, legend = false }) {
  const valueAxis = {
    stacked,
    // Counts and money are magnitudes: a truncated baseline overstates the swing.
    beginAtZero: true,
    border: { display: false },
    grid: { color: TOKENS.grid, drawTicks: false },
    ticks: {
      color: TOKENS.text3,
      font: { family: 'JetBrains Mono, monospace', size: 11 },
      padding: 8,
      callback: (v) => (valueKind === 'money' ? fmtCompact(v) : valueKind === 'ms' ? `${v}` : fmtCompact(v)),
    },
  };
  const catAxis = {
    stacked,
    border: { display: false },
    grid: { display: false },
    ticks: {
      color: TOKENS.text3,
      font: { family: 'Inter, sans-serif', size: 11 },
      maxRotation: 0,
      autoSkipPadding: 16,
      padding: 6,
    },
  };

  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: horizontal ? 'nearest' : 'index', intersect: false },
    layout: { padding: { top: 8, right: 8 } },
    scales: horizontal ? { x: valueAxis, y: catAxis } : { x: catAxis, y: valueAxis },
    plugins: {
      legend: legend
        ? {
            display: true,
            position: 'top',
            // 'start', not 'end': end-alignment lays items out from a pre-measured
            // total width, so any measurement drift pushes the last item off-canvas.
            align: 'start',
            labels: {
              color: TOKENS.text2,
              font: { family: 'Inter, sans-serif', size: 11 },
              boxWidth: 10,
              boxHeight: 10,
              usePointStyle: true,
              pointStyle: 'rectRounded',
              padding: 14,
            },
          }
        : { display: false },
      tooltip: {
        backgroundColor: CSS('--tooltip-bg'),
        borderColor: CSS('--tooltip-border'),
        borderWidth: 1,
        titleColor: TOKENS.text1,
        bodyColor: TOKENS.text2,
        titleFont: { family: 'Inter, sans-serif', size: 12, weight: '600' },
        bodyFont: { family: 'JetBrains Mono, monospace', size: 12 },
        padding: 10,
        cornerRadius: 8,
        displayColors: true,
        boxWidth: 8,
        boxHeight: 8,
        usePointStyle: true,
        callbacks: {
          label: (c) => {
            const v = horizontal ? c.parsed.x : c.parsed.y;
            const name = c.dataset.label && c.dataset.label !== 'all' ? `${c.dataset.label}: ` : '';
            return `  ${name}${fmtValue(v, valueKind, currency)}`;
          },
        },
      },
    },
  };
}

/* ---------------------------------------------------------- chart makers */

function lineChart(canvas, { labels, series, interval, valueKind, currency }) {
  const names = Object.keys(series);
  const multi = names.length > 1;
  const colors = TOKENS.series;

  const datasets = names.map((name, i) => ({
    label: name === 'all' ? '' : name,
    data: series[name],
    borderColor: colors[i % colors.length],
    backgroundColor: 'transparent',
    borderWidth: 2,
    tension: 0.25,
    pointRadius: 0,
    pointHoverRadius: 5,
    pointHoverBorderWidth: 2,
    pointHoverBorderColor: TOKENS.surface,
    pointHoverBackgroundColor: colors[i % colors.length],
    pointHitRadius: 16,
  }));

  return new Chart(canvas, {
    type: 'line',
    data: { labels: labels.map((l) => axisLabel(l, interval)), datasets },
    options: baseOptions({ interval, valueKind, currency, legend: multi }),
    plugins: [crosshair],
  });
}

function barChart(canvas, { labels, series, interval, valueKind, currency, stacked = false, statusKey = null }) {
  const names = Object.keys(series);
  const multi = names.length > 1;
  const colors = TOKENS.series;

  const datasets = names.map((name, i) => {
    const color = statusKey && TOKENS.status[name] ? TOKENS.status[name]() : colors[i % colors.length];
    const isTop = i === names.length - 1;
    return {
      label: statusKey ? STATUS_LABEL[name] ?? name : name === 'all' ? '' : name,
      data: series[name],
      backgroundColor: color,
      // 2px surface gap between stacked segments and adjacent bars alike.
      // borderRadius stays a NUMBER: the object form renders a blank legend swatch.
      borderColor: TOKENS.surface,
      borderWidth: stacked ? 2 : 0,
      borderRadius: stacked ? (isTop ? 4 : 0) : 4,
      borderSkipped: stacked ? 'bottom' : false,
      maxBarThickness: 28,
      categoryPercentage: 0.78,
      barPercentage: 0.92,
    };
  });

  return new Chart(canvas, {
    type: 'bar',
    data: { labels: labels.map((l) => axisLabel(l, interval)), datasets },
    options: baseOptions({ interval, valueKind, currency, stacked, legend: multi || !!statusKey }),
  });
}

/* Ranked-bar label budget. The axis width is pinned so a long label truncates
   with an ellipsis instead of overflowing off the left edge of the canvas;
   the untruncated text is still in the tooltip and the table view. */
const LABEL_MAX = 22;
const LABEL_AXIS_W = 168;

/** Horizontal ranked bars with direct value labels at the data end. */
function rankChart(canvas, { data, valueKind, currency }) {
  const entries = Object.entries(data).filter(([, v]) => v > 0);
  const labels = entries.map(([k]) => k);
  const values = entries.map(([, v]) => v);
  const accent = CSS('--s1');

  const directLabels = {
    id: 'directLabels',
    afterDatasetsDraw(chart) {
      const { ctx } = chart;
      const meta = chart.getDatasetMeta(0);
      ctx.save();
      ctx.font = '11px JetBrains Mono, monospace';
      ctx.fillStyle = TOKENS.text2;
      ctx.textBaseline = 'middle';
      meta.data.forEach((bar, i) => {
        const text = fmtValue(values[i], valueKind, currency);
        const w = ctx.measureText(text).width;
        // Outside the data end when it fits, otherwise tucked inside it — the
        // longest bar is the one that matters most, so it never loses its value.
        if (bar.x + 8 + w < chart.chartArea.right + 46) {
          ctx.fillStyle = TOKENS.text2;
          ctx.fillText(text, bar.x + 8, bar.y);
        } else {
          ctx.fillStyle = CSS('--surface-0');
          ctx.fillText(text, bar.x - w - 8, bar.y);
        }
      });
      ctx.restore();
    },
  };

  return new Chart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: '',
        data: values,
        backgroundColor: accent,
        borderRadius: 4,
        borderSkipped: false,
        maxBarThickness: 18,
        categoryPercentage: 0.82,
      }],
    },
    options: (() => {
      const o = baseOptions({ valueKind, currency, horizontal: true });
      o.indexAxis = 'y';
      o.layout.padding.right = 54;   // room for the direct labels
      o.scales.y.ticks.font = { family: 'JetBrains Mono, monospace', size: 11 };
      // Every bar keeps its label: autoSkip would silently drop every other one.
      o.scales.y.ticks.autoSkip = false;
      o.scales.y.ticks.callback = function (v) {
        const s = this.getLabelForValue(v);
        return s.length > LABEL_MAX ? `${s.slice(0, LABEL_MAX - 1)}…` : s;
      };
      // Pin the axis width so a long label is truncated, never clipped off-canvas.
      o.scales.y.afterFit = (scale) => { scale.width = LABEL_AXIS_W; };
      o.scales.x.ticks.display = false;
      o.scales.x.grid.display = false;
      return o;
    })(),
    plugins: [directLabels],
  });
}

/* ------------------------------------------------------------ table view */

function seriesTable(labels, series, interval, valueKind, currency) {
  const names = Object.keys(series);
  const head = `<tr><th>Bucket</th>${names.map((n) => `<th>${STATUS_LABEL[n] ?? (n === 'all' ? 'Value' : n)}</th>`).join('')}</tr>`;
  const body = labels
    .map((l, i) => {
      const cells = names.map((n) => `<td>${fmtValue(series[n][i] ?? 0, valueKind, currency)}</td>`).join('');
      return `<tr><td>${axisLabel(l, interval)}</td>${cells}</tr>`;
    })
    .join('');
  return `<table class="dv-table"><thead>${head}</thead><tbody>${body}</tbody></table>`;
}

function rankTable(data, valueKind, currency) {
  const rows = Object.entries(data)
    .map(([k, v]) => `<tr><td>${escapeHtml(k)}</td><td>${fmtValue(v, valueKind, currency)}</td></tr>`)
    .join('');
  return `<table class="dv-table"><thead><tr><th>Item</th><th>Value</th></tr></thead><tbody>${rows}</tbody></table>`;
}

const escapeHtml = (s) =>
  String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ----------------------------------------------------------------- boot */

const PANELS = [
  { id: 'traffic',         kind: 'line', valueKind: 'int' },
  { id: 'statusMix',       kind: 'bar',  valueKind: 'int', stacked: true, statusKey: true },
  { id: 'revenue',         kind: 'line', valueKind: 'money' },
  { id: 'revByPlan',       kind: 'bar',  valueKind: 'money', stacked: true },
  { id: 'creditsByAction', kind: 'bar',  valueKind: 'int', stacked: true },
  { id: 'levelMix',        kind: 'bar',  valueKind: 'int', stacked: true, statusKey: true },
  { id: 'topPaths',        kind: 'rank', valueKind: 'int' },
  { id: 'topActions',      kind: 'rank', valueKind: 'int' },
  { id: 'slowest',         kind: 'rank', valueKind: 'ms' },
  { id: 'planSplit',       kind: 'rank', valueKind: 'money' },
  { id: 'topErrors',       kind: 'rank', valueKind: 'int' },
];

function boot() {
  const D = window.SLEEK;
  if (!D || D.meta.empty) return;

  Chart.defaults.font.family = 'Inter, sans-serif';
  Chart.defaults.color = TOKENS.text2;

  const { labels, panels, meta } = D;
  const currency = meta.currency;

  PANELS.forEach((p) => {
    const card = document.querySelector(`[data-panel="${p.id}"]`);
    if (!card) return;
    const canvas = card.querySelector('canvas');
    const payload = panels[p.id];
    const hasData = p.kind === 'rank'
      ? payload && Object.values(payload.data).some((v) => v > 0)
      : payload && Object.keys(payload.series).length > 0;

    if (!hasData) {
      card.querySelector('.panel-body').innerHTML =
        '<p class="empty">No data for this range.</p>';
      card.querySelector('.panel-toggle')?.remove();
      return;
    }

    const opts = { labels, interval: meta.interval, valueKind: p.valueKind, currency, ...p };
    if (p.kind === 'line') lineChart(canvas, { ...opts, series: payload.series });
    else if (p.kind === 'bar') barChart(canvas, { ...opts, series: payload.series });
    else rankChart(canvas, { ...opts, data: payload.data });

    // Table view — identity never rests on colour alone.
    const toggle = card.querySelector('.panel-toggle');
    const tableHost = card.querySelector('.panel-table');
    toggle?.addEventListener('click', () => {
      const showing = card.classList.toggle('show-table');
      if (showing && !tableHost.dataset.built) {
        tableHost.innerHTML = p.kind === 'rank'
          ? rankTable(payload.data, p.valueKind, currency)
          : seriesTable(labels, payload.series, meta.interval, p.valueKind, currency);
        tableHost.dataset.built = '1';
      }
      toggle.textContent = showing ? 'Chart' : 'Table';
      toggle.setAttribute('aria-pressed', String(showing));
    });
  });
}

/* Wait for the web fonts before charting: Chart.js measures legend and tick text
   once, and measuring against the fallback font mis-sizes every label it lays out. */
document.addEventListener('DOMContentLoaded', () => {
  const fonts = document.fonts?.ready ?? Promise.resolve();
  fonts.then(boot).catch(boot);
});
