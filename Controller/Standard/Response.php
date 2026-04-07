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
           
			if ($paymentMethod->validateResponse($params,'web')) {

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
                $quoteId = $params['txnid'];
                if (strpos($quoteId, '-') !== false) {
                    $quoteId = explode('-', $quoteId)[0];
                }
           
                $quote = $this->quoteRepository->get($quoteId);
                
                $incrementId = $quote->getReservedOrderId();
                if ($incrementId) {
                    $order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
                    $status = strtolower($params['status'] ?? '');
                    if (in_array($status, ['cancel', 'failed', 'failure'], true)) {
                        if ($order && $order->getId() && $order->canCancel()) {
                            try {
                            
                                    $order->registerCancellation($params['txnMessage'] ?? $params['error_Message'] ?? __('Order cancelled'))->save();
                                
                            } catch (\Exception $e) {
                                $this->messageManager->addErrorMessage(__('Something went wrong while cancelling your order.Please contact support.'));
                                return $this->getResponse()->setRedirect(
                                        $this->getCheckoutHelper()->getUrl('checkout').'#payment'
                                );
                            }
                        }
                    }
                }
                $quote->setIsActive(true);
                $quote->setReservedOrderId(null);
                $this->quoteRepository->save($quote);
                $this->checkoutSession->replaceQuote($quote);
                $this->messageManager->addErrorMessage(__('Your order has been canceled.'));

                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout').'#payment';
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
			$this->_logger->debug("something went wrong....".$e->getMessage());
            $this->messageManager->addExceptionMessage($e,  __('We can\'t place the order. Please contact support.'));	
        } catch (\Exception $e) {
			$this->_logger->debug("something went wrong....".$e->getMessage());

            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order. Please contact support.'));			
        }
        $this->getResponse()->setRedirect($returnUrl);
    }

}
