<?php
declare(strict_types=1);
 
namespace PayUIndia\Payu\Cron;
use Magento\Sales\Api\OrderRepositoryInterface;
class OrderUpdate
{
    protected $logger;
    protected $payuHelper;
    protected $paymentMethodModel;
    protected $OrderCollectionFactory;
    protected $timezone;
    protected $orderSender;
    protected $payuLogger;
    private $invoiceSender;
    private $orderRepository;
	protected $_moduleDir;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $OrderCollectionFactory,
        \PayUIndia\Payu\Helper\Payu                                $payuHelper,
        \PayUIndia\Payu\Model\Payu                                 $paymentMethodModel,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface       $timezone,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender        $orderSender,
		\Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        OrderRepositoryInterface $orderRepository,
        \PayUIndia\Payu\Logger\Logger                              $logger,
		\Magento\Framework\Module\Dir                               $moduleDir
 
    )
    {
 
        $this->paymentMethodModel = $paymentMethodModel;
        $this->payuHelper = $payuHelper;
        $this->OrderCollectionFactory = $OrderCollectionFactory;
        $this->timezone = $timezone;
        $this->orderSender = $orderSender;
		$this->invoiceSender = $invoiceSender;
        $this->orderRepository = $orderRepository;
        $this->payuLogger = $logger;
		$this->_moduleDir = $moduleDir;

    }
 
    public function execute()
    {
        $this->payuLogger->info('its update');
        $end= new \DateTime();
        $end= $end->format('Y-m-d H:i:s');
        $dateTime=new \DateTime();
        $dateTime->sub(new \DateInterval('PT10M'));
        $start=$dateTime->format(\Magento\Framework\StdLib\DateTime::DATETIME_PHP_FORMAT);
        $orderList = $this->OrderCollectionFactory->create()->addFieldToFilter('created_at', array('lteq' => $start))->addFieldToFilter('status','pending_payment');
        $orderList->getSelect()->join(
            ['sop' => 'sales_order_payment'],
            'main_table.entity_id = sop.parent_id',
            array('method')
        )->where('sop.method = ?', 'payu')->order('created_at','DESC');
       
        $this->payuLogger->info('query ' . $orderList->getSelect()->__toString());
        foreach ($orderList as $orderData){
            $order = $this->orderRepository->get($orderData->getId());
            $txnid=$order->getTxnid();
            $paymentVerData=$this->paymentVerify($order,$txnid);
            $paymentResponse=json_decode($paymentVerData,true);

		    $this->payuLogger->info('order id ' . json_encode($order->getIncrementId()));
            $this->payuLogger->info('Response from payu');
		    
            
            if($order->getState()=='pending_payment' && $paymentResponse['transaction_details'][$txnid]['status']=='success'){
                $this->payuHelper->updateOrderFromResponse($order,$paymentResponse['transaction_details'][$txnid]);
                $payment = $order->getPayment();
                 $order->addStatusHistoryComment('set success status from payu cron');
                $this->postProcessing($order, $payment, $paymentResponse['transaction_details'][$txnid]);
 
 
            } elseif ($order->getState() == 'pending_payment' && $paymentResponse['transaction_details'][$txnid]['status'] == 'pending') {
                
                $order->setStatus('pending_payment');
                $order->setState('pending_payment');
                $order->addStatusHistoryComment('set pending status from payu cron');
                $this->orderRepository->save($order);
                
            }elseif ($order->getState()=='pending_payment' && $paymentResponse['transaction_details'][$txnid]['status'] == 'failure'){
                
                $order->setStatus('canceled');
                $order->setState('canceled');
                $order->addStatusHistoryComment('set failure status from payu cron');
                $this->orderRepository->save($order);
            }else{
                
                $this->payuLogger->info('order up-to-date');

            }
        }
    }
    public function paymentVerify($order,$txnid)
    {
        $fields = array(
            'key' => $this->payuHelper->getConfigData("merchant_key",$order->getStoreId()),
            'command' => 'verify_payment',
            'var1' => $txnid,
            'hash' => ''
        );
 
        $hash = hash("sha512", $fields['key'] . '|' . $fields['command'] . '|' . $fields['var1'] . '|' . $this->payuHelper->getConfigData('salt',$order->getStoreId()));
        $fields['hash'] = $hash;
 
        $fields_string = http_build_query($fields);
        $url = 'https://info.payu.in/merchant/postservice.php?form=2';
        if ($this->payuHelper->getConfigData('environment',$order->getStoreId()) == 'sandbox')
            $url = "https://test.payu.in/merchant/postservice.php?form=2";
 
        $pemPath = $this->_moduleDir->getDir('PayUIndia_Payu',\Magento\Framework\Module\Dir::MODULE_ETC_DIR) . '/cacert.pem';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
         if (file_exists($pemPath)) {
			curl_setopt($curl, CURLOPT_CAINFO, $pemPath);
		}
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields_string);
        $response = curl_exec($curl);
        $curlerr = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
        $message = '';
        $res = '';
        if ($curlerr || $httpCode != 200) {
            $message = "Curl Error: " . $curlerr . " | HTTP Code: " . $httpCode;
			if ($this->payuHelper->getConfigData('debuglog')==true) {
				$this->payuLogger->info("Verify response Curl Error: " . $message);
			}
            return false;
        } else {
            $res = json_decode($response, true);
        }
        return $response;
    }
 
    public function postProcessing(\Magento\Sales\Model\Order $order,\Magento\Sales\Model\Order\Payment $payment, $response)
    {
       
        try {               
            $orderemail = $this->payuHelper->getConfigData("orderemail");
            $geninvoice = $this->payuHelper->getConfigData("generateinvoice");
            $emailinvoice = $this->payuHelper->getConfigData("invoiceemail");   
            if($this->paymentMethodModel->verifyPayment($order,$response['txnid']))
            {   
                $payment->setTransactionId($response['txnid'])       
                ->setPreparedMessage('SUCCESS')
                ->setShouldCloseParentTransaction(true)
                ->setIsTransactionClosed(0)
                ->setAdditionalInformation('payu_mihpayid', $response['mihpayid'])
                ->setAdditionalInformation('payu_order_status', 'approved');    
                
                If (isset($response['additional_charges'])) {
                    $payment->setAdditionalInformation('Additional Charges', $response['additional_charges']);      
 
                }
                $order->save();
                
                                  
                
                // Fix for Bug- Order Item 'base_original_price' and 'original_price' not updated during order save
                foreach ($order->getAllItems() as $item)
                {
                    $item->setBasePrice($item->getBasePrice())->setOriginalPrice($item->getOriginalPrice())->setBaseOriginalPrice($item->getBaseOriginalPrice())->save();
 
                }
                
                $order->setTotalPaid($response['amt'])->save();
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING,true)->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING)->save();               
                $order->setCanSendNewEmailFlag(true)->save();
                $order->setIsCustomerNotified(true)->save();
                
                if($orderemail)
                    $this->orderSender->send($order);
                
                if($geninvoice && $order->canInvoice()) {
                    $invoice = $order->prepareInvoice();
                    $invoice->register();
                    $this->payuLogger->info('invoice Create');
                    $invoice->save();
 
                    if($emailinvoice) {
                       $this->invoiceSender->send($invoice);
                    }
                }   
            }
            else {
             //modified to cancel order in case of failed or canceled payment
             if($this->payuHelper->getConfigData('debuglog')==true)                                      
                $this->payuLogger->info('cancel order in processing method'.json_encode($order->getIncrementId()));                      
                
             $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED,true)->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
             $order->save();
            }
        }
        catch(Exception $e){
            if($this->payuHelper->getConfigData('debuglog')==true)
                $this->payuLogger->info('Exception'.$e->getMessage());  
        }
    }
}