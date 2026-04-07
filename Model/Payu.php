<?php
declare(strict_types=1);
namespace PayUIndia\Payu\Model;

use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Escaper;
class Payu extends \Magento\Payment\Model\Method\AbstractMethod {

    const PAYMENT_PAYU_CODE = 'payu';
    const ACC_BIZ = 'payubiz';
    const ACC_MONEY = 'payumoney';

    protected $_code = self::PAYMENT_PAYU_CODE;

	protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    protected $_isInitializeNeeded      = true;
    protected $_isOffline               = false;
    protected $_isGateway = true;

    protected $_canAuthorize = true;
    protected $_canCapture = true;
	

    /**
     *
     * @var \Magento\Framework\UrlInterface 
     */
    protected $_urlBuilder;
    protected $_supportedCurrencyCodes = array(
        'AFN', 'ALL', 'DZD', 'ARS', 'AUD', 'AZN', 'BSD', 'BDT', 'BBD',
        'BZD', 'BMD', 'BOB', 'BWP', 'BRL', 'GBP', 'BND', 'BGN', 'CAD',
        'CLP', 'CNY', 'COP', 'CRC', 'HRK', 'CZK', 'DKK', 'DOP', 'XCD',
        'EGP', 'EUR', 'FJD', 'GTQ', 'HKD', 'HNL', 'HUF', 'INR', 'IDR',
        'ILS', 'JMD', 'JPY', 'KZT', 'KES', 'LAK', 'MMK', 'LBP', 'LRD',
        'MOP', 'MYR', 'MVR', 'MRO', 'MUR', 'MXN', 'MAD', 'NPR', 'TWD',
        'NZD', 'NIO', 'NOK', 'PKR', 'PGK', 'PEN', 'PHP', 'PLN', 'QAR',
        'RON', 'RUB', 'WST', 'SAR', 'SCR', 'SGF', 'SBD', 'ZAR', 'KRW',
        'LKR', 'SEK', 'CHF', 'SYP', 'THB', 'TOP', 'TTD', 'TRY', 'UAH',
        'AED', 'USD', 'VUV', 'VND', 'XOF', 'YER'
    );
    
	protected $payuLogger;
	
    private $checkoutSession;
	
	private $customerSession;
	private $invoiceSender;
	protected $_moduleDir;
	private $escaper;
    /**
     * 
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $paymentLogger
     * @param \PayUIndia\Payu\Logger\Logger $logger
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
      public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
		\Magento\Payment\Model\Method\Logger $paymentLogger,
        \PayUIndia\Payu\Logger\Logger $logger,
        \PayUIndia\Payu\Helper\Payu $helper,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
		\Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
		\Magento\Customer\Model\Session $customerSession,
		\Magento\Sales\Model\Service\InvoiceService $invoiceService,
		\Magento\Framework\Module\Dir $moduleDir,
		Escaper $escaper

    ) {
        $this->helper = $helper;
        $this->orderSender = $orderSender;
		$this->invoiceSender = $invoiceSender;
        $this->httpClientFactory = $httpClientFactory;
        $this->checkoutSession = $checkoutSession;
		$this->customerSession = $customerSession;
		$this->payuLogger = $logger;
		$this->_invoiceService  = $invoiceService;
		$this->_moduleDir = $moduleDir;
		$this->escaper = $escaper;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $paymentLogger
        );

    }

    public function canUseForCurrency($currencyCode) {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }
        return true;
    }
	
	public function getMethodCode()
	{
		return $this->_code;		
	}
	
    public function getRedirectUrl() {
        return $this->helper->getUrl($this->getConfigData('redirect_url'));
    }

    public function getReturnUrl() {
		$url = $this->getConfigData('return_url');
		return $url ?: $this->helper->getUrl('payu/standard/response');
    }

    public function getCancelUrl() {
		$url = $this->getConfigData('cancel_url');

		return $url ?: $this->helper->getUrl('payu/standard/cancel');
    }

	public function getMethodTitle() {
		return $this->getConfigData("title");
	}
    /**
     * Return url according to environment
     * @return string
     */
    public function getCgiUrl() {
        $env = $this->getConfigData('environment');
        if ($env === 'production') {
            return $this->getConfigData('production_url');
        }
        return $this->getConfigData('sandbox_url');
    }

