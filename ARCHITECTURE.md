# BAPI Architecture

## Source Of Truth

This document is split between repository evidence and confirmed production configuration.

- Repository source of truth: `AGENTS.md`, `.gitignore`, `bapi2.php`, `bapiLoop.php`, `php/crypto-con.php`, `php/history.php`, `php/newBuy.php`, all files under `php/ingest/`, and `sql/create_historical_ingestion_tables.sql`.
- Production configuration source of truth: the cron and logrotate snippets provided in the request, plus the confirmed production path `/var/www/html/binance`.
- Anything not supported by those sources is intentionally omitted.

## 1. Project Purpose And System Boundaries

BAPI is the Binance ingestion and valuation application. The repository implements:

- Symbol metadata ingestion from Binance exchange info.
- Spot balance snapshot ingestion.
- Account trade ingestion.
- Market kline ingestion.
- Historical and current portfolio valuation caches.
- Health checks over the ingestion and valuation state stored in MySQL.

Confirmed boundaries from the inspected source:

- BAPI reads Binance API credentials from `/etc/web-applications/trading-app/binance.php`.
- BAPI reads database credentials from `/etc/web-applications/trading-app/database.php` through `/var/www/html/php/crypto-con.php`.
- BAPI uses the shared Binance library at `/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php`.
- The only explicit mention of CARL in the inspected repository is a comment in `php/history.php`. I did not find repository evidence of a formal BAPI/CARL integration boundary.
- The legacy/manual trading scripts `bapi2.php`, `bapiLoop.php`, `php/history.php`, and `php/newBuy.php` reference `crypto.orders`, but that table is not defined in the inspected SQL file.

## 2. Repository Layout

Top-level items:

- `AGENTS.md`: repository-specific working rules.
- `.gitignore`: excludes secrets, logs, local tooling files, and editor artifacts.
- `ARCHITECTURE.md`: this document.
- `bapi2.php`: legacy/manual trading script that queries balances and checks `crypto.orders`.
- `bapiLoop.php`: infinite loop wrapper that repeatedly includes `bapi2.php`.
- `php/`: application PHP code.
- `sql/`: schema file for the ingestion and valuation tables.

Important `php/` contents:

- `php/crypto-con.php`: shared MySQL bootstrap used by the application code.
- `php/history.php`: legacy order-history synchronization logic around `crypto.orders`.
- `php/newBuy.php`: legacy/manual buy-order creation logic around `crypto.orders`.
- `php/ingest/`: ingestion, catch-up, valuation, and health-check scripts.

Important `php/ingest/` scripts:

- `run_ingestion_cycle.php`: orchestrates the main ingestion and valuation cycle.
- `check_ingestion_health.php`: checks freshness and completeness of ingestion and valuation state.
- `sync_symbols.php`: ingests Binance symbol metadata into `crypto.symbols`.
- `sync_balance_snapshot.php`: ingests spot balances into `crypto.balance_snapshots`.
- `sync_account_trades.php`: ingests account trade history into `crypto.account_trades`.
- `sync_active_account_trades.php`: selects active symbols and runs the trade ingester per symbol.
- `sync_market_klines.php`: ingests market candles for one symbol and interval.
- `sync_active_market_klines.php`: selects active symbols and runs the kline ingester per symbol and interval.
- `catchup_market_klines.php`: repeated single-symbol kline catch-up runner.
- `calculate_portfolio_snapshot_valuation.php`: historical valuation of stored balance snapshots.
- `calculate_current_portfolio_valuation.php`: current valuation of the latest balance snapshot.

## 3. Database Architecture

The inspected schema file is `sql/create_historical_ingestion_tables.sql`. It targets the `crypto` database and defines these confirmed tables.

