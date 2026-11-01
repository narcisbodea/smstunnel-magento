<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use SmsTunnel\SmsNotifications\Model\Outbox;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;

/**
 * bin/magento module:uninstall --remove-data SmsTunnel_SmsNotifications: removes the texts, the settings
 * (API key included) and the module's message queue.
 */
class Uninstall implements UninstallInterface
{
    /**
     * @inheritdoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        $connection->dropTable($setup->getTable(MessageResource::TABLE));
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'smstunnel/%']);
        if ($connection->isTableExists($setup->getTable('queue'))) {
            $connection->delete($setup->getTable('queue'), ['name = ?' => Outbox::TOPIC]);
        }

        $setup->endSetup();
    }
}