	public function initialize($paymentAction, $stateObject) {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
    }

    public function buildCheckoutRequest($quote = null,$enforcePaymethod= null) {
		
		if ($quote === null) {
			$quote = $this->checkoutSession->getQuote();
		}
		
		if (!$quote->hasItems())
        {
			return ['error' => 'Empty cart...'];
		}
		$billing_address = $quote->getBillingAddress();
        
        $mkey=$this->getConfigData("merchant_key");
		$sprovider = "";
		
		if ($this->getConfigData('account_type') == self::ACC_MONEY) {
			$sprovider=$this->getConfigData("service_provider");
		}
			
		$fu = $this->getConfigData('uniquetxnid');
		if($fu)
			$txnid = $quote->getId().'-'.bin2hex(random_bytes(4)); //added to make txnid unique			
		else 
			$txnid	= $quote->getId(); 
		
		$amount = round((float)$quote->getBaseGrandTotal(), 2);
		$udf5 		= 'Magento_v.2';	
		$email = $quote->getCustomerEmail();
		
		if(empty($email))
		{
			$email = $billing_address->getEmail();
		}
		$firstname=$billing_address->getFirstName();
		$hash = $this->generatePayuHash($txnid,$amount,$quote->getId(), $firstname,
			$email,$this->checkoutSession->getSessionId(),$udf5);
		
		$params = array();
		$params['action'] = $this->getConfigData('paymentaction'); //redirect or bolt
		
		if($params['action']=='bolt')
		{	
			
	
			$params['data'] = "<form action='' method='post' style='display: none' id='frm_payu_response' name='frm_payu_response' enctype='multipart/form-data'>
			    <input type='hidden' id='full_response' name='full_response' value='' >
				<input type='submit' id='sbt' name='sbt' value='Submit' >
				</form>
			
					
					<script type='text/javascript'>
					
					var tri=0;
								
					function doPayment()
					{
					
						var data = { key: '".$mkey."',
							hash: '".$hash."',
							txnid: '".$txnid."',
							amount: '".$amount."',
							firstname: '".$this->escaper->escapeJs($firstname)."',
							lastname: '".$this->escaper->escapeJs($billing_address->getLastname())."',
							email: '".$this->escaper->escapeJs($email)."',
							phone: '".$this->escaper->escapeJs($billing_address->getTelephone())."',
							productinfo: '".$quote->getId()."',
							surl: '".$this->getReturnUrl()."',
							furl: '".$this->getCancelUrl()."',
							curl:'".$this->getCancelUrl()."',
							udf1: '".$this->checkoutSession->getSessionId()."',
							udf5: '".$udf5."',
							enforce_paymethod: '".$enforcePaymethod."'

						};
						var handlers = {responseHandler: function (BOLT) {
							
							var frm = document.getElementById('frm_payu_response');
							
							if(BOLT.response.surl !== undefined){
								frm.action = BOLT.response.surl;
							}else {
								frm.action = '".$this->getCancelUrl()."';
							}		

							frm.elements.namedItem('full_response').value = JSON.stringify(BOLT.response);
							
							frm.submit();
							
							},							
							catchException: function (BOLT) {
								console.log('Payment failed. ' + BOLT.message );
							}						
						};                
						
						bolt.launch( data , handlers );		
				
						return false;
					}			
					
						doPayment();
					
					</script>";

					$this->helper->createTempOrder($txnid,$quote);
		}
		elseif($params['action']=='redirect')
		{
		
			$params['data'] = "<form action=\"".$this->getCgiUrl()."\" method=\"post\" id=\"payu_payment_form\" name=\"payu_payment_form\">
					<input type=\"hidden\" name=\"key\" value=\"". $mkey. "\" />
						<input type=\"hidden\" name=\"txnid\" value=\"".$txnid."\" />
						<input type=\"hidden\" name=\"amount\" value=\"".$amount."\" />
						<input type=\"hidden\" name=\"productinfo\" value=\"".$quote->getId()."\" />
						<input type=\"hidden\" name=\"firstname\" value=\"".$this->escaper->escapeHtmlAttr($firstname)."\" />
						<input type=\"hidden\" name=\"Lastname\" value=\"" .$this->escaper->escapeHtmlAttr($billing_address->getLastname())."\" />
						<input type=\"hidden\" name=\"city\" value=\"".$this->escaper->escapeHtmlAttr($billing_address->getCity()). "\" />
						<input type=\"hidden\" name=\"state\" value=\"". $this->escaper->escapeHtmlAttr($billing_address->getRegion()). "\" />
						<input type=\"hidden\" name=\"zip\" value=\"". $this->escaper->escapeHtmlAttr($billing_address->getPostcode()). "\" />
						<input type=\"hidden\" name=\"country\" value=\"".$this->escaper->escapeHtmlAttr($billing_address->getCountryId()). "\" />
						<input type=\"hidden\" name=\"email\" value=\"". $this->escaper->escapeHtmlAttr($email)."\" />
						<input type=\"hidden\" name=\"phone\" value=\"".$this->escaper->escapeHtmlAttr($billing_address->getTelephone())."\" />
						<input type=\"hidden\" name=\"udf1\" value=\"".$this->checkoutSession->getSessionId()."\" />
						<input type=\"hidden\" name=\"udf5\" value=\"".$udf5."\" />
						<input type=\"hidden\" name=\"enforce_paymethod\" value=\"".$enforcePaymethod."\" />
						<input type=\"hidden\" name=\"surl\" value=\"". $this->getReturnUrl(). "\" />
						<input type=\"hidden\" name=\"furl\" value=\"". $this->getCancelUrl()."\" />
						<input type=\"hidden\" name=\"curl\" value=\"".$this->getCancelUrl()."\" />
						<input type=\"hidden\" name=\"service_provider\" value=\"". $sprovider ."\" />
						<input type=\"hidden\" name=\"Hash\" value=\"".$hash."\" />
						<input type=\"hidden\" name=\"Pg\" value=\"\" />
						
						<button style='display:none' id='submit_payu_payment_form' name='submit_payu_payment_form'>Pay Now</button>
					</form>
					<script type=\"text/javascript\">document.getElementById(\"payu_payment_form\").submit();</script>";

					$this->helper->createTempOrder($txnid,$quote);
		}
        return $params;
    }

