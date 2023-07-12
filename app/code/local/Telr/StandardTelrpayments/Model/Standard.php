<?php

class Telr_StandardTelrpayments_Model_Standard extends Mage_Payment_Model_Method_Abstract
{

    /**
    * Config instance
    * @var Telr_Telrpayment_Model_Config
    */
    protected $_config = null;

    /**
    * unique internal payment method identifier
    */
    protected $_code = 'standardtelrpayments';
    protected $_formBlockType = 'standardtelrpayments/form';
 
    /**
    * Availability options
    */
    protected $_isGateway              = true;
    protected $_canRefund              = true;
    protected $_canVoid                = true;
    protected $_canUseInternal         = true;
    protected $_canUseCheckout         = true;
    protected $_canUseForMultishipping = false;
    protected $_isOffline = true;
    protected $helper;
    protected $logger;
    protected $_minAmount = null;
    protected $_maxAmount = null;
    protected $_orderFactory;
    protected $_checkoutSession;
    protected $orderManagement;
    protected $orderSender;
    protected $_order;
    protected $api_url = 'https://secure.telr.com/gateway/order.json';

    protected $_supportedCurrencyCodes = array(
        'AFN', 'ALL', 'DZD', 'ARS', 'AUD', 'AZN', 'BSD', 'BDT', 'BBD',
        'BZD', 'BMD', 'BOB', 'BWP', 'BRL', 'GBP', 'BND', 'BGN', 'CAD',
        'CLP', 'CNY', 'COP', 'CRC', 'HRK', 'CZK', 'DKK', 'DOP', 'XCD',
        'EGP', 'EUR', 'FJD', 'GTQ', 'HKD', 'HNL', 'HUF', 'INR', 'IDR',
        'ILS', 'JMD', 'JPY', 'KZT', 'KES', 'LAK', 'MMK', 'LBP', 'LRD',
        'MOP', 'MYR', 'MVR', 'MRO', 'MUR', 'MXN', 'MAD', 'NPR', 'TWD',
        'NZD', 'NIO', 'NOK', 'PKR', 'PGK', 'PEN', 'PHP', 'PLN', 'QAR',
        'RON', 'RUB', 'WST', 'SAR', 'SCR', 'SGF', 'SBD', 'ZAR', 'KRW',
        'LKR', 'SEK', 'CHF', 'SYP', 'THB', 'TOP', 'TTD', 'TRY', 'UAH',
        'AED', 'USD', 'VUV', 'VND', 'XOF', 'YER'
    );

