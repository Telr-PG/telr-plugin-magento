<?php
/**
 * Source model for available payment method v2
 */
class Telr_StandardTelrpayments_Model_System_Config_Language
{
    public function toOptionArray()
    {
        return array (
            'en' => Mage::helper('standardtelrpayments')->__("English"),
            'ar' => Mage::helper('standardtelrpayments')->__("Arabic"),
        );
    }
}