    public function generatePayuHash($txnid, $amount, $productInfo, $name,
            $email,$udf1,$udf5) {
        $SALT = $this->getConfigData('salt');

        $posted = array(
            'key' => $this->getConfigData("merchant_key"),
            'txnid' => $txnid,
            'amount' => $amount,
            'productinfo' => $productInfo,
            'firstname' => $name,
            'email' => $email,
			'udf1' => $udf1,
			'udf5' => $udf5,			
        );

        $hashSequence = 'key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5|udf6|udf7|udf8|udf9|udf10';

        $hashVarsSeq = explode('|', $hashSequence);
        $hash_string = '';
        foreach ($hashVarsSeq as $hash_var) {
            $hash_string .= isset($posted[$hash_var]) ? $posted[$hash_var] : '';
            $hash_string .= '|';
        }
        $hash_string .= $SALT;	
		
		
        return strtolower(hash('sha512', $hash_string));
    }

    //validate response
    public function validateResponse($returnParams,string $type = 'web') {
		
		if ($type === 'web' && ($returnParams['status'] ?? '') !== 'success') {
			return false;
		}
        if ($returnParams['key'] != $this->getConfigData("merchant_key")) {
            return false;
        }
        
		//validate hash
		if(isset($returnParams['hash'])){			
			$txnid 			= $returnParams['txnid'];
			$amount        	= $returnParams['amount'];
			$productinfo   	= $returnParams['productinfo'];
			$firstname     	= $returnParams['firstname'];
			$email         	= $returnParams['email'];
			$Udf1 			= $returnParams['udf1'];
			$Udf2 			= $returnParams['udf2'];
		 	$Udf3 			= $returnParams['udf3'];
		 	$Udf4 			= $returnParams['udf4'];
		 	$Udf5 			= $returnParams['udf5'];
		 	$Udf6 			= $returnParams['udf6'];
		 	$Udf7 			= $returnParams['udf7'];
		 	$Udf8 			= $returnParams['udf8'];
		 	$Udf9 			= $returnParams['udf9'];
		 	$Udf10 			= $returnParams['udf10'];
			$additionalCharges 	= 	0; 
			if (isset($returnParams["additionalCharges"])) $additionalCharges = $returnParams['additionalCharges'];
							
			$keyString =  $this->getConfigData("merchant_key").'|'.$txnid.'|'.$amount.'|'.$productinfo.'|'.$firstname.'|'.$email.'|'.$Udf1.'|'.$Udf2.'|'.$Udf3.'|'.$Udf4.'|'.$Udf5.'|'.$Udf6.'|'.$Udf7.'|'.$Udf8.'|'.$Udf9.'|'.$Udf10;
		  
			$keyArray = explode("|",$keyString);
			$reverseKeyArray = array_reverse($keyArray);
			$reverseKeyString=implode("|",$reverseKeyArray);			 
			$status=$returnParams['status'];			
			$saltString     = $this->getConfigData('salt').'|'.$status.'|'.$reverseKeyString;
			if($additionalCharges > 0) 
				$saltString     = $additionalCharges.'|'.$saltString;
			
			$sentHashString = strtolower(hash('sha512', $saltString));			
			
			if($sentHashString != $returnParams['hash'])
				return false;
			else
				return true;
		}
        return false;
    }

