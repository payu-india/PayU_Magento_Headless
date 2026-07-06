<?php

namespace PayUIndia\Payu\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Exception\LocalizedException;


class Payu extends AbstractHelper
{
    const PRODUCT_TYPE_BUNDLE = 'bundle';
    const PRODUCT_TYPE_SOFT = 'soft';
    private $excludedProductType = [self::PRODUCT_TYPE_BUNDLE,self::PRODUCT_TYPE_SOFT];
    private $session;
    private $customerSession;
    private $quote;
    private $quoteManagement;
    private $orderSender;
    private $_storeManager;
    private $customerFactory;
    private $customerRepository;
    private $orderService;
    private $cartManagement;
    protected $order;
    protected $scopeConfig;
    protected $shipconfig;
    protected $payuEventLog;
    protected $payuEventCollection;
    protected $payuWebhook;
    protected $payuWebhookCollection;
    protected $customerCollectionFactory;
    protected $encrypted;
    protected $region;
    protected $shippingMethod;
    protected $accountManagement;
    protected $_encryptor;
    public function __construct(
        Context $context,
        \Magento\Checkout\Model\Session $session,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Sales\Model\Service\OrderService $orderService,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Shipping\Model\Config $shipconfig,
        \PayUIndia\Payu\Model\PayuEventLog $payuEventLog,
        \PayUIndia\Payu\Model\ResourceModel\PayuEventLog\Collection $payuEventCollection,
        \PayUIndia\Payu\Model\PayuWebhook $payuWebhook,
        \PayUIndia\Payu\Model\ResourceModel\PayuWebhook\Collection $payuWebhookCollection,
        \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory $customerCollectionFactory,
        \Magento\Config\Model\Config\Backend\Encrypted $encrypted,
        \Magento\Directory\Model\Region $region,
        \PayUIndia\Payu\Model\ShippingMethod $shippingMethod,
        \Magento\Customer\Api\AccountManagementInterface $accountManagement,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor
    ) {
        $this->session = $session;
        $this->quote = $quote;
        $this->order =  $order;
        $this->quoteManagement = $quoteManagement;
        $this->cartManagement = $cartManagement;
        $this->customerSession = $customerSession;
        $this->_storeManager = $storeManager;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderService = $orderService;
        $this->shipconfig = $shipconfig;
        $this->scopeConfig = $scopeConfig;
        $this->payuEventLog = $payuEventLog;
        $this->payuEventCollection = $payuEventCollection;
        $this->payuWebhook = $payuWebhook;
        $this->payuWebhookCollection = $payuWebhookCollection;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->encrypted = $encrypted;
        $this->region=$region;
        $this->_encryptor = $encryptor;
        $this->shippingMethod=$shippingMethod;
        $this->accountManagement=$accountManagement;

        parent::__construct($context);
    }

    public function cancelCurrentOrder($comment)
    {
        $order = $this->session->getLastRealOrder();
        if ($order->getId() && $order->getState() != Order::STATE_CANCELED) {
            //$order->registerCancellation($comment)->save();
            return true;
        }
        return false;
    }

    public function restoreQuote()
    {
        return $this->session->restoreQuote();
    }

    public function getUrl($route, $params = [])
    {
        return $this->_getUrl($route, $params);
    }

