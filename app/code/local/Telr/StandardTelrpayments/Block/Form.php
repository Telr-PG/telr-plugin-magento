<?php
 
class Telr_StandardTelrpayments_Block_Form extends Mage_Payment_Block_Form {
     
    /**
     * Constructor Set template.
     */
    protected function _construct() {
        parent::_construct();
        $this->setTemplate('standard_telrpayments/form.phtml');
    }

}
?>