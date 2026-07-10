<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL | E_STRICT);

$startTime = microtime(true);
$completed = 0;
$failed = 0;
$status = 'completed';

$steps = [
    ['script' => 'sync_symbols.php', 'args' => []],
    ['script' => 'sync_balance_snapshot.php', 'args' => []],
    ['script' => 'sync_active_account_trades.php', 'args' => []],
    ['script' => 'sync_active_market_klines.php', 'args' => ['1m']],
];

foreach ($steps as $step) {
    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . $step['script'];
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath);

    foreach ($step['args'] as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $childOutput = trim(implode("\n", $output));
    if ($childOutput !== '') {
        echo $childOutput . "\n";
    }

    if ($exitCode === 0 && strpos($childOutput, 'status=failed') === false) {
        $completed++;
    } else {
        $failed++;
        $status = 'failed';
    }
}

$runtime = round(microtime(true) - $startTime, 2);

echo "run_ingestion_cycle: completed={$completed}, failed={$failed}, runtime={$runtime}s, status={$status}\n";
