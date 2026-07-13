# BAPI Operations Runbook

## Table Of Contents

- [1. Purpose And Scope](#1-purpose-and-scope)
- [2. Source Of Truth](#2-source-of-truth)
- [3. Environment Overview](#3-environment-overview)
- [4. Configuration And Secrets](#4-configuration-and-secrets)
- [5. Initial Deployment Checklist](#5-initial-deployment-checklist)
- [6. Routine Deployment And Update Procedure](#6-routine-deployment-and-update-procedure)
- [7. Production Cron Jobs](#7-production-cron-jobs)
- [8. Logging And Log Rotation](#8-logging-and-log-rotation)
- [9. Health Monitoring](#9-health-monitoring)
- [10. Normal Operating Checks](#10-normal-operating-checks)
- [11. Watchlist Operations](#11-watchlist-operations)
- [12. Kline Catch-Up And Stale Market Data Recovery](#12-kline-catch-up-and-stale-market-data-recovery)
- [13. Balance Trade And Symbol Ingestion Recovery](#13-balance-trade-and-symbol-ingestion-recovery)
- [14. Valuation Cache Recovery](#14-valuation-cache-recovery)
- [15. API Error Investigation](#15-api-error-investigation)
- [16. Ingestion Run Investigation](#16-ingestion-run-investigation)
- [17. Locking And Stuck-Process Recovery](#17-locking-and-stuck-process-recovery)
- [18. Database Operational Checks](#18-database-operational-checks)
- [19. Failure Scenarios And Recovery Matrix](#19-failure-scenarios-and-recovery-matrix)
- [20. Production Validation Checklist](#20-production-validation-checklist)
- [21. Backup Restore And Rollback Limitations](#21-backup-restore-and-rollback-limitations)
- [22. Security And Safety Notes](#22-security-and-safety-notes)
- [23. Operational Roadmap](#23-operational-roadmap)

## 1. Purpose And Scope

This is the operational runbook for the BAPI Binance ingestion and valuation service.

BAPI is responsible for:

- Ingesting Binance symbol metadata.
- Capturing spot balance snapshots.
- Ingesting account trades.
- Ingesting market klines.
- Maintaining historical and current valuation caches.
- Checking ingestion and valuation health.

Confirmed boundaries:

- BAPI reads Binance API credentials from `/etc/web-applications/trading-app/binance.php`.
- BAPI reads database credentials from `/etc/web-applications/trading-app/database.php`.
- BAPI uses the app-side bootstrap file `/var/www/html/php/crypto-con.php`.
- BAPI uses the Binance PHP library at `/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php`.
- The repository only contains one explicit CARL reference, a comment in `php/history.php`. There is no repository evidence of a formal BAPI/CARL integration boundary.
- Legacy/manual scripts in the repository reference `crypto.orders`, but that table is not defined in the inspected schema file.

See `ARCHITECTURE.md` for the confirmed schema and execution-flow details.

## 2. Source Of Truth

This runbook uses three source categories:

- Repository-derived facts: `AGENTS.md`, `.gitignore`, `ARCHITECTURE.md`, `bapi2.php`, `bapiLoop.php`, `php/crypto-con.php`, `php/history.php`, `php/newBuy.php`, all files under `php/ingest/`, and `sql/create_historical_ingestion_tables.sql`.
- Confirmed manual production configuration: the cron entries, logrotate stanza, and production path `/var/www/html/binance` supplied in the request.
- External responsibilities not managed by the repository: package installation, OS clock/timezone policy, cron installation, logrotate installation, database backups, secret provisioning, and rollback coordination.

Anything not supported by those sources is omitted.

## 3. Environment Overview

Confirmed paths and branch:

- Local development: `X:\WEBSITES\UMB-VPS-03\BAPI`
- Production: `/var/www/html/binance`
- Branch: `main`

Runtime components confirmed by source:

- PHP CLI
- MySQL access
- Binance API credentials outside the web root
- Filesystem permissions to read the external secret files and write production logs
- `cron`
- `flock`
- `logrotate`

Do not guess version numbers unless the server already exposes them.

## 4. Configuration And Secrets

Confirmed bootstrap and secret loading:

- `php/crypto-con.php` loads database credentials from `/etc/web-applications/trading-app/database.php` and creates a `mysqli` connection.
- Ingestion and valuation scripts load Binance credentials from `/etc/web-applications/trading-app/binance.php`.
- The repository does not contain these secret files.

Relevant repository guidance:

- `.gitignore` excludes common secret patterns such as `.env`, `*.key`, `*.pem`, `*secret*`, `*password*`, and `*credentials*`.
- `AGENTS.md` explicitly warns not to expose, log, commit, or hardcode credentials.

Operational guidance:

- Keep `/etc/web-applications/trading-app/binance.php` and `/etc/web-applications/trading-app/database.php` outside Git.
- Ensure production-only secret files are readable by the account that runs the cron jobs and not broadly writable.
- Do not print or paste the contents of the secret files into logs, tickets, or shells that persist history.

Expected to exist outside Git:

- `/etc/web-applications/trading-app/binance.php`
- `/etc/web-applications/trading-app/database.php`
- `/var/www/html/php/crypto-con.php` in the runtime location
- `/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php`
- `/var/www/html/vendor/autoload.php`

## 5. Initial Deployment Checklist

Use this for a new server. Steps marked `Manual` are production-server configuration tasks not stored in Git.

1. Check out the repository on the target server and ensure the working tree is on `main`.
2. Confirm the production path is `/var/www/html/binance`.
3. Verify PHP CLI is installed and available to the cron user.
4. Verify MySQL connectivity is available to the application.
5. Verify `cron`, `flock`, and `logrotate` are installed.
6. Place the repository-managed runtime files in their production locations, including the app-side bootstrap at `/var/www/html/php/crypto-con.php`.
7. `Manual` Place `/etc/web-applications/trading-app/binance.php` and `/etc/web-applications/trading-app/database.php` on the server.
8. `Manual` Confirm file permissions allow the cron user to read the secret files and the app directories.
9. Apply `sql/create_historical_ingestion_tables.sql` to the target MySQL database.
10. `Manual` Verify OS clock and timezone settings are consistent with the production host and database server.
11. Run an initial symbol sync.
12. Run an initial balance snapshot.
13. Run trade ingestion.
14. Run kline ingestion or catch-up for the active symbols you want to populate.
15. Run historical valuation for missing snapshots.
16. Run current valuation.
17. Run the health check and confirm a healthy result.

Supported script syntax from source:

```bash
php php/ingest/sync_symbols.php
php php/ingest/sync_balance_snapshot.php
php php/ingest/sync_account_trades.php [SYMBOL]
php php/ingest/sync_active_account_trades.php [SYMBOL ...]
php php/ingest/sync_active_market_klines.php [INTERVAL ...] [--catchup] [--max-runs=N]
php php/ingest/catchup_market_klines.php SYMBOL INTERVAL [max_runs]
php php/ingest/calculate_portfolio_snapshot_valuation.php --latest-unvalued
php php/ingest/calculate_current_portfolio_valuation.php
php php/ingest/check_ingestion_health.php
```

Initial deployment notes:

- `sync_account_trades.php` accepts one optional symbol argument.
- `sync_active_account_trades.php` accepts additional symbol arguments and only processes symbols that are in `crypto.symbols` with `status = 'TRADING'`.
- `sync_active_market_klines.php` defaults to `1m` when no interval is supplied.
- `catchup_market_klines.php` requires a symbol and interval.
- The valuation scripts are CLI-only.

## 6. Routine Deployment And Update Procedure

Use this when updating an existing production server from Git.

1. Record the current commit SHA before changing anything.
2. Check repository status and confirm whether the working tree is clean or contains only expected local config changes.
3. Confirm the branch is `main`.
4. Review the incoming diff, especially `sql/`, `php/ingest/`, and `php/crypto-con.php`.
5. Pull only the intended branch updates.
6. Do not overwrite external secrets, cron entries, logrotate config, or any manually maintained files outside Git.
7. If the schema changed, review `sql/create_historical_ingestion_tables.sql` before applying it.
8. Run the relevant validation scripts after the update.
9. If validation fails, stop and investigate before touching the next environment.

Safe Git checks:

```bash
git status --short
git branch --show-current
git rev-parse HEAD
git fetch origin
git pull --ff-only origin main
```

Guidance:

- Use `--ff-only` so production does not merge unexpected history.
- Never assume a pull is safe if the working tree already contains local production-only edits.
- Keep the previous commit SHA so rollback can be coordinated if needed.
- Rollback is an external operational action; the repository does not include a deployment or rollback script.

## 7. Production Cron Jobs

Confirmed manual production crontab:

```cron
* * * * * flock -n /var/run/bapi-ingestion.lock -c 'cd /var/www/html/binance && /usr/bin/php php/ingest/run_ingestion_cycle.php' >> /var/log/bapi-ingestion.log 2>&1
*/5 * * * * flock /var/run/bapi-ingestion.lock -c 'cd /var/www/html/binance && /usr/bin/php php/ingest/check_ingestion_health.php' >> /var/log/bapi-health.log 2>&1
```

Why it is configured this way:

- Ingestion runs every minute because the cycle is designed to ingest symbol metadata, balances, trades, klines, and valuations continuously.
- Ingestion uses `flock -n` so a minute tick does not queue behind a cycle that is still running.
- Health monitoring uses blocking `flock` so it waits for ingestion to finish instead of reading during a mid-cycle state.
- If an ingestion cycle exceeds one minute, the next cron tick skips starting a second copy.
- The shared lock prevents false mid-cycle health failures by forcing health checks to read a settled state.

Manual configuration note:

- These cron entries are manual server configuration unless they have been copied into a repository-managed server config file.

## 8. Logging And Log Rotation

Confirmed production logs:

- `/var/log/bapi-ingestion.log`
- `/var/log/bapi-health.log`

Useful commands:

```bash
tail -n 50 /var/log/bapi-ingestion.log
tail -n 50 /var/log/bapi-health.log
tail -f /var/log/bapi-ingestion.log
tail -f /var/log/bapi-health.log
stat /var/log/bapi-ingestion.log /var/log/bapi-health.log
grep -nE 'status=failed|status=unhealthy|reasons=' /var/log/bapi-ingestion.log /var/log/bapi-health.log
```

Confirmed logrotate configuration:

```text
/var/log/bapi-ingestion.log
/var/log/bapi-health.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    create 0644 root root
}
```

Directive summary:

- `daily` rotates once per day.
- `rotate 14` keeps 14 rotated copies.
- `compress` gzips old rotations.
- `delaycompress` leaves the most recent rotated file uncompressed until the next rotation.
- `missingok` does not fail if a log is absent.
- `notifempty` skips empty logs.
- `copytruncate` copies the log to the archive and truncates the original file in place.
- `create 0644 root root` recreates the log with the given mode and ownership.

Safe validation commands:

```bash
sudo logrotate -d /etc/logrotate.conf
sudo logrotate -dv /etc/logrotate.conf
```

The exact include file that contains the stanza may differ on each server, but debug validation is safe because it does not rotate files.

## 9. Health Monitoring

Script syntax:

```bash
php php/ingest/check_ingestion_health.php [--account-type=SPOT] [--balance-max-age=180] [--kline-max-age=180] [--current-cache-max-age=180] [--api-error-window=15]
```

Supported CLI options and defaults:

- `--account-type=SPOT`
- `--balance-max-age=180`
- `--kline-max-age=180`
- `--current-cache-max-age=180`
- `--api-error-window=15`

Behavior:

- CLI-only.
- Exits `0` when healthy.
- Exits `1` when unhealthy or if the arguments are invalid.
- Prints one line of key-value fields to STDOUT.
- Prints `check_ingestion_health: ...` error text to STDERR for invalid usage or failed checks.

Confirmed output fields:

- `account_type`
- `last_ingest_age`
- `recent_ingest_runs`
- `recent_failed_runs`
- `balance_age`
- `active_symbols`
- `oldest_kline_age`
- `stale_klines`
- `missing_klines`
- `current_cache_age`
- `current_cache_snapshot_match`
- `coverage`
- `missing_prices`
- `historical_cache`
- `recent_api_errors`
- `status`
- `reasons` when unhealthy

Confirmed unhealthy reasons:

- `no_recent_ingest_activity`
- `recent_failed_ingest_runs`
- `missing_balance_snapshot`
- `stale_balance`
- `missing_current_cache`
- `current_cache_snapshot_mismatch`
- `stale_current_cache`
- `current_cache_missing_prices`
- `current_cache_incomplete_coverage`
- `missing_historical_cache`
- `no_active_symbols`
- `missing_klines`
- `stale_klines`
- `recent_api_errors`
- `internal_error`

How to interpret the results:

- Stale or missing klines means the active symbol set does not have a fresh enough `market_klines` row for `interval_name = '1m'`.
- Stale balance snapshots mean the latest `balance_snapshots.snapshot_time` is older than the configured age limit.
- Failed ingest runs mean `ingest_runs` contains recent rows with `status = 'failed'`.
- Stale current valuation cache means `current_portfolio_valuations.price_reference_time` is older than the configured cache age.
- Current cache snapshot mismatch means `current_portfolio_valuations.balance_snapshot_time` does not match the latest balance snapshot time.
- Missing historical valuation cache means there is no row in `portfolio_snapshot_valuations` for the latest balance snapshot.
- Incomplete price coverage means the current cache has `coverage_percentage` below 100.
- Missing prices means the current cache has one or more unpriced assets.
- Recent API errors means `api_errors` contains rows in the recent error window.

Operational note:

- `check_ingestion_health.php` reads live state directly. If you run it while ingestion is still mutating the cache, you can see a temporary snapshot mismatch. The production cron avoids that by using the shared lock.

## 10. Normal Operating Checks

Daily:

- Confirm `check_ingestion_health.php` reports `status=healthy`.
- Review `/var/log/bapi-ingestion.log` for the latest completed cycle and any `status=failed` lines.
- Review `/var/log/bapi-health.log` for `status=unhealthy` and `reasons=`.
- Confirm no recent `api_errors` rows are accumulating.
- Confirm the latest balance snapshot is fresh.

Weekly:

- Confirm cron entries still exist.
- Confirm logs are rotating and growing as expected.
- Check `ingest_runs` for failed or unusually long jobs.
- Check `portfolio_snapshot_valuations` and `current_portfolio_valuations` are populated.
- Confirm active symbols still have fresh 1m klines.

Post-deployment:

- Run one ingestion cycle manually.
- Run the health check manually.
- Confirm the latest balance snapshot, current cache, and historical cache are all present.
- Confirm there are no unexpected local Git changes on production.

## 11. Watchlist Operations

`ingest_symbol_watchlist` is the gate for active market kline ingestion. `sync_active_market_klines.php` only selects symbols that are:

- Enabled in `crypto.ingest_symbol_watchlist`.
- Present in `crypto.symbols`.
- Marked `status = 'TRADING'`.

Safe SQL examples:

```sql
SELECT watchlist_id, symbol, enabled, reason, created_at, updated_at
FROM crypto.ingest_symbol_watchlist
ORDER BY enabled DESC, symbol ASC;
```

```sql
UPDATE crypto.ingest_symbol_watchlist
SET enabled = 1, reason = 'manual enable'
WHERE symbol = 'SHIBUSDT';
```

```sql
UPDATE crypto.ingest_symbol_watchlist
SET enabled = 0, reason = 'manual disable'
WHERE symbol = 'SHIBUSDT';
```

```sql
SELECT symbol, reason
FROM crypto.ingest_symbol_watchlist
WHERE symbol = 'SHIBUSDT';
```

Follow-up after adding or enabling a symbol:

1. Confirm the symbol exists in `crypto.symbols` and is `TRADING`.
2. Run trade ingestion for the symbol if you need immediate trade history coverage.
3. Run kline catch-up for the symbol and interval if market data is behind.
4. Refresh historical and current valuation caches.
5. Re-run the health check.

Supported commands:

```bash
php php/ingest/sync_account_trades.php SHIBUSDT
php php/ingest/catchup_market_klines.php SHIBUSDT 1m
php php/ingest/calculate_portfolio_snapshot_valuation.php --latest-unvalued
php php/ingest/calculate_current_portfolio_valuation.php
php php/ingest/check_ingestion_health.php
```

## 12. Kline Catch-Up And Stale Market Data Recovery

Scripts and syntax:

```bash
php php/ingest/sync_market_klines.php SYMBOL INTERVAL
php php/ingest/sync_active_market_klines.php [INTERVAL ...] [--catchup] [--max-runs=N]
php php/ingest/catchup_market_klines.php SYMBOL INTERVAL [max_runs]
```

Verified example:

```bash
php php/ingest/catchup_market_klines.php SHIBUSDT 1m
```

Stopping conditions confirmed by source:

- Worker exit code is non-zero.
- Worker summary reports `status=failed`.
- No klines were returned.
- No database changes were made.
- A partial page was returned with fewer than 1000 klines.

Output fields to watch:

- `klines`
- `inserted`
- `updated`
- `errors`
- `runs_attempted`
- `runs_completed`
- `stopped_reason`
- `status`

How to confirm recovery:

- Re-run the health check and confirm there are no `missing_klines` or `stale_klines` reasons.
- Confirm the latest 1m candle age is within the configured limit.

## 13. Balance, Trade, And Symbol Ingestion Recovery

### `sync_symbols.php`

Syntax:

```bash
php php/ingest/sync_symbols.php
```

Behavior:

- No CLI arguments.
- Pulls Binance exchange info.
- Upserts into `crypto.symbols`.
- Writes a run row in `ingest_runs`.
- Writes API failures to `api_errors`.

### `sync_balance_snapshot.php`

Syntax:

```bash
php php/ingest/sync_balance_snapshot.php
```

Behavior:

- No CLI arguments.
- Captures a millisecond snapshot time.
- Inserts or updates `crypto.balance_snapshots` rows for `account_type = 'SPOT'`.
- Records `snapshot_time`, `free`, `locked`, and `total`.
- Writes run and error records.

### `sync_account_trades.php`

Syntax:

```bash
php php/ingest/sync_account_trades.php [SYMBOL]
```

Behavior:

- One optional symbol argument.
- Without a symbol argument, it processes all `TRADING` symbols in `crypto.symbols`.
- Uses `ingest_cursors` with `cursor_name = 'account_trades:<SYMBOL>'` and an empty `interval_name`.
- Advances by Binance trade id.
- Upserts into `crypto.account_trades`.
- Records `cursor_before` and `cursor_after` in `ingest_runs`.

### `sync_active_account_trades.php`

Syntax:

```bash
php php/ingest/sync_active_account_trades.php [SYMBOL ...]
```

Behavior:

- Accepts zero or more additional symbol arguments.
- Builds a candidate list from `crypto.account_trades`, `crypto.ingest_cursors` where `endpoint = 'account_trades'`, and enabled watchlist rows.
- Filters the final list to `crypto.symbols.status = 'TRADING'`.
- Runs `sync_account_trades.php` once per selected symbol.
- Sleeps 250 ms between worker runs.

How to identify partial failure:

- Check the script summary line for `status=completed_with_errors` or `status=failed`.
- Review `ingest_runs.error_count`, `records_inserted`, and `records_updated`.
- Review `api_errors` for the failing endpoint and symbol.

## 14. Valuation Cache Recovery

Scripts and supported modes:

```bash
php php/ingest/calculate_portfolio_snapshot_valuation.php --snapshot-time=MS [--account-type=SPOT]
php php/ingest/calculate_portfolio_snapshot_valuation.php --latest-unvalued [--account-type=SPOT] [--limit=N]
php php/ingest/calculate_portfolio_snapshot_valuation.php --from=MS --to=MS [--account-type=SPOT] [--limit=N]
php php/ingest/calculate_portfolio_snapshot_valuation.php --all [--account-type=SPOT] [--limit=N]
php php/ingest/calculate_current_portfolio_valuation.php [--account-type=SPOT]
```

Recovery procedures:

- Missing historical cache: run `calculate_portfolio_snapshot_valuation.php --latest-unvalued`.
- Stale historical valuations: rerun the specific snapshot, range, or `--all` mode as appropriate.
- Stale current cache: run `calculate_current_portfolio_valuation.php`.
- Current cache snapshot mismatch: rerun current valuation after the balance snapshot and market data are aligned.
- Missing price coverage: rerun the valuation after the upstream symbol or kline gap is fixed.

When to rerun valuation versus the full ingestion cycle:

- Rerun only valuation when the upstream balance snapshot and kline data are already present.
- Rerun the ingestion cycle when the upstream data itself is incomplete or stale.

## 15. API Error Investigation

Schema:

- `api_errors.error_id`
- `api_errors.run_id`
- `api_errors.endpoint`
- `api_errors.symbol`
- `api_errors.interval_name`
- `api_errors.http_status`
- `api_errors.api_code`
- `api_errors.api_message`
- `api_errors.request_params`
- `api_errors.raw_response`
- `api_errors.created_at`

Important implementation note:

- The inspected writers populate `run_id`, `endpoint`, `symbol`, `interval_name`, `api_message`, and `request_params`.
- `http_status`, `api_code`, and `raw_response` exist in the schema but are not populated by the inspected writers.

Safe SQL examples:

```sql
SELECT error_id, created_at, endpoint, symbol, interval_name, http_status, api_code, api_message
FROM crypto.api_errors
ORDER BY created_at DESC
LIMIT 20;
```

```sql
SELECT endpoint, COUNT(*) AS error_count, MAX(created_at) AS last_seen
FROM crypto.api_errors
WHERE created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY endpoint
ORDER BY error_count DESC, last_seen DESC;
```

```sql
SELECT endpoint, symbol, http_status, COUNT(*) AS error_count, MAX(created_at) AS last_seen
FROM crypto.api_errors
WHERE created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY endpoint, symbol, http_status
ORDER BY error_count DESC, last_seen DESC;
```

Interpretation:

- A single endpoint or symbol failing intermittently often points to a transient Binance-side issue or a data gap.
- Repeated failures across multiple runs, especially across different symbols or endpoints, are more consistent with credential, permissions, schema, or code issues.

## 16. Ingestion Run Investigation

Schema:

- `ingest_runs.run_id`
- `ingest_runs.run_type`
- `ingest_runs.endpoint`
- `ingest_runs.symbol`
- `ingest_runs.interval_name`
- `ingest_runs.status`
- `ingest_runs.started_at`
- `ingest_runs.finished_at`
- `ingest_runs.from_time`
- `ingest_runs.to_time`
- `ingest_runs.records_inserted`
- `ingest_runs.records_updated`
- `ingest_runs.error_count`
- `ingest_runs.request_params`
- `ingest_runs.cursor_before`
- `ingest_runs.cursor_after`
- `ingest_runs.notes`

Safe SQL examples:

```sql
SELECT run_id, run_type, endpoint, symbol, interval_name, status, started_at, finished_at,
       records_inserted, records_updated, error_count
FROM crypto.ingest_runs
ORDER BY started_at DESC
LIMIT 20;
```

```sql
SELECT run_id, run_type, endpoint, symbol, interval_name, status, started_at, finished_at
FROM crypto.ingest_runs
WHERE status = 'failed'
ORDER BY started_at DESC;
```

```sql
SELECT run_id, run_type, endpoint, symbol, interval_name, status,
       started_at,
       finished_at,
       TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, NOW())) AS runtime_seconds
FROM crypto.ingest_runs
WHERE status = 'started'
ORDER BY started_at ASC;
```

```sql
SELECT run_type, endpoint, COUNT(*) AS run_count, MAX(started_at) AS last_started
FROM crypto.ingest_runs
GROUP BY run_type, endpoint
ORDER BY run_count DESC, last_started DESC;
```

How CLI output and `ingest_runs` work together:

- CLI output gives the immediate summary for one run.
- `ingest_runs` records the durable execution history, counts, and cursors.
- `api_errors` captures the error details when a run fails or partially fails.

## 17. Locking And Stuck-Process Recovery

Shared lock:

- `/var/run/bapi-ingestion.lock`

Observed behavior:

- `flock` uses process-held locks.
- The lock normally releases when the process exits.
- The lock file itself is not the primary recovery target.

Safe checks:

```bash
sudo flock -n /var/run/bapi-ingestion.lock -c 'echo lock available'
sudo lsof /var/run/bapi-ingestion.lock
pgrep -af 'php .*/php/ingest/'
ps -eo pid,etime,cmd | grep '[p]hp .*/php/ingest/'
```

How to read the results:

- If `flock -n` succeeds, the lock is free.
- If `lsof` shows a PHP process on the lock file, that process is holding the lock.
- `pgrep` and `ps` show whether ingestion or health scripts are still running.
- `etime` shows how long the process has been running.

Cautious recovery procedure for a genuinely stuck process:

1. Confirm the log file has stopped advancing.
2. Confirm the PHP process is still present.
3. Confirm the process is not simply doing a long but valid run.
4. If it is genuinely stuck, terminate the specific PHP PID first with a normal signal.
5. Recheck the logs and the lock before rerunning the job.

Do not delete the lock file as the primary recovery step.

## 18. Database Operational Checks

Safe observation queries:

```sql
SELECT 'symbols' AS table_name, COUNT(*) AS row_count FROM crypto.symbols
UNION ALL SELECT 'balance_snapshots', COUNT(*) FROM crypto.balance_snapshots
UNION ALL SELECT 'account_trades', COUNT(*) FROM crypto.account_trades
UNION ALL SELECT 'market_klines', COUNT(*) FROM crypto.market_klines
UNION ALL SELECT 'ingest_runs', COUNT(*) FROM crypto.ingest_runs
UNION ALL SELECT 'api_errors', COUNT(*) FROM crypto.api_errors;
```

```sql
SELECT 'balance_snapshots' AS table_name, MAX(snapshot_time) AS latest_value FROM crypto.balance_snapshots
UNION ALL SELECT 'account_trades', MAX(trade_time) FROM crypto.account_trades
UNION ALL SELECT 'market_klines', MAX(close_time) FROM crypto.market_klines
UNION ALL SELECT 'ingest_runs', MAX(started_at) FROM crypto.ingest_runs
UNION ALL SELECT 'api_errors', MAX(created_at) FROM crypto.api_errors
UNION ALL SELECT 'current_portfolio_valuations', MAX(calculated_at) FROM crypto.current_portfolio_valuations
UNION ALL SELECT 'portfolio_snapshot_valuations', MAX(calculated_at) FROM crypto.portfolio_snapshot_valuations;
```

```sql
SELECT account_type, coverage_percentage, missing_price_count, missing_asset_count, calculated_at
FROM crypto.current_portfolio_valuations
ORDER BY calculated_at DESC;
```

```sql
SELECT account_type, snapshot_time, coverage_percentage, missing_price_count, missing_asset_count, calculated_at
FROM crypto.portfolio_snapshot_valuations
ORDER BY snapshot_time DESC;
```

Approximate `crypto` schema storage:

```sql
SELECT table_schema,
       ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_mb
FROM information_schema.tables
WHERE table_schema = 'crypto'
GROUP BY table_schema;
```

Observation versus repair:

- These queries observe state only.
- Do not use them as a substitute for fixing the underlying ingestion or data issue.

## 19. Failure Scenarios And Recovery Matrix

| Symptom | Likely cause | Verification command | Recovery action | Final validation |
| --- | --- | --- | --- | --- |
| Health reports stale klines | `market_klines` is behind for one or more active symbols | `php php/ingest/check_ingestion_health.php` | Run `sync_active_market_klines.php` or `catchup_market_klines.php` | Health returns `status=healthy` |
| Health reports missing klines | A watchlisted trading symbol has no local 1m candle | `php php/ingest/check_ingestion_health.php` | Backfill the missing symbol with kline ingestion or catch-up | Missing kline reason disappears |
| Current cache mismatch | Health ran while caches and balances were mid-update or a cycle was incomplete | `php php/ingest/check_ingestion_health.php` | Wait for ingestion to finish, then rerun current valuation if needed | `current_cache_snapshot_match=yes` |
| Missing historical cache | Latest balance snapshot has no row in `portfolio_snapshot_valuations` | `php php/ingest/check_ingestion_health.php` | Run `calculate_portfolio_snapshot_valuation.php --latest-unvalued` | Historical cache exists |
| Stale current cache | `current_portfolio_valuations.price_reference_time` is too old | `php php/ingest/check_ingestion_health.php` | Run `calculate_current_portfolio_valuation.php` | Current cache age is fresh |
| Missing prices or reduced coverage | A non-parity asset has no usable local pair or candle | Health check plus valuation output | Fix upstream symbol or kline gap and rerun valuation | Coverage returns to 100% where expected |
| Recent API errors | Binance call failure, credential issue, or schema/worker error | `SELECT ... FROM crypto.api_errors ...` | Review the endpoint and rerun after correction | No new recent API errors |
| Failed ingestion run | Worker exited non-zero or recorded `status=failed` | `SELECT ... FROM crypto.ingest_runs WHERE status='failed'` | Rerun the affected worker or the full cycle | Latest run completes cleanly |
| Cron not running | Cron entry missing or disabled | `crontab -l` or service checks | Restore the manual cron entries | Logs advance on schedule |
| Logs not updating | Job not running, blocked, or failing before log write | `tail -f /var/log/bapi-ingestion.log` | Check cron, lock, and process state | New log lines appear |
| Lock contention | Another ingestion cycle is already holding the shared lock | `sudo flock -n /var/run/bapi-ingestion.lock -c 'echo lock available'` | Wait, or resolve the stuck process if one exists | Lock becomes available |
| Database connection failure | Missing credentials, unreachable DB, or invalid privileges | `php php/ingest/check_ingestion_health.php` | Fix `database.php`, network, or DB access | Health check can read the DB |
| Binance authentication failure | Missing or invalid API credentials | `php php/ingest/sync_symbols.php` or any worker | Fix `/etc/web-applications/trading-app/binance.php` | Worker completes and no new auth errors appear |

## 20. Production Validation Checklist

Use this after deployment or after any incident.

- Confirm the repository is on `main` and the working tree is in the expected state.
- Confirm the schema is current for the code you deployed.
- Confirm `/etc/web-applications/trading-app/binance.php` and `/etc/web-applications/trading-app/database.php` are present.
- Confirm a full ingestion cycle completes with zero failed steps.
- Confirm `php php/ingest/check_ingestion_health.php` returns `status=healthy`.
- Confirm the cron entries are installed.
- Confirm the shared lock is in use and prevents overlap.
- Confirm both log files are advancing.
- Confirm logrotate is configured and debug validation passes.
- Confirm `current_portfolio_valuations` and `portfolio_snapshot_valuations` contain expected rows.
- Confirm all active symbols have fresh 1m klines.
- Confirm there are no recent `api_errors` rows.

Suggested command set:

```bash
git status --short
git branch --show-current
php php/ingest/run_ingestion_cycle.php
php php/ingest/check_ingestion_health.php
tail -n 50 /var/log/bapi-ingestion.log
tail -n 50 /var/log/bapi-health.log
```

## 21. Backup, Restore, And Rollback Limitations

The inspected repository does not provide a backup or restore process.

External operational responsibilities:

- Database backups are handled outside the repository.
- Secret backups are handled outside the repository.
- Rollback is a server and Git operations task, not an application feature.

Git rollback considerations:

- Record the pre-update commit SHA.
- Keep production-only config files out of the Git history.
- Do not attempt a rollback that overwrites external secret files or manual cron entries.

## 22. Security And Safety Notes

Confirmed safeguards:

- Health and valuation scripts are CLI-only.
- Secrets are loaded from paths outside the web root.
- The repository ignores common secret and local debug files.
- Database access uses `mysqli`, not PDO.
- Ingestion writes to the database and logs errors in structured tables.

Warnings:

- Do not expose ingestion scripts publicly.
- Do not commit credentials.
- Do not run destructive SQL without backups.
- Do not overlap manual ingestion with cron ingestion unless the shared lock is respected.
- Do not edit production files directly without reconciling the change with Git.

## 23. Operational Roadmap

Planned future work, not currently operational:

- Portfolio cost basis
- Realized P&L
- Unrealized P&L
- ROI analytics

When these features are implemented, the operational procedures in this document must be updated.
