<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL | E_STRICT);

require_once '/var/www/html/php/crypto-con.php';

$startTime = microtime(true);
$allowedIntervals = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];
$catchup = false;
$maxRuns = 10;
$intervals = [];
$completed = 0;
$failed = 0;
$status = 'completed';

function fail_usage($message)
{
    echo "sync_active_market_klines: {$message}\n";
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

function load_watchlist_symbols($db)
{
    $symbols = [];
    $sql = "SELECT DISTINCT w.symbol
            FROM crypto.ingest_symbol_watchlist w
            INNER JOIN crypto.symbols s ON s.symbol = w.symbol
            WHERE w.enabled = 1 AND s.status = 'TRADING'";
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Failed to load watchlist symbols.');
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

for ($i = 1; $i < $argc; $i++) {
    $arg = trim($argv[$i]);

    if ($arg === '--catchup') {
        $catchup = true;
        continue;
    }

    if (strpos($arg, '--max-runs=') === 0) {
        $maxRuns = substr($arg, strlen('--max-runs='));
        if (!is_numeric($maxRuns) || (string) (int) $maxRuns !== (string) $maxRuns) {
            fail_usage('Invalid max-runs value.');
        }
        $maxRuns = (int) $maxRuns;
        if ($maxRuns < 1 || $maxRuns > 100) {
            fail_usage('max-runs must be between 1 and 100.');
        }
        continue;
    }

    if (!in_array($arg, $allowedIntervals, true)) {
        fail_usage('Invalid interval argument: ' . $arg);
    }

    $intervals[$arg] = true;
}

if (count($intervals) === 0) {
    $intervals['1m'] = true;
}

$intervals = array_keys($intervals);
sort($intervals, SORT_STRING);

try {
    $db = get_db_connection();
    if ($db->connect_errno) {
        throw new RuntimeException('Database connection failed.');
    }

    $symbols = load_watchlist_symbols($db);
    $workerScript = __DIR__ . DIRECTORY_SEPARATOR . ($catchup ? 'catchup_market_klines.php' : 'sync_market_klines.php');

    foreach ($symbols as $symbol) {
        foreach ($intervals as $interval) {
            $command = escapeshellarg(PHP_BINARY) . ' ' .
                escapeshellarg($workerScript) . ' ' .
                escapeshellarg($symbol) . ' ' .
                escapeshellarg($interval);

            if ($catchup) {
                $command .= ' ' . escapeshellarg((string) $maxRuns);
            }

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
            $summary = trim(implode("\n", $output));

            if ($summary !== '') {
                echo $summary . "\n";
            }

            if ($exitCode === 0 && strpos($summary, 'status=failed') === false) {
                $completed++;
            } else {
                $failed++;
            }

            usleep(250000);
        }
    }

    if ($failed > 0) {
        $status = 'completed_with_errors';
    }
} catch (Throwable $e) {
    $status = 'failed';
    $failed++;
}

$runtime = round(microtime(true) - $startTime, 2);
$symbolCount = isset($symbols) ? count($symbols) : 0;
$intervalCount = count($intervals);

echo "sync_active_market_klines: symbols={$symbolCount}, intervals={$intervalCount}, completed={$completed}, failed={$failed}, runtime={$runtime}s, status={$status}\n";
