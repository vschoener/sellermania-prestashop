<?php

class SellermaniaRepository
{
    /** @var Db  */
    private $db;

    /** @var string  */
    private $table;
    /**
     * @var SellermaniaOrder
     */
    private $sellermaniaOrder;

    public function __construct(Db $db, SellermaniaOrder $sellermaniaOrder = null)
    {
        $this->db = $db;
        $this->table = _DB_PREFIX_.'sellermania_order';
        $this->sellermaniaOrder = $sellermaniaOrder;
    }

    /**
     * @param SellermaniaOrder $sellermaniaOrder
     * @return $this
     */
    public function setSellermaniaOrder(SellermaniaOrder $sellermaniaOrder)
    {
        $this->sellermaniaOrder = $sellermaniaOrder;
        return $this;
    }

    public function getSellermaniaOrder()
    {
        return $this->sellermaniaOrder;
    }

    /**
     * @return $this
     */
    public function checkHandledOrders()
    {
        $this->db->execute("
            UPDATE `ps_sellermania_order` so
            SET so.`isHandled` = 1
            WHERE so.id_sellermania_order NOT IN (
                SELECT id
                FROM (
                    SELECT id_sellermania_order as id
                    FROM `ps_sellermania_order` so
                    WHERE so.info REGEXP '\"Status\":\"6\"'
                ) t
            );
        ");

        return $this;
    }

    /**
     * @return array
     */
    public function getNotHandledOrder()
    {
        $orders = $this->db->executeS("
            SELECT so.`id_order`
            FROM `$this->table` so
            WHERE so.isHandled = 0
        ");

        $list = [];
        if ($orders) {
            foreach ($orders as $order) {
                $list[] = $order['id_order'];
            }
            unset($orders);
        }

        return $list;
    }

    /**
     * @return bool
     * @throws Exception
     * @internal param SellermaniaOrder $sellermaniaOrder
     */
    public function isOrderReadyToShip()
    {
        if (!$this->sellermaniaOrder) {
            throw new Exception('Did you forget to set the sellermania order ?');
        }
        $apiInfo = $this->sellermaniaOrder->getApiOrderInfo();

        // Check if there is a flag to dispatch
        $status = true;
        foreach ($apiInfo['OrderInfo']['Product'] as $product) {
            if (isset($product['Status'])) {
                if ($product['Status'] != 1) {
                    // Get out if one the products is not ready to ship
                    $status = false;
                    break;
                }
            }
        }

        return $status;
    }

    /**
     * @param SellermaniaOrder $sellermaniaOrder
     * @param array $productsInfo
     * @return array|bool
     */
    public function saveProductsStatus(SellermaniaOrder $sellermaniaOrder, array $productsInfo)
    {
        $orderItems = [];
        $apiOrderInfo = $sellermaniaOrder->getApiOrderInfo();

        // Build order items to request the API
        foreach($apiOrderInfo['OrderInfo']['Product'] as &$product) {
            foreach ($productsInfo as $productInfo) {
                if ($product['Sku'] == $productInfo['sku'] &&
                    $product['Status'] == \Sellermania\OrderConfirmClient::STATUS_TO_BE_CONFIRMED)
                {
                    $orderItems[] = [
                        'orderId' => pSQL($apiOrderInfo['OrderInfo']['OrderId']),
                        'sku' => pSQL($productInfo['sku']),
                        'orderStatusId' => $productInfo['orderStatusId'],
                        'trackingNumber' => '',
                        'shippingCarrier' => '',
                    ];
                    $product['Status'] = $productInfo['orderStatusId'];
                }
            }
        }

        // Check if there order item status to change
        if (empty($orderItems)) {
            return false;
        }

        $result = $this->confirmOrder($orderItems);

        return $result;
    }

    /**
     * @param array $orderItems
     * @return array|bool
     */
    public function confirmOrder(array $orderItems)
    {
        $result = false;

        // Make API call
        try {
            // Calling the confirmOrder service
            $client = new Sellermania\OrderConfirmClient();
            $client->setEmail(Configuration::get('SM_ORDER_EMAIL'));
            $client->setToken(Configuration::get('SM_ORDER_TOKEN'));
            $client->setEndpoint(Configuration::get('SM_CONFIRM_ORDER_ENDPOINT'));
            $result = $client->confirmOrder($orderItems);

            // Fix data (when only one result, array is not the same)
            if (!isset($result['OrderItemConfirmationStatus'][0])) {
                $result['OrderItemConfirmationStatus'] = [$result['OrderItemConfirmationStatus']];
            }

            // Return results
            return $result;
        } catch (\Exception $e) {
            Context::getContext()->smarty->assign('sellermania_error', strip_tags($e->getMessage()));
        }

        return $result;
    }

    public function updatePrestaShopOrderFromApiOrder(SellermaniaOrder $sellermaniaOrder)
    {

    }
}
