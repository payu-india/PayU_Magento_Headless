<?php

namespace PayUIndia\Payu\Model;

use Magento\Framework\Model\AbstractModel;
use PayUIndia\Payu\Model\ResourceModel\PayuWebhook as ResourceModel;

class PayuWebhook extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ResourceModel::class);
    }
}
