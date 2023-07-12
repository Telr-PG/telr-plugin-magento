<?php
class Telr_StandardTelrpayments_StandardController extends Mage_Core_Controller_Front_Action
{
    protected $_order;
    protected function _expireAjax()
    {
        if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
            exit;
        }
    }

    public function getStandard()
    {
        return Mage::getSingleton('standardtelrpayments/standard');
    }

    public function redirectAction()
    {
        try{
            $session = Mage::getSingleton('checkout/session');            
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());
            $settings = Mage::helper('standardtelrpayments')->telrSettings();

            $ivp_framed = ($settings['payment_method'] == 2 && Mage::getModel('standardtelrpayments/Standard')->isSSL()) ? true : false; 
         
             if ($order->getBillingAddress()) {

                //Proceed with generating Order Request for Standard & Framed Checkout.
                 $payment_url = Mage::getModel('standardtelrpayments/Standard')->generateTelrRequest($order);

                //Proceed to Payment if payment request created successfully with gateway.
                if ($payment_url) {

                     // Check if Payment mode = framed & SSL is active, else proceed with regular checkout page.
                     if($ivp_framed){
                        $iframe_checkout_page = Mage::getModel('standardtelrpayments/Standard')->getiframeUrl();
                        Mage::getSingleton('checkout/session')->setTelrPaymentUrl($payment_url);
                        $this->getResponse()->setRedirect(Mage::getUrl('standardtelrpayments/standard/process', array('_secure' => true)));
                     }else{
                        $this->getResponse()->setRedirect($payment_url);
                     }

                } 
                else {
                  $this->_cancelPayment();
                  $this->_checkoutSession->restoreQuote();
                  $this->messageManager->addError(__('Sorry, unable to process your transaction at this time.'));
                  $this->getResponse()->setRedirect($this->getTelrHelper()->getUrl('checkout/cart'));
                }
            } else {
                $this->_cancelPayment();
                $this->_checkoutSession->restoreQuote();
                $this->getResponse()->setRedirect($this->getTelrHelper()->getUrl('checkout'));
            }

            $session->unsQuoteId();
            $session->unsRedirectUrl();

        }
        catch(Exception $e){
            var_dump($e->getMessage());
        }
    }

    /** 
      * IFrame Checkout Process Action
      */
    public function processAction(){
        $iFrameUrl = Mage::getSingleton('checkout/session')->getTelrPaymentUrl();

        if($iFrameUrl && $iFrameUrl != ''){
          $this->loadLayout();
          $this->renderLayout();
        }else{
            $this->_cancelPayment();
            $this->_checkoutSession->restoreQuote();
            $this->messageManager->addError(__('Sorry, unable to process your transaction at this time.'));
            $this->getResponse()->setRedirect($this->getTelrHelper()->getUrl('checkout/cart'));
        }
    }

    /**
     * When a customer cancel payment from Telr.
     */
    public function cancelAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getTelrStandardQuoteId(true));
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->cancel()->save();
            }
            Mage::helper('standardtelrpayments/checkout')->restoreQuote();
        }
        $this->_redirect('checkout/cart');
    }

    public function cancelIframeAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getTelrStandardQuoteId(true));
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->cancel()->save();
            }
            Mage::helper('standardtelrpayments/checkout')->restoreQuote();
        }
        $returnUrl = Mage::getUrl('checkout/cart');
        echo "<script type='text/javascript'>window.top.location.href = '" . $returnUrl . "'</script>";
    }

    public function  successAction()
    {         
         $session = Mage::getSingleton('checkout/session');
         $order_id = $this->getRequest()->getParam('coid');
         if($order_id!="" && $session!=""){
           
             Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
             $session->setQuoteId($session->getTelrStandardQuoteId(true));

             $validateResp = Mage::getModel('standardtelrpayments/Standard')->validateResponse($order_id);

              if($validateResp) {
                $this->_redirect('checkout/onepage/success', array('_secure'=>true));
                return false;

            } else {
                $this->_cancelPayment();
                $this->_checkoutSession->restoreQuote();
                $returnUrl = $this->getTelrHelper()->getUrl('checkout/onepage/failure');
            }
       }
        $this->getResponse()->setRedirect($returnUrl);
    }

    public function  successIframeAction()
    {         
        $session = Mage::getSingleton('checkout/session');
        $order_id = $this->getRequest()->getParam('coid');
        $returnUrl = Mage::getUrl('checkout/onepage/failure');
        
        if($order_id!="" && $session!=""){

            Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
            $session->setQuoteId($session->getTelrStandardQuoteId(true));

            $validateResp = Mage::getModel('standardtelrpayments/Standard')->validateResponse($order_id);
            
            if($validateResp) {
                $returnUrl = Mage::getUrl('checkout/onepage/success');
            } else {
                $this->_cancelPayment();
                $this->_checkoutSession->restoreQuote();
            }
        }
        echo "<script type='text/javascript'>window.top.location.href = '" . $returnUrl . "'</script>";
    }    

    /**
      * IVPCallBack Action
      * This action is called directly from Telr server as server to server call when 
      * Transaction status is updated on Telr. This action accepts cart_id as input parameter
      * and Transaction response as Post Parameters. 
      * According to Order Status & Transaction Status, Order is updated in CMS.
      */
    public function ivpcallbackAction(){
        Mage::log("IVPCallBack Params: " . json_encode($_POST));
        if (isset($_GET['cart_id']) && !empty($_GET['cart_id']) && !empty($_POST)) {
           
            // proceed to update order payment details:
            $cartIdExtract = explode("_", $_POST['tran_cartid']);
            $order_id = $cartIdExtract[0];
           
            if ($order_id == $_GET['cart_id']) {
                try {
                    $tranType = $_POST['tran_type'];
                    $tranStatus = $_POST['tran_authstatus'];

                    if ($tranStatus == 'A') {
                        switch ($tranType) {
                            case '1':
                            case '4':
                            case '7':
                                $settings = Mage::helper('standardtelrpayments')->telrSettings();
		                        $orderStatus = $settings['order_status'];
		                
                                Mage::getModel('standardtelrpayments/Standard')->updateOrderStatus($order_id, $orderStatus, $_POST['tran_ref']);
                                Mage::getModel('standardtelrpayments/Standard')->generateOrderInvoice($order_id);
                                break;

                            case '2':
                            case '6':
                            case '8':
                                Mage::getModel('standardtelrpayments/Standard')->updateOrderStatus($order_id, "cancelled", $_POST['tran_ref']);
                                break;

                            case '3':
                                Mage::getModel('standardtelrpayments/Standard')->refundOrder($order_id);
                                break;

                            default:
                                // No action defined
                                break;
                        }
                    }
                } catch (Exception $e) {
                    // Error Occurred While processing request.
                    print_r($e->getMessage());
                     die('Error Occurred While processing request');
                }
            } else {
                 die('Cart id mismatch');
            }
            
            exit;
        }
        else{
            die('Invalid Cart id');
            exit;
        }
    }

    public function CancelorderAction($order_id){

         try {
                $order = Mage::getModel('sales/order')->loadByIncrementId('100000149');
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
        catch (Mage_Core_Exception $e) {
            print_r($e->getMessage());
        }

    }


    protected function getTelrHelper() {
        return $this->_telrHelper;
    }
}
