<?php

require_once __DIR__.'/../../config/config.inc.php';
require_once __DIR__.'/sellermania.php';

$sellerMania = new Sellermania();

$orders = Tools::getValue('orderBox', []);
$sellerManiaRepository = new SellermaniaRepository(Db::getInstance());
$results = [];
$paging = 50;
$maxOrders = $paging;
$current = 0;
$totalOrders = count($orders);
$orderItems = [];
foreach ($orders as $orderId) {

    $sellerManiaOrder = SellermaniaOrder::getSellermaniaOrderFromOrderId($orderId);
    if (!Validate::isLoadedObject($sellerManiaOrder)) {
        continue;
    }

    // Build products list from our own api response
    $apiOrderInfo = $sellerManiaOrder->getApiOrderInfo();
    $products = [];

    foreach ($apiOrderInfo['OrderInfo']['Product'] as $product) {
        $products[] = [
            'sku' => $product['Sku'],
            'orderStatusId' => \Sellermania\OrderConfirmClient::STATUS_CONFIRMED,
        ];
    }

    $sellerManiaRepository->setSellermaniaOrder($sellerManiaOrder);
    $orderItems = array_merge($orderItems, $sellerManiaRepository->buildOrderItems($products));

    if ((++$current && $current >= $totalOrders) || $current >= $maxOrders) {
        $maxOrders += $paging;

        $results += $sellerManiaRepository->saveProductsStatus($orderItems);
        $orderItems = [];
    }

}

// Update handled orders (more fast than send queries one by one)
$sellerManiaRepository->checkHandledOrders();
echo json_encode($results);

// Best way is to stream the json result.

