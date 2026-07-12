<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "check_ingestion_health: CLI only\n");
    exit(1);
}

require_once '/var/www/html/php/crypto-con.php';

const DEFAULT_ACCOUNT_TYPE = 'SPOT';
const DEFAULT_BALANCE_MAX_AGE_SECONDS = 180;
const DEFAULT_KLINE_MAX_AGE_SECONDS = 180;
const DEFAULT_CURRENT_CACHE_MAX_AGE_SECONDS = 180;
const DEFAULT_API_ERROR_WINDOW_MINUTES = 15;

$accountType = DEFAULT_ACCOUNT_TYPE;
$balanceMaxAgeSeconds = DEFAULT_BALANCE_MAX_AGE_SECONDS;
$klineMaxAgeSeconds = DEFAULT_KLINE_MAX_AGE_SECONDS;
$currentCacheMaxAgeSeconds = DEFAULT_CURRENT_CACHE_MAX_AGE_SECONDS;
$apiErrorWindowMinutes = DEFAULT_API_ERROR_WINDOW_MINUTES;
$nowMs = (int) floor(microtime(true) * 1000);

function usage($message = null)
{
    if ($message !== null) {
        fwrite(STDERR, "check_ingestion_health: {$message}\n");
    }

    $text = <<<TXT
Usage:
  php php/ingest/check_ingestion_health.php [--account-type=SPOT] [--balance-max-age=180] [--kline-max-age=180] [--current-cache-max-age=180] [--api-error-window=15]
TXT;
    fwrite(STDERR, $text . "\n");
    exit($message === null ? 0 : 1);
}

function fail($message)
{
    fwrite(STDERR, "check_ingestion_health: {$message}\n");
    exit(1);
}

function get_db_connection()
{
    global $con, $mysqli, $dbConfig;

    if (isset($con) && $con instanceof mysqli) {
        return $con;
    }

    if (isset($mysqli) && $mysqli instanceof mysqli) {
        return $mysqli;
    }

    if (isset($dbConfig) && is_array($dbConfig)) {
        return new mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database']
        );
    }

    throw new RuntimeException('Database connection is not available.');
}

function bind_params(mysqli_stmt $stmt, $types, array &$params)
{
    $bind = [$types];
    foreach ($params as $index => &$value) {
        $bind[] = &$params[$index];
    }
    unset($value);

    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

function prepare_statement(mysqli $db, $sql, $errorMessage)
{
    $stmt = $db->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException($errorMessage);
    }

    return $stmt;
}

function parse_positive_int_option($value, $label)
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) {
        usage("Invalid {$label} value.");
    }

    return (int) $value;
}

function parse_args(array $argv, &$accountType, &$balanceMaxAgeSeconds, &$klineMaxAgeSeconds, &$currentCacheMaxAgeSeconds, &$apiErrorWindowMinutes)
{
    for ($i = 1; $i < count($argv); $i++) {
        $arg = trim((string) $argv[$i]);
        if ($arg === '') {
            continue;
        }

        if ($arg === '--account-type' || $arg === '--balance-max-age' || $arg === '--kline-max-age' || $arg === '--current-cache-max-age' || $arg === '--api-error-window') {
            usage('Missing value for ' . $arg);
        }

        if (strpos($arg, '--account-type=') === 0) {
            $accountType = strtoupper(trim(substr($arg, strlen('--account-type='))));
            continue;
        }

        if (strpos($arg, '--balance-max-age=') === 0) {
            $balanceMaxAgeSeconds = parse_positive_int_option(substr($arg, strlen('--balance-max-age=')), 'balance-max-age');
            continue;
        }

        if (strpos($arg, '--kline-max-age=') === 0) {
            $klineMaxAgeSeconds = parse_positive_int_option(substr($arg, strlen('--kline-max-age=')), 'kline-max-age');
            continue;
        }

        if (strpos($arg, '--current-cache-max-age=') === 0) {
            $currentCacheMaxAgeSeconds = parse_positive_int_option(substr($arg, strlen('--current-cache-max-age=')), 'current-cache-max-age');
            continue;
        }

        if (strpos($arg, '--api-error-window=') === 0) {
            $apiErrorWindowMinutes = parse_positive_int_option(substr($arg, strlen('--api-error-window=')), 'api-error-window');
            continue;
        }

        usage('Unknown argument: ' . $arg);
    }
}

