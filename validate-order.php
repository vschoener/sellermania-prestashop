<?php

require_once __DIR__.'/../../config/config.inc.php';
require_once __DIR__.'/sellermania.php';

$sellerMania = new Sellermania();

$orders = Tools::getValue('orderBox', []);
$sellerManiaRepository = new SellermaniaRepository(Db::getInstance());
$results = [];
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

    // Submit this list to our main method
    $result = $sellerManiaRepository->saveProductsStatus($sellerManiaOrder, $products);
    $results[] = $result;
}

echo json_encode($results);

