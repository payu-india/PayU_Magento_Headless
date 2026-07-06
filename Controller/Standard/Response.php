<?php

namespace PayUIndia\Payu\Controller\Standard;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;


class Response extends \PayUIndia\Payu\Controller\PayuAbstract implements CsrfAwareActionInterface, HttpGetActionInterface,HttpPostActionInterface
{

	/**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }
 
    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute() {
        $returnUrl = $this->getCheckoutHelper()->getUrl('checkout');
        try {
            $paymentMethod = $this->getPaymentMethod();

			$allParam = $this->getRequest()->getParams();
			if(array_key_exists('full_response',$allParam)){
				$params = json_decode($allParam['full_response'],true);
			}else{
				$params = $allParam;
			}		
			if ($paymentMethod->validateResponse($params)) {

				$quoteId = $params["txnid"];
                if(strpos($quoteId,'-') > 0)
                {
                    $q=explode('-',$quoteId);
                    $quoteId=$q[0];
                }

                $quote = $this->quoteRepository->get($quoteId);

                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/success');
				
				$order = $this->getOrderById($params["txnid"]);
				$this->getCheckoutHelper()->updateOrderFromResponse($order,$params);
				
				if($paymentMethod->getConfigData('debuglog')==true)
					$this->_logger->debug("PayU Created Order ...");
				
				$this->checkoutSession->setLastSuccessQuoteId($quote->getId())
										->setLastQuoteId($quote->getId())
										->clearHelperData();
				if(empty($order) === false)
				{
					if($paymentMethod->getConfigData('debuglog')==true)
						$this->_logger->debug("PayU Updating Order ...");
					
					$payment = $order->getPayment();
					
					$paymentMethod->postProcessing($order, $payment, $params);
					$quote->setIsActive(false)->save();
					$this->checkoutSession->replaceQuote($quote);
					$this->checkoutSession->setLastOrderId($order->getId())
											->setLastRealOrderId($order->getIncrementId())
											->setLastOrderStatus($order->getStatus());
					
					if($paymentMethod->getConfigData('debuglog')==true)
						$this->_logger->debug("PayU Updated Order ...Redirecting to Success...");
				}
				
				
				
            } else {
                $this->messageManager->addErrorMessage(__('Payment failed.'));
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/failure');
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
			$this->_logger->debug("something went wrong....".$e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
			$this->_logger->debug("something went wrong....".$e->getMessage());

            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));			
        }
        $this->getResponse()->setRedirect($returnUrl);
    }

}
