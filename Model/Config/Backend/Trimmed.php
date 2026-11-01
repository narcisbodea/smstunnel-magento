<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Saves the value without the spaces a copy and paste brings along.
 */
class Trimmed extends Value
{
    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $this->setValue(trim((string) $this->getValue()));

        return parent::beforeSave();
    }
}
