<?php

namespace PayUIndia\Payu\Controller\Standard;

class Webhook extends \PayUIndia\Payu\Controller\PayuAbstract
{
    public function execute(){

        
        if($this->getPaymentMethod()->getConfigData('active') == true && $this->getPaymentMethod()->getConfigData('enablewebhook')==true ){
            $rawPost = $this->getPostData();

            $postData = json_decode($rawPost, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                parse_str($rawPost, $postData);
            }
            if (!$this->getPaymentMethod()->validateResponse($postData,'webhook')) {
                $this->_logger->warning('PayU webhook rejected - invalid signature', [
                    'txnid' => $postData['txnid'] ?? 'unknown'
                 ]);
                $this->getResponse()->setHttpResponseCode(400);
                return;
            }
            $this->getCheckoutHelper()->saveWebhook($rawPost);
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