    public function createTempOrder($txnId)
    {
        $quote = $this->session->getQuote();

        if($this->scopeConfig->getValue('payment/payu/paymentaction')=='expresscheckout'){
            $store = $this->_storeManager->getStore();
            $websiteId = $this->_storeManager->getStore()->getWebsiteId();
            $customer=$this->customerFactory->create();
            $customer->setWebsiteId($websiteId);



            if($this->customerSession->isLoggedIn()) {
                $email = $this->customerSession->getCustomer()->getEmail();
                $fname = $this->customerSession->getCustomer()->getFirstname() ?? '';
                $lname = $this->customerSession->getCustomer()->getLastname() ?? '';
            }else{
                $email = 'guestuser'.uniqid().time().'@gmail.com';
                $fname = 'fname';
                $lname = 'lname';
            }
            $customer->loadByEmail($email);
            if(!$customer->getEntityId()){
                $quote->setCustomerEmail($email);
                $quote->setCustomerIsGuest(true);
            }else{
                $billingAddress = $this->accountManagement->getDefaultBillingAddress($customer->getEntityId());
                $shippingAddress= $this->accountManagement->getDefaultShippingAddress($customer->getEntityId());
                $customer= $this->customerRepository->getById($customer->getEntityId());
                $quote->assignCustomer($customer);
            }
           if(isset($billingAddress)) {
               $address = [
                   'firstname' => $billingAddress->getFirstname(),
                   'lastname' => $billingAddress->getLastname(),
                   'street' => $billingAddress->getStreet(),
                   'city' => $billingAddress->getCity(),
                   'country_id' => $billingAddress->getCountryId(),
                   'region' => $billingAddress->getRegion(),
                   'region_id' => $billingAddress->getRegionId(),
                   'postcode' => $billingAddress->getPostcode(),
                   'telephone' => $billingAddress->getTelephone(),
                   'fax' => '',
                   'company' => $billingAddress->getCompany(),
                   'save_in_address_book' => 0
               ];
           }
           else{
               $address  = [
                   'firstname'    => 'guest',
                   'lastname'     => 'user',
                   'street' => 'street',
                   'city' => 'delhi',
                   'country_id' => 'IN',
                   'region' => 'Delhi',
                   'region_id' => '578',
                   'postcode' => 'XXXXXX',
                   'telephone' => '1234567890',
                   'fax' => '',
                   'company'=>'',
                   'save_in_address_book' => 0
               ];
           }
        //$address=$quote->getBillingAddress()->getData();

            //Set Address to quote

            $quote->getBillingAddress()->addData($address);
            if(isset($shippingAddress)) {
                $address = [
                    'firstname' => $shippingAddress->getFirstname(),
                    'lastname' => $shippingAddress->getLastname(),
                    'street' => $shippingAddress->getStreet(),
                    'city' => $shippingAddress->getCity(),
                    'country_id' => $shippingAddress->getCountryId(),
                    'region' => $shippingAddress->getRegion(),
                    'region_id' => $shippingAddress->getRegionId(),
                    'postcode' => $shippingAddress->getPostcode(),
                    'telephone' => $shippingAddress->getTelephone(),
                    'fax' => '',
                    'company' => $shippingAddress->getCompany(),
                    'save_in_address_book' => 0
                ];
            }
            $quote->getShippingAddress()->addData($address);
            $activeCarriers = $this->getShippingMethods();
            //echo  "<pre>";print_r($activeCarriers);die;
            $shippingAddress=$quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod('freeshipping_freeshipping');

            $quote->setInventoryProcessed(false);
            $quote->setPaymentMethod('payu');
            $quote->save();
            $quote->getPayment()->importData(['method' => 'payu']);
            $quote->collectTotals()->save();
        }

        // Create Order From Quote
        $orderid = $this->cartManagement->placeOrder($quote->getId());
        $m2order = $this->order->load($orderid);
        $m2order->setTxnid($txnId)->addStatusHistoryComment($txnId)->save();
        $increment_id = $m2order->getRealOrderId();
        $m2order->setCanSendNewEmailFlag(false)->save();
        if($m2order->getEntityId()){
            $result['order_id']= $m2order->getRealOrderId();
        }else{
            $result=['error'=>1,'msg'=>'erro message'];
        }
        return $result;
    }

