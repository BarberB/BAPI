<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL | E_STRICT);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "calculate_portfolio_snapshot_valuation: CLI only\n");
    exit(1);
}

require_once '/var/www/html/php/crypto-con.php';

const CALCULATION_VERSION = 'portfolio_snapshot_v1';
const PARITY_ASSETS = ['USD', 'USDT', 'USDC', 'FDUSD', 'BUSD'];
const INTERVAL_ORDER = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];
const FRESH_THRESHOLD_MS = 300000;
const AGING_THRESHOLD_MS = 1800000;

$mode = null;
$snapshotTimeArg = null;
$rangeFromArg = null;
$rangeToArg = null;
$accountType = 'SPOT';
$limit = null;

$attempted = 0;
$completed = 0;
$failed = 0;
$overallStatus = 'completed';

function usage($message = null)
{
    if ($message !== null) {
        fwrite(STDERR, "calculate_portfolio_snapshot_valuation: {$message}\n");
    }

    $text = <<<TXT
Usage:
  php php/ingest/calculate_portfolio_snapshot_valuation.php --snapshot-time=MS [--account-type=SPOT]
  php php/ingest/calculate_portfolio_snapshot_valuation.php --latest-unvalued [--account-type=SPOT] [--limit=N]
  php php/ingest/calculate_portfolio_snapshot_valuation.php --from=MS --to=MS [--account-type=SPOT] [--limit=N]
  php php/ingest/calculate_portfolio_snapshot_valuation.php --all [--account-type=SPOT] [--limit=N]
TXT;
    fwrite(STDERR, $text . "\n");
    exit($message === null ? 0 : 1);
}

function fail($message)
{
    fwrite(STDERR, "calculate_portfolio_snapshot_valuation: {$message}\n");
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

function json_value($value)
{
    if ($value === null) {
        return null;
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
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

function decimal_zeros()
{
    return '0.000000000000000000';
}

function decimal_from_value($value)
{
    if ($value === null || $value === '') {
        return decimal_zeros();
    }

    if (is_string($value)) {
        return $value;
    }

    return number_format((float) $value, 18, '.', '');
}

function decimal_add($left, $right)
{
    if (function_exists('bcadd')) {
        return bcadd(decimal_from_value($left), decimal_from_value($right), 18);
    }

    return number_format(((float) $left) + ((float) $right), 18, '.', '');
}

function decimal_mul($left, $right)
{
    if (function_exists('bcmul')) {
        return bcmul(decimal_from_value($left), decimal_from_value($right), 18);
    }

    return number_format(((float) $left) * ((float) $right), 18, '.', '');
}

function decimal_cmp_zero($value)
{
    if (function_exists('bccomp')) {
        return bccomp(decimal_from_value($value), '0', 18);
    }

    $floatValue = (float) $value;
    if ($floatValue > 0) {
        return 1;
    }

    if ($floatValue < 0) {
        return -1;
    }

    return 0;
}

function decimal_format_for_log($value)
{
    $value = decimal_from_value($value);
    $value = rtrim($value, '0');
    $value = rtrim($value, '.');
    return $value === '' ? '0' : $value;
}

function is_parity_asset($asset)
{
    return in_array($asset, PARITY_ASSETS, true);
}

function valid_int_string($value)
{
    return is_string($value) && preg_match('/^-?[0-9]+$/', $value);
}

function load_snapshot_times(mysqli $db, $accountType, $mode, $fromTime = null, $toTime = null, $limit = null)
{
    if ($mode === 'snapshot') {
        return [$fromTime];
    }

    if ($mode === 'latest_unvalued') {
        $snapshots = [];
        $stmt = $db->prepare(
            "SELECT DISTINCT snapshot_time
             FROM crypto.balance_snapshots
             WHERE account_type = ?
             ORDER BY snapshot_time DESC"
        );
        $stmt->bind_param('s', $accountType);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $snapshots[] = (string) $row['snapshot_time'];
        }
        $stmt->close();

        $valued = [];
        $stmt = $db->prepare(
            "SELECT snapshot_time
             FROM crypto.portfolio_snapshot_valuations
             WHERE account_type = ?"
        );
        $stmt->bind_param('s', $accountType);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $valued[(string) $row['snapshot_time']] = true;
        }
        $stmt->close();

        $unvalued = [];
        foreach ($snapshots as $snapshotTime) {
            if (!isset($valued[$snapshotTime])) {
                $unvalued[] = $snapshotTime;
            }
        }

        if ($limit !== null) {
            $unvalued = array_slice($unvalued, 0, $limit);
        }

        return $unvalued;
    }

    $sql = "SELECT DISTINCT snapshot_time
            FROM crypto.balance_snapshots
            WHERE account_type = ?";
    $types = 's';
    $params = [$accountType];

    if ($mode === 'range') {
        $sql .= " AND snapshot_time BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $fromTime;
        $params[] = $toTime;
    }

    $sql .= " ORDER BY snapshot_time ASC";
    if ($limit !== null) {
        $sql .= " LIMIT ?";
        $types .= 'i';
        $params[] = $limit;
    }

    $stmt = $db->prepare($sql);
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $snapshots = [];
    while ($row = $result->fetch_assoc()) {
        $snapshots[] = (string) $row['snapshot_time'];
    }
    $stmt->close();

    return $snapshots;
}

function load_holdings(mysqli $db, $accountType, $snapshotTime)
{
    $stmt = $db->prepare(
        "SELECT asset, total
         FROM crypto.balance_snapshots
         WHERE account_type = ? AND snapshot_time = ? AND total <> 0
         ORDER BY asset ASC"
    );
    $stmt->bind_param('ss', $accountType, $snapshotTime);
    $stmt->execute();
    $result = $stmt->get_result();

    $holdings = [];
    while ($row = $result->fetch_assoc()) {
        $asset = strtoupper(trim((string) ($row['asset'] ?? '')));
        if ($asset === '') {
            continue;
        }

        $holdings[] = [
            'asset' => $asset,
            'total' => decimal_from_value($row['total'] ?? decimal_zeros()),
        ];
    }

    $stmt->close();
    return $holdings;
}

function load_supported_symbols(mysqli $db, array $symbols)
{
    if (count($symbols) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($symbols), '?'));
    $types = str_repeat('s', count($symbols));
    $sql = "SELECT symbol, base_asset, quote_asset
            FROM crypto.symbols
            WHERE symbol IN ({$placeholders}) AND quote_asset = 'USDT'";

    $stmt = $db->prepare($sql);
    bind_params($stmt, $types, $symbols);
    $stmt->execute();
    $result = $stmt->get_result();

    $supported = [];
    while ($row = $result->fetch_assoc()) {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol !== '') {
            $supported[$symbol] = [
                'symbol' => $symbol,
                'base_asset' => strtoupper(trim((string) ($row['base_asset'] ?? ''))),
                'quote_asset' => strtoupper(trim((string) ($row['quote_asset'] ?? ''))),
            ];
        }
    }

    $stmt->close();
    return $supported;
}

function load_latest_candles(mysqli $db, array $symbols, $snapshotTime)
{
    if (count($symbols) === 0) {
        return [];
    }

    $symbolPlaceholders = implode(',', array_fill(0, count($symbols), '?'));
    $intervalPlaceholders = implode(',', array_fill(0, count(INTERVAL_ORDER), '?'));
    $types = str_repeat('s', count($symbols)) . 's' . str_repeat('s', count(INTERVAL_ORDER));
    $params = array_values($symbols);
    $params[] = $snapshotTime;
    foreach (INTERVAL_ORDER as $intervalName) {
        $params[] = $intervalName;
    }

    $sql = "SELECT mk.symbol, mk.interval_name, mk.open_time, mk.close_time, mk.close_price
            FROM crypto.market_klines mk
            INNER JOIN (
                SELECT symbol, interval_name, MAX(close_time) AS close_time
                FROM crypto.market_klines
                WHERE symbol IN ({$symbolPlaceholders})
                  AND close_time <= ?
                  AND interval_name IN ({$intervalPlaceholders})
                GROUP BY symbol, interval_name
            ) latest
              ON latest.symbol = mk.symbol
             AND latest.interval_name = mk.interval_name
             AND latest.close_time = mk.close_time
            ORDER BY mk.symbol ASC, FIELD(mk.interval_name, '1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d') ASC,
                     mk.close_time DESC, mk.open_time DESC";

    $stmt = $db->prepare($sql);
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $candles = [];
    while ($row = $result->fetch_assoc()) {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        $intervalName = trim((string) ($row['interval_name'] ?? ''));
        if ($symbol === '' || $intervalName === '') {
            continue;
        }

        if (!isset($candles[$symbol])) {
            $candles[$symbol] = [];
        }

        if (!isset($candles[$symbol][$intervalName])) {
            $candles[$symbol][$intervalName] = [
                'symbol' => $symbol,
                'interval_name' => $intervalName,
                'open_time' => (string) $row['open_time'],
                'close_time' => (string) $row['close_time'],
                'close_price' => decimal_from_value($row['close_price'] ?? decimal_zeros()),
            ];
        }
    }

    $stmt->close();
    return $candles;
}

