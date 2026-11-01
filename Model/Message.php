<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * One text: queued, sent, failed or skipped (table smstunnel_message).
 */
class Message extends AbstractModel
{
    public const EVENT_ORDER_PLACED = 'order_placed';
    public const EVENT_OWNER_NEW_ORDER = 'owner_new_order';
    public const EVENT_INVOICE_CREATED = 'invoice_created';
    public const EVENT_SHIPMENT_CREATED = 'shipment_created';
    public const EVENT_ORDER_CANCELED = 'order_canceled';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_TEST = 'test';

    /** Waiting to be handed to SMSTunnel (again, after an error that can pass). */
    public const STATUS_PENDING = 'pending';
    /** Being handed to SMSTunnel right now. */
    public const STATUS_SENDING = 'sending';
    /** SMSTunnel accepted it for the phone. */
    public const STATUS_SENT = 'sent';
    /** SMSTunnel refused it, or every attempt failed. */
    public const STATUS_FAILED = 'failed';
    /** Never sent: no valid number, a text too long, or texts turned off. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var string
     */
    protected $_eventPrefix = 'smstunnel_message';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Message::class);
    }
}
