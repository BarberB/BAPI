<?php
//Order History

	
	
	////print_r($history);
	////exit;

//CANCELED ORDERS
	// Fetch all past orders for a symbol (e.g., RVNUSDT)
	$allOrders = $api->orders("RVNUSDT", 100); 	
	// Extract only orderIds of canceled orders
	$canceledOrderIds = array_map(function ($order) {
		return $order['orderId'];
	}, array_filter($allOrders, function ($order) {
		return $order['status'] === "CANCELED";
	}));

	// Display canceled orders
	//print_r($canceledOrderIds);
	//exit;
	$canOrders = "SELECT orderId, status FROM crypto.orders WHERE status='NEW'";
	//echo $canOrders."\r\n";
	//$currentOrders = "SELECT * FROM crypto.orders";
	$canOrdersResult = $con->query($canOrders);
	if ($canOrdersResult->num_rows > 0) {
		while ($can = $canOrdersResult->fetch_assoc()) {
			//echo $can['orderId']."\r\n";
			

			//if(in_array($historyId,$orderIds)){
			if(in_array($can['orderId'],$canceledOrderIds)){	
				$updateCanceledOrder = "
				UPDATE crypto.orders SET status='CANCELED' WHERE orderId = '{$can['orderId']}'
				";
				if($con->query($updateCanceledOrder)){	
					echo "cancel logged: ".$can['orderId']."\r\n";
				} else {
					echo "cancel failed: \r\n";
					exit;
				}
			}
		}
	}

//Log order history
	//ok, this needs a new approach, buys are now being created after the api confirmation
	//manual buys are not being tracked!
	//Manual buys need to be accounted for
	//Manual sales also
	
	//Open Orders and orderHistory both need to be checked.  If it is open and not in the db then it is manual.
	
	//All orders should be updated with the history.

	$currentOrders = "SELECT * FROM crypto.orders LIMIT 50";
	//$currentOrders = "SELECT * FROM crypto.orders";
	$currentOrdersResult = $con->query($currentOrders);
	// Store results in an array
	$orderArray = [];
	$previousHId = 0;
	if ($currentOrdersResult->num_rows > 0) {
		while ($row = $currentOrdersResult->fetch_assoc()) {
			$orderArray[] = $row;
		}
	}

	$history = $api->history("RVNUSDT", 20);
	//print_r($orderArray);
	//echo "Id logged: ".$order['orderId']."\r\n";
	echo "History Count: ";
	echo count($history)."\r\n";
	for ($x = 0; $x <= (count($history) - 1); $x++) {

		$historyId = $history[$x]['orderId'];
		
//		if($historyId == '27414749'){
//			$orderStatus = $api->orderStatus("RVNUSDT", $historyId);
//			print_r($orderStatus);
//			exit;
//		}
		
		if($historyId !== $previousHId){
			//print_r($orderArray['orderId']);
			//exit;

		// Extract all orderId values from the array
		$orderIds = array_column($orderArray, 'orderId');

		if(in_array($historyId,$orderIds)){
			
			$orderStatus = $api->orderStatus("RVNUSDT", $historyId);
			//if it's in the array the commision and the commision type need to be updated if null.  That can be found with history();
			$thisOrder = "SELECT * FROM crypto.orders WHERE orderId='$historyId'";
			//echo $canOrders."\r\n";
			//$currentOrders = "SELECT * FROM crypto.orders";
			$thisOrdersResult = $con->query($thisOrder);
			if ($thisOrdersResult->num_rows > 0) {
				while ($in = $thisOrdersResult->fetch_assoc()) {
					//Update SQL
					$upCount = 0;
					
					
					if(($in['status'] !== $orderStatus['status']) | empty($in['commission']) | empty($in['commissionAsset'])){
						$orderUpdates = "UPDATE crypto.orders SET ";
						//Check Status
						if($in['status'] !== $orderStatus['status']){
							$orderUpdates .= "status = '".$orderStatus['status']."'";
							$orderUpdates .= ", ";
							echo "status updated: $historyId\r\n";
							$upCount++;
						}

						//Check Commissions
						if(empty($in['commission'])){
							$orderUpdates .= "commission = '".$history[$x]['commission']."'";
							$orderUpdates .= ", ";
							echo "commission updated: $historyId\r\n";
							$upCount++;
						}
						if(empty($in['commissionAsset'])){
							$orderUpdates .= "commissionAsset = '".$history[$x]['commissionAsset']."' ";
							echo "commissionAsset updated: $historyId\r\n";
							$upCount++;
						}

						$orderUpdates .= "WHERE orderId = '$historyId'";
						
						if($con->query($orderUpdates)){	
							echo "DB updated\r\n";
						} else {

							echo "insert failed: \r\n";
							exit;
						}
					} else {
						echo "Already Updated\r\n";	
					}
					
					//exit;
					//Update if necessary
					//echo $orderUpdates."\r\n";
					
				}
			}
			
			//if a sell and status is completed, update the db, also update the commission columns.
			
			



			//$orderStatus = $api->orderStatus("RVNUSDT", $history[$x]['orderId']);
			//print_r($orderStatus);
			echo "--- in array ---\r\n";
		} else {
			//add entry to db
			//print_r($history[$x]);
			$orderStatus = $api->orderStatus("RVNUSDT", $history[$x]['orderId']);
			//print_r($orderStatus);
			//exit;
			$insertOrderQuery = "
				INSERT INTO crypto.orders
				(
					symbol,
					bId,
					orderId,
					orderListId,
					clientOrderId,
					price,
					origQty,
					executedQty,
					cummulativeQuoteQty,
					status,
					timeInForce,
					type,
					side,
					stopPrice,
					icebergQty,
					time,
					updateTime,
					isWorking,
					workingTime,
					origQuoteOrderQty,
					selfTradePreventionMode
				)
				VALUES
				(
					'".$orderStatus['symbol']."',
					'".$history[$x]['id']."',
					'".$orderStatus['orderId']."',
					'".$orderStatus['orderListId']."',
					'".$orderStatus['clientOrderId']."',
					'".$history[$x]['price']."',
					'".$orderStatus['origQty']."',
					'".$orderStatus['executedQty']."',
					'".$orderStatus['cummulativeQuoteQty']."',
					'".$orderStatus['status']."',
					'".$orderStatus['timeInForce']."',
					'".$orderStatus['type']."',
					'".$orderStatus['side']."',
					'".$orderStatus['stopPrice']."',
					'".$orderStatus['icebergQty']."',
					'".$orderStatus['time']."',
					'".$orderStatus['updateTime']."',
					'".$orderStatus['isWorking']."',
					'".$orderStatus['workingTime']."',
					'".$orderStatus['origQuoteOrderQty']."',
					'".$orderStatus['selfTradePreventionMode']."'
				);
			";
			if($con->query($insertOrderQuery)){	
			echo "history logged: ".$orderStatus['orderId']." - ".$orderStatus['type']."\r\n";
			} else {
				echo "insert failed: \r\n";
				exit;
			}

		}
		echo "---------------- $x --------------------\r\n";
		//exit;

		//Label cancelled orders
			//Select sellOrderId from all orders, check the status of that order id in the history.  If canceled the db needs to be updated. Otherwise CARL might assume that the order was made
				//When quering a logged seller 
		}
		$previousHId = $historyId;
	}
?>