function choose_best_candle(array $candlesByInterval)
{
    foreach (INTERVAL_ORDER as $intervalName) {
        if (isset($candlesByInterval[$intervalName])) {
            return $candlesByInterval[$intervalName];
        }
    }

    return null;
}

function calculate_snapshot(mysqli $db, $accountType, $snapshotTime)
{
    $holdings = load_holdings($db, $accountType, $snapshotTime);
    $totalNonzeroAssetCount = count($holdings);

    $valuationAssets = [];
    foreach ($holdings as $holding) {
        if (!is_parity_asset($holding['asset'])) {
            $valuationAssets[] = $holding['asset'] . 'USDT';
        }
    }

    $supportedSymbols = load_supported_symbols($db, array_values(array_unique($valuationAssets)));
    $latestCandles = load_latest_candles($db, array_keys($supportedSymbols), $snapshotTime);

    $estimatedValue = decimal_zeros();
    $stablecoinValue = decimal_zeros();
    $valuedAssetCount = 0;
    $missingAssetCount = 0;
    $freshPriceCount = 0;
    $agingPriceCount = 0;
    $stalePriceCount = 0;
    $missingPriceCount = 0;
    $missingAssets = [];
    $priceSources = [];

    foreach ($holdings as $holding) {
        $asset = $holding['asset'];
        $total = $holding['total'];

        if (is_parity_asset($asset)) {
            $value = decimal_mul($total, '1.000000000000000000');
            $estimatedValue = decimal_add($estimatedValue, $value);
            $stablecoinValue = decimal_add($stablecoinValue, $value);
            $valuedAssetCount++;
            $priceSources[] = [
                'asset' => $asset,
                'source_type' => 'stablecoin_parity',
                'price' => '1.000000000000000000',
            ];
            continue;
        }

        $expectedPair = $asset . 'USDT';
        if (!isset($supportedSymbols[$expectedPair])) {
            $missingAssetCount++;
            $missingPriceCount++;
            $missingAssets[] = [
                'asset' => $asset,
                'expected_pair' => $expectedPair,
                'reason' => 'missing_local_symbol',
            ];
            continue;
        }

        $candle = isset($latestCandles[$expectedPair]) ? choose_best_candle($latestCandles[$expectedPair]) : null;
        if ($candle === null) {
            $missingAssetCount++;
            $missingPriceCount++;
            $missingAssets[] = [
                'asset' => $asset,
                'expected_pair' => $expectedPair,
                'reason' => 'missing_local_candle',
            ];
            continue;
        }

        $closeTime = (string) $candle['close_time'];
        $closePrice = decimal_from_value($candle['close_price']);
        $ageMs = (float) $snapshotTime - (float) $closeTime;
        if ($ageMs <= FRESH_THRESHOLD_MS) {
            $freshPriceCount++;
            $freshness = 'fresh';
        } elseif ($ageMs <= AGING_THRESHOLD_MS) {
            $agingPriceCount++;
            $freshness = 'aging';
        } else {
            $stalePriceCount++;
            $freshness = 'stale';
        }

        $value = decimal_mul($total, $closePrice);
        $estimatedValue = decimal_add($estimatedValue, $value);
        $valuedAssetCount++;

        $priceSources[] = [
            'asset' => $asset,
            'source_type' => 'local_candle',
            'symbol' => $expectedPair,
            'interval_name' => $candle['interval_name'],
            'close_time' => (int) $candle['close_time'],
            'close_price' => $closePrice,
            'freshness' => $freshness,
        ];
    }

    $coveragePercentage = $totalNonzeroAssetCount > 0
        ? number_format(($valuedAssetCount / $totalNonzeroAssetCount) * 100, 6, '.', '')
        : '0.000000';

    usort($missingAssets, static function (array $left, array $right) {
        return strcmp($left['asset'], $right['asset']);
    });

    usort($priceSources, static function (array $left, array $right) {
        return strcmp($left['asset'], $right['asset']);
    });

    return [
        'account_type' => $accountType,
        'snapshot_time' => $snapshotTime,
        'estimated_value_usdt' => $estimatedValue,
        'stablecoin_value_usdt' => $stablecoinValue,
        'valued_asset_count' => $valuedAssetCount,
        'total_nonzero_asset_count' => $totalNonzeroAssetCount,
        'missing_asset_count' => $missingAssetCount,
        'coverage_percentage' => $coveragePercentage,
        'fresh_price_count' => $freshPriceCount,
        'aging_price_count' => $agingPriceCount,
        'stale_price_count' => $stalePriceCount,
        'missing_price_count' => $missingPriceCount,
        'missing_assets' => json_value($missingAssets),
        'price_sources' => json_value($priceSources),
        'calculation_version' => CALCULATION_VERSION,
    ];
}