    public function postProcessing(\Magento\Sales\Model\Order $order,\Magento\Sales\Model\Order\Payment $payment, $response) 
	{
		$this->payuLogger->info('Payu response ');
		try {				
			$orderemail = $this->getConfigData("orderemail");
			$geninvoice = $this->getConfigData("generateinvoice");
			$emailinvoice = $this->getConfigData("invoiceemail");	
			$order->save();			
			if($this->verifyPayment($order,$response['txnid']))
			{	
				$payment->setTransactionId($response['txnid'])       
				->setPreparedMessage('SUCCESS')
				->setShouldCloseParentTransaction(true)
				->setIsTransactionClosed(0)
				->setAdditionalInformation('payu_mihpayid', $response['mihpayid'])
				->setAdditionalInformation('payu_order_status', 'approved');
				
				If (isset($response['additionalCharges'])) {
					$payment->setAdditionalInformation('Additional Charges', (float)$response['additionalCharges']);		
					if($geninvoice) {
						$payment->registerCaptureNotification(($response['net_amount_debit']+(float)$response['additionalCharges']),true);
					} else {
						$payment->setAmountPaid($response['net_amount_debit']+(float)$response['additionalCharges']);
						$payment->setBaseAmountPaid($response['net_amount_debit']+(float)$response['additionalCharges']);
					}
					$order->save();
				}
				else {
					if($geninvoice) {
						$payment->registerCaptureNotification($response['net_amount_debit'],true)->save();
					} else {
						$payment->setAmountPaid($response['net_amount_debit']);
						$payment->setBaseAmountPaid($response['net_amount_debit']);
						$payment->save();
					}
				}
				if($this->getConfigData('debuglog')==true)
					$this->payuLogger->info('Payment has been saved');					
				
				
				foreach ($order->getAllItems() as $item) 
				{ 
					$item->setBasePrice($item->getBasePrice())->setOriginalPrice($item->getOriginalPrice())->setBaseOriginalPrice($item->getBaseOriginalPrice())->save();

				}
				

				$order->setTotalPaid($response['net_amount_debit'])->save();

				$order->setState(Order::STATE_PROCESSING,true)->setStatus(Order::STATE_PROCESSING)->save();				
				
				$order->setCanSendNewEmailFlag(true)->save();
				
				$order->setIsCustomerNotified(true)->save();

				
				if($orderemail)
					$this->orderSender->send($order);
				
				if($geninvoice && $order->canInvoice()) {
					$invoice = $order->prepareInvoice();
					$invoice->register();
					
					$invoice->save();
					if($this->getConfigData('debuglog')==true)
						$this->payuLogger->info('Invoice has been saved');
					if($emailinvoice) {
						$this->invoiceSender->send($invoice);
					}
				}
				
				
			}
			else {
				//modified to cancel order in case of failed or canceled payment
				$errorMsg = $response['error_Message'] ?? $response['field9'] ?? 'Payment failed';							  
				$order->addCommentToStatusHistory("Payment Failed: " . $errorMsg);
				$order->setState(Order::STATE_CANCELED,true)->setStatus(Order::STATE_CANCELED);	
				$order->save();
			}
		}
		catch(\Exception $e){
			if($this->getConfigData('debuglog')==true)
				$this->payuLogger->info('Exception: ' .$e->getMessage());									  
			
		}
    }

