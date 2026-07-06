<?php

namespace PayUIndia\Payu\Model;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class WebhookUrl extends \Magento\Config\Block\System\Config\Form\Field
{
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }
    protected function _getElementHtml(AbstractElement $element)
    {
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $url=$baseUrl."payu/standard/webhook/";
        $copyButton = "<span class='rzp-pending-order-cron-to-clipboard' style='background-color: #337ab7; color: white; border: none;cursor: pointer; padding: 2px 4px; text-decoration: none;display: inline-block;'>Copy Cron</span>";

        $element->setComment("To setup the webhook copy the URL and paste it into Payu Dashboard . <br><br><strong>$url</strong>");
        return $element->getElementHtml();
    }

}