| Table | Purpose | Key fields and constraints | Relationships |
| --- | --- | --- | --- |
| `symbols` | Cached Binance symbol metadata. | Primary key `symbol`; indexed by `(base_asset, quote_asset)` and `status`. | Parent table for trade, kline, cursor, run, error, and watchlist references. |
| `account_trades` | Ingested personal trade history. | Primary key `(symbol, trade_id)`; indexes on `(symbol, order_id)`, `(symbol, trade_time)`, and `commission_asset`. | `symbol` references `symbols.symbol`. |
| `market_klines` | Ingested OHLCV market candles. | Primary key `(symbol, interval_name, open_time)`; index on `(symbol, interval_name, close_time)`. | `symbol` references `symbols.symbol`. |
| `market_agg_trades` | Stored aggregated market trades. | Primary key `(symbol, agg_trade_id)`; indexes on `(symbol, trade_time)` and `(symbol, first_trade_id, last_trade_id)`. | `symbol` references `symbols.symbol`. No writer script was found in the inspected repository. |
| `balance_snapshots` | Spot balance snapshots by asset and capture time. | Auto-increment `snapshot_id`; unique `(account_type, asset, snapshot_time)`; indexes on `snapshot_time` and `(asset, snapshot_time)`. | No foreign keys in this file. |
| `portfolio_snapshot_valuations` | Historical valuation cache for a specific balance snapshot. | Primary key `(account_type, snapshot_time)`; indexes on `snapshot_time` and `calculated_at`. | Written by `calculate_portfolio_snapshot_valuation.php`. |
| `current_portfolio_valuations` | Latest valuation cache per account type. | Primary key `account_type`; indexes on `balance_snapshot_time`, `price_reference_time`, and `calculated_at`. | Written by `calculate_current_portfolio_valuation.php`. |
| `ingest_runs` | Run ledger for ingestion jobs. | Auto-increment `run_id`; indexes on status/start, endpoint/symbol/interval, and time window. | `symbol` references `symbols.symbol`. |
| `ingest_cursors` | Cursor storage for incremental ingestion. | Auto-increment `cursor_id`; unique scope `(cursor_name, endpoint, symbol, interval_name)`; index on `updated_at`. | `symbol` references `symbols.symbol`. |
| `api_errors` | API and worker error ledger. | Auto-increment `error_id`; indexes on `run_id`, `(endpoint, created_at)`, and `(symbol, created_at)`. | `run_id` references `ingest_runs.run_id` with `ON DELETE SET NULL`; `symbol` references `symbols.symbol`. |
| `ingest_symbol_watchlist` | Enabled symbol watchlist for active ingestion. | Auto-increment `watchlist_id`; unique `symbol`; index on `(enabled, symbol)`. | `symbol` references `symbols.symbol`. |

Confirmed table roles by pipeline:

- Ingestion state is tracked in `ingest_runs` and `ingest_cursors`.
- API error tracking is stored in `api_errors`.
- The watchlist is stored in `ingest_symbol_watchlist`.
- Balances are stored in `balance_snapshots`.
- Trades are stored in `account_trades`.
- Klines are stored in `market_klines`.
- Valuation caches are stored in `portfolio_snapshot_valuations` and `current_portfolio_valuations`.

## 4. Ingestion Flow

### Main cycle

`php/ingest/run_ingestion_cycle.php` executes these steps in this exact order:

1. `sync_symbols.php`
2. `sync_balance_snapshot.php`
3. `sync_active_account_trades.php`
4. `sync_active_market_klines.php 1m`
5. `calculate_portfolio_snapshot_valuation.php --latest-unvalued`
6. `calculate_current_portfolio_valuation.php`

It captures each child script's output, counts a step as failed if the child exits non-zero or prints `status=failed`, and finally prints a one-line cycle summary with completed, failed, runtime, and overall status fields.

### Symbol sync

`sync_symbols.php`:

- Calls Binance `exchangeInfo()`.
- Upserts each returned symbol into `crypto.symbols`.
- Preserves raw Binance response JSON in `raw_response`.
- Records the run in `ingest_runs`.
- Records failures in `api_errors`.

### Balance snapshot ingestion

`sync_balance_snapshot.php`:

- Captures a millisecond `snapshot_time` at start.
- Calls Binance account balances.
- Stores one row per asset in `crypto.balance_snapshots` for account type `SPOT`.
- Uses `free`, `locked`, and `total` values from the API response.
- Records the run in `ingest_runs`.
- Records failures in `api_errors`.

### Active account trade ingestion

`sync_active_account_trades.php`:

- Builds a candidate symbol list from `crypto.account_trades`, `crypto.ingest_cursors` for `endpoint = 'account_trades'`, and enabled symbols in `crypto.ingest_symbol_watchlist`.
- Accepts extra symbol arguments on the CLI.
- Filters the final list to symbols present in `crypto.symbols` with `status = 'TRADING'`.
- Runs `sync_account_trades.php SYMBOL` once per selected symbol.
- Sleeps 250 ms between worker runs.
- Reports `completed`, `failed`, and `status` in its summary line.

`sync_account_trades.php`:

- Optionally accepts a single symbol argument.
- Without a symbol argument, it processes all `TRADING` symbols in `crypto.symbols`.
- Uses `ingest_cursors` with `cursor_name = 'account_trades:<SYMBOL>'`, `endpoint = 'account_trades'`, `symbol = <SYMBOL>`, and empty `interval_name`.
- Sets `fromId` to the previous cursor `last_id + 1` when a cursor exists.
- Calls Binance trade history with a limit of 1000.
- Upserts each trade into `crypto.account_trades`.
- Updates the cursor with the highest trade id and trade time seen in the run.
- Stores `cursor_before` and `cursor_after` JSON in `ingest_runs`.
- Emits per-run counts for symbols, trades, inserts, updates, skips, errors, and status.