function upsert_snapshot_valuation(mysqli $db, array $payload)
{
    $stmt = $db->prepare(
        "INSERT INTO crypto.portfolio_snapshot_valuations
            (account_type, snapshot_time, estimated_value_usdt, stablecoin_value_usdt,
             valued_asset_count, total_nonzero_asset_count, missing_asset_count,
             coverage_percentage, fresh_price_count, aging_price_count, stale_price_count,
             missing_price_count, missing_assets, price_sources, calculation_version)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            estimated_value_usdt = VALUES(estimated_value_usdt),
            stablecoin_value_usdt = VALUES(stablecoin_value_usdt),
            valued_asset_count = VALUES(valued_asset_count),
            total_nonzero_asset_count = VALUES(total_nonzero_asset_count),
            missing_asset_count = VALUES(missing_asset_count),
            coverage_percentage = VALUES(coverage_percentage),
            fresh_price_count = VALUES(fresh_price_count),
            aging_price_count = VALUES(aging_price_count),
            stale_price_count = VALUES(stale_price_count),
            missing_price_count = VALUES(missing_price_count),
            missing_assets = VALUES(missing_assets),
            price_sources = VALUES(price_sources),
            calculation_version = VALUES(calculation_version),
            calculated_at = CURRENT_TIMESTAMP"
    );

    $stmt->bind_param(
        'ssssiiisiiiisss',
        $payload['account_type'],
        $payload['snapshot_time'],
        $payload['estimated_value_usdt'],
        $payload['stablecoin_value_usdt'],
        $payload['valued_asset_count'],
        $payload['total_nonzero_asset_count'],
        $payload['missing_asset_count'],
        $payload['coverage_percentage'],
        $payload['fresh_price_count'],
        $payload['aging_price_count'],
        $payload['stale_price_count'],
        $payload['missing_price_count'],
        $payload['missing_assets'],
        $payload['price_sources'],
        $payload['calculation_version']
    );

    $stmt->execute();
    $stmt->close();
}

function load_mode_from_args(array $argv)
{
    global $mode, $snapshotTimeArg, $rangeFromArg, $rangeToArg, $accountType, $limit;

    for ($i = 1; $i < count($argv); $i++) {
        $arg = trim((string) $argv[$i]);
        if ($arg === '') {
            continue;
        }

        if ($arg === '--snapshot-time') {
            usage('Missing value for --snapshot-time');
        }
        if ($arg === '--from') {
            usage('Missing value for --from');
        }
        if ($arg === '--to') {
            usage('Missing value for --to');
        }
        if ($arg === '--account-type') {
            usage('Missing value for --account-type');
        }
        if ($arg === '--limit') {
            usage('Missing value for --limit');
        }

        if (strpos($arg, '--snapshot-time=') === 0) {
            if ($mode !== null) {
                usage('Only one mode may be selected.');
            }
            $mode = 'snapshot';
            $snapshotTimeArg = substr($arg, strlen('--snapshot-time='));
            continue;
        }

        if (strpos($arg, '--from=') === 0) {
            if ($mode !== null) {
                usage('Only one mode may be selected.');
            }
            $mode = 'range';
            $rangeFromArg = substr($arg, strlen('--from='));
            continue;
        }

        if (strpos($arg, '--to=') === 0) {
            $rangeToArg = substr($arg, strlen('--to='));
            continue;
        }

        if ($arg === '--latest-unvalued') {
            if ($mode !== null) {
                usage('Only one mode may be selected.');
            }
            $mode = 'latest_unvalued';
            continue;
        }

        if ($arg === '--all') {
            if ($mode !== null) {
                usage('Only one mode may be selected.');
            }
            $mode = 'all';
            continue;
        }

        if (strpos($arg, '--account-type=') === 0) {
            $accountType = strtoupper(trim(substr($arg, strlen('--account-type='))));
            continue;
        }

        if (strpos($arg, '--limit=') === 0) {
            $limitValue = substr($arg, strlen('--limit='));
            if (!valid_int_string($limitValue) || (int) $limitValue < 1) {
                usage('Invalid limit value.');
            }
            $limit = (int) $limitValue;
            continue;
        }

        usage('Unknown argument: ' . $arg);
    }
}

