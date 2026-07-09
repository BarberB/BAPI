<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL | E_STRICT);

require_once '/var/www/html/php/crypto-con.php';

$startTime = microtime(true);
$completed = 0;
$failed = 0;
$status = 'completed';

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

function add_symbol(&$symbols, $symbol)
{
    $symbol = strtoupper(trim((string) $symbol));
    if ($symbol === '') {
        return;
    }

    if (!preg_match('/^[A-Z0-9]{1,32}$/', $symbol)) {
        throw new InvalidArgumentException('Invalid symbol argument: ' . $symbol);
    }

    $symbols[$symbol] = true;
}

function load_symbol_column($db, $sql, &$symbols)
{
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Failed to load symbols.');
    }

    while ($row = $result->fetch_assoc()) {
        add_symbol($symbols, $row['symbol'] ?? '');
    }

    $result->free();
}

function filter_trading_symbols($db, $candidateSymbols)
{
    $selected = [];
    $stmt = $db->prepare("SELECT 1 FROM crypto.symbols WHERE symbol = ? AND status = 'TRADING' LIMIT 1");

    foreach (array_keys($candidateSymbols) as $symbol) {
        $stmt->bind_param('s', $symbol);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $selected[] = $symbol;
        }
        $stmt->free_result();
    }

    $stmt->close();
    sort($selected, SORT_STRING);

    return $selected;
}

try {
    $db = get_db_connection();
    if ($db->connect_errno) {
        throw new RuntimeException('Database connection failed.');
    }

    $candidateSymbols = [];
    load_symbol_column($db, "SELECT DISTINCT symbol FROM crypto.account_trades", $candidateSymbols);
    load_symbol_column(
        $db,
        "SELECT DISTINCT symbol FROM crypto.ingest_cursors WHERE endpoint = 'account_trades'",
        $candidateSymbols
    );
    load_symbol_column(
        $db,
        "SELECT symbol FROM crypto.ingest_symbol_watchlist WHERE enabled = 1",
        $candidateSymbols
    );

    for ($i = 1; $i < $argc; $i++) {
        add_symbol($candidateSymbols, $argv[$i]);
    }

    $selectedSymbols = filter_trading_symbols($db, $candidateSymbols);
    $workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'sync_account_trades.php';

    foreach ($selectedSymbols as $symbol) {
        $command = escapeshellarg(PHP_BINARY) . ' ' .
            escapeshellarg($workerScript) . ' ' .
            escapeshellarg($symbol);

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $summary = implode("\n", $output);

        if ($exitCode === 0 && strpos($summary, 'status=failed') === false) {
            $completed++;
        } else {
            $failed++;
        }

        usleep(250000);
    }

    if ($failed > 0) {
        $status = 'completed_with_errors';
    }
} catch (Throwable $e) {
    $status = 'failed';
    $failed++;
}

$runtime = round(microtime(true) - $startTime, 2);
$selectedCount = isset($selectedSymbols) ? count($selectedSymbols) : 0;

echo "sync_active_account_trades: selected={$selectedCount}, completed={$completed}, failed={$failed}, runtime={$runtime}s, status={$status}\n";
