<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Table smstunnel_message.
 */
class Message extends AbstractDb
{
    public const TABLE = 'smstunnel_message';

    /**
     * The database connection of the table.
     *
     * @return AdapterInterface
     * @throws LocalizedException when there is no connection
     */
    public function getAdapter(): AdapterInterface
    {
        $connection = $this->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new LocalizedException(__('The database connection is not available.'));
        }

        return $connection;
    }

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'entity_id');
    }
}
