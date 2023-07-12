<?php

class Telr_StandardTelrpayments_Helper_Data extends Mage_Core_Helper_Abstract {

    /**
     * 
     * Get Real Ip Address 
     * (Check ip from share internet || to check ip is pass from proxy)
     * @return string
     */
    function getRealIpAddr() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }

    /**
     * 
     * Get Telr Payment Gateway configuration settings 
     * 
     * @return array
     */
    public function telrSettings() {

        return $configValue = Mage::getStoreConfig('payment/standardtelrpayments');
    }
    

}

?>