function age_seconds_from_ms($nowMs, $timestampMs)
{
    if ($timestampMs === null) {
        return null;
    }

    $timestampMs = (int) $timestampMs;
    if ($timestampMs <= 0) {
        return null;
    }

    return max(0, (int) floor((($nowMs - $timestampMs) / 1000)));
}

function age_seconds_from_timestamp($timestamp)
{
    if ($timestamp === null) {
        return null;
    }

    $ts = strtotime((string) $timestamp);
    if ($ts === false) {
        return null;
    }

    return max(0, time() - $ts);
}

function format_age($seconds)
{
    return $seconds === null ? 'NA' : ((int) $seconds) . 's';
}

function load_recent_ingest_activity(mysqli $db, $cutoff)
{
    $sql = "
        SELECT
            COUNT(*) AS recent_runs,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS recent_failed_runs,
            MAX(COALESCE(finished_at, started_at)) AS last_activity_at
        FROM crypto.ingest_runs
        WHERE COALESCE(finished_at, started_at) >= ?
    ";

    $stmt = prepare_statement($db, $sql, 'Failed to load recent ingest activity.');
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: [
        'recent_runs' => 0,
        'recent_failed_runs' => 0,
        'last_activity_at' => null,
    ];
}

function load_recent_api_errors(mysqli $db, $cutoff)
{
    $sql = "
        SELECT
            COUNT(*) AS recent_api_errors,
            COUNT(DISTINCT run_id) AS recent_error_runs,
            MAX(created_at) AS last_api_error_at
        FROM crypto.api_errors
        WHERE created_at >= ?
    ";

    $stmt = prepare_statement($db, $sql, 'Failed to load recent API errors.');
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: [
        'recent_api_errors' => 0,
        'recent_error_runs' => 0,
        'last_api_error_at' => null,
    ];
}

function load_latest_balance_snapshot_time(mysqli $db, $accountType)
{
    $stmt = prepare_statement(
        $db,
        "SELECT snapshot_time
         FROM crypto.balance_snapshots
         WHERE account_type = ?
         ORDER BY snapshot_time DESC
         LIMIT 1",
        'Failed to load latest balance snapshot.'
    );
    $stmt->bind_param('s', $accountType);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) $row['snapshot_time'] : null;
}

function load_current_cache_row(mysqli $db, $accountType)
{
    $stmt = prepare_statement(
        $db,
        "SELECT balance_snapshot_time, price_reference_time, estimated_value_usdt, stablecoin_value_usdt,
                valued_asset_count, total_nonzero_asset_count, missing_asset_count, coverage_percentage,
                fresh_price_count, aging_price_count, stale_price_count, missing_price_count
         FROM crypto.current_portfolio_valuations
         WHERE account_type = ?
         LIMIT 1",
        'Failed to load current portfolio valuation cache.'
    );
    $stmt->bind_param('s', $accountType);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function historical_cache_exists(mysqli $db, $accountType, $snapshotTime)
{
    $stmt = prepare_statement(
        $db,
        "SELECT 1
         FROM crypto.portfolio_snapshot_valuations
         WHERE account_type = ? AND snapshot_time = ?
         LIMIT 1",
        'Failed to load historical portfolio valuation cache.'
    );
    $stmt->bind_param('ss', $accountType, $snapshotTime);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function load_active_symbols(mysqli $db)
{
    $symbols = [];
    $sql = "
        SELECT DISTINCT w.symbol
        FROM crypto.ingest_symbol_watchlist w
        INNER JOIN crypto.symbols s ON s.symbol = w.symbol
        WHERE w.enabled = 1 AND s.status = 'TRADING'
        ORDER BY w.symbol
    ";
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Failed to load active symbols.');
    }

    while ($row = $result->fetch_assoc()) {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol !== '' && preg_match('/^[A-Z0-9]{1,32}$/', $symbol)) {
            $symbols[$symbol] = true;
        }
    }
    $result->free();

    $symbols = array_keys($symbols);
    sort($symbols, SORT_STRING);

    return $symbols;
}

