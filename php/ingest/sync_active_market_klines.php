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

function parse_worker_summary($summary)
{
    $fields = [];
    if (preg_match_all('/([a-z_]+)=([^,\\s]+)/', $summary, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $fields[$match[1]] = $match[2];
        }
    }

    return $fields;
}

function run_worker($scriptPath, $args)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath);

    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $summary = trim(implode("\n", $output));
    if ($summary !== '') {
        echo $summary . "\n";
    }

    return [$exitCode, $summary, parse_worker_summary($summary)];
}

function kline_stop_reason($fields, $exitCode)
{
    if ($exitCode !== 0) {
        return 'worker_exit_nonzero';
    }

    if (($fields['status'] ?? '') === 'failed') {
        return 'worker_status_failed';
    }

    $klines = isset($fields['klines']) ? (int) $fields['klines'] : 0;
    $inserted = isset($fields['inserted']) ? (int) $fields['inserted'] : 0;
    $updated = isset($fields['updated']) ? (int) $fields['updated'] : 0;

    if ($klines === 0) {
        return 'no_klines';
    }

    if ($inserted === 0 && $updated === 0) {
        return 'no_changes';
    }

    if ($klines < 1000) {
        return 'partial_page';
    }

    return null;
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
    $workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'sync_market_klines.php';

    if (!$catchup) {
        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                [$exitCode, $summary] = run_worker($workerScript, [$symbol, $interval]);

                if ($exitCode === 0 && strpos($summary, 'status=failed') === false) {
                    $completed++;
                } else {
                    $failed++;
                }

                usleep(250000);
            }
        }
    } else {
        // In active catch-up mode, --max-runs is the maximum number of round-robin
        // passes. Each pass gives every still-behind symbol/interval one kline batch.
        $jobs = [];
        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                $key = $symbol . ':' . $interval;
                $jobs[$key] = [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'runs_attempted' => 0,
                    'runs_completed' => 0,
                    'caught_up' => false,
                    'failed' => false,
                    'stop_reason' => 'not_started',
                    'status' => 'pending',
                ];
            }
        }

        $passesAttempted = 0;
        $batchesAttempted = 0;
        $overallStopReason = 'all_caught_up';
        for ($pass = 1; $pass <= $maxRuns; $pass++) {
            $hasPendingJob = false;
            foreach ($jobs as $job) {
                if (!$job['caught_up'] && !$job['failed']) {
                    $hasPendingJob = true;
                    break;
                }
            }

            if (!$hasPendingJob) {
                $overallStopReason = 'all_caught_up';
                break;
            }

            $passesAttempted++;

            foreach ($jobs as $key => $job) {
                if ($job['caught_up'] || $job['failed']) {
                    continue;
                }

                $jobs[$key]['runs_attempted']++;
                $batchesAttempted++;
                $workerResult = run_worker($workerScript, [$job['symbol'], $job['interval']]);
                $exitCode = $workerResult[0];
                $fields = $workerResult[2];
                $stopReason = kline_stop_reason($fields, $exitCode);

                if ($exitCode === 0 && ($fields['status'] ?? '') !== 'failed') {
                    $jobs[$key]['runs_completed']++;
                    $completed++;
                } else {
                    $jobs[$key]['failed'] = true;
                    $jobs[$key]['status'] = 'failed';
                    $jobs[$key]['stop_reason'] = $stopReason;
                    $failed++;
                    $status = 'failed';
                    $overallStopReason = $stopReason;
                    break 2;
                }

                if ($stopReason !== null) {
                    $jobs[$key]['caught_up'] = true;
                    $jobs[$key]['status'] = 'completed';
                    $jobs[$key]['stop_reason'] = $stopReason;
                } else {
                    $jobs[$key]['status'] = 'behind';
                    $jobs[$key]['stop_reason'] = 'full_page';
                }

                usleep(250000);
            }
        }

        if ($overallStopReason === 'all_caught_up') {
            foreach ($jobs as $job) {
                if (!$job['caught_up'] && !$job['failed']) {
                    $overallStopReason = 'max_passes_reached';
                    break;
                }
            }
        }

        $caughtUp = 0;
        $stillBehind = 0;
        foreach ($jobs as $job) {
            if ($job['caught_up']) {
                $caughtUp++;
            } elseif (!$job['failed']) {
                $stillBehind++;
            }

            echo "sync_active_market_klines_symbol: symbol={$job['symbol']}, interval={$job['interval']}, runs_attempted={$job['runs_attempted']}, runs_completed={$job['runs_completed']}, caught_up=" .
                ($job['caught_up'] ? 'yes' : 'no') .
                ", stop_reason={$job['stop_reason']}, status={$job['status']}\n";
        }

        if ($failed > 0) {
            $status = 'failed';
        } elseif ($stillBehind > 0) {
            $status = 'completed_with_backlog';
        }
    }
} catch (Throwable $e) {
    $status = 'failed';
    $failed++;
}

$runtime = round(microtime(true) - $startTime, 2);
$symbolCount = isset($symbols) ? count($symbols) : 0;
$intervalCount = count($intervals);

if ($catchup) {
    $passesAttempted = isset($passesAttempted) ? $passesAttempted : 0;
    $batchesAttempted = isset($batchesAttempted) ? $batchesAttempted : 0;
    $caughtUp = isset($caughtUp) ? $caughtUp : 0;
    $stillBehind = isset($stillBehind) ? $stillBehind : 0;
    $overallStopReason = isset($overallStopReason) ? $overallStopReason : 'fatal_error';

    echo "sync_active_market_klines: mode=catchup, max_runs_semantics=passes, symbols={$symbolCount}, intervals={$intervalCount}, passes_attempted={$passesAttempted}, total_symbol_batches_attempted={$batchesAttempted}, total_symbol_batches_completed={$completed}, total_symbol_batches_failed={$failed}, symbols_caught_up={$caughtUp}, symbols_still_behind={$stillBehind}, overall_stop_reason={$overallStopReason}, runtime={$runtime}s, status={$status}\n";
} else {
    if ($failed > 0 && $status !== 'failed') {
        $status = 'completed_with_errors';
    }

    echo "sync_active_market_klines: symbols={$symbolCount}, intervals={$intervalCount}, completed={$completed}, failed={$failed}, runtime={$runtime}s, status={$status}\n";
}
