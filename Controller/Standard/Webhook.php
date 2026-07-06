<?php

namespace PayUIndia\Payu\Controller\Standard;

class Webhook extends \PayUIndia\Payu\Controller\PayuAbstract
{
    public function execute(){

        
        if($this->getPaymentMethod()->getConfigData('active') == true && $this->getPaymentMethod()->getConfigData('enablewebhook')==true ){
            $post = $this->getPostData();
            $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/webhook.log');
            $logger = new \Zend_Log();
            $logger->addWriter($writer);

            $logger->info("post:");
            $logger->info(json_encode($post));
            $this->getCheckoutHelper()->saveWebhook($post);
        }
    }
    protected function getPostData()
    {
        $request = file_get_contents('php://input');

        if (!isset($request) || empty($request))
        {
            $request = "{}";
        }

        return $request;
    }

}
