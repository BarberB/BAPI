<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL | E_STRICT);

require_once '/var/www/html/php/crypto-con.php';
require_once '/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php';
require_once '/var/www/html/vendor/autoload.php';

$jobName = 'sync_account_trades';
$endpoint = 'account_trades';
$symbolArg = isset($argv[1]) ? strtoupper(trim($argv[1])) : null;
$symbolsProcessed = 0;
$tradesFetched = 0;
$inserted = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
$status = 'started';
$runId = null;
$cursorBefore = [];
$cursorAfter = [];

function json_value($value)
{
    if ($value === null) {
        return null;
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function sanitize_error($message)
{
    return preg_replace('/[A-Za-z0-9_\-]{32,}/', '[redacted]', (string) $message);
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

function log_api_error($db, $runId, $endpoint, $symbol, $message)
{
    $requestParams = json_value(['jobName' => 'sync_account_trades', 'symbol' => $symbol]);
    $apiMessage = sanitize_error($message);

    $stmt = $db->prepare(
        "INSERT INTO crypto.api_errors
            (run_id, endpoint, symbol, api_message, request_params)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $runId, $endpoint, $symbol, $apiMessage, $requestParams);
    $stmt->execute();
    $stmt->close();
}

function load_symbols($db, $symbolArg)
{
    if ($symbolArg !== null && !preg_match('/^[A-Z0-9]{1,32}$/', $symbolArg)) {
        throw new InvalidArgumentException('Invalid symbol argument.');
    }

    $symbols = [];
    if ($symbolArg !== null) {
        $stmt = $db->prepare("SELECT symbol FROM crypto.symbols WHERE symbol = ? LIMIT 1");
        $stmt->bind_param('s', $symbolArg);
    } else {
        $stmt = $db->prepare("SELECT symbol FROM crypto.symbols WHERE status = 'TRADING' ORDER BY symbol");
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $symbols[] = $row['symbol'];
    }
    $stmt->close();

    return $symbols;
}

function load_cursor($db, $cursorName, $endpoint, $symbol)
{
    $stmt = $db->prepare(
        "SELECT last_id, last_time
         FROM crypto.ingest_cursors
         WHERE cursor_name = ? AND endpoint = ? AND symbol = ? AND interval_name = ''
         LIMIT 1"
    );
    $stmt->bind_param('sss', $cursorName, $endpoint, $symbol);
    $stmt->execute();
    $result = $stmt->get_result();
    $cursor = $result->fetch_assoc();
    $stmt->close();

    return $cursor ?: ['last_id' => null, 'last_time' => null];
}

function save_cursor($db, $cursorName, $endpoint, $symbol, $lastId, $lastTime)
{
    $cursorValue = json_value([
        'last_trade_id' => $lastId,
        'last_trade_time' => $lastTime,
    ]);

    $stmt = $db->prepare(
        "INSERT INTO crypto.ingest_cursors
            (cursor_name, endpoint, symbol, interval_name, last_id, last_time, cursor_value)
         VALUES (?, ?, ?, '', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            last_id = VALUES(last_id),
            last_time = VALUES(last_time),
            cursor_value = VALUES(cursor_value)"
    );
    $stmt->bind_param('sssiis', $cursorName, $endpoint, $symbol, $lastId, $lastTime, $cursorValue);
    $stmt->execute();
    $stmt->close();
}

function fetch_account_trades($api, $symbol, $fromId)
{
    $limit = 1000;
    if ($fromId !== null) {
        return $api->history($symbol, $limit, $fromId);
    }

    return $api->history($symbol, $limit);
}

try {
    $db = get_db_connection();
    if ($db->connect_errno) {
        throw new RuntimeException('Database connection failed.');
    }

    $requestParams = json_value(['jobName' => $jobName, 'symbol' => $symbolArg]);
    $stmt = $db->prepare(
        "INSERT INTO crypto.ingest_runs
            (run_type, endpoint, status, request_params)
         VALUES (?, ?, 'started', ?)"
    );
    $stmt->bind_param('sss', $jobName, $endpoint, $requestParams);
    $stmt->execute();
    $runId = $db->insert_id;
    $stmt->close();

    $symbols = load_symbols($db, $symbolArg);
    if (count($symbols) === 0) {
        throw new RuntimeException('No symbols found to process.');
    }

    $upsertSql = "INSERT INTO crypto.account_trades
        (symbol, trade_id, order_id, order_list_id, price, qty, quote_qty,
         commission, commission_asset, trade_time, is_buyer, is_maker,
         is_best_match, raw_response)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         order_id = VALUES(order_id),
         order_list_id = VALUES(order_list_id),
         price = VALUES(price),
         qty = VALUES(qty),
         quote_qty = VALUES(quote_qty),
         commission = VALUES(commission),
         commission_asset = VALUES(commission_asset),
         trade_time = VALUES(trade_time),
         is_buyer = VALUES(is_buyer),
         is_maker = VALUES(is_maker),
         is_best_match = VALUES(is_best_match),
         raw_response = VALUES(raw_response)";
    $upsertStmt = $db->prepare($upsertSql);

    $config = require '/etc/web-applications/trading-app/binance.php';
    $api = new Binance\API($config['api_key'], $config['api_secret']);

    foreach ($symbols as $symbol) {
        try {
            $cursorName = 'account_trades:' . $symbol;
            $cursor = load_cursor($db, $cursorName, $endpoint, $symbol);
            $fromId = $cursor['last_id'] === null ? null : ((int) $cursor['last_id'] + 1);
            $cursorBefore[$symbol] = $cursor;

            $trades = fetch_account_trades($api, $symbol, $fromId);
            if (!is_array($trades)) {
                $skipped++;
                continue;
            }

            $maxTradeId = $cursor['last_id'] === null ? null : (int) $cursor['last_id'];
            $maxTradeTime = $cursor['last_time'] === null ? null : (int) $cursor['last_time'];

            foreach ($trades as $trade) {
                if (!isset($trade['id'], $trade['time'])) {
                    $skipped++;
                    continue;
                }

                $tradeId = (int) $trade['id'];
                $orderId = isset($trade['orderId']) ? (int) $trade['orderId'] : null;
                $orderListId = isset($trade['orderListId']) ? (int) $trade['orderListId'] : null;
                $price = (string) ($trade['price'] ?? '0');
                $qty = (string) ($trade['qty'] ?? '0');
                $quoteQty = (string) ($trade['quoteQty'] ?? '0');
                $commission = (string) ($trade['commission'] ?? '0');
                $commissionAsset = $trade['commissionAsset'] ?? null;
                $tradeTime = (int) $trade['time'];
                $isBuyer = !empty($trade['isBuyer']) ? 1 : 0;
                $isMaker = !empty($trade['isMaker']) ? 1 : 0;
                $isBestMatch = array_key_exists('isBestMatch', $trade) && $trade['isBestMatch'] ? 1 : 0;
                $rawResponse = json_value($trade);

                $upsertStmt->bind_param(
                    'siiisssssiiiis',
                    $symbol,
                    $tradeId,
                    $orderId,
                    $orderListId,
                    $price,
                    $qty,
                    $quoteQty,
                    $commission,
                    $commissionAsset,
                    $tradeTime,
                    $isBuyer,
                    $isMaker,
                    $isBestMatch,
                    $rawResponse
                );
                $upsertStmt->execute();

                if ($upsertStmt->affected_rows === 1) {
                    $inserted++;
                } elseif ($upsertStmt->affected_rows === 2) {
                    $updated++;
                }

                $tradesFetched++;
                $maxTradeId = $maxTradeId === null ? $tradeId : max($maxTradeId, $tradeId);
                $maxTradeTime = $maxTradeTime === null ? $tradeTime : max($maxTradeTime, $tradeTime);
            }

            if ($maxTradeId !== null) {
                save_cursor($db, $cursorName, $endpoint, $symbol, $maxTradeId, $maxTradeTime);
                $cursorAfter[$symbol] = ['last_id' => $maxTradeId, 'last_time' => $maxTradeTime];
            }

            $symbolsProcessed++;
            usleep(250000);
        } catch (Throwable $e) {
            $errors++;
            log_api_error($db, $runId, $endpoint, $symbol, $e->getMessage());
        }
    }

    $upsertStmt->close();
    $status = $errors > 0 ? 'completed_with_errors' : 'completed';

    $cursorBeforeJson = json_value($cursorBefore);
    $cursorAfterJson = json_value($cursorAfter);
    $stmt = $db->prepare(
        "UPDATE crypto.ingest_runs
         SET status = ?, finished_at = CURRENT_TIMESTAMP,
             records_inserted = ?, records_updated = ?, error_count = ?,
             cursor_before = ?, cursor_after = ?
         WHERE run_id = ?"
    );
    $stmt->bind_param(
        'siiissi',
        $status,
        $inserted,
        $updated,
        $errors,
        $cursorBeforeJson,
        $cursorAfterJson,
        $runId
    );
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $status = 'failed';
    $errors++;

    if (isset($db) && $db instanceof mysqli && !$db->connect_errno) {
        log_api_error($db, $runId, $endpoint, $symbolArg, $e->getMessage());

        if ($runId !== null) {
            $stmt = $db->prepare(
                "UPDATE crypto.ingest_runs
                 SET status = 'failed', finished_at = CURRENT_TIMESTAMP,
                     records_inserted = ?, records_updated = ?, error_count = ?
                 WHERE run_id = ?"
            );
            $stmt->bind_param('iiii', $inserted, $updated, $errors, $runId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo "sync_account_trades: symbols={$symbolsProcessed}, trades={$tradesFetched}, inserted={$inserted}, updated={$updated}, skipped={$skipped}, errors={$errors}, status={$status}\n";
