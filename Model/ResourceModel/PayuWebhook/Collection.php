<?php

namespace PayUIndia\Payu\Model\ResourceModel\PayuWebhook;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PayUIndia\Payu\Model\PayuWebhook as Model;
use PayUIndia\Payu\Model\ResourceModel\PayuWebhook as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