### Active market kline ingestion

`sync_active_market_klines.php`:

- Loads enabled watchlist symbols joined against `crypto.symbols` with `status = 'TRADING'`.
- Accepts optional interval arguments from this confirmed list: `1m`, `3m`, `5m`, `15m`, `30m`, `1h`, `2h`, `4h`, `6h`, `8h`, `12h`, `1d`.
- Defaults to `1m` when no interval is supplied.
- In normal mode, runs `sync_market_klines.php SYMBOL INTERVAL` once per symbol and interval.
- In `--catchup` mode, it treats `--max-runs=N` as the maximum number of round-robin passes over symbol/interval jobs.
- In catch-up mode, a job is marked caught up when the worker returns fewer than 1000 klines, no changes, or no klines, and it stops immediately on worker failure.
- Sleeps 250 ms between worker runs.

`sync_market_klines.php`:

- Requires `SYMBOL` and `INTERVAL` arguments.
- Validates the symbol against `crypto.symbols` with `status = 'TRADING'`.
- Uses cursor scope `cursor_name = 'market_klines:<SYMBOL>:<INTERVAL>'`, `endpoint = 'market_klines'`, `symbol = <SYMBOL>`, `interval_name = <INTERVAL>`.
- If no cursor exists, starts from 30 days before the current time.
- Calls Binance candlesticks with limit 1000.
- Upserts each normalized candle into `crypto.market_klines`.
- Updates the cursor with the highest open time and close time seen in the run.
- Stores `cursor_before` and `cursor_after` in `ingest_runs`.

`catchup_market_klines.php`:

- Requires `SYMBOL` and `INTERVAL`.
- Accepts an optional `max_runs` argument.
- Repeats `sync_market_klines.php SYMBOL INTERVAL` until one of the worker stop conditions is met or the run limit is reached.
- Stops on worker exit failure, worker `status=failed`, zero klines, zero changes, or a partial page under 1000 klines.

### Kline and trade cursor behavior

- Trade cursors advance by Binance trade id.
- Kline cursors advance by candle open time and close time.
- Cursor data is persisted in `ingest_cursors.cursor_value` as JSON.
- Both workers are idempotent through primary keys and `ON DUPLICATE KEY UPDATE`.

## 5. Valuation Flow

### Historical snapshot valuation

`calculate_portfolio_snapshot_valuation.php`:

- Is CLI-only.
- Supports these mutually exclusive modes: `--snapshot-time=MS`, `--latest-unvalued`, `--from=MS --to=MS`, and `--all`.
- Accepts `--account-type=SPOT` and optional `--limit=N` where supported.
- Loads balance snapshots for the selected account type and snapshot times.
- Values each non-zero holding using local candle data from `crypto.market_klines`.
- Writes results into `crypto.portfolio_snapshot_valuations`.

### Current portfolio valuation

`calculate_current_portfolio_valuation.php`:

- Is CLI-only.
- Supports `--account-type=SPOT`.
- Uses the latest balance snapshot for the account type.
- Writes the latest valuation into `crypto.current_portfolio_valuations`.

### Price selection logic

Both valuation scripts use the same confirmed pricing rules:

- Stablecoin or parity handling applies to `USD`, `USDT`, `USDC`, `FDUSD`, and `BUSD` at a price of `1.000000000000000000`.
- Non-parity assets are valued against the USDT pair for the asset, for example `RVN` uses `RVNUSDT`.
- The selected price comes from the first available candle in interval priority order: `1m`, `3m`, `5m`, `15m`, `30m`, `1h`, `2h`, `4h`, `6h`, `8h`, `12h`, `1d`.
- If the expected local symbol is missing, the asset is counted as missing with reason `missing_local_symbol`.
- If the symbol exists but no candle is available at or before the reference time, the asset is counted as missing with reason `missing_local_candle`.

### Freshness categories

- A price is `fresh` when candle age is at most 300000 ms.
- A price is `aging` when age is greater than 300000 ms and at most 1800000 ms.
- A price is `stale` when age exceeds 1800000 ms.

### Coverage and missing-price behavior

- `coverage_percentage` is `valued_asset_count / total_nonzero_asset_count * 100`, formatted to six decimals.
- `valued_asset_count` includes parity assets and assets with a resolved local candle.
- `missing_asset_count` and `missing_price_count` increase when a required symbol or candle cannot be found.
- `missing_assets` stores the asset name, expected pair, and reason for each miss.
- `price_sources` stores the resolved price source for each valued asset.
- No fallback pricing path is implemented beyond parity assets and local candles.

## 6. Health Monitoring

`php/ingest/check_ingestion_health.php` is CLI-only and exits with code `0` when healthy and `1` when unhealthy or invalid.