    public function getShippingMethods() {
        $activeCarriers = $this->shipconfig->getActiveCarriers();

        foreach($activeCarriers as $carrierCode => $carrierModel) {
            $options = array();

            if ($carrierMethods = $carrierModel->getAllowedMethods()) {
                foreach ($carrierMethods as $methodCode => $method) {
                    $code = $carrierCode . '_' . $methodCode;
                    $options[] = array('value' => $code, 'label' => $method);
                }
                $carrierTitle = $this->scopeConfig
                    ->getValue('carriers/'.$carrierCode.'/title');
            }

            $methods[] = array('value' => $options, 'label' => $carrierTitle);
        }

        return $methods;
    }
    public function saveEventLogs($type=null,$header=null,$request=null, $response=null){

        if($response==null)
            $txnid = $request["txnid"];
        if($request==null)
            $txnid = $response["txnid"];

        $eventData=$this->payuEventCollection->addFieldToFilter('txnid',$txnid)->getFirstItem();

        if(count($eventData->getData())<1)
        {
            $events=$this->payuEventLog;
            $data=[
                'request_type'=> $type,
                'request_method'=>'POST',
                'request_url'=>'Order Payment',
                'request_header'=>'',
                'request_data'=>json_encode($request,true),
                'response_status'=>'',
                'response_header'=>$header,
                'response_data'=>json_encode($response,true),
                'txnid'=> $txnid
            ];
            $events->addData($data);
            $events->save();
        }else{
            $eventData->setResponseHeader($header);
            $eventData->setResponseStatus($response['status']);
            $eventData->setResponseData(json_encode($response,true));
            $eventData->save();
        }
    }
    public function saveWebhook($params)
    {
        $post=rawurldecode($params);
        //parse_str($post,$postData);
        $postData = json_decode($post, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $txnid = isset($postData['txnid']) ? $postData['txnid'] : $postData['merchantTxnId'];
        } else {
            parse_str($params, $postData);  
            $txnid = isset($postData['txnid']) ? $postData['txnid'] : (isset($postData['merchantTxnId']) ? $postData['merchantTxnId'] : null);
           
        }
        //$txnid=isset($postData['txnid'])?$postData['txnid']:$postData['merchantTxnId'];
        $webhookData= $this->payuWebhookCollection->addFieldToFilter('txn_id',$txnid);
        $data = [
            'txn_id' => $txnid,
            'mihpayid' => $postData['mihpayid'],
            'response' => json_encode($postData, true),
            'status' => 0,
            'payment_response' => $postData['status'],
            'type' => 'payment'
        ];
        if(isset($postData['action']))
            $data['type'] = $postData['action'];

        if(count($webhookData->getData())>1) {
            foreach ($webhookData as $item) {
                if ($item->getTxnId() == $params['txnid'] && $item->getPaymentResponse() == $postData['status']) {

                } else {
                    $webhook=$this->payuWebhook->addData($data)->save();
                }
            }
        }else{
		//$logger->info('data: '.json_encode($data));
            $webhook=$this->payuWebhook->addData($data)->save();
		//$logger->info('data save');
        }
    }
    public function updateOrderFromResponse($order,$params)
    {
        /* For Direct success reposnce */
        if(isset($params['shipping_address'])){
            $this->setAddress($order,$params);
            if(!$this->customerSession->isLoggedIn()) {
                $customerId = $this->assignCustomer($params);
                if ($customerId) {
                    $order->setCustomerIsGuest(0);
                    $order->setCustomerId($customerId);
                    $order->save();
                }
            }
        }
        /* For Cron */
        else{
            $orderAddress=$this->orderDetailsApi($params['txnid']);
            $orderAddress=json_decode($orderAddress,true);
            if(isset($orderAddress['data'])) {
                $orderData = $orderAddress['data'];
                $address = $orderData['address'][0];
                $this->setAddressUsingCron($order, $address);
                if ($order->getCustomerIsGuest()) {
                    $customerId = $this->assignCustomerByCron($address);
                    if ($customerId) {
                        $order->setCustomerIsGuest(0);
                        $order->setCustomerId($customerId);
                        $order->save();
                    }
                }
            }
        }
        if(isset($params['extra_charges'])){
            $this->setShippingMethod($order,$params);
        }
        // echo "<pre>";
        // print_r($params);
        // die;
        if((isset($params['offer_availed']) && isset($params['transaction_offer']))||isset($params['transactionOffer']))
        {
            if(isset($params['transaction_offer']))
                $offerArr = $params['transaction_offer'];
            else $offerArr = $params['transactionOffer'];

            if (!is_array($offerArr)) {
                $offerArr = json_decode($offerArr, true);
            }
            if(isset($offerArr['offer_data'])){
                $des=$offerArr['offer_data'];
                $description=$des[0];
                $discountDescription="Title - ".$description['offer_title']." | Offer Key - ".$description['offer_key']." |  Type - ".$description['offer_type'];
                $customDiscount=$offerArr['discount_data']['total_discount'];
                if($customDiscount > 0 && $description['offer_type']!="CASHBACK"){
                    $this->setDiscount($order,$customDiscount,$discountDescription);
                }
            }
        }

         /** -----------------------------
         *  2. Handle SKU-level Offers
         * ----------------------------- */
        $cartDetails = $params['cart_details'] ?? null;

        // Agar stringified JSON hai to decode karo
        if (is_string($cartDetails)) {
            $cartDetails = json_decode($cartDetails, true);
        }

        if (isset($cartDetails['sku_details']) && is_array($cartDetails['sku_details'])) {
            foreach ($cartDetails['sku_details'] as $skuItem) {
                $skuDiscount = $skuItem['discount'] ?? 0;
                if ($skuDiscount > 0) {
                    $discountDescription =
                        " | Offer Key - " . ($skuItem['offer_applied'] ?? '') .
                        " | Type - " . ($skuItem['offer_type'] ?? 'SKU-OFFER');

                    $this->setSkuBasedDiscount($order, $skuDiscount, $discountDescription, $skuItem['sku_id']);
                }
            }
        }

    


    }

