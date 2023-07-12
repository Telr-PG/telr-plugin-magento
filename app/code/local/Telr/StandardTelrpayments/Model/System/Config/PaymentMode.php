<?php
/**
 * Source model for available payment method v2
 */
class Telr_StandardTelrpayments_Model_System_Config_PaymentMode
{
    public function toOptionArray()
    {
        return array (
            0 => Mage::helper('standardtelrpayments')->__("Standard"),
            2 => Mage::helper('standardtelrpayments')->__("IFrame"),
        );
    }
}
