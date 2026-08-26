<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Observer\Vat;

use Magento\Customer\Model\Session;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Calculation as CalculationResource;
use Psr\Log\LoggerInterface;

/**
 * Restores whatever customer group SetQuoteCustomerGroup swapped away from,
 * once tax totals have finished collecting — so nothing downstream (invoice
 * export, order confirmation, account pages) ever sees the temporary swap.
 */
class RestoreQuoteCustomerGroup implements ObserverInterface
{
    private const INSEAD_TAX_COMMENT = 'insead_tax_comment';
    private const TAX_ARTICLE = 'tax_article';

    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly TaxRuleRepositoryInterface $taxRuleRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly CalculationResource $calculationResource,
        private readonly Calculation $calculation,
        private readonly LoggerInterface $logger,
        private readonly Session $customerSession
    ) {
    }
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote || !$quote->hasData(SetQuoteCustomerGroup::PROCESSED_FLAG)) {
            return;
        }

        $address = $quote->getBillingAddress();

        if (!empty($quote->getAllItems())) {
            $comments = [];
            $articles = [];
            foreach ($quote->getAllItems() as $quoteItem) {
                $taxMeta = $this->getTaxMetaFromRule($quote, $quoteItem, $address);
                $inseadTaxComment = trim((string)($taxMeta[self::INSEAD_TAX_COMMENT] ?? ''));
                $taxArticle = trim((string)($taxMeta[self::TAX_ARTICLE] ?? ''));

                // Always persist item-level tax metadata, even when blank,
                // so previously-set values can be explicitly cleared.
                $quoteItem->setData(self::INSEAD_TAX_COMMENT, $inseadTaxComment);
                $quoteItem->setData(self::TAX_ARTICLE, $taxArticle);

                if ($inseadTaxComment !== '') {
                    $comments[$inseadTaxComment] = true;
                }
                if ($taxArticle !== '') {
                    $articles[$taxArticle] = true;
                }
            }
            if (!empty($comments)) {
                $quote->setData(self::INSEAD_TAX_COMMENT, implode(', ', array_keys($comments)));
            }
            if (!empty($articles)) {
                $quote->setData(self::TAX_ARTICLE, implode(', ', array_keys($articles)));
            }
        }

        // Restore original customer group
        if ($address && $address->hasData('insead_prev_customer_group_id')) {
            $quote->setCustomerGroupId($address->getData('insead_prev_customer_group_id'));
            $address->unsetData('insead_prev_customer_group_id');
        }

        $quote->unsetData(SetQuoteCustomerGroup::PROCESSED_FLAG);
    }

    protected function getTaxMetaFromRule(
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Model\Quote\Item $quoteItem,
        \Magento\Quote\Model\Quote\Address $address
    ): array {
        $taxMeta = [
            self::INSEAD_TAX_COMMENT => null,
            self::TAX_ARTICLE => null,
        ];
        $customerTaxClassId = $quote->getCustomerTaxClassId();

        $_product = $quoteItem->getProduct();
        if ($customerTaxClassId) {
            try {
                $store = $_product->getStore();
                $rateRequest = $this->calculation->getRateRequest(
                    $quote->getShippingAddress(),
                    $quote->getBillingAddress(),
                    $customerTaxClassId,
                    $store,
                    $quote->getCustomer()->getId()
                );
                $_product = $quoteItem->getProduct();
                $productTaxClassOptionLabel = $_product->getAttributeText('tax_class_id');
                $attribute = $_product->getResource()->getAttribute('tax_class_id');
                $productTaxClassId = $rateRequest->getProductClassId();
                if ($attribute->usesSource()) {
                    $productTaxClassId = $attribute->getSource()->getOptionId($productTaxClassOptionLabel);
                }
                $rateRequest->setProductClassId($productTaxClassId);
                $fullInfo = $this->calculationResource->getCalculationProcess($rateRequest, null);
                $taxInfo = [];
                foreach ($fullInfo as $info) {
                    if (isset($info['hidden']) && $info['hidden']) {
                        continue;
                    }
                    foreach ($info['rates'] as $rate) {
                        $taxInfo['rule_id'] = $rate['rule_id'];
                        $taxInfo['tax_code'] = $rate['code'];
                        $taxInfo['percent'] = $rate['percent'];
                    }
                }
                if (!empty($taxInfo) && isset($taxInfo['rule_id'])) {
                    $ruleSearchCriteria = $this->searchCriteriaBuilder
                        ->addFilter('tax_calculation_rule_id', [$taxInfo['rule_id']], 'eq')
                        ->create();
                    $taxRules = $this->taxRuleRepository->getList($ruleSearchCriteria)->getItems();
                    foreach ($taxRules as $rule) {
                        $comment = trim((string)$rule->getData(self::INSEAD_TAX_COMMENT));
                        if ($comment !== '') {
                            $taxMeta[self::INSEAD_TAX_COMMENT] = $comment;
                        }
                        $taxArticle = trim((string)$rule->getData(self::TAX_ARTICLE));
                        if ($taxArticle !== '') {
                            $taxMeta[self::TAX_ARTICLE] = $taxArticle;
                        }
                    }
                }
            } catch (\Exception $ex) {
                $this->logger->info('Message ' . $ex->getMessage());
            }
        }

        return $taxMeta;
    }
}
