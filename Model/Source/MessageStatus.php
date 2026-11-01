<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use SmsTunnel\SmsNotifications\Model\Message;

/**
 * Where a text is, for the grid of sent texts.
 */
class MessageStatus implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray()
    {
        return [
            ['value' => Message::STATUS_PENDING, 'label' => __('Waiting')],
            ['value' => Message::STATUS_SENDING, 'label' => __('Sending')],
            ['value' => Message::STATUS_SENT, 'label' => __('Sent to your phone')],
            ['value' => Message::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => Message::STATUS_SKIPPED, 'label' => __('Skipped')],
        ];
    }
}
