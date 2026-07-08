<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders the custom financing / e-invoicing fields on the admin order view.
 */
class OrderInfo extends Template
{
    /** Column code => label, in display order. */
    private const FIELDS = [
        'peoplesoft_id'               => 'PeopleSoft ID',
        'financing_profile'           => 'Financing Profile',
        'sector_of_activity'          => 'Sector of Activity',
        'nationality'                 => 'Nationality',
        'gender'                      => 'Gender',
        'tax_registration_status'     => 'Tax Registration Status',
        'company_legal_name'          => 'Company Legal Name',
        'commercial_company_name'     => 'Commercial Company Name',
        'organization_type'           => 'Organization Type',
        'invoice_recipient_firstname' => 'Invoice Recipient First Name',
        'invoice_recipient_lastname'  => 'Invoice Recipient Last Name',
        'invoice_email'               => 'Invoice Email',
        'reg_type_label'              => 'Registration Type',
        'reg_type_value'              => 'Registration Value',
        'uen'                         => 'Business Reg / SIRET / UEN',
        'vat_intracommunity'          => 'VAT Intracommunity',
        'vat_uae'                     => 'VAT Number (UAE)',
        'gst_number'                  => 'GST Number',
        'tax_id_number'               => 'Tax ID Number',
        'certificate_id'              => 'Certificate ID',
        'duns_number'                 => 'D-U-N-S Number',
        'po_number'                   => 'PO Number',
        'routing_address'             => 'Routing Address',
        'tax_exempt_file'             => 'Tax Exempt Certificate',
    ];

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->registry->registry('current_order');
    }

    /**
     * @return array<int,array{label:string,value:string,url:?string}>
     */
    public function getInfo(): array
    {
        $order = $this->getOrder();
        if (!$order) {
            return [];
        }
        $rows = [];
        foreach (self::FIELDS as $code => $label) {
            $value = $order->getData($code);
            if ($value !== null && $value !== '') {
                $rows[] = [
                    'label' => (string) __($label),
                    'value' => (string) $value,
                    'url'   => $code === 'tax_exempt_file' ? $this->getMediaUrl((string) $value) : null,
                ];
            }
        }
        return $rows;
    }

    /** Public media URL for a relative pub/media path (e.g. the uploaded tax-exempt file). */
    private function getMediaUrl(string $relativePath): ?string
    {
        try {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            return rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
