<?php
declare(strict_types=1);

namespace PayUIndia\Payu\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Quote\Model\QuoteIdMaskFactory;

class PayuResponseResolver implements ResolverInterface
{

    private $quoteRepository;
    private $checkoutSession;
    private $checkoutHelper;
    private $paymentMethod;
    private $orderFactory;
    private $logger;
    protected $quoteIdMaskFactory;

    public function __construct(
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Checkout\Model\Session $checkoutSession,
        \PayUIndia\Payu\Helper\Payu $checkoutHelper,
        \PayUIndia\Payu\Model\Payu $paymentMethod,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Psr\Log\LoggerInterface $logger,
        QuoteIdMaskFactory $quoteIdMaskFactory
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->checkoutSession = $checkoutSession;
        $this->checkoutHelper = $checkoutHelper;
        $this->paymentMethod = $paymentMethod;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        
    }

    public function resolve(
        $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {

        try {

            //$txnid = $args['txnid'];
            $event = strtolower($args['event']);
            if (!$event) {
                throw new GraphQlInputException(__('event is required.'));
            }
            $maskedQuoteId = $args['quote_id'] ?? null;
            if (!$maskedQuoteId) {
                throw new GraphQlInputException(__('quote_id is required.'));
            }

            $params = [];

            if (!empty($args['full_response'])) {
                $params = json_decode($args['full_response'], true) ?? [];
            }
           
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedQuoteId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();


            $quote = $this->quoteRepository->get($quoteId);

            $incrementId = $quote->getReservedOrderId();
            $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

            /*
            SUCCESS
            */

            if ($event === "success") {

                if ($this->paymentMethod->validateResponse($params)) {

                    $this->checkoutHelper->updateOrderFromResponse($order, $params);

                    $payment = $order->getPayment();

                    $this->paymentMethod->postProcessing($order, $payment, $params);

                    $quote->setIsActive(false);
                    $this->quoteRepository->save($quote);

                    return [
                        "success" => true,
                        "message" => "Payment successful",
                        "order_id" => $order->getIncrementId()
                    ];
                }
            }

            /*
            CANCEL / FAILURE
            */

            if (in_array($event, ["cancel","failed","failure"])) {

                if ($order && $order->getId() && $order->canCancel()) {
                    $order->registerCancellation("Payment failed")->save();
                }

                $quote->setIsActive(true);
                $quote->setReservedOrderId(null);

                $this->quoteRepository->save($quote);

                $this->checkoutSession->replaceQuote($quote);

                return [
                    "success" => false,
                    "message" => "Payment cancelled",
                    "order_id" => $order->getIncrementId()
                ];
            }

            return [
                "success" => false,
                "message" => "Invalid payment status",
                "order_id" => $order->getIncrementId()
            ];

        } catch (\Exception $e) {

            $this->logger->error($e->getMessage());

            return [
                "success" => false,
                "message" => "Payment processing error.Please contact support.",
                "order_id" => null
            ];
        }
    }
}