try {
    load_mode_from_args($argv);

    if ($mode === null) {
        usage('A mode is required.');
    }

    if (!preg_match('/^[A-Z0-9_]{1,32}$/', $accountType)) {
        usage('Invalid account type.');
    }

    if ($mode === 'snapshot') {
        if ($limit !== null) {
            usage('--limit is not allowed with --snapshot-time.');
        }
        if (!valid_int_string($snapshotTimeArg) || (string) (int) $snapshotTimeArg !== $snapshotTimeArg) {
            usage('Invalid snapshot time.');
        }
        $snapshotTimeArg = (string) $snapshotTimeArg;
    } elseif ($mode === 'range') {
        if ($rangeFromArg === null || $rangeToArg === null) {
            usage('--from and --to must be provided together.');
        }
        if (!valid_int_string($rangeFromArg) || (string) (int) $rangeFromArg !== $rangeFromArg) {
            usage('Invalid --from value.');
        }
        if (!valid_int_string($rangeToArg) || (string) (int) $rangeToArg !== $rangeToArg) {
            usage('Invalid --to value.');
        }
        if ((int) $rangeFromArg > (int) $rangeToArg) {
            usage('--from must be less than or equal to --to.');
        }
        $rangeFromArg = (string) $rangeFromArg;
        $rangeToArg = (string) $rangeToArg;
    }

    $db = get_db_connection();
    if ($db->connect_errno) {
        fail('Database connection failed.');
    }

    $tableCheck = $db->prepare(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'portfolio_snapshot_valuations'
         LIMIT 1"
    );
    $tableCheck->execute();
    $tableResult = $tableCheck->get_result();
    if (!$tableResult || $tableResult->num_rows === 0) {
        $tableCheck->close();
        fail('portfolio_snapshot_valuations table does not exist.');
    }
    $tableCheck->close();

    $snapshotTimes = load_snapshot_times($db, $accountType, $mode, $snapshotTimeArg, $rangeToArg, $limit);
    if (count($snapshotTimes) === 0) {
        fwrite(STDOUT, "calculate_portfolio_snapshot_valuation: snapshots_attempted=0, snapshots_completed=0, snapshots_failed=0, status=completed\n");
        exit(0);
    }

    foreach ($snapshotTimes as $snapshotTime) {
        $attempted++;

        try {
            $payload = calculate_snapshot($db, $accountType, $snapshotTime);
            if (!$db->begin_transaction()) {
                throw new RuntimeException('Failed to start database transaction.');
            }
            upsert_snapshot_valuation($db, $payload);
            $db->commit();
            $completed++;

            $line = sprintf(
                'calculate_portfolio_snapshot_valuation: account_type=%s snapshot_time=%s total_assets=%d valued_assets=%d missing_assets=%d coverage=%s estimated_value=%s status=completed',
                $payload['account_type'],
                $payload['snapshot_time'],
                $payload['total_nonzero_asset_count'],
                $payload['valued_asset_count'],
                $payload['missing_asset_count'],
                $payload['coverage_percentage'],
                decimal_format_for_log($payload['estimated_value_usdt'])
            );
            fwrite(STDOUT, $line . "\n");
        } catch (Throwable $e) {
            @$db->rollback();
            $failed++;
            $overallStatus = 'failed';
            fwrite(
                STDOUT,
                sprintf(
                    'calculate_portfolio_snapshot_valuation: account_type=%s snapshot_time=%s status=failed error=%s',
                    $accountType,
                    $snapshotTime,
                    preg_replace('/[\\r\\n]+/', ' ', $e->getMessage())
                ) . "\n"
            );
        }
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}

$status = $failed > 0 ? 'completed_with_errors' : 'completed';
fwrite(
    STDOUT,
    sprintf(
        'calculate_portfolio_snapshot_valuation: snapshots_attempted=%d snapshots_completed=%d snapshots_failed=%d status=%s',
        $attempted,
        $completed,
        $failed,
        $status
    ) . "\n"
);

exit($failed > 0 ? 1 : 0);
