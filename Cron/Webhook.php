<?php
declare(strict_types=1);
namespace PayUIndia\Payu\Cron;

use PayUIndia\Payu\Model\ResourceModel\PayuWebhook\CollectionFactory as WebhookCollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PayUIndia\Payu\Model\ResourceModel\PayuWebhook;

class Webhook
{
    protected $payuLogger;
    protected $webhookCollectionFactory;
    protected $orderCollectionFactory;
    protected $payuHelper;
    protected $paymentMethodModel;
    protected $orderRepository;
    protected $webhookResource;

    

    public function __construct(
         WebhookCollectionFactory $webhookCollectionFactory,
        OrderCollectionFactory $orderCollectionFactory,
         OrderRepositoryInterface $orderRepository,
        \PayUIndia\Payu\Helper\Payu $payuHelper,
        \PayUIndia\Payu\Model\Payu $paymentMethodModel,
        \PayUIndia\Payu\Logger\Logger $logger,
        PayuWebhook $webhookResource
    )
    {
        $this->webhookCollectionFactory = $webhookCollectionFactory;
       
        $this->orderCollectionFactory = $orderCollectionFactory;
         $this->orderRepository = $orderRepository;
        $this->paymentMethodModel = $paymentMethodModel;
        $this->payuHelper = $payuHelper;
        $this->payuLogger = $logger;
        $this->webhookResource = $webhookResource;
    }

    public function execute()
    {
        $webhookCollectionData = $this->webhookCollectionFactory->create();
        $webhookCollectionData->addFieldToFilter('status', 0);
        
        foreach($webhookCollectionData as $data){
            
            $webhookDataArr = json_decode((string)$data->getResponse(), true);
            if (!$webhookDataArr) {
                continue;
            }
          
            $this->payuLogger->info('Webhook cron : ');
          
            $orderCollection = $this->orderCollectionFactory->create();
            $orderCollection->addFieldToFilter('txnid', $data->getTxnId());
            $order = $orderCollection->getFirstItem();

            if ($this->paymentMethodModel->validateResponse($webhookDataArr,'webhook')) {
                $this->payuLogger->info('Processed Order', [
                    'txnid' => $data->getTxnId(),
                    'order_id' => $order->getIncrementId()

                ]);
                if ($order->getState() == 'pending_payment' && $data->getPaymentResponse() == 'success' && $data->getType()=='payment') {
                    $this->payuHelper->updateOrderFromResponse($order, $webhookDataArr);
                    $payment = $order->getPayment();
                    $this->paymentMethodModel->postProcessing($order, $payment, $webhookDataArr);
                    $data->setStatus(1);
                    $this->webhookResource->save($data);
                } elseif ($order->getState() == 'pending_payment' && $data->getPaymentResponse() == 'failure' && $data->getType()=='payment') {
                    $errorMsg = $webhookDataArr['error_Message'] ?? $webhookDataArr['field9'] ?? 'Payment failed';							  
				    $order->addCommentToStatusHistory("Payment Failed: " . $errorMsg);
                    $order->setStatus('canceled');
                    $order->setState('canceled');
                    $this->orderRepository->save($order);
                    $data->setStatus(1);
                    $this->webhookResource->save($data);
                } 
            }else {
                    $this->payuLogger->info('Webhook hash mismatch - skipping', [
                    'txnid' => $data->getTxnId(),
                    'order_id' => $order->getIncrementId()

                ]);
                $data->setStatus(1);
                $this->webhookResource->save($data);
            }
        }
        $this->payuLogger->info('webhook and order has been updated');

    }
}
