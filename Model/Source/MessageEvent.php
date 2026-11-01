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
 * What a text is about, for the grid of sent texts.
 */
class MessageEvent implements OptionSourceInterface
{
    /**
     * Options for the grid's column and filter.
     *
     * @return array<int,array{value:string,label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        return [
            ['value' => Message::EVENT_ORDER_PLACED, 'label' => __('Order placed')],
            ['value' => Message::EVENT_OWNER_NEW_ORDER, 'label' => __('New order (to you)')],
            ['value' => Message::EVENT_INVOICE_CREATED, 'label' => __('Invoice created')],
            ['value' => Message::EVENT_SHIPMENT_CREATED, 'label' => __('Shipment created')],
            ['value' => Message::EVENT_ORDER_CANCELED, 'label' => __('Order canceled')],
            ['value' => Message::EVENT_STATUS_CHANGED, 'label' => __('Status changed')],
            ['value' => Message::EVENT_TEST, 'label' => __('Test text')],
        ];
    }
}