function load_latest_1m_candles(mysqli $db, array $symbols, $nowMs)
{
    if (count($symbols) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($symbols), '?'));
    $types = str_repeat('s', count($symbols)) . 's';
    $params = array_values($symbols);
    $params[] = $nowMs;

    $sql = "
        SELECT symbol, MAX(close_time) AS close_time
        FROM crypto.market_klines
        WHERE symbol IN ({$placeholders})
          AND interval_name = '1m'
          AND close_time <= ?
        GROUP BY symbol
        ORDER BY symbol
    ";

    $stmt = prepare_statement($db, $sql, 'Failed to load latest active 1m candles.');
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $candles = [];
    while ($row = $result->fetch_assoc()) {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol === '') {
            continue;
        }

        $candles[$symbol] = [
            'symbol' => $symbol,
            'close_time' => (int) ($row['close_time'] ?? 0),
        ];
    }

    $stmt->close();
    return $candles;
}

try {
    parse_args($argv, $accountType, $balanceMaxAgeSeconds, $klineMaxAgeSeconds, $currentCacheMaxAgeSeconds, $apiErrorWindowMinutes);

    if (!preg_match('/^[A-Z0-9_]{1,32}$/', $accountType)) {
        usage('Invalid account type.');
    }

    $db = get_db_connection();
    if ($db->connect_errno) {
        fail('Database connection failed.');
    }

    $ingestCutoff = date('Y-m-d H:i:s', (int) floor($nowMs / 1000) - ($apiErrorWindowMinutes * 60));
    $apiErrorCutoff = $ingestCutoff;

    $reasons = [];

    $ingestHealth = load_recent_ingest_activity($db, $ingestCutoff);
    $recentIngestRuns = (int) ($ingestHealth['recent_runs'] ?? 0);
    $recentFailedRuns = (int) ($ingestHealth['recent_failed_runs'] ?? 0);
    $lastIngestAge = format_age(age_seconds_from_timestamp($ingestHealth['last_activity_at'] ?? null));
    if ($recentIngestRuns === 0) {
        $reasons[] = 'no_recent_ingest_activity';
    }
    if ($recentFailedRuns > 0) {
        $reasons[] = 'recent_failed_ingest_runs';
    }

    $latestBalanceSnapshotTime = load_latest_balance_snapshot_time($db, $accountType);
    if ($latestBalanceSnapshotTime === null) {
        $balanceAge = null;
        $reasons[] = 'missing_balance_snapshot';
    } else {
        $balanceAge = age_seconds_from_ms($nowMs, $latestBalanceSnapshotTime);
        if ($balanceAge !== null && $balanceAge > $balanceMaxAgeSeconds) {
            $reasons[] = 'stale_balance';
        }
    }

    $currentCacheRow = load_current_cache_row($db, $accountType);
    if ($currentCacheRow === null) {
        $currentCacheAge = null;
        $currentCacheSnapshotMatch = $latestBalanceSnapshotTime === null ? 'NA' : 'no';
        $currentCoverage = 'NA';
        $currentMissingPrices = 'NA';
        $reasons[] = 'missing_current_cache';
    } else {
        $currentCacheAge = age_seconds_from_ms($nowMs, (int) ($currentCacheRow['price_reference_time'] ?? 0));
        $currentCacheSnapshotTime = isset($currentCacheRow['balance_snapshot_time']) ? (int) $currentCacheRow['balance_snapshot_time'] : null;
        if ($latestBalanceSnapshotTime === null) {
            $currentCacheSnapshotMatch = 'NA';
        } else {
            $currentCacheSnapshotMatch = ($currentCacheSnapshotTime === $latestBalanceSnapshotTime) ? 'yes' : 'no';
            if ($currentCacheSnapshotMatch === 'no') {
                $reasons[] = 'current_cache_snapshot_mismatch';
            }
        }

        $currentCoverage = (string) ($currentCacheRow['coverage_percentage'] ?? '0.000000');
        $currentMissingPrices = (int) ($currentCacheRow['missing_price_count'] ?? 0);
        if ($currentCacheAge !== null && $currentCacheAge > $currentCacheMaxAgeSeconds) {
            $reasons[] = 'stale_current_cache';
        }
        if ($currentMissingPrices > 0) {
            $reasons[] = 'current_cache_missing_prices';
        }
        if ((float) $currentCoverage < 100.0) {
            $reasons[] = 'current_cache_incomplete_coverage';
        }
    }

    if ($latestBalanceSnapshotTime === null) {
        $historicalCache = 'NA';
    } else {
        $historicalCache = historical_cache_exists($db, $accountType, (string) $latestBalanceSnapshotTime) ? 'yes' : 'no';
        if ($historicalCache === 'no') {
            $reasons[] = 'missing_historical_cache';
        }
    }

    $activeSymbols = load_active_symbols($db);
    $activeSymbolCount = count($activeSymbols);
    if ($activeSymbolCount === 0) {
        $oldestKlineAge = null;
        $staleKlonesCount = 0;
        $missingKlonesCount = 0;
        $reasons[] = 'no_active_symbols';
    } else {
        $latestCandles = load_latest_1m_candles($db, $activeSymbols, $nowMs);
        $foundCount = 0;
        $staleKlonesCount = 0;
        $missingKlonesCount = 0;
        $oldestKlineAge = null;

        foreach ($activeSymbols as $symbol) {
            if (!isset($latestCandles[$symbol])) {
                $missingKlonesCount++;
                continue;
            }

            $foundCount++;
            $ageSeconds = age_seconds_from_ms($nowMs, (int) $latestCandles[$symbol]['close_time']);
            if ($ageSeconds !== null) {
                if ($oldestKlineAge === null || $ageSeconds > $oldestKlineAge) {
                    $oldestKlineAge = $ageSeconds;
                }
                if ($ageSeconds > $klineMaxAgeSeconds) {
                    $staleKlonesCount++;
                }
            } else {
                $missingKlonesCount++;
            }
        }

        if ($missingKlonesCount > 0) {
            $reasons[] = 'missing_klines';
        }
        if ($staleKlonesCount > 0) {
            $reasons[] = 'stale_klines';
        }
        if ($foundCount === 0) {
            $oldestKlineAge = null;
        }
    }

    $apiErrors = load_recent_api_errors($db, $apiErrorCutoff);
    $recentApiErrors = (int) ($apiErrors['recent_api_errors'] ?? 0);
    if ($recentApiErrors > 0) {
        $reasons[] = 'recent_api_errors';
    }

    $reasons = array_values(array_unique($reasons));
    sort($reasons, SORT_STRING);
    $status = count($reasons) === 0 ? 'healthy' : 'unhealthy';

    $fields = [
        'check_ingestion_health: account_type=' . $accountType,
        'last_ingest_age=' . $lastIngestAge,
        'recent_ingest_runs=' . $recentIngestRuns,
        'recent_failed_runs=' . $recentFailedRuns,
        'balance_age=' . format_age($balanceAge),
        'active_symbols=' . $activeSymbolCount,
        'oldest_kline_age=' . format_age($oldestKlineAge),
        'stale_klines=' . $staleKlonesCount,
        'missing_klines=' . $missingKlonesCount,
        'current_cache_age=' . format_age($currentCacheAge),
        'current_cache_snapshot_match=' . $currentCacheSnapshotMatch,
        'coverage=' . ($currentCoverage === 'NA' ? 'NA' : $currentCoverage),
        'missing_prices=' . ($currentMissingPrices === 'NA' ? 'NA' : (string) $currentMissingPrices),
        'historical_cache=' . $historicalCache,
        'recent_api_errors=' . $recentApiErrors,
        'status=' . $status,
    ];

    if (count($reasons) > 0) {
        $fields[] = 'reasons=' . implode(',', $reasons);
    }

    fwrite(STDOUT, implode(' ', $fields) . "\n");
    exit($status === 'healthy' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(
        STDOUT,
        'check_ingestion_health: account_type=' . $accountType .
        ' status=unhealthy reasons=internal_error' . "\n"
    );
    exit(1);
}
