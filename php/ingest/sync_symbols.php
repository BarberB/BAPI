<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once '/var/www/html/php/crypto-con.php';
require_once '/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php';
require_once '/var/www/html/vendor/autoload.php';

$jobName = 'sync_symbols';
$endpoint = 'exchangeInfo';
$processed = 0;
$inserted = 0;
$updated = 0;
$status = 'started';
$runId = null;

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

function log_api_error($db, $runId, $endpoint, $message)
{
    $requestParams = json_value(['jobName' => 'sync_symbols']);
    $apiMessage = sanitize_error($message);

    $stmt = $db->prepare(
        "INSERT INTO crypto.api_errors
            (run_id, endpoint, api_message, request_params)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('isss', $runId, $endpoint, $apiMessage, $requestParams);
    $stmt->execute();
    $stmt->close();
}

try {
    $db = get_db_connection();
    if ($db->connect_errno) {
        throw new RuntimeException('Database connection failed.');
    }

    $requestParams = json_value(['jobName' => $jobName]);
    $stmt = $db->prepare(
        "INSERT INTO crypto.ingest_runs
            (run_type, endpoint, status, request_params)
         VALUES (?, ?, 'started', ?)"
    );
    $stmt->bind_param('sss', $jobName, $endpoint, $requestParams);
    $stmt->execute();
    $runId = $db->insert_id;
    $stmt->close();

    $config = require '/etc/web-applications/trading-app/binance.php';
    $api = new Binance\API($config['api_key'], $config['api_secret']);
    $exchangeInfo = $api->exchangeInfo();
    $symbols = isset($exchangeInfo['symbols']) && is_array($exchangeInfo['symbols'])
        ? $exchangeInfo['symbols']
        : [];

    $existsStmt = $db->prepare("SELECT 1 FROM crypto.symbols WHERE symbol = ? LIMIT 1");
    $upsertStmt = $db->prepare(
        "INSERT INTO crypto.symbols
            (symbol, base_asset, quote_asset, status, base_asset_precision,
             quote_asset_precision, order_types, permissions, filters, raw_response)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            base_asset = VALUES(base_asset),
            quote_asset = VALUES(quote_asset),
            status = VALUES(status),
            base_asset_precision = VALUES(base_asset_precision),
            quote_asset_precision = VALUES(quote_asset_precision),
            order_types = VALUES(order_types),
            permissions = VALUES(permissions),
            filters = VALUES(filters),
            raw_response = VALUES(raw_response)"
    );

    foreach ($symbols as $symbolInfo) {
        $symbol = $symbolInfo['symbol'] ?? '';
        if ($symbol === '') {
            continue;
        }

        $baseAsset = $symbolInfo['baseAsset'] ?? '';
        $quoteAsset = $symbolInfo['quoteAsset'] ?? '';
        $symbolStatus = $symbolInfo['status'] ?? '';
        $basePrecision = (int) ($symbolInfo['baseAssetPrecision'] ?? 0);
        $quotePrecision = (int) ($symbolInfo['quoteAssetPrecision'] ?? 0);
        $orderTypes = json_value($symbolInfo['orderTypes'] ?? null);
        $permissions = json_value($symbolInfo['permissions'] ?? null);
        $filters = json_value($symbolInfo['filters'] ?? null);
        $rawResponse = json_value($symbolInfo);

        $existsStmt->bind_param('s', $symbol);
        $existsStmt->execute();
        $existsStmt->store_result();
        $symbolExists = $existsStmt->num_rows > 0;
        $existsStmt->free_result();

        $upsertStmt->bind_param(
            'ssssiissss',
            $symbol,
            $baseAsset,
            $quoteAsset,
            $symbolStatus,
            $basePrecision,
            $quotePrecision,
            $orderTypes,
            $permissions,
            $filters,
            $rawResponse
        );
        $upsertStmt->execute();

        $processed++;
        if ($symbolExists) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    $existsStmt->close();
    $upsertStmt->close();

    $status = 'completed';
    $stmt = $db->prepare(
        "UPDATE crypto.ingest_runs
         SET status = ?, finished_at = CURRENT_TIMESTAMP,
             records_inserted = ?, records_updated = ?
         WHERE run_id = ?"
    );
    $stmt->bind_param('siii', $status, $inserted, $updated, $runId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $status = 'failed';

    if (isset($db) && $db instanceof mysqli && !$db->connect_errno) {
        log_api_error($db, $runId, $endpoint, $e->getMessage());

        if ($runId !== null) {
            $stmt = $db->prepare(
                "UPDATE crypto.ingest_runs
                 SET status = 'failed', finished_at = CURRENT_TIMESTAMP,
                     records_inserted = ?, records_updated = ?, error_count = 1
                 WHERE run_id = ?"
            );
            $stmt->bind_param('iii', $inserted, $updated, $runId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo "sync_symbols: processed={$processed}, inserted={$inserted}, updated={$updated}, status={$status}\n";
