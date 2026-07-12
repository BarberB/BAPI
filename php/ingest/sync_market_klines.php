<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once '/var/www/html/php/crypto-con.php';
require_once '/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php';
require_once '/var/www/html/vendor/autoload.php';

$jobName = 'sync_market_klines';
$endpoint = 'market_klines';
$allowedIntervals = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];
$symbol = isset($argv[1]) ? strtoupper(trim($argv[1])) : null;
$interval = isset($argv[2]) ? trim($argv[2]) : null;
$klinesFetched = 0;
$inserted = 0;
$updated = 0;
$errors = 0;
$status = 'started';
$runId = null;
$fromTime = null;
$toTime = null;
$cursorBefore = null;
$cursorAfter = null;

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

function log_api_error($db, $runId, $endpoint, $symbol, $interval, $message)
{
    $requestParams = json_value([
        'jobName' => 'sync_market_klines',
        'symbol' => $symbol,
        'interval' => $interval,
    ]);
    $apiMessage = sanitize_error($message);

    $stmt = $db->prepare(
        "INSERT INTO crypto.api_errors
            (run_id, endpoint, symbol, interval_name, api_message, request_params)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssss', $runId, $endpoint, $symbol, $interval, $apiMessage, $requestParams);
    $stmt->execute();
    $stmt->close();
}

function assert_trading_symbol($db, $symbol)
{
    $stmt = $db->prepare("SELECT 1 FROM crypto.symbols WHERE symbol = ? AND status = 'TRADING' LIMIT 1");
    $stmt->bind_param('s', $symbol);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if (!$exists) {
        throw new InvalidArgumentException('Symbol is not present as TRADING in crypto.symbols.');
    }
}

function load_cursor($db, $cursorName, $endpoint, $symbol, $interval)
{
    $stmt = $db->prepare(
        "SELECT last_id, last_time, cursor_value
         FROM crypto.ingest_cursors
         WHERE cursor_name = ? AND endpoint = ? AND symbol = ? AND interval_name = ?
         LIMIT 1"
    );
    $stmt->bind_param('ssss', $cursorName, $endpoint, $symbol, $interval);
    $stmt->execute();
    $result = $stmt->get_result();
    $cursor = $result->fetch_assoc();
    $stmt->close();

    return $cursor ?: ['last_id' => null, 'last_time' => null, 'cursor_value' => null];
}

function save_cursor($db, $cursorName, $endpoint, $symbol, $interval, $lastOpenTime, $lastCloseTime)
{
    $cursorValue = json_value([
        'last_open_time' => $lastOpenTime,
        'last_close_time' => $lastCloseTime,
    ]);

    $stmt = $db->prepare(
        "INSERT INTO crypto.ingest_cursors
            (cursor_name, endpoint, symbol, interval_name, last_id, last_time, cursor_value)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            last_id = VALUES(last_id),
            last_time = VALUES(last_time),
            cursor_value = VALUES(cursor_value)"
    );
    $stmt->bind_param(
        'ssssiis',
        $cursorName,
        $endpoint,
        $symbol,
        $interval,
        $lastOpenTime,
        $lastCloseTime,
        $cursorValue
    );
    $stmt->execute();
    $stmt->close();
}

function fetch_klines($api, $symbol, $interval, $fromTime)
{
    return $api->candlesticks($symbol, $interval, 1000, $fromTime);
}

function normalize_kline($key, $kline)
{
    if (!is_array($kline)) {
        return null;
    }

    if (array_key_exists(0, $kline)) {
        return [
            'open_time' => (int) $kline[0],
            'open_price' => (string) $kline[1],
            'high_price' => (string) $kline[2],
            'low_price' => (string) $kline[3],
            'close_price' => (string) $kline[4],
            'volume' => (string) $kline[5],
            'close_time' => (int) $kline[6],
            'quote_asset_volume' => (string) ($kline[7] ?? '0'),
            'trade_count' => (int) ($kline[8] ?? 0),
            'taker_buy_base_volume' => (string) ($kline[9] ?? '0'),
            'taker_buy_quote_volume' => (string) ($kline[10] ?? '0'),
            'raw_response' => json_value($kline),
        ];
    }

    if (!is_numeric($key) || !isset($kline['open'], $kline['high'], $kline['low'], $kline['close'], $kline['volume'])) {
        return null;
    }

    return [
        'open_time' => (int) $key,
        'open_price' => (string) $kline['open'],
        'high_price' => (string) $kline['high'],
        'low_price' => (string) $kline['low'],
        'close_price' => (string) $kline['close'],
        'volume' => (string) $kline['volume'],
        'close_time' => (int) ($kline['closeTime'] ?? 0),
        'quote_asset_volume' => (string) ($kline['assetVolume'] ?? '0'),
        'trade_count' => (int) ($kline['trades'] ?? 0),
        'taker_buy_base_volume' => (string) ($kline['buyBaseVolume'] ?? '0'),
        'taker_buy_quote_volume' => (string) ($kline['buyAssetVolume'] ?? '0'),
        'raw_response' => json_value($kline),
    ];
}

try {
    if ($symbol === null || $interval === null) {
        throw new InvalidArgumentException('Usage: php php/ingest/sync_market_klines.php SYMBOL INTERVAL');
    }

    if (!preg_match('/^[A-Z0-9]{1,32}$/', $symbol)) {
        throw new InvalidArgumentException('Invalid symbol argument.');
    }

    if (!in_array($interval, $allowedIntervals, true)) {
        throw new InvalidArgumentException('Invalid interval argument.');
    }

    $db = get_db_connection();
    if ($db->connect_errno) {
        throw new RuntimeException('Database connection failed.');
    }

    assert_trading_symbol($db, $symbol);

    $cursorName = 'market_klines:' . $symbol . ':' . $interval;
    $cursorBefore = load_cursor($db, $cursorName, $endpoint, $symbol, $interval);
    $fromTime = $cursorBefore['last_time'] === null
        ? (int) ((time() - (30 * 24 * 60 * 60)) * 1000)
        : ((int) $cursorBefore['last_time'] + 1);

    $requestParams = json_value([
        'jobName' => $jobName,
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => 1000,
    ]);
    $cursorBeforeJson = json_value($cursorBefore);
    $stmt = $db->prepare(
        "INSERT INTO crypto.ingest_runs
            (run_type, endpoint, symbol, interval_name, status, from_time, request_params, cursor_before)
         VALUES (?, ?, ?, ?, 'started', ?, ?, ?)"
    );
    $stmt->bind_param('ssssiss', $jobName, $endpoint, $symbol, $interval, $fromTime, $requestParams, $cursorBeforeJson);
    $stmt->execute();
    $runId = $db->insert_id;
    $stmt->close();

    $config = require '/etc/web-applications/trading-app/binance.php';
    $api = new Binance\API($config['api_key'], $config['api_secret']);
    $klines = fetch_klines($api, $symbol, $interval, $fromTime);

    if (!is_array($klines)) {
        throw new RuntimeException('Kline response was not an array.');
    }

    $stmt = $db->prepare(
        "INSERT INTO crypto.market_klines
            (symbol, interval_name, open_time, open_price, high_price, low_price,
             close_price, volume, close_time, quote_asset_volume, trade_count,
             taker_buy_base_volume, taker_buy_quote_volume, raw_response)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            open_price = VALUES(open_price),
            high_price = VALUES(high_price),
            low_price = VALUES(low_price),
            close_price = VALUES(close_price),
            volume = VALUES(volume),
            close_time = VALUES(close_time),
            quote_asset_volume = VALUES(quote_asset_volume),
            trade_count = VALUES(trade_count),
            taker_buy_base_volume = VALUES(taker_buy_base_volume),
            taker_buy_quote_volume = VALUES(taker_buy_quote_volume),
            raw_response = VALUES(raw_response)"
    );

    $lastOpenTime = null;
    $lastCloseTime = null;
    foreach ($klines as $key => $rawKline) {
        $kline = normalize_kline($key, $rawKline);
        if ($kline === null || $kline['open_time'] <= 0 || $kline['close_time'] <= 0) {
            continue;
        }

        $stmt->bind_param(
            'ssisssssisisss',
            $symbol,
            $interval,
            $kline['open_time'],
            $kline['open_price'],
            $kline['high_price'],
            $kline['low_price'],
            $kline['close_price'],
            $kline['volume'],
            $kline['close_time'],
            $kline['quote_asset_volume'],
            $kline['trade_count'],
            $kline['taker_buy_base_volume'],
            $kline['taker_buy_quote_volume'],
            $kline['raw_response']
        );
        $stmt->execute();

        if ($stmt->affected_rows === 1) {
            $inserted++;
        } elseif ($stmt->affected_rows === 2) {
            $updated++;
        }

        $klinesFetched++;
        $lastOpenTime = $lastOpenTime === null ? $kline['open_time'] : max($lastOpenTime, $kline['open_time']);
        $lastCloseTime = $lastCloseTime === null ? $kline['close_time'] : max($lastCloseTime, $kline['close_time']);
        $toTime = $lastCloseTime;
    }
    $stmt->close();

    if ($lastOpenTime !== null && $lastCloseTime !== null) {
        save_cursor($db, $cursorName, $endpoint, $symbol, $interval, $lastOpenTime, $lastCloseTime);
        $cursorAfter = ['last_id' => $lastOpenTime, 'last_time' => $lastCloseTime];
    } else {
        $cursorAfter = $cursorBefore;
    }

    $status = 'completed';
    $cursorAfterJson = json_value($cursorAfter);
    $stmt = $db->prepare(
        "UPDATE crypto.ingest_runs
         SET status = ?, finished_at = CURRENT_TIMESTAMP,
             to_time = ?, records_inserted = ?, records_updated = ?,
             error_count = ?, cursor_after = ?
         WHERE run_id = ?"
    );
    $stmt->bind_param('siiiisi', $status, $toTime, $inserted, $updated, $errors, $cursorAfterJson, $runId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $status = 'failed';
    $errors++;

    if (isset($db) && $db instanceof mysqli && !$db->connect_errno) {
        log_api_error($db, $runId, $endpoint, $symbol, $interval, $e->getMessage());

        if ($runId !== null) {
            $stmt = $db->prepare(
                "UPDATE crypto.ingest_runs
                 SET status = 'failed', finished_at = CURRENT_TIMESTAMP,
                     to_time = ?, records_inserted = ?, records_updated = ?,
                     error_count = ?
                 WHERE run_id = ?"
            );
            $stmt->bind_param('iiiii', $toTime, $inserted, $updated, $errors, $runId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo "sync_market_klines: symbol={$symbol}, interval={$interval}, klines={$klinesFetched}, inserted={$inserted}, updated={$updated}, errors={$errors}, status={$status}\n";
