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

    public function __construct(Db $db)
    {
        $this->db = $db;
        $this->table = _DB_PREFIX_.'sellermania_order';
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

    public function buildOrderItems(array $productsInfo)
    {
        if (!$this->sellermaniaOrder) {
            throw new Exception('Did you forget to set the sellermania order ?');
        }

        $orderItems = [];

        $apiOrderInfo = $this->sellermaniaOrder->getApiOrderInfo();
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

        return $orderItems;
    }

    /**
     * @param array $orderItems
     * @return array|bool
     * @throws Exception
     */
    public function saveProductsStatus(array $orderItems)
    {
        if (!($result = $this->sendOrderToAPI($orderItems))) {
            return false;
        }

        // Update any sellermania orders status from last result
        foreach ($result['OrderItemConfirmationStatus'] as $orderItemConfirmation) {
            foreach ($orderItems as $orderItem) {
                if ($orderItem['orderId'] == $orderItemConfirmation['orderId']) {
                    if ($orderItemConfirmation['Status'] == 'SUCCESS') {
                        $sellermaniaOrder = SellermaniaOrder::getFromRefOrder($orderItem['orderId']);
                        if (Validate::isLoadedObject($sellermaniaOrder)) {
                            $apiInfo = $sellermaniaOrder->getApiOrderInfo();
                            foreach($apiInfo['OrderInfo']['Product'] as &$product) {
                                if ($product['Sku'] == $orderItem['sku']) {
                                    $product['Status'] = $orderItem['orderStatusId'];
                                    $sellermaniaOrder->setApiInfo($apiInfo);
                                    $sellermaniaOrder->save();
                                    break;
                                }
                            }
                        }
                    }
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Save shipping status
     * @param array $shippingInfo
     * @return array|bool
     * @throws Exception
     */
    public function registerShippingData(array $shippingInfo)
    {
        if (!$this->sellermaniaOrder) {
            throw new Exception('Did you forget to set the sellermania order ?');
        }

        // Check shipping status
        if (!($readyToShip = $this->isOrderReadyToShip())) {
            return false;
        }

        $apiInfo = $this->getSellermaniaOrder()->getApiOrderInfo();
        $orderItems = [];

        // Preprocess data
        foreach ($apiInfo['OrderInfo']['Product'] as $product) {
            if ($product['Status'] == 1) {
                $orderItems[] = array(
                    'orderId' => pSQL($apiInfo['OrderInfo']['OrderId']),
                    'sku' => pSQL($product['Sku']),
                    'orderStatusId' => \Sellermania\OrderConfirmClient::STATUS_DISPATCHED,
                    'trackingNumber' => pSQL($shippingInfo['tracking_number']),
                    'shippingCarrier' => pSQL($shippingInfo['shipping_name']),
                );
            }
        }

        return $this->sendOrderToAPI($orderItems);
    }

    /**
     * @param array $orderItems
     * @return array|bool
     */
    public function sendOrderToAPI(array $orderItems)
    {
        $result = false;

        if (empty($orderItems)) {
            return $result;
        }

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
        } catch (\Exception $e) {
            Context::getContext()->smarty->assign('sellermania_error', strip_tags($e->getMessage()));
        }

        return $result;
    }

    /**
     * Refresh order status
     * @param $orderId
     * @param $sellermania_order
     * @param Sellermania $sellermania
     * @return bool
     */
    public function refreshOrderStatus($orderId, $sellermania_order, Sellermania $sellermania)
    {
        $context = Context::getContext();
        // Fix data (when only one product, array is not the same)
        if (!isset($sellermania_order['OrderInfo']['Product'][0]))
            $sellermania_order['OrderInfo']['Product'] = array($sellermania_order['OrderInfo']['Product']);

        // Check which status the order is
        $new_order_state = false;
        foreach ($sellermania->sellermania_order_states as $kos => $os)
            if ($new_order_state === false)
            {
                // If the status is a priority status and one of the product has this status
                // The order will have this status
                if ($os['sm_prior'] == 1)
                {
                    foreach ($sellermania_order['OrderInfo']['Product'] as $kp => $product)
                        if (isset($product['Status']) && $product['Status'] == $os['sm_status'])
                            $new_order_state = Configuration::get($kos);
                }

                // If the status is not a priority status and all products have this status
                // The order will have this status
                if ($os['sm_prior'] == 0)
                {
                    $new_order_state = Configuration::get($kos);
                    foreach ($sellermania_order['OrderInfo']['Product'] as $kp => $product)
                        if (isset($product['Status']) && $product['Status'] != $os['sm_status'])
                            $new_order_state = false;
                }
            }

        // If all order states are either dispatched or cancel, then it's a dispatched order
        if ($new_order_state === false)
        {
            // Check if there is at least one line as "Dispatched"
            foreach ($sellermania_order['OrderInfo']['Product'] as $kp => $product)
                if ($product['Status'] == $sellermania->sellermania_order_states['PS_OS_SM_DISPATCHED']['sm_status'])
                    $new_order_state = Configuration::get('PS_OS_SM_DISPATCHED');

            // If yes, we check if others states are not different of "CANCEL" or "DISPATCH"
            if ($new_order_state == Configuration::get('PS_OS_SM_DISPATCHED'))
                foreach ($sellermania_order['OrderInfo']['Product'] as $kp => $product)
                    if ($product['Status'] != $sellermania->sellermania_order_states['PS_OS_SM_CANCEL_CUS']['sm_status'] &&
                        $product['Status'] != $sellermania->sellermania_order_states['PS_OS_SM_CANCEL_SEL']['sm_status'] &&
                        $product['Status'] != $sellermania->sellermania_order_states['PS_OS_SM_DISPATCHED']['sm_status'])
                        $new_order_state = false;
        }

        // If status is false or equal to first status assigned, we do not change it
        if ($new_order_state === false || $new_order_state == Configuration::get('PS_OS_SM_AWAITING'))
            return false;


        // We check if the status is not already set
        $id_order_history = Db::getInstance()->getValue('
        SELECT `id_order_history` FROM `'._DB_PREFIX_.'order_history`
        WHERE `id_order` = '.(int)$orderId.'
        AND `id_order_state` = '.(int)$new_order_state);
        if ($id_order_history > 0)
            return false;


        // Load order and check existings payment
        $order = new Order((int)$orderId);

        // If order does not exists anymore we stop status update
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $employeeId = isset($context->employee) ? $context->employee->id : 0;
        // *** Orders Export Compliancy *** //
        // If the new state is TO DISPATCH or DISPATCHED
        if (in_array((int)$new_order_state, array(Configuration::get('PS_OS_SM_TO_DISPATCH'), Configuration::get('PS_OS_SM_DISPATCHED'))))
        {
            // Retrieve order history
            $order_history_ids = array();
            $order_history = $order->getHistory(Configuration::get('PS_DEFAULT_LANG'));
            foreach ($order_history as $oh)
                $order_history_ids[] = $oh['id_order_state'];

            // If PAYMENT STATE is not in order history
            if (!in_array((int)Configuration::get('PS_OS_PAYMENT'), $order_history_ids))
            {
                // We add the payment state
                $history = new OrderHistory();
                $history->id_order = $order->id;
                $history->id_employee = $employeeId;
                $history->id_order_state = (int)Configuration::get('PS_OS_PAYMENT');
                $history->changeIdOrderState((int)Configuration::get('PS_OS_PAYMENT'), $order->id);
                $history->add();
            }
        }


        // Create new OrderHistory
        $history = new OrderHistory();
        $history->id_order = $order->id;
        $history->id_employee = $employeeId;
        $history->id_order_state = (int)$new_order_state;
        $history->changeIdOrderState((int)$new_order_state, $order->id);
        return $history->add();
    }
}
