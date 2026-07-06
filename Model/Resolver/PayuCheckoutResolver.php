<?php
namespace PayUIndia\Payu\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PayUIndia\Payu\Model\Payu;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Api\CartRepositoryInterface;

class PayuCheckoutResolver implements ResolverInterface
{
    protected $payu;
    protected $quoteIdMaskFactory;
    protected $cartRepository;

    public function __construct(
        Payu $payu,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository
    ) {
        $this->payu = $payu;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->cartRepository = $cartRepository;
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $maskedQuoteId = $args['quote_id'] ?? null;
        if (!$maskedQuoteId) {
            throw new GraphQlInputException(__('quote_id is required.'));
        }

        $enforcePaymethod = $args['enforce_paymethod'] ?? null;
        if (!$enforcePaymethod) {
            throw new GraphQlInputException(__('enforce_paymethod is required.'));
        }

        // Convert masked ID to real quote ID
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedQuoteId, 'masked_id');
        $quoteId = $quoteIdMask->getQuoteId();

        if (!$quoteId) {
            throw new GraphQlInputException(__('Invalid quote_id.'));
        }

        // Load quote correctly
        $quote = $this->cartRepository->get($quoteId);

        try {
            $result = $this->payu->buildCheckoutRequest($quote, $enforcePaymethod);

            if (isset($result['error'])) {
                //return ['html' => $result['error']];
                throw new GraphQlInputException(__($result['error']));
            }

        } catch (\Exception $e) {
            throw new GraphQlInputException(__('PayU Error: %1.', $e->getMessage()));
        }

        return ['html' => $result['data'] ?? ''];
    }
}