	public function verifyPayment(\Magento\Sales\Model\Order $order,$txnid)
	{
		$flag = $this->getConfigData('verifypayment');
		
		if(!$flag) return true;
		
		$fields = array(
				'key' => $this->getConfigData("merchant_key",$order->getStoreId()),
				'command' => 'verify_payment',
				'var1' => $txnid,
				'hash' => ''
			);
				
		$hash = hash("sha512", $fields['key'].'|'.$fields['command'].'|'.$fields['var1'].'|'.$this->getConfigData('salt',$order->getStoreId()) );
		$fields['hash'] = $hash;
		$fields_string = http_build_query($fields);
		$url = 'https://info.payu.in/merchant/postservice.php?form=2';
		if( $this->getConfigData('environment',$order->getStoreId()) == 'sandbox' )
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
		$res ='';
		if ($curlerr || $httpCode != 200) {
			$message = "Curl Error: " . $curlerr . " | HTTP Code: " . $httpCode;
			if ($this->getConfigData('debuglog') == true) {
				$this->payuLogger->info("Verify response Curl Error: " . $message);
			}

			return false;
		}
		else 
		{
			$res = json_decode($response,true);
			
			
			
			if(!isset($res['status']))
				$message = $res['msg'];
			else{
				$res = $res['transaction_details'];
				$res = $res[$txnid];					
			}
			if($res['status'] == 'success' && $res['transaction_amount'] >= round((float)$order->getBaseGrandTotal(), 2))
			{	
				return true;
			}
			else{
				$this->payuLogger->info("Payment amount mismatch:", ['txnid' => $txnid]);
				return false;	
			} 
		}			
	}	
	
	//Additional coding in case requirement comes
	public function cancelOrder(\Magento\Sales\Model\Order $order)	
	{
		try
		{
			$orderId=$order->getId();			
	
			if($order->canCancel())
			{
				$order->setTotalPaid(0); 
				$order->setState(Order::STATE_CANCELED,true)->setStatus(Order::STATE_CANCELED);	
				$order->save();
			}
	
			if($this->getConfigData('debuglog')==true)
				$this->payuLogger->info("Order Canceled", ['order_id' => $orderId]);								  
			
			return true;
		}
		catch(\Exception $e){
			if($this->getConfigData('debuglog')==true)
				$this->payuLogger->info($e->getMessage());
			return false;
		}
	}
}
