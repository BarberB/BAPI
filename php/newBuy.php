<?php

echo "You currently do not have a position at: " . $rvnPrice . "\r\n";
			  
          $randomNumber = rand(1, 6);
		  $dollars = $dollars + $randomNumber;
			  
          //Create the order and send.
		  $quantityRaw = $dollars / $rvnPrice;
		  $quantity = $quantityRaw;
		  $newQ = floatval( $quantityRaw );
		  $newQ = intval( $newQ );

		  if($rvnPrice < $firstBid){
			
			if ( $order = $api->buy( "RVNUSDT", $newQ, $rvnPrice ) ) {
			  $message = "Buy order placed for " . $quantity . " of RVN @" . $rvnPrice . " \r\n";
			  array_push($buyOrders,$rvnPrice);
			  echo $message . "\r\n";
			  $x++;
				//The buy order should be entered into the db here vs caputuring history at the end.
				
				//ok example output from completed buy order...
					//Array
					//(
					//    [symbol] => RVNUSDT
					//    [orderId] => 27435092
					//    [orderListId] => -1
					//    [clientOrderId] => f4tv0hSUzwB99fi1afqFQw
					//    [transactTime] => 1738950981624
					//    [price] => 0.01495000
					//    [origQty] => 100.00000000
					//    [executedQty] => 100.00000000
					//    [cummulativeQuoteQty] => 1.49500000
					//    [status] => FILLED
					//    [timeInForce] => GTC
					//    [type] => LIMIT
					//    [side] => BUY
					//    [workingTime] => 1738950981624
					//    [fills] => Array
					//        (
					//            [0] => Array
					//                (
					//                    [price] => 0.01495000
					//                    [origQty] => 100.00000000
					//                    [commission] => 0.40000000
					//                    [commissionAsset] => RVN
					//                    [tradeId] => 170094
					//                )
					//
					//        )
					//
					//    [selfTradePreventionMode] => EXPIRE_MAKER
					//)
				
				// example of NEW buy order...
					//[symbol] => RVNUSDT
					//[orderId] => 27435120
					//[orderListId] => -1
					//[clientOrderId] => qmc6c2i1MfZnZz5BTKBRho
					//[transactTime] => 1738952130914
					//[price] => 0.01330000
					//[origQty] => 100.00000000
					//[executedQty] => 0.00000000
					//[cummulativeQuoteQty] => 0.00000000
					//[status] => NEW
					//[timeInForce] => GTC
					//[type] => LIMIT
					//[side] => BUY
					//[workingTime] => 1738952130914
					//[fills] => Array
					//    (
					//    )
					//
					//[selfTradePreventionMode] => EXPIRE_MAKER

                $insertOrderQuery = "
                    INSERT INTO crypto.orders
                    (
						symbol,
                        orderId,
                        orderListId,
                        clientOrderId,
                        transactTime,
                        price,
                        origQty,
                        executedQty,
                        cummulativeQuoteQty,
                        status,
                        timeInForce,
                        type,
                        side,
                        workingTime
                    )
                    VALUES
                    (
                        '".$order['symbol']."',
                        '".$order['orderId']."',
                        '".$order['orderListId']."',
                        '".$order['clientOrderId']."',
                        '".$order['transactTime']."',
                        '".$order['price']."',
                        '".$order['origQty']."',
                        '".$order['executedQty']."',
                        '".$order['cummulativeQuoteQty']."',
                        '".$order['status']."',
                        '".$order['timeInForce']."',
                        '".$order['type']."',
                        '".$order['side']."',
                        '".$order['workingTime']."'
                    );
                ";

				//Update the db with the api response
				if($con->query($insertOrderQuery)){	
				echo "New Buy Successful, Id logged: ".$order['orderId']."\r\n";
				} else {
					echo "insert failed: \r\n";
					exit;
				}
			}
			  
			//array_push($buyOrders,$rvnPrice);
			//$x++;
		  } else {
			echo "array price is above current price $x.\r\n";	
			//$x++;
		  }

?>