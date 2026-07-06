<?php
namespace PayUIndia\Payu\Cron;

class Webhook
{
    protected $logger;
    protected $webhookCollection;
    protected $webhookModel;
    protected $orderModel;
    protected $payuHelper;
    protected $paymentMethodModel;

    public function __construct(
        \PayUIndia\Payu\Model\ResourceModel\PayuWebhook\Collection $webhookCollection,
        \PayUIndia\Payu\Model\PayuWebhook $webhookModel,
        \Magento\Sales\Model\Order $orderModel,
        \PayUIndia\Payu\Helper\Payu $payuHelper,
        \PayUIndia\Payu\Model\Payu $paymentMethodModel
    )
    {
        $this->webhookCollection = $webhookCollection;
        $this->webhookModel = $webhookModel;
        $this->orderModel = $orderModel;
        $this->paymentMethodModel = $paymentMethodModel;
        $this->payuHelper = $payuHelper;
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/webhookcron.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
    }

    public function execute()
    {
        $webhookCollectionData=$this->webhookCollection->addFieldToFilter('status',0);
        foreach($webhookCollectionData as $data){
            //dd($data->getData());
            $modelData=$this->webhookModel->load($data->getId());
            $this->logger->info('Webhook cron : ');
            $webhookDataArr=json_decode($data->getResponse(),true);
            $order=$this->orderModel->load($data->getTxnId(),'txnid');
            $this->logger->info(json_encode($order->getData(),true));
           // $txnid = isset($webhookDataArr['txnid']) ? $webhookDataArr['txnid'] : (isset($webhookDataArr['merchantTxnId']) ? $webhookDataArr['merchantTxnId'] : null);
            $quoteid=explode('-',isset($webhookDataArr['txnid']));
            $billingAddress=$order->getBillingAddress();
             if ($billingAddress) {
            $txnid=isset($webhookDataArr['txnid']) ? $webhookDataArr['txnid'] : (isset($webhookDataArr['merchantTxnId']) ? $webhookDataArr['merchantTxnId'] : null);
            $amount=isset($webhookDataArr['amount'])?$webhookDataArr['amount']:(isset($webhookDataArr['amt']) ? $webhookDataArr['amt'] : null);
            $productInfo=$quoteid[0];
            $name=$billingAddress->getFirstname();
            $email=$billingAddress->getEmail();
            $udf1=isset($webhookDataArr['udf1']);
            $udf5=isset($webhookDataArr['udf5']);
            $hash=$this->paymentMethodModel->generatePayuHash($txnid, $amount, $productInfo, $name,
                $email,$udf1,$udf5);
            $this->logger->info($hash);
            $this->logger->info(isset($webhookDataArr['hash']));
            if ($order->getState() == 'pending_payment' && $data->getPaymentResponse() == 'success' && $data->getType()=='payment') {
                $this->payuHelper->updateOrderFromResponse($order, $webhookDataArr);
                $payment = $order->getPayment();
                $this->paymentMethodModel->postProcessing($order, $payment, $webhookDataArr);
                $modelData->setStatus(1);
                $modelData->save();
            } elseif ($order->getState() == 'pending_payment' && $data->getPaymentResponse() == 'failure' && $data->getType()=='payment') {
                $order->setStatus('canceled');
                $order->setState('canceled');
                $order->save();
                $modelData->setStatus(1);
                $modelData->save();
            } 
            }
            else {
                $modelData->setStatus(1);
                $modelData->save();
            }
        }
        $this->logger->info("webhook and order has been updated");

    }
}
