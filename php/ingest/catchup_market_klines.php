<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL | E_STRICT);

$allowedIntervals = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];
$symbol = isset($argv[1]) ? strtoupper(trim($argv[1])) : null;
$interval = isset($argv[2]) ? trim($argv[2]) : null;
$maxRuns = isset($argv[3]) ? $argv[3] : 10;
$runsAttempted = 0;
$runsCompleted = 0;
$stoppedReason = 'max_runs_reached';
$status = 'completed';

function fail_usage($message)
{
    echo "catchup_market_klines: {$message}\n";
    exit(1);
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

if ($symbol === null || $interval === null) {
    fail_usage('Usage: php php/ingest/catchup_market_klines.php SYMBOL INTERVAL [max_runs]');
}

if (!preg_match('/^[A-Z0-9]{1,32}$/', $symbol)) {
    fail_usage('Invalid symbol argument.');
}

if (!in_array($interval, $allowedIntervals, true)) {
    fail_usage('Invalid interval argument.');
}

if (!is_numeric($maxRuns) || (string) (int) $maxRuns !== (string) $maxRuns) {
    fail_usage('Invalid max_runs argument.');
}

$maxRuns = (int) $maxRuns;
if ($maxRuns < 1 || $maxRuns > 100) {
    fail_usage('max_runs must be between 1 and 100.');
}

$workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'sync_market_klines.php';

for ($run = 1; $run <= $maxRuns; $run++) {
    $runsAttempted++;
    $command = escapeshellarg(PHP_BINARY) . ' ' .
        escapeshellarg($workerScript) . ' ' .
        escapeshellarg($symbol) . ' ' .
        escapeshellarg($interval);

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $summary = trim(implode("\n", $output));
    if ($summary !== '') {
        echo $summary . "\n";
    }

    $fields = parse_worker_summary($summary);

    if ($exitCode !== 0) {
        $stoppedReason = 'worker_exit_nonzero';
        $status = 'failed';
        break;
    }

    if (($fields['status'] ?? '') === 'failed') {
        $stoppedReason = 'worker_status_failed';
        $status = 'failed';
        break;
    }

    $runsCompleted++;
    $klines = isset($fields['klines']) ? (int) $fields['klines'] : 0;
    $inserted = isset($fields['inserted']) ? (int) $fields['inserted'] : 0;
    $updated = isset($fields['updated']) ? (int) $fields['updated'] : 0;

    if ($klines === 0) {
        $stoppedReason = 'no_klines';
        break;
    }

    if ($inserted === 0 && $updated === 0) {
        $stoppedReason = 'no_changes';
        break;
    }

    if ($klines < 1000) {
        $stoppedReason = 'partial_page';
        break;
    }

    usleep(250000);
}

echo "catchup_market_klines: symbol={$symbol}, interval={$interval}, runs_attempted={$runsAttempted}, runs_completed={$runsCompleted}, stopped_reason={$stoppedReason}, status={$status}\n";
