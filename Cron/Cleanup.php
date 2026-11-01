<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use SmsTunnel\SmsNotifications\Model\Config;
use SmsTunnel\SmsNotifications\Model\Message;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;

/**
 * Every night: deletes sent, failed and skipped texts older than the configured number of days.
 */
class Cleanup
{
    /**
     * @var MessageResource
     */
    private $resource;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param MessageResource $resource
     * @param Config $config
     * @param DateTime $dateTime
     */
    public function __construct(MessageResource $resource, Config $config, DateTime $dateTime)
    {
        $this->resource = $resource;
        $this->config = $config;
        $this->dateTime = $dateTime;
    }

    /**
     * Run the job.
     *
     * @return int how many texts were deleted
     */
    public function execute(): int
    {
        $days = $this->config->getRetentionDays();
        if ($days === 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $days * 86400);

        return $this->resource->getAdapter()->delete($this->resource->getMainTable(), [
            'created_at < ?' => $cutoff,
            'status IN (?)' => [Message::STATUS_SENT, Message::STATUS_FAILED, Message::STATUS_SKIPPED],
        ]);
    }
}
