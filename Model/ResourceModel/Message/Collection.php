<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\ResourceModel\Message;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use SmsTunnel\SmsNotifications\Model\Message;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;

/**
 * Texts in smstunnel_message.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Message::class, MessageResource::class);
    }
}