Confirmed CLI arguments:

- `--account-type=SPOT`
- `--balance-max-age=180`
- `--kline-max-age=180`
- `--current-cache-max-age=180`
- `--api-error-window=15`

Checks performed:

- Recent ingestion activity within the API error window.
- Recent failed ingest runs within the API error window.
- Latest balance snapshot age.
- Current valuation cache existence.
- Current valuation cache age, measured from `current_portfolio_valuations.price_reference_time`.
- Current valuation cache snapshot match against the latest balance snapshot.
- Current valuation cache coverage and missing-price count.
- Historical valuation cache existence for the latest balance snapshot.
- Active watchlist symbols with `enabled = 1` and `symbols.status = 'TRADING'`.
- Latest 1m candle age for each active symbol.
- Missing or stale klines.
- Recent API errors within the API error window.

Output fields:

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
- `current_cache_snapshot_match` as `yes`, `no`, or `NA`
- `coverage`
- `missing_prices`
- `historical_cache` as `yes`, `no`, or `NA`
- `recent_api_errors`
- `status`
- `reasons` when unhealthy

Failure reasons emitted by source:

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

## 7. Scheduling And Locking

Confirmed production cron configuration, provided externally and not stored in Git:

```cron
* * * * * flock -n /var/run/bapi-ingestion.lock -c 'cd /var/www/html/binance && /usr/bin/php php/ingest/run_ingestion_cycle.php' >> /var/log/bapi-ingestion.log 2>&1
*/5 * * * * flock /var/run/bapi-ingestion.lock -c 'cd /var/www/html/binance && /usr/bin/php php/ingest/check_ingestion_health.php' >> /var/log/bapi-health.log 2>&1
```

Operational inference from the confirmed locking choice:

- Ingestion uses `flock -n` so a new minute does not queue behind a still-running cycle. This avoids overlap and backlog growth.
- Health monitoring uses blocking `flock` so it waits for the same lock rather than observing an in-flight mutation or racing the ingestion cycle. This keeps the diagnostic snapshot aligned with committed state.

## 8. Logging And Log Rotation

Confirmed production logs, provided externally and not stored in Git:

- `/var/log/bapi-ingestion.log`
- `/var/log/bapi-health.log`

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

## 9. Deployment

Confirmed paths:

- Local repository path: `X:\WEBSITES\UMB-VPS-03\BAPI`
- Production path: `/var/www/html/binance`
- Shared application bootstrap path outside the repo: `/var/www/html/php/crypto-con.php`
- Shared vendor paths outside the repo: `/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php` and `/var/www/html/vendor/autoload.php`

Confirmed deployment facts from source:

- The repository code is invoked from the production path shown in the cron configuration, and the cron line explicitly performs `cd /var/www/html/binance` before running the relative PHP script path.
- The code loads database and Binance credentials from `/etc/web-applications/trading-app/`.
- No deployment script is present in the inspected repository.

Manual server configuration not stored in Git:

- Cron entries.
- Logrotate configuration.
- External credential files.
- The production path layout itself.

## 10. Failure Recovery

Recovery actions supported by inspected scripts and modes only:

- Stale or missing klines: rerun `sync_active_market_klines.php` in normal mode for the affected intervals, or use `--catchup` and `--max-runs=N` when the goal is to advance lagging symbol/interval pairs until the worker reports caught-up conditions.
- Failed ingestion runs: rerun `run_ingestion_cycle.php`, or rerun the individual worker that failed if the failure is isolated to one stage.
- Stale valuation caches: rerun `calculate_portfolio_snapshot_valuation.php --latest-unvalued` and then `calculate_current_portfolio_valuation.php`.
- API errors: inspect `api_errors` entries for the failing run and rerun the affected ingestion worker after the underlying API or data issue is corrected.
- Lock contention: the ingestion cron exits immediately when the lock is already held; the supported recovery is to wait for the lock to clear and allow the next scheduled minute, or rerun the script after the prior run completes.
- Health-check failures: read the printed `reasons=` field, fix the underlying data freshness or coverage issue, and rerun `check_ingestion_health.php`.

Commands explicitly supported by the inspected code:

```bash
php php/ingest/run_ingestion_cycle.php
php php/ingest/check_ingestion_health.php
php php/ingest/sync_market_klines.php SYMBOL INTERVAL
php php/ingest/catchup_market_klines.php SYMBOL INTERVAL [max_runs]
php php/ingest/calculate_portfolio_snapshot_valuation.php --latest-unvalued
php php/ingest/calculate_current_portfolio_valuation.php
```

## 11. Future Roadmap

The next planned feature is portfolio cost basis, realized and unrealized P&L, and ROI analytics.

Planned means not implemented in the inspected repository. The current codebase only provides ingestion, valuation, and health monitoring.