    public function setAddress($order,$params){

        try{

        
        $streetData=isset($params['shipping_address']['addressLine'])?$params['shipping_address']['addressLine']:$params['address1'].' '.$params['address2'];

        $streetData=isset($params['shipping_address']['addressLine2'])?$streetData.' '.$params['shipping_address']['addressLine2']:$streetData."";

        $street=wordwrap($streetData, 30, ",");
        $name=explode(' ',isset($params['shipping_address']['name'])?$params['shipping_address']['name']:$params['firstname']);
        $fname=$name[0];
       $lname = (!empty($name[1])) ? $name[1] : (!empty($params['lastname']) ? $params['lastname'] : 'User');
        $address  = [
            'firstname' => isset($fname)?$fname:'New',
            'lastname' => isset($lname)?$lname:'user',
            'street' => $street,
            'email' => isset($params['shipping_address']['email'])?$params['shipping_address']['email']:$params['email'],
            'city' => isset($params['shipping_address']['city'])?$params['shipping_address']['city']:$params['city'],
            'country_id' => isset($params['shipping_address']['country'])?$params['shipping_address']['country']:'IN',
            'region' => isset($params['shipping_address']['state'])?$params['shipping_address']['state']:$params['state'],
            'postcode' => isset($params['shipping_address']['pincode'])?$params['shipping_address']['pincode']:$params['zipcode'],
            'telephone' => isset($params['shipping_address']['addressPhoneNumber'])?$params['shipping_address']['addressPhoneNumber']:$params['phone'],
            'fax' => '',
            'company'=>'',
            'save_in_address_book' => 0
        ];
        $address['address_type']='billing';
        $order->getBillingAddress()->addData($address);
        $address['address_type']='shipping';
        $order->getShippingAddress()->addData($address);
        $order->setCustomerEmail(isset($params['shipping_address']['email'])?$params['shipping_address']['email']:$params['email']);
        $order->setCustomerFirstname($fname);
        $order->setCustomerLastname($lname);
        $order->save();
        }catch(\Exception $e){
            throw new LocalizedException(__($e->getMessage()));
        }

    }

