<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use SmsTunnel\SmsNotifications\Model\CallingCodes;

/**
 * Accepts +44, 44 or 0044 and saves it as +44.
 */
class CountryCode extends Value
{
    /**
     * @var CallingCodes
     */
    private $callingCodes;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param CallingCodes $callingCodes
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        CallingCodes $callingCodes,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->callingCodes = $callingCodes;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Clean and check the code before it is saved.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '') {
            $code = $this->callingCodes->clean($value);
            if ($code === null) {
                throw new LocalizedException(
                    __('"%1" is not a country calling code. Use one like +1, +44 or +40.', $value)
                );
            }
            $value = '+' . $code;
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
