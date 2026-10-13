<?php
declare(strict_types=1);

namespace Insead\CustomCheckout\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/** Rejects saving anything that is not a JSON object of string => string pairs (empty is allowed). */
class JsonMap extends Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '') {
            $decoded = json_decode($value, true);
            $valid = is_array($decoded) && $decoded !== [];
            foreach ((array) $decoded as $key => $label) {
                if (!is_string($label) || trim($label) === '' || trim((string) $key) === '') {
                    $valid = false;
                }
            }
            if (!$valid) {
                throw new LocalizedException(
                    __('%1 must be a JSON object such as {"94": "Airlines/Aviation"}.', $this->getData('field_config/label'))
                );
            }
        }

        return parent::beforeSave();
    }
}
