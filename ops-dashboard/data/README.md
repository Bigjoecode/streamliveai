# Drop your logs here

Put exports in this folder and reload the dashboard. Format is detected per file —
there is nothing to configure and no naming convention to follow.

Supported: `.log`, `.txt`, `.csv`, `.tsv`, `.json` (array or JSON Lines), and any of
those gzipped (`.gz`). Files starting with `.` or `README` are ignored.

Parsed results are cached in `../cache/` keyed by file mtime and size, so a large
access log is only read once. Replace a file and it re-parses automatically; delete
`../cache/` to force a full re-parse.

## The four streams

### 1. Web server access logs → `access`

Apache/Nginx **combined** format is read directly, with an optional trailing
response time (seconds) which powers the p95 endpoint panel:

```
41.58.12.7 - - [20/Sep/2026:12:48:01 +0100] "GET /pricing HTTP/1.1" 200 21118 "https://google.com/" "Mozilla/5.0 ..." 0.412
```

To emit that trailing field in Nginx, add `$request_time` to your `log_format`.

### 2. Laravel / application logs → `error`

The stock Laravel log line is read directly, and stack-trace lines fold into the
entry above them:

```
[2026-09-20 12:48:01] production.ERROR: cURL error 28: Operation timed out
#0 /var/www/app/Http/Controllers/GenerationController.php(148): ...
```

Grab these from `storage/logs/laravel*.log` on the server.

### 3. Billing / credits → `billing`

A CSV or JSON export from your payments table. Headers are matched loosely — case,
spacing and punctuation are ignored, and partial matches work (`stripe_amount_cents`
finds `amount`, and a header containing `cents`, `minor` or `kobo` is divided by 100).

| Field | Headers that match |
|---|---|
| timestamp *(required)* | `created_at`, `date`, `paid_at`, `timestamp`, … |
| user | `user_email`, `user_id`, `customer`, `email`, … |
| plan | `plan`, `tier`, `package`, `subscription` |
| amount | `amount`, `total`, `revenue`, `price`, … |
| currency | `currency`, `ccy` |
| credits | `credits`, `tokens`, `units`, `quantity` |
| status | `payment_status`, `status`, `state` |

Only rows whose status is `success`, `ok`, `paid` or `completed` count toward revenue.

### 4. App / usage logs → `usage`

Same loose-header CSV or JSON, one row per action:

| Field | Headers that match |
|---|---|
| timestamp *(required)* | as above |
| user | as above |
| action | `event_type`, `event`, `action`, `operation`, `feature`, `model` |
| credits | `credits_used`, `credits`, `units`, `tokens` |
| status | `status`, `state`, `result` |

## How a file is routed

Detection runs in this order, so you usually get the right stream for free:

1. Laravel log line shape → **error**
2. Apache/Nginx combined line shape → **access**
3. Tabular with an `amount` column, or `plan` + `credits`/`currency`, or a header
   mentioning invoice/payment/subscription → **billing**
4. Tabular with a `level` column → **error**
5. Tabular with `path` + (`method` | `ip` | `bytes`) → **access**
6. Anything else tabular → **usage**

If a file lands in the wrong stream, rename a column to something more specific
(`http_status` vs `payment_status` is the usual culprit) and reload.

Rows without a parseable timestamp are dropped and counted under **Unparsed** in
the Data sources panel at the bottom of the dashboard — if that number is large,
the timestamp column probably is not being found.

## A note on what to export

You do **not** need to hand over credentials or a database dump. A CSV per table,
with the columns above, is enough — and you can redact emails to a hash or an id
first; the dashboard only ever counts distinct users, it never displays them.