    public function assignCustomer($params){
        try{
            if (isset($params['email']) && !empty($params['email']))
                $email=$params['email'];
            else
                $email=$params['shipping_address']['email'];
            $customerData= $this->customerCollectionFactory->create();
            $customerData=$customerData->addFieldToFilter('email',$email)->getFirstItem();

            if(!$customerData->getId()){
                $websiteId  = $this->_storeManager->getWebsite()->getWebsiteId();
                $customer   = $this->customerFactory->create();
                $customer->setWebsiteId($websiteId);

                // Preparing data for new customer
                $name=explode(' ',isset($params['shipping_address']['name'])?$params['shipping_address']['name'] : 'Guest User');
                $fname=$name[0];
               $lname = (!empty($name[1])) ? $name[1] : (!empty($params['lastname']) ? $params['lastname'] : 'User');
                $customer->setEmail($email);
                $customer->setFirstname($fname);
                $customer->setLastname($lname);
                $customer->setPassword($fname."@".$lname);
                $customer->setPayuPhoneNumber($params['phone']);
                // Save data
                $customer->save();
                // $customer->sendNewAccountEmail();
                return $customer->getId();
            }
            else{
                $customer=$this->customerFactory->create()->load($customerData->getId());
                $customer->setPayuPhoneNumber($params['phone'])->save();

                return $customerData->getId();
            }
        }
        catch(\Exception $e){
            throw new LocalizedException(__($e->getMessage()));
        }

    }

