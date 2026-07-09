<?php 
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL|E_STRICT);
$startTime = date("Y-m-d H:i:s");
$startTime = new DateTime($startTime);


require_once '/var/www/html/php/crypto-con.php';

$mysqli = new mysqli(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database']
);

if ($mysqli->connect_errno) {
    error_log('Database connection failed: ' . $mysqli->connect_error);
    http_response_code(500);
    exit('Database connection failed.');
}

//$con = mysqli_connect($servername, $username, $password, $dbname);
$con = $mysqli;
if (!$con) {  die('Could not connect: ' . mysqli_error($con));}

require_once '/var/www/html/vendor/jaggedsoft/php-binance-api/php-binance-api.php';
require_once '/var/www/html/vendor/autoload.php';

$config = require '/etc/web-applications/trading-app/binance.php';

$api = new Binance\API(
    $config['api_key'],
    $config['api_secret']
);



//$api = new Binance\API($api_key,$secret_key);
$api->useServerTime();
// Trading pair and parameters
$symbol = 'RVNUSDT'; // Replace with the desired trading pair
$interval = '3m'; // Replace with the desired time interval (e.g., 1m, 5m, 1h, 1d)
$limit = 1; // Replace with the number of data points you want to retrieve

$balances = $api->balances();
//print_r($balances);
//exit;
$needsSell = "SELECT * FROM crypto.orders WHERE side='BUY' AND sellOrderId IS NULL AND status='FILLED' AND orderId >'27508572' ORDER BY orderId ASC";
$orderQueryResult = $con->query($needsSell);
 echo $orderQueryResult->num_rows."\n";


$endTime = date("Y-m-d H:i:s");
$endTime = new DateTime($endTime);
$interval = $startTime->diff($endTime);
// Get the duration in the format of hours, minutes, and seconds
$duration = $interval->format('%s seconds');

// Output the duration
echo "Duration: " . $duration."\n";
?>
