<?php
declare(strict_types=1);
namespace PayUIndia\Payu\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;

class Payu extends AbstractHelper
{
    const PRODUCT_TYPE_BUNDLE = 'bundle';
    /**
     * @var $excludedProductType - List of product types to be excluded for discount distribution
     */
    private $excludedProductType = [self::PRODUCT_TYPE_BUNDLE];
    private $session;
    private $customerSession;
    private $quote;
    private $quoteManagement;
    private $_storeManager;
    private $customerFactory;
    private $customerRepository;
    private $orderService;
    private $cartManagement;
    protected $order;
    protected $scopeConfig;
    protected $shipconfig;
    protected $payuWebhook;
    protected $payuWebhookCollection;
    protected $orderRepository;

    


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
        \PayUIndia\Payu\Model\PayuWebhook $payuWebhook,
        \PayUIndia\Payu\Model\ResourceModel\PayuWebhook\Collection $payuWebhookCollection,
         OrderRepositoryInterface $orderRepository,
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
        $this->payuWebhook = $payuWebhook;
        $this->payuWebhookCollection = $payuWebhookCollection;
        $this->orderRepository = $orderRepository;
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

    public function createTempOrder($txnId,$quote = null)
    {
        if ($quote === null) {
            $quote = $this->session->getQuote();
        }
       
        if($this->customerSession->isLoggedIn()) {
            $email = $this->customerSession->getCustomer()->getEmail();
            $fname = $this->customerSession->getCustomer()->getFirstname() ?? '';
            $lname = $this->customerSession->getCustomer()->getLastname() ?? '';
            $quote->setCustomerEmail($email);
               
        }
        $quote->setInventoryProcessed(false);
        $quote->setPaymentMethod('payu');
        $quote->save();
        $quote->getPayment()->importData(['method' => 'payu']);
        $quote->collectTotals()->save();
        
 
        // Create Order From Quote
        $orderid = $this->cartManagement->placeOrder($quote->getId());
        $m2order = $this->orderRepository->get($orderid);

        $m2order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $m2order->setStatus('pending_payment');
        $m2order->setIsNotified(false);
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

    public function getConfigData($value, $storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->_storeManager->getStore()->getId();
        }
        return $this->scopeConfig->getValue(
            'payment/payu/' . $value,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
     public function saveWebhook($params)
    {
        $post=rawurldecode($params);
        //parse_str($post,$postData);
        $postData = json_decode($post, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $txnid = isset($postData['txnid']) ? $postData['txnid'] : (isset($postData['merchantTxnId']) ? $postData['merchantTxnId'] : null);
        } else {
            parse_str($params, $postData);  
            $txnid = isset($postData['txnid']) ? $postData['txnid'] : (isset($postData['merchantTxnId']) ? $postData['merchantTxnId'] : null);
           
        }
        //$txnid=isset($postData['txnid'])?$postData['txnid']:$postData['merchantTxnId'];
        $webhookData= $this->payuWebhookCollection->addFieldToFilter('txn_id',$txnid);
        $data = [
            'txn_id' => $txnid,
            'mihpayid' => $postData['mihpayid'],
            'response' => json_encode($postData),
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
            $webhook=$this->payuWebhook->addData($data)->save();
        }
    }
    public function updateOrderFromResponse($order,$params)
    {
        if(isset($params['offer']) && isset($params['offer_availed']) && isset($params['transaction_offer']))
        {
            $offerArr = $params['transaction_offer'];
            if (!is_array($offerArr)) {
                $offerArr = json_decode($offerArr, true);
            }
            if(isset($offerArr['offer_data'])){
                $des=$offerArr['offer_data'];
                $description=$des[0];
                $discountDescription="Title - ".$description['offer_title']." | Offer Key - ".$description['offer_key']." |  Type - ".$description['offer_type'];
                $customDiscount=$offerArr['discount_data']['total_discount'];
                if($customDiscount > 0){
                    $this->setDiscount($order,$customDiscount,$discountDescription);
                }
            }

        }else{
            if(isset($params['disc']) && $params['disc']>0)
            {
                $discountDescription='Payu Offer';
                $customDiscount=$params['disc'];
                $this->setDiscount($order,$customDiscount,$discountDescription);
            }
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

}