    public function setDiscount($order, $customDiscount, $discountDescription)
    {
        //used to store discount values in addStatusHistoryComment
        $existingDiscount = $order->getDiscountAmount();
        $newDiscount = $existingDiscount - $customDiscount; // Adding the custom discount

        $order->setDiscountAmount($newDiscount);
        $order->setBaseDiscountAmount($newDiscount);
        $order->setGrandTotal($order->getGrandTotal() - $customDiscount);
        $order->setBaseGrandTotal($order->getBaseGrandTotal() - $customDiscount);
        $order->setDiscountDescription($order->getDiscountDescription() . '|||payu :-' . $discountDescription);

        $order->addStatusHistoryComment('discount-' . $existingDiscount . 'payu discount-' . $customDiscount);
        $order->setBaseTotalInvoiced($order->getGrandTotal());
        $order->setTotalInvoiced($order->getGrandTotal());
        $payment = $order->getPayment();
        $payment->setBaseAmountPaid($order->getGrandTotal());
        $payment->setAmountPaid($order->getGrandTotal());
        $payment->setBaseAmountOrdered($order->getGrandTotal());
        $payment->setAmountOrdered($order->getGrandTotal());
        //save order payment
        $payment->save();
        $totalNew = 0;
        foreach ($order->getAllItems() as $item) {
            if(!in_array($item->getProductType(), $this->excludedProductType)) {
                $totalNew = $totalNew + ($item->getPriceInclTax() * $item->getQtyOrdered()) - $item->getDiscountAmount();
            }
        }
       
        if($totalNew > 0) {
            // Distribute the discount proportionally among each order item
            foreach ($order->getAllItems() as $item) {
                if(!in_array($item->getProductType(), $this->excludedProductType)) {
                    $itemPriceInclTax = ($item->getPriceInclTax() * $item->getQtyOrdered()) - $item->getDiscountAmount();
                    $itemDiscountAmount = ($itemPriceInclTax / $totalNew) * abs($customDiscount);
                    $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $itemDiscountAmount);
                    $item->setDiscountAmount($item->getDiscountAmount() + $itemDiscountAmount);
                    //save order item after adding discount portion
                    $item->save();
                }
            }
        }
        //save order data
        $order->save();
    }
    public function setSkuBasedDiscount($order, $customDiscount, $discountDescription,$skuId=null)
    {
        //used to store discount values in addStatusHistoryComment
        $existingDiscount = $order->getDiscountAmount();
        $newDiscount = $existingDiscount - $customDiscount; // Adding the custom discount

        $order->setDiscountAmount($newDiscount);
        $order->setBaseDiscountAmount($newDiscount);
        $order->setGrandTotal($order->getGrandTotal() - $customDiscount);
        $order->setBaseGrandTotal($order->getBaseGrandTotal() - $customDiscount);
        $order->setDiscountDescription($order->getDiscountDescription() . '|||payu :-' . $discountDescription);

        $order->addStatusHistoryComment('discount-' . $existingDiscount . 'payu discount-' . $customDiscount);
        $order->setBaseTotalInvoiced($order->getGrandTotal());
        $order->setTotalInvoiced($order->getGrandTotal());
        $payment = $order->getPayment();
        $payment->setBaseAmountPaid($order->getGrandTotal());
        $payment->setAmountPaid($order->getGrandTotal());
        $payment->setBaseAmountOrdered($order->getGrandTotal());
        $payment->setAmountOrdered($order->getGrandTotal());
        //save order payment
        $payment->save();
        
       
        
            // Distribute the discount proportionally among each order item
            foreach ($order->getAllItems() as $item) {

                if($item->getSku()== $skuId) {
                  $itemNewDiscount=$item->getDiscountAmount() + $customDiscount;
                    $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $itemNewDiscount);
                    $item->setDiscountAmount($item->getDiscountAmount() + $itemNewDiscount);
                    //save order item after adding discount portion
                    $item->save();
                }
            }
        
        
        $order->save();
    }


    // public function setDiscount($order,$customDiscount,$discountDescription)
    // {

    //     $total=$order->getBaseSubtotal();
    //     $order->setDiscountAmount($customDiscount);
    //     $order->setBaseDiscountAmount($customDiscount);
    //     $order->setBaseGrandTotal($order->getBaseGrandTotal()-$customDiscount);
    //     $order->setGrandTotal($order->getGrandTotal()-$customDiscount);
    //     $order->setDiscountDescription($discountDescription);
    //     $shippingAddress = $order->getShippingAddress();
    //     if ($shippingAddress) {
    //         $shippingAddress->setDiscountAmount($customDiscount);
    //         $shippingAddress->setDiscountDescription($discountDescription);
    //         $shippingAddress->setBaseDiscountAmount($customDiscount);
    //     }
    //     $orderBillingAddress = $order->getBillingAddress();
    //     $orderBillingAddress->setDiscountAmount($customDiscount);
    //     $orderBillingAddress->setDiscountDescription($discountDescription);
    //     $orderBillingAddress->setBaseDiscountAmount($customDiscount);

    //     $order->setSubtotal((float) $order->getSubTotal());
    //     $order->setBaseSubtotal((float) $order->getBaseSubtotal());
    //     $order->setGrandTotal((float)  $order->getGrandTotal());
    //     $order->setBaseGrandTotal((float) $order->getBaseGrandTotal());
    //     $order ->save();

    //     $order->setBaseTotalInvoiced($order->getGrandTotal());
    //     $order->setTotalInvoiced($order->getGrandTotal());
    //     $payment=$order->getpayment();
    //     $payment->setBaseAmountPaid($order->getGrandTotal());
    //     $payment->setAmountPaid($order->getGrandTotal());
    //     $payment->setBaseAmountOrdered($order->getGrandTotal());
    //     $payment->setAmountOrdered($order->getGrandTotal());
    //     $payment->save();
    //     foreach($order->getAllItems() as $item){
    //         $rat=$item->getPriceInclTax()/$total;
    //         $ratdisc=abs($customDiscount)*$rat;
    //         $discountAmt=($item->getDiscountAmount()+$ratdisc) * $item->getQtyOrdered();
    //         $base=($item->getBaseDiscountAmount()+$ratdisc) * $item->getQtyOrdered();
    //         $item->setBaseDiscountAmount($base);
    //         $item->setDiscountAmount($discountAmt);
    //         $item->save();
    //     }
    //     $order->save();
    // }

    public function getConfigData($value, $storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->_storeManager->getStore()->getId();
        }
        $configValue = $this->scopeConfig->getValue(
            'payment/payu/' . $value,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    
        // If it's a sensitive value, decrypt it
        if (in_array($value, ['merchant_key', 'salt'])) { // Example of sensitive keys
            return $this->_encryptor->decrypt($configValue);
        }
    
        return $configValue;
    }
    public function orderDetailsApi($txnid)
    {
        $key = $this->encrypted->processValue($this->getConfigData("merchant_key"));
        $secret = $this->encrypted->processValue($this->getConfigData('salt'));
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $requestJson = $this->getRequestBody();
        $hashString = "|".$date . "|" . $secret;
        $hash = $this->getSha512Hash($hashString);

        $url = 'https://apitest.payu.in/cart/order/'.$txnid;
        if($this->getConfigData('environment')=='production')
            $URL= 'https://api.payu.in/cart/order/'.$txnid;
        //$date = 'Thu, 15 Feb 2024 06:18:04 GMT';
        $authorization = 'hmac username="'.$key.'", algorithm="sha512", headers="date", signature="'.$hash.'"';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Date: ' . $date,
            'Authorization: ' . $authorization
        ]);
        $response = curl_exec($ch);
        return $response;
    }
    private function getRequestBody() {

        return (object) ['udf1' => ''];
    }

    private function getSha512Hash($hashString) {
        $messageDigest = hash('sha512', $hashString, true);
        $hashtext = bin2hex($messageDigest);
        $hashtext = str_pad($hashtext, 128, '0', STR_PAD_LEFT); // Pad to 128 characters
        return $hashtext;
    }
    public function getRegion($regionCode, $countryCode)
    {
        return $this->region->loadByCode($regionCode, $countryCode)->getName();
    }

    public function setAddressUsingCron($order,$params)
    {
        $streetData=isset($params['shippingAddress']['addressLine'])?$params['shippingAddress']['addressLine']:"";

        $streetData=isset($params['shippingAddress']['addressLine2'])?$streetData.' '.$params['shippingAddress']['addressLine2']:"";
        $email=isset($params['shippingAddress']['email'])?$params['shippingAddress']['email']:"";
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        $email=explode('+',$email);
        $sanitizedEmail=$email[1];
        if (isset($email[1])) {
            $strip = strstr($email[1], "@", true);
            $email[1] = str_replace($strip, '', $email[1]);
            $sanitizedEmail = $email[0] . $email[1];

        }
        $street=wordwrap($streetData, 30, ",");
        $name=explode(' ',isset($params['shippingAddress']['name'])?$params['shippingAddress']['name']:"");
        $fname=$name[0];
        $lname=isset($name[1])?$name[1]:"";
        $address  = [
            'firstname' => isset($fname)?$fname:'New',
            'lastname' => isset($lname)?$lname:'user',
            'street' => $street,
            'email' => $sanitizedEmail,
            'city' => isset($params['shippingAddress']['city'])?$params['shippingAddress']['city']:"",
            'country_id' => isset($params['shippingAddress']['country'])?$params['shippingAddress']['country']:'IN',
            'region' => isset($params['shippingAddress']['state'])?$params['shippingAddress']['state']:"",
            'postcode' => isset($params['shippingAddress']['pincode'])?$params['shippingAddress']['pincode']:"",
            'telephone' => isset($params['shippingAddress']['addressPhoneNumber'])?$params['shippingAddress']['addressPhoneNumber']:"",
            'fax' => '',
            'save_in_address_book' => 0
        ];
        $order->getBillingAddress()->addData($address);
        $order->getShippingAddress()->addData($address);
        $order->setCustomerEmail($sanitizedEmail);
        $order->setCustomerFirstname($fname);
        $order->setCustomerLastname($lname);
        $order->save();
    }
    public function assignCustomerByCron($params){
        try{
            if (isset($params['email']) && !empty($params['email']))
                $email=$params['email'];
            else
                $email=$params['shippingAddress']['email'];
            $customerData= $this->customerCollectionFactory->create();
            $customerData=$customerData->addFieldToFilter('email',$email)->getFirstItem();

            if(!$customerData->getId()){
                $websiteId  = $this->_storeManager->getWebsite()->getWebsiteId();
                $customer   = $this->customerFactory->create();
                $customer->setWebsiteId($websiteId);

                // Preparing data for new customer
                $name=explode(' ',isset($params['shippingAddress']['name'])?$params['shippingAddress']['name'] : 'Guest User');
                $fname=$name[0];
                $lname=isset($name[1])?$name[1]: 'User';
                $customer->setEmail($email);
                $customer->setFirstname($fname);
                $customer->setLastname($lname);
                $customer->setPassword($fname."@".$lname);
                $customer->setPayuPhoneNumber($params['addressPhoneNumber']);
                // Save data
                $customer->save();
                // $customer->sendNewAccountEmail();
                return $customer->getId();
            }
            else{
                $customer=$this->customerFactory->create()->load($customerData->getId());
                $customer->setPayuPhoneNumber($params['phone'])->save();

                return $customerData->getId();
            }
        }
        catch(\Exception $e){
            throw new LocalizedException(__($e->getMessage()));
        }

    }

    public function setShippingMethod($order,$params)
    {
        $extraCharges=$params['extra_charges'];
        if(isset($extraCharges['carrier_code'])){
        $order->setShippingMethod($extraCharges['carrier_code'].'_'.$extraCharges['method_code']);
        $order->setShippingInclTax($extraCharges['shipping_charges']);
        $order->setBaseShippingInclTax($extraCharges['shipping_charges']);
        $order->setBaseTotalDue($extraCharges['total_amount']) ;
        $order->setTotalDue($extraCharges['total_amount']);
        $order->setBaseGrandTotal($extraCharges['total_amount']);
        $order->setBaseShippingAmount($extraCharges['shipping_charges']);
        if($extraCharges['shipping_charges']==0){
            $order->setBaseShippingAmount(null);
        }
        $order->setBaseShippingTaxAmount($extraCharges['tax_info']['total']);
        $order->setBaseTaxAmount($extraCharges['tax_info']['total']);
        $order->setGrandTotal($extraCharges['total_amount']);
        $order->setShippingAmount($extraCharges['shipping_charges']);
        $order->setShippingDescription($extraCharges['carrier_title']);
        $order->save();
        $shippingData=$this->shippingMethod->getShippingMethodsForOrder($params);
         if($order->getTaxAmount() !== $extraCharges['tax_info']['total']){
            $order->setTaxAmount($extraCharges['tax_info']['total']);
            $order->setShippingTaxAmount($shippingData->price_incl_tax - $shippingData->price_excl_tax );
            $order->setBaseShippingTaxAmount($shippingData->price_incl_tax - $shippingData->price_excl_tax);
            if(($shippingData->price_incl_tax - $shippingData->price_excl_tax)==0){
                $order->setShippingTaxAmount(null);
                $order->setBaseShippingTaxAmount(null);
            }
        }
        $payment=$order->getPayment();
        $payment->setBaseShippingAmount($extraCharges['shipping_charges']);
        $payment->setShippingAmount($extraCharges['shipping_charges']);
        $payment->setBaseAmountOrdered($extraCharges['total_amount']);
        $payment->setAmountOrdered($extraCharges['total_amount']);

        $payment->save();
        $order->save();
    }
    }
}
