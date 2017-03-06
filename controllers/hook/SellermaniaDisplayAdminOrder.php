<?php
/*
* 2010-2016 Sellermania / Froggy Commerce / 23Prod SARL
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to team@froggy-commerce.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade your module to newer
* versions in the future.
*
*  @author         Froggy Commerce <team@froggy-commerce.com>
*  @copyright      2010-2016 Sellermania / Froggy Commerce / 23Prod SARL
*  @version        1.0
*  @license        http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

/*
 * Security
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Load ImportOrder Controller
require_once(dirname(__FILE__).'/SellermaniaImportOrder.php');

class SellermaniaDisplayAdminOrderController
{
    /**
     * @var private array status
     */
    private $status_list = array();

    /** @var  SellermaniaRepository */
    private $sellermaniaRepository;

    /**
     * Controller constructor
     */
    public function __construct($module, $dir_path, $web_path)
    {
        $this->module = $module;
        $this->web_path = $web_path;
        $this->dir_path = $dir_path;
        $this->context = Context::getContext();
        $this->ps_version = str_replace('.', '', substr(_PS_VERSION_, 0, 3));

        $this->status_list = array(
            6 => $this->module->l('To be confirmed', 'sellermaniadisplayadminorder'),
            10 => $this->module->l('Awaiting confirmation', 'sellermaniadisplayadminorder'),
            9 => $this->module->l('Confirmed', 'sellermaniadisplayadminorder'),
            3 => $this->module->l('Cancelled by the customer', 'sellermaniadisplayadminorder'),
            4 => $this->module->l('Cancelled by the seller', 'sellermaniadisplayadminorder'),
            1 => $this->module->l('To dispatch', 'sellermaniadisplayadminorder'),
            5 => $this->module->l('Awaiting dispatch', 'sellermaniadisplayadminorder'),
            2 => $this->module->l('Dispatched', 'sellermaniadisplayadminorder'),
        );
    }

    /**
     * Save status
     * @param SellermaniaOrder $sellermaniaOrder
     * @return array|bool
     */
    public function saveProductsStatusFromRequest(SellermaniaOrder $sellermaniaOrder)
    {
        $info = json_decode($sellermaniaOrder->info, true);

        // Check if form has been submitted
        if (Tools::getValue('sellermania_line_max') == '' || empty($info)) {
            return false;
        }

        $products = [];
        $line_max = Tools::getValue('sellermania_line_max');
        for ($i = 1; $i <= $line_max; $i++) {
            if (Tools::getValue('sku_status_' . $i) != '') {
                // Find match and check if not already marked as changed
                foreach ($info['OrderInfo']['Product'] as &$product)
                    if ($product['Sku'] == Tools::getValue('sku_status_' . $i) &&
                        $product['Status'] == \Sellermania\OrderConfirmClient::STATUS_TO_BE_CONFIRMED
                    ) {
                        $products[] = [
                            'sku' => pSQL(Tools::getValue('sku_status_' . $i)),
                            'orderStatusId' => Tools::getValue('status_' . $i),
                        ];
                        $product['Status'] = Tools::getValue('status_' . $i);
                    }
            }
        }

        $sellermaniaRepository = new SellermaniaRepository(Db::getInstance());
        return $sellermaniaRepository->saveProductsStatus($sellermaniaOrder, $products);
    }

    /**
     * Save shipping status
     * @return array|bool
     */
    public function saveShippingStatus()
    {
        // Check if form has been submitted
        if (Tools::getValue('sellermania_tracking_registration') == '')
            return false;

        // Check shipping status
        if (!($readyToShip = $this->sellermaniaRepository->isOrderReadyToShip())) {
            return false;
        }

        // Set orders param
        $orders = array(
            array(
                'id_order' => (int)Tools::getValue('id_order'),
                'tracking_number' => Tools::getValue('tracking_number'),
                'shipping_name' => Tools::getValue('shipping_name'),
            ),
        );

        // Register shipping data
        return self::registerShippingData($orders);
    }


    /**
     * Save shipping status
     * @param $orders
     * @return array|bool
     */
    public static function registerShippingData($orders)
    {
        // Set order items array
        $order_items = array();

        // For each order
        foreach ($orders as $order)
        {
            // Retrieve order data
            $sellermania_order = Db::getInstance()->getValue('SELECT `info` FROM `'._DB_PREFIX_.'sellermania_order` WHERE `id_order` = '.(int)$order['id_order']);
            if (!empty($sellermania_order))
            {
                // Decode order data
                $sellermania_order = json_decode($sellermania_order, true);

                // Check shipping status
                if (self::isReadyToShip($sellermania_order)) {
                    // Preprocess data
                    foreach ($sellermania_order['OrderInfo']['Product'] as $product) {
                        if ($product['Status'] == 1) {
                            $order_items[] = array(
                                'orderId' => pSQL($sellermania_order['OrderInfo']['OrderId']),
                                'sku' => pSQL($product['Sku']),
                                'orderStatusId' => \Sellermania\OrderConfirmClient::STATUS_DISPATCHED,
                                'trackingNumber' => pSQL($order['tracking_number']),
                                'shippingCarrier' => pSQL($order['shipping_name']),
                            );
                        }
                    }
                }
            }
        }

        if (empty($order_items))
            return false;

        try
        {
            // Calling the confirmOrder service
            $client = new Sellermania\OrderConfirmClient();
            $client->setEmail(Configuration::get('SM_ORDER_EMAIL'));
            $client->setToken(Configuration::get('SM_ORDER_TOKEN'));
            $client->setEndpoint(Configuration::get('SM_CONFIRM_ORDER_ENDPOINT'));
            $result = $client->confirmOrder($order_items);

            // Fix data (when only one result, array is not the same)
            if (!isset($result['OrderItemConfirmationStatus'][0]))
                $result['OrderItemConfirmationStatus'] = array($result['OrderItemConfirmationStatus']);

            // Return results
            return $result;
        }
        catch (\Exception $e)
        {
            Context::getContext()->smarty->assign('sellermania_error', strip_tags($e->getMessage()));
            return false;
        }
    }

    /**
     * Refresh order
     * @param string $order_id
     * @return mixed array data
     */
    public function refreshOrder($order_id)
    {
        // Retrieving data
        try
        {
            $client = new Sellermania\OrderClient();
            $client->setEmail(Configuration::get('SM_ORDER_EMAIL'));
            $client->setToken(Configuration::get('SM_ORDER_TOKEN'));
            $client->setEndpoint(Configuration::get('SM_ORDER_ENDPOINT'));
            $result = $client->getOrderById($order_id);

            // Preprocess data and fix order
            $controller = new SellermaniaImportOrderController($this->module, $this->dir_path, $this->web_path);
            $controller->data = $result['SellermaniaWs']['GetOrderResponse']['Order'];
            $controller->preprocessData();
            $controller->order = new Order((int)Tools::getValue('id_order'));
            $controller->fixOrder(false);

            // Saving it
            $id_sellermania_order = Db::getInstance()->getValue('SELECT `id_sellermania_order` FROM `'._DB_PREFIX_.'sellermania_order` WHERE `id_order` = '.(int)Tools::getValue('id_order'));
            $sellermania_order = new SellermaniaOrder($id_sellermania_order);
            $sellermania_order->info = json_encode($controller->data);
            $sellermania_order->date_accepted = NULL;
            $sellermania_order->update();

            // Return data
            return $controller->data;
        }
        catch (\Exception $e)
        {
            $this->context->smarty->assign('sellermania_error', strip_tags($e->getMessage()));
            return false;
        }
    }

    /**
     * Is order ready to be shipped
     * @param $sellermania_order
     * @return int flag
     */
    public static function isReadyToShip($sellermania_order)
    {

    }


    /**
     * Refresh order status
     * @param $sellermania_order
     * @return bool
     */
    public function refreshOrderStatus($id_order, $sellermania_order)
    {
        // Fix data (when only one product, array is not the same)
        if (!isset($sellermania_order['OrderInfo']['Product'][0]))
            $sellermania_order['OrderInfo']['Product'] = array($sellermania_order['OrderInfo']['Product']);

        // Check which status the order is
        $new_order_state = false;
        foreach ($this->module->sellermania_order_states as $kos => $os)
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
                if ($product['Status'] == $this->module->sellermania_order_states['PS_OS_SM_DISPATCHED']['sm_status'])
                    $new_order_state = Configuration::get('PS_OS_SM_DISPATCHED');

            // If yes, we check if others states are not different of "CANCEL" or "DISPATCH"
            if ($new_order_state == Configuration::get('PS_OS_SM_DISPATCHED'))
                foreach ($sellermania_order['OrderInfo']['Product'] as $kp => $product)
                    if ($product['Status'] != $this->module->sellermania_order_states['PS_OS_SM_CANCEL_CUS']['sm_status'] &&
                        $product['Status'] != $this->module->sellermania_order_states['PS_OS_SM_CANCEL_SEL']['sm_status'] &&
                        $product['Status'] != $this->module->sellermania_order_states['PS_OS_SM_DISPATCHED']['sm_status'])
                        $new_order_state = false;
        }

        // If status is false or equal to first status assigned, we do not change it
        if ($new_order_state === false || $new_order_state == Configuration::get('PS_OS_SM_AWAITING'))
            return false;


        // We check if the status is not already set
        $id_order_history = Db::getInstance()->getValue('
        SELECT `id_order_history` FROM `'._DB_PREFIX_.'order_history`
        WHERE `id_order` = '.(int)$id_order.'
        AND `id_order_state` = '.(int)$new_order_state);
        if ($id_order_history > 0)
            return false;


        // Load order and check existings payment
        $order = new Order((int)$id_order);

        // If order does not exists anymore we stop status update
        if ($order->id < 1)
            return false;

        $employeeId = isset($this->context->employee) ? $this->context->employee->id : 0;
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
        $history->add();
    }


    /**
     * Run method
     * @return string $html
     */
    public function run()
    {
        $orderId = (int)Tools::getValue('id_order');
        $sellermaniaOrder = SellermaniaOrder::getSellermaniaOrderFromOrderId($orderId);
        $this->sellermaniaRepository = new SellermaniaRepository(Db::getInstance(), $sellermaniaOrder);
        $info = $sellermaniaOrder->getApiOrderInfo();

        // Retrieve order data
        if (!ValidateCore::isLoadedObject($sellermaniaOrder) || empty($info)) {
            return '';
        }

        // Save order line status
        $result_status_update = $this->saveProductsStatusFromRequest($sellermaniaOrder);

        // Check if there is a flag to dispatch
        $result_shipping_status_update = $this->saveShippingStatus($info);

        // Refresh order from Sellermania webservices
        $return = $this->refreshOrder($info['OrderInfo']['OrderId']);
        if ($return !== false) {
            $info = $return;
        }

        // Refresh flag to dispatch
        $isReadyToShip = self::isReadyToShip($info);

        // Refresh order status
        $this->refreshOrderStatus(Tools::getValue('id_order'), $info);

        // Get order currency
        $order = new Order((int)Tools::getValue('id_order'));
        $sellermania_currency = new Currency($order->id_currency);

        // Compliancy date format with PS 1.4
        if ($this->ps_version == '14')
            $this->context->smarty->ps_language = new Language($this->context->cookie->id_lang);

        $this->context->smarty->assign('ps_version', $this->ps_version);
        $this->context->smarty->assign('sellermania_order', $info);
        $this->context->smarty->assign('sellermania_currency', $sellermania_currency);
        $this->context->smarty->assign('sellermania_module_path', $this->web_path);
        $this->context->smarty->assign('sellermania_status_list', $this->status_list);
        $this->context->smarty->assign('sellermania_conditions_list', $this->module->sellermania_conditions_list);
        $this->context->smarty->assign('sellermania_status_to_ship', $isReadyToShip);
        $this->context->smarty->assign('sellermania_status_update', $result_status_update);
        $this->context->smarty->assign('sellermania_shipping_status_update', $result_shipping_status_update);

        $this->context->smarty->assign('sellermania_enable_native_refund_system', Configuration::get('SM_ENABLE_NATIVE_REFUND_SYSTEM'));

        return $this->module->compliantDisplay('displayAdminOrder.tpl');
    }
}

