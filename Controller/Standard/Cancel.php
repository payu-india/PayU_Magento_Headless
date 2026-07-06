<?php

namespace PayUIndia\Payu\Controller\Standard;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;


class Cancel extends \PayUIndia\Payu\Controller\PayuAbstract implements CsrfAwareActionInterface, HttpGetActionInterface, HttpPostActionInterface
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
        $goto="";
        try {
            $allParam = $this->getRequest()->getParams();
            $params = isset($allParam['full_response']) ? json_decode($allParam['full_response'], true): $allParam;
            $data = [
                'txnid'      => $params['txnid'] ?? $params['txnId'] ?? null,
                'status'     => $params['txnStatus'] ?? $params['status'] ?? null,
                'txnmessage' => $params['txnMessage'] ?? $params['error_Message'] ?? __('Order cancelled')
            ];
            if (empty($data['txnid'])) {
                $this->messageManager->addErrorMessage(__('Invalid transaction details.'));
                return $this->getResponse()->setRedirect(
                    $this->getCheckoutHelper()->getUrl('checkout').$goto
                );
            }
            $quoteId = $data['txnid'];
            if (strpos($quoteId, '-') !== false) {
                $quoteId = explode('-', $quoteId)[0];
            }
            try {
                $quote = $this->quoteRepository->get($quoteId);
                $goto="#payment";
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Unable to restore your cart.'));
                return $this->getResponse()->setRedirect(
                    $this->getCheckoutHelper()->getUrl('checkout').$goto
                );
            }
           
            $incrementId = $quote->getReservedOrderId();
            if ($incrementId) {
                $order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
                $status = strtolower($data['status'] ?? '');
                if (in_array($status, ['cancel', 'failed', 'failure'], true)) {
                    if ($order && $order->getId() && $order->canCancel()) {
                        try {
                        
                                $order->registerCancellation($data['txnmessage'])->save();
                            
                        } catch (\Exception $e) {
                            $this->messageManager->addErrorMessage(__('Something went wrong while cancelling your order.'));
                            return $this->getResponse()->setRedirect(
                                    $this->getCheckoutHelper()->getUrl('checkout').$goto
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
            return $this->getResponse()->setRedirect(
                    $this->getCheckoutHelper()->getUrl('checkout').$goto
            );
        } catch (\Exception $e) {

            $this->messageManager->addErrorMessage(__('Something went wrong while cancelling your order.'));
            return $this->getResponse()->setRedirect(
                    $this->getCheckoutHelper()->getUrl('checkout').$goto
            );
        }
    }
 
}
