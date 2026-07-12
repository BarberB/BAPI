<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once '/var/www/html/php/crypto-con.php';
require_once '/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php';
require_once '/var/www/html/vendor/autoload.php';

$jobName = 'sync_balance_snapshot';
$endpoint = 'account_balances';
$snapshotTime = (int) floor(microtime(true) * 1000);
$assetsProcessed = 0;
$inserted = 0;
$updated = 0;
$errors = 0;
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
    $requestParams = json_value(['jobName' => 'sync_balance_snapshot']);
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

    $requestParams = json_value(['jobName' => $jobName, 'snapshot_time' => $snapshotTime]);
    $stmt = $db->prepare(
        "INSERT INTO crypto.ingest_runs
            (run_type, endpoint, status, from_time, to_time, request_params)
         VALUES (?, ?, 'started', ?, ?, ?)"
    );
    $stmt->bind_param('ssiis', $jobName, $endpoint, $snapshotTime, $snapshotTime, $requestParams);
    $stmt->execute();
    $runId = $db->insert_id;
    $stmt->close();

    $config = require '/etc/web-applications/trading-app/binance.php';
    $api = new Binance\API($config['api_key'], $config['api_secret']);
    $balances = $api->balances();

    if (!is_array($balances)) {
        throw new RuntimeException('Balance response was not an array.');
    }

    $stmt = $db->prepare(
        "INSERT INTO crypto.balance_snapshots
            (snapshot_time, account_type, asset, free, locked, total, raw_response)
         VALUES (?, 'SPOT', ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            free = VALUES(free),
            locked = VALUES(locked),
            total = VALUES(total),
            raw_response = VALUES(raw_response)"
    );

    foreach ($balances as $asset => $balance) {
        if (!is_array($balance)) {
            continue;
        }

        $asset = strtoupper((string) $asset);
        $free = (string) ($balance['available'] ?? $balance['free'] ?? '0');
        $locked = (string) ($balance['onOrder'] ?? $balance['locked'] ?? '0');
        $total = (string) ((float) $free + (float) $locked);
        $rawResponse = json_value($balance);

        $stmt->bind_param('isssss', $snapshotTime, $asset, $free, $locked, $total, $rawResponse);
        $stmt->execute();

        if ($stmt->affected_rows === 1) {
            $inserted++;
        } elseif ($stmt->affected_rows === 2) {
            $updated++;
        }

        $assetsProcessed++;
    }

    $stmt->close();

    $status = 'completed';
    $stmt = $db->prepare(
        "UPDATE crypto.ingest_runs
         SET status = ?, finished_at = CURRENT_TIMESTAMP,
             records_inserted = ?, records_updated = ?, error_count = ?
         WHERE run_id = ?"
    );
    $stmt->bind_param('siiii', $status, $inserted, $updated, $errors, $runId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    $status = 'failed';
    $errors++;

    if (isset($db) && $db instanceof mysqli && !$db->connect_errno) {
        log_api_error($db, $runId, $endpoint, $e->getMessage());

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

echo "sync_balance_snapshot: assets={$assetsProcessed}, inserted={$inserted}, updated={$updated}, errors={$errors}, status={$status}\n";
