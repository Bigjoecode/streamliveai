# SleekAI operations dashboard

A standalone PHP dashboard that charts your access, error, billing and usage logs.
No database, no composer, no build step — it reads files out of `data/` and renders.

## Run it

```
http://localhost/sleekai.com.ng/ops-dashboard/
```

Requires PHP 8.1+ (8.3 is what this was built and tested against; it uses `match`,
enums-free arrow fns, `array_is_list` and `str_contains`). XAMPP already provides it.

Out of the box it shows generated sample data so you can see it working. To switch
to real data:

```bash
rm ops-dashboard/data/sample-*          # drop the fixtures
# copy your exports into ops-dashboard/data/
```

Then reload. Formats are auto-detected per file — see [data/README.md](data/README.md)
for what each stream expects and how a file gets routed.

To regenerate the samples: `php ops-dashboard/tools/make-samples.php`

## Layout

| Path | What it is |
|---|---|
| `index.php` | Page shell, design tokens, KPI tiles, panel markup |
| `assets/app.js` | Chart rendering, hover layer, table views |
| `lib/ingest.php` | Format detection and the four parsers |
| `lib/metrics.php` | Bucketing, top-N, status mapping, deltas |
| `lib/payload.php` | Decides what every panel shows |
| `tools/make-samples.php` | Generates the `sample-*` fixtures |
| `data/` | Drop your logs here (deny-listed from HTTP) |
| `cache/` | Parsed-file cache (deny-listed from HTTP) |

## How it reads data

Each file in `data/` is sniffed, parsed and normalised into one of four streams —
`access`, `error`, `usage`, `billing` — then merged and sorted by timestamp. Parsed
results are cached in `cache/`, keyed by file mtime and size, so a 3 MB access log is
only read once. Replace a file and it re-parses automatically; `rm -rf cache/*.cache`
forces a full re-parse.

Rows without a parseable timestamp are dropped and reported under **Unparsed** in the
Data sources panel at the bottom of the page. A large number there almost always means
the timestamp column wasn't matched — check the alias table in `data/README.md`.

## Chart conventions

The palette is not arbitrary. Series colours were validated against the `#14171C`
surface for lightness band, chroma, colour-vision separation and contrast (worst
adjacent CVD ΔE 13.0, worst adjacent normal-vision ΔE 19.3, all marks ≥3:1):

```
--s1 #C98500   --s2 #D55181   --s3 #008300   --s4 #9085E9   --s5 #E66767   --s6 #3987E5
```

Brand amber `#F2B705` is reserved for UI chrome (active range pill, focus rings,
crosshair) and is never used as a series colour — it sits outside the dark lightness
band and would not be distinguishable as a mark.

Status slots are reserved and always ship with a text label, never colour alone:
`--st-good #199E70`, `--st-warn #C98500`, `--st-crit #E5484D`, `--st-neutral #6B727E`
(3xx redirects are neutral, not an alert state).

Other rules the code holds to, so edits don't quietly break them:

- **Never a second y-axis.** Two measures of different scale get two panels.
- **Categorical hues are assigned in fixed order and never cycled.** A series past
  the 4th folds into "Other" (`sleek_cap_series`), so a colour always means one thing.
- **Value axes begin at zero** — a truncated baseline overstates the swing.
- **Every panel has a table view** (the `Table` toggle), so identity never rests on
  colour alone and the exact numbers are always reachable.
- **Windows snap to bucket boundaries**, so the first bucket is never a partial slice
  that reads as a fake dip. The final bucket is "so far" and will be partial — that's
  intentional and true of any live dashboard.
- Legend `align` is `start`, not `end`: end-alignment lays items out from a
  pre-measured total width, and any font-metric drift pushes the last item off-canvas.
  Charts also wait on `document.fonts.ready` for the same reason.

## Security

`data/` and `cache/` ship with an `.htaccess` that denies all HTTP access, because this
folder sits in a public web root and raw logs would otherwise be downloadable. **That
rule was not verified against a running Apache** — it needs `AllowOverride All` for the
vhost (XAMPP's default for `htdocs`). Confirm with:

```bash
curl -o /dev/null -w '%{http_code}\n' http://localhost/sleekai.com.ng/ops-dashboard/data/sample-access.log
# expect 403
```

If you get 200, the override isn't on and you should move `data/` outside the web root
(change the path in `index.php`) rather than rely on the deny rule.

**The dashboard page itself has no authentication.** Anyone who can reach the URL sees
your revenue and error data. Do not deploy it to `sleekai.com.ng` as-is — put it behind
your existing Laravel auth, an Apache basic-auth realm, or keep it local only.
