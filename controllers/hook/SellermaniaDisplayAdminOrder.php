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

    /** @var  SellermaniaOrder */
    private $sellermaniaOrder;

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
     * @return array|bool
     */
    public function saveProductsStatusFromRequest()
    {
        $info = $this->sellermaniaOrder->getApiOrderInfo();

        // Check if form has been submitted
        if (Tools::getValue('sellermania_line_max') == '') {
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

        $orderItems = $this->sellermaniaRepository->buildOrderItems($products);
        $result = $this->sellermaniaRepository->saveProductsStatus($orderItems);

        return $result;
    }

    /**
     * Save shipping status
     * @return array|bool
     */
    public function saveShippingStatus()
    {
        // Check if form has been submitted
        if (Tools::getValue('sellermania_tracking_registration') == '') {
            return false;
        }

        // Register shipping data
        return $this->sellermaniaRepository->registerShippingData([
            'tracking_number' => Tools::getValue('tracking_number'),
            'shipping_name' => Tools::getValue('shipping_name'),
        ]);
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
     * Run method
     * @return string $html
     */
    public function run()
    {
        $orderId = (int) Tools::getValue('id_order');
        $this->sellermaniaOrder = SellermaniaOrder::getSellermaniaOrderFromOrderId($orderId);
        $apiOrderInfo = $this->sellermaniaOrder->getApiOrderInfo();

        // Retrieve order data
        if (!ValidateCore::isLoadedObject($this->sellermaniaOrder) || empty($apiOrderInfo)) {
            return '';
        }

        $this->sellermaniaRepository = new SellermaniaRepository(Db::getInstance());
        $this->sellermaniaRepository->setSellermaniaOrder($this->sellermaniaOrder);

        // Save order line status
        $result_status_update = $this->saveProductsStatusFromRequest();

        // Check if there is a flag to dispatch
        $result_shipping_status_update = $this->saveShippingStatus();

        // Refresh order from Sellermania webservices
        $return = $this->refreshOrder($apiOrderInfo['OrderInfo']['OrderId']);
        if (is_array($return)) {
            $apiOrderInfo = $this->sellermaniaOrder->setApiInfo($return)->getApiOrderInfo();
        }

        // Refresh flag to dispatch
        $isReadyToShip = $this->sellermaniaRepository->isOrderReadyToShip();

        $sellermania = new Sellermania();
        // Refresh order status
        $this->sellermaniaRepository->refreshOrderStatus(Tools::getValue('id_order'), $apiOrderInfo, $sellermania);

        // Get order currency
        $order = new Order((int)Tools::getValue('id_order'));
        $sellermania_currency = new Currency($order->id_currency);

        // Compliancy date format with PS 1.4
        if ($this->ps_version == '14')
            $this->context->smarty->ps_language = new Language($this->context->cookie->id_lang);

        $this->context->smarty->assign('ps_version', $this->ps_version);
        $this->context->smarty->assign('sellermania_order', $apiOrderInfo);
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

