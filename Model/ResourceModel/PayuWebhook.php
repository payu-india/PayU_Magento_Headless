<?php

namespace PayUIndia\Payu\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PayuWebhook extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('payu_webhook', 'id');
    }
}