    private function requestGateway($api_url, $params) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, count($params));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        $returnData = json_decode(curl_exec($ch),true);
        curl_close($ch);
        return $returnData;
    }


    /**
    * Payment request
    *
    * @param $order Object
    * @throws \Magento\Framework\Validator\Exception
    */
    public function generateTelrRequest($order) {
        $version = Mage::getVersion();
        $this->_order=$order;
        $settings = Mage::helper('standardtelrpayments')->telrSettings();
        
        $payment_method = 0;
        if($settings['payment_method'] == 2 && $this->isSSL()){
            $ivp_framed = true; 
            $payment_method = 2;
        }else{
            $ivp_framed = false; 
            $payment_method = 0;
        }

        $billing_address = $this->_order->getBillingAddress();
        $shipping_address = $this->_order->getShippingAddress();
        $customerSession = Mage::getSingleton('checkout/session');
        $cart_id=$this->_order->getRealOrderId();

        $merchant_txn_id = $cart_id . '_' . uniqid();

        $params['ivp_amount'] = round($this->_order->getGrandTotal(), 2);
        $params['ivp_currency'] = $this->_order->getOrderCurrencyCode();
        $params['ivp_authkey'] = $settings["authentication_key"];
        $params['bill_addr1'] = $billing_address->getStreet()[0];
        $params['ivp_lang'] = $settings['paymentpage_language'];
        $params['ivp_desc'] = $settings['transaction_desc'];
        $params['bill_fname'] = $billing_address->getName();
        $params['bill_sname'] = $billing_address->getName();
        $params['ivp_framed'] = $payment_method;
        $params['ivp_source'] = 'Magento Store v' . $version;
        $params['ivp_store'] = $settings['store_id'];
        $params['ivp_test'] = $settings['testmode'];
        $params['ivp_cart'] = $merchant_txn_id;
        $params['ivp_method'] = 'create';

        if (count($billing_address->getStreet()) > 1) {
            $params['bill_addr2']  = $billing_address->getStreet()[1];
        }

        if (count($billing_address->getStreet()) > 2) {
            $params['bill_addr3']  = $billing_address->getStreet()[2];
        }

        $params['bill_city'] = $billing_address->getCity();
        $params['bill_region'] = $billing_address->getRegion();
        $params['bill_zip'] = $billing_address->getPostcode();
        $params['bill_country'] = $billing_address->getCountryId();
        $params['bill_email'] = $this->_order->getCustomerEmail();
        $params['bill_tel'] = $billing_address->getTelephone();
        $params['bill_phone'] = $billing_address->getTelephone();
        $params['return_auth'] = $this->getSucessAuthUrl($ivp_framed).'?coid='.$this->_order->getRealOrderId();
        $params['return_can'] =  $this->getCancelUrl($ivp_framed).'?coid='.$this->_order->getRealOrderId();
        $params['return_decl'] =  $this->getCancelUrl($ivp_framed).'?coid='.$this->_order->getRealOrderId();
        $params['ivp_update_url'] = $this->getIvpCallbackUrl() . "?cart_id=" . $cart_id;

        try {
            $results = $this->requestGateway($this->api_url, $params);
            $url = false;
            if (isset($results['order']['ref']) && isset($results['order']['url'])) {
                $ref = trim($results['order']['ref']);
                $url = trim($results['order']['url']);
                Mage::getSingleton('checkout/session')->setTelrOrderRef($ref);
                return $url;
            }
        } catch (Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage()]);
            Mage::log('Error creating transaction, exception from curl request.');
        }
        Mage::log('Error creating transaction, no ref/url obtained.');
        return false;
    }


    private function notifyOrder() {
        $this->orderSender->send($this->_order);
        $this->order->addStatusHistoryComment('Customer email sent')->setIsCustomerNotified(true)->save();
    }

    /**
    * Return the provided comment as either a string or a order status history object
    *
    * @param string $comment
    * @param bool $makeHistory
    * @return string|\Magento\Sales\Model\Order\Status\History
    */
    protected function addOrderComment($comment,$makeHistory=false) {
        $message=$comment;
        if ($makeHistory) {
            $message=$this->_order->addStatusHistoryComment($message);
            $message->setIscustomerNotified(null);
        }
        return $message;
    }

    private function registerAuth($message,$txref) {
     
        $this->logDebug("registerAuth");

        $payment = $this->_order->getPayment();
        $payment->setTransactionId($txref);
        $payment->setIsTransactionClosed(0);
        $payment->setAdditionalInformation('telr_message', $message);
        $payment->setAdditionalInformation('telr_ref', $txref);
        $payment->setAdditionalInformation('telr_status',Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH);
        $payment->place();
    }

    private function registerPending($message,$txref) {
        $this->logDebug("registerPending");

        $payment = $this->_order->getPayment();
        $payment->setTransactionId($txref);
        $payment->setIsTransactionClosed(0);
        $payment->setAdditionalInformation('telr_message', $message);
        $payment->setAdditionalInformation('telr_ref', $txref);
        $payment->setAdditionalInformation('telr_status', 'Pending');
        $payment->place();
    }

    private function registerCapture($message,$txref) {
        $this->logDebug("registerCapture");
        $payment = $this->_order->getPayment();
        $payment->setTransactionId($txref);
        $payment->setIsTransactionClosed(1);
        $payment->setAdditionalInformation('telr_message', $message);
        $payment->setAdditionalInformation('telr_ref', $txref);
        $payment->setAdditionalInformation('telr_status', Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE);
        $payment->place();
    }

    private function updateOrder($message, $state, $status, $notify) {
        if ($state) {
            $this->_order->setState($state);
            if ($status) {
                $this->_order->setStatus($status);
            }
            $this->_order->save();
        } else if ($status) {
            $this->_order->setStatus($status);
            $this->_order->save();
        }
        if ($message) {
            $this->_order->addStatusHistoryComment($message);
            $this->_order->save();
        }
        if ($notify) {
            $this->notifyOrder();
        }
    }

    public function refundOrder($order_id) {
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $invoices = array();
        foreach ($order->getInvoiceCollection() as $invoice) {
            $invoices[] = $invoice;
        }
                
        $service = Mage::getModel('sales/service_order', $order);
     
        foreach ($invoices as $invoice) {
            $creditmemo = $service->prepareInvoiceCreditmemo($invoice); 
            $creditmemo->setRefundRequested(true);
            $creditmemo->setOfflineRequested(true);
            $creditmemo->setPaymentRefundDisallowed(false);
            $creditmemo->register();
            $creditmemo->save();
            Mage::getModel('core/resource_transaction')
                ->addObject($creditmemo)
                ->addObject($order)
                ->save();
        } 
    }

    private function getStateCode($name) {
        if (strcasecmp($name,"processing")==0) { return Mage_Sales_Model_Order::STATE_PROCESSING; }
        if (strcasecmp($name,"review")==0)     { return Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW; }
        if (strcasecmp($name,"paypending")==0) { return Mage_Sales_Model_Order::STATE_PENDING_PAYMENT; }
        if (strcasecmp($name,"pending")==0)    { return Mage_Sales_Model_Order::STATE_PENDING_PAYMENT; }
        if (strcasecmp($name,"cancelled")==0)   { return Mage_Sales_Model_Order::STATE_CANCELED; }
        if (strcasecmp($name,"complete")==0)   { return Mage_Sales_Model_Order::STATE_COMPLETE; }
        return false;
    }

    /**
    * Transaction was authorised
    */
    private function paymentCompleted($txref) {
        $this->registerCapture('Payment completed',$txref);
        $message='Payment completed: '.$txref;
        $state=$this->getStateCode("processing");
        $this->updateOrder($message, $state, $state, false);
    }

    /**
    * Transaction has not been completed (deferred payment method, or on hold)
    */
    private function paymentPending($txref) {
        $this->registerPending('Payment pending',$txref);
        $message='Payment pending: '.$txref;
        $state=$this->getStateCode("paypending");
        $this->updateOrder($message, $state, $state, false);
    }

    /**
    * Transaction has not been authorised but completed (auth method used, or sale put on hold)
    */
    private function paymentAuthorised($txref) {
        $this->registerAuth('Payment authorised',$txref);
        $message='Payment authorisation: '.$txref;
        $state=$this->getStateCode("review");
        $this->updateOrder($message, $state, $state, false);
    }

    /**
    * Transaction has been refunded (may be partial refund)
    */
    private function paymentRefund($txref, $currency, $amount) {
        $message='Refund of '.$currency.' '.$amount.': '.$txref;
        $this->updateOrder($message, false, false, false);
    }

    /**
    * Transaction has been voided
    */
    private function paymentVoided($txref, $currency, $amount) {
        $message='Void of '.$currency.' '.$amount.': '.$txref;
        $this->updateOrder($message, false, false, false);
    }

    /**
    * Transaction request has been cancelled
    */
    private function paymentCancelled() {
        $message='Payment request cancelled';
        $state=$this->getStateCode("cancelled");
        $this->updateOrder($message, $state, $state, false);
    }

    private function logDebug($message) {
        $dbg['telr']=$message;
    // $this->logger->debug($dbg,null,true);
    }

    public function updateOrderStatus($order_id, $status, $txn_ref){
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $message = '';
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        switch ($status) {
            case 'complete':
                $message='Payment completed: ' . $txn_ref;
                $state=$this->getStateCode("complete");
                break;

            case 'cancelled':
                $message='Payment request cancelled';
                $state=$this->getStateCode("cancelled");
                break;
                
            case 'canceled':
                $message='Payment request cancelled';
                $state=$this->getStateCode("cancelled");
                break;

            case 'refunded':
                $message='Transaction Refunded ref: ' . $txn_ref;
                $state=$this->getStateCode("cancelled");
                break;
            
            default:
                $message = 'default';
                $state=$this->getStateCode($status);
                break;
        }
        $this->updateOrder($message, $state, $state, false);
    }

    /**
    * Generate order Invoices from Order id
    */
    public function generateOrderInvoice($order_id) {
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $invoice = Mage::getModel('sales/service_order', $order)
                    ->prepareInvoice();
                
        if (!$invoice->getTotalQty()) {
            Mage::throwException(
                Mage::helper('core')->__('Cannot create an invoice without products.')
            );
        }
        
        /*$invoice->setRequestedCaptureCase(
            Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE
        );*/
        
        $invoice->addComment('Invoice genrated automatically');
        $invoice->register();
        
        $transactionSave = Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($order);
        
        $transactionSave->save();
        $order->setTotalPaid($order->getGrandTotal());  
        $order->setBaseTotalPaid($order->getGrandTotal());  
        $order->save();  
        try {
            $invoice->sendEmail(true);
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')
                ->addError($this->__('Unable to send the invoice email.'));
        }                
        $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID)->save(); 
    }

    /**
    * Payment request validation
    */
    public function validateResponse($order_id) {
        $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $this->_order=$order;

        $settings = Mage::helper('standardtelrpayments')->telrSettings();
        $customerSession = Mage::getSingleton('checkout/session');

        $auth_key = $settings["authentication_key"];
        $store_id = $settings['store_id'];
        $telr_order_ref = Mage::getSingleton('checkout/session')->getTelrOrderRef();

        $params = array(
            'ivp_method'   => 'check',
            'ivp_store'    => $store_id,
            'ivp_authkey'  => $auth_key,
            'order_ref'    => $telr_order_ref
        );

        $results = $this->requestGateway($this->api_url, $params);
        $objOrder='';
        $objError='';
        if (isset($results['order'])) { $objOrder = $results['order']; }
        if (isset($results['error'])) { $objError = $results['error']; }
        if (is_array($objError)) { 
            return false;
        }

        if (!isset(
            $objOrder['cartid'],
            $objOrder['status']['code'],
            $objOrder['transaction']['status'],
            $objOrder['transaction']['ref'])) {
            return false;
        }

        $new_tx=$objOrder['transaction']['ref'];
        $ordStatus=$objOrder['status']['code'];
        $txStatus=$objOrder['transaction']['status'];
        $cart_id=$objOrder['cartid'];
        $txnamount=$results['order']['amount'];
        $parts=explode('_', $cart_id, 2);
        $order_id=$parts[0];

        if (($ordStatus==-1) || ($ordStatus==-2)) {
            // Order status EXPIRED (-1) or CANCELLED (-2)
            $this->paymentCancelled($new_tx);
            return false;
        }
        if ($ordStatus==4) {
            // Order status PAYMENT_REQUESTED (4)
            $this->paymentPending($new_tx);
            return true;
        }
        if ($ordStatus==2) {
            // Order status AUTH (2)
            $this->paymentAuthorised($new_tx);
            return true;
        }
        if ($ordStatus==3) {
            // Order status PAID (3)
            if ($txStatus=='P') {
                // Transaction status of pending or held
                $this->paymentPending($new_tx);
                if (!$order->canInvoice()) {
        		    Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
        		}
        		
        		$invoice = Mage::getModel('sales/service_order', $order)
        		    ->prepareInvoice();
        		
        		if (!$invoice->getTotalQty()) {
        		    Mage::throwException(
        		        Mage::helper('core')->__('Cannot create an invoice without products.')
        		    );
        		}
        		
        		$invoice->setRequestedCaptureCase(
        		    Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE
        		);
        		
        		$invoice->addComment('Invoice genrated automatically');
        		$invoice->register();
        		
        		$transactionSave = Mage::getModel('core/resource_transaction')
        		    ->addObject($invoice)
        		    ->addObject($order);
        		
        		$transactionSave->save();
        		
        		try {
        		    $invoice->sendEmail(true);
        		} catch (Exception $e) {
        		    Mage::logException($e);
        		    Mage::getSingleton('core/session')
        		        ->addError($this->__('Unable to send the invoice email.'));
        		}
		        $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID)->save();
                return true;
            }
            if ($txStatus=='H') {
                // Transaction status of pending or held
                $this->paymentAuthorised($new_tx);
               if (!$order->canInvoice()) {
        		    Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
        		}
        		
        		$invoice = Mage::getModel('sales/service_order', $order)
        		    ->prepareInvoice();
        		
        		if (!$invoice->getTotalQty()) {
        		    Mage::throwException(
        		        Mage::helper('core')->__('Cannot create an invoice without products.')
        		    );
        		}
        		
        		$invoice->setRequestedCaptureCase(
        		    Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE
        		);
        		
        		$invoice->addComment('Invoice genrated automatically');
        		$invoice->register();
        		
        		$transactionSave = Mage::getModel('core/resource_transaction')
        		    ->addObject($invoice)
        		    ->addObject($order);
        		
        		$transactionSave->save();
        		
        		try {
        		    $invoice->sendEmail(true);
        		} catch (Exception $e) {
        		    Mage::logException($e);
        		    Mage::getSingleton('core/session')
        		        ->addError($this->__('Unable to send the invoice email.'));
        		}  
                $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID)->save();              
                return true;
            }
            if ($txStatus=='A') {
                // Transaction status = authorised
                // $this->paymentCompleted($new_tx);
                // Set The Default Transaction status set in settings
                $settings = Mage::helper('standardtelrpayments')->telrSettings();
                $orderStatus = $settings['order_status'];
                $this->updateOrderStatus($order_id, $orderStatus,'000');
              
                if (!$order->canInvoice()) {
		            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
        		}
        		
        		$invoice = Mage::getModel('sales/service_order', $order)
        		    ->prepareInvoice();
        		
        		if (!$invoice->getTotalQty()) {
        		    Mage::throwException(
        		        Mage::helper('core')->__('Cannot create an invoice without products.')
        		    );
        		}
        		
        		/*$invoice->setRequestedCaptureCase(
        		    Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE
        		);*/
        		
        		$invoice->addComment('Invoice genrated automatically');
        		$invoice->register();
        		
        		$transactionSave = Mage::getModel('core/resource_transaction')
        		    ->addObject($invoice)
        		    ->addObject($order);
        		
        		$transactionSave->save();
                $order->setTotalPaid($txnamount);  
        		$order->setBaseTotalPaid($txnamount);  
                $order->save();  
        		try {
        		    $invoice->sendEmail(true);
        		} catch (Exception $e) {
        		    Mage::logException($e);
        		    Mage::getSingleton('core/session')
        		        ->addError($this->__('Unable to send the invoice email.'));
        		}                
                $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID)->save();     
                return true;
            }
        }
        // Declined
        return false;
    }

    public function getOrderPlaceRedirectUrl(){
        return Mage::getUrl('standardtelrpayments/standard/redirect', array('_secure' => true));
    }

    public function isSSL() {
        $isSecure = false;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            $isSecure = true;
        }
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
            $isSecure = true;
        }
        return $isSecure;
    }

    public function getCancelUrl($isFramed = false) {
        if($isFramed == true){
            return Mage::getUrl('standardtelrpayments/standard/cancelIframe', array('_secure' => true));
        }else{
            return Mage::getUrl('standardtelrpayments/standard/cancel', array('_secure' => true));
        }

    }

    public function getSucessAuthUrl($isFramed = false) {
        if($isFramed == true){
            return Mage::getUrl('standardtelrpayments/standard/successIframe', array('_secure' => true));
        }else{
            return Mage::getUrl('standardtelrpayments/standard/success', array('_secure' => true));
        }

    }

    public function getIvpCallbackUrl() {
        return Mage::getUrl('standardtelrpayments/standard/ivpcallback', array('_secure' => true));

    }

    public function getiframeUrl() {
        return Mage::getUrl('standardtelrpayments/standard/process', array('_secure' => true));

    }
}
