<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;

/**
 * Writes texts to smstunnel_message and hands them to the queue consumer, so nothing waits on SMSTunnel.
 *
 * The row is the source of truth: when the message queue is not running, the smstunnel_send_pending cron job
 * sends what is waiting. A unique key per text keeps it from being queued twice.
 */
class Outbox
{
    public const TOPIC = 'smstunnel.message.send';
    public const MAX_LENGTH = 1600;

    /**
     * @var MessageResource
     */
    private $resource;

    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param MessageResource $resource
     * @param PublisherInterface $publisher
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        MessageResource $resource,
        PublisherInterface $publisher,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->publisher = $publisher;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * Queue one text for one number.
     *
     * A number that cannot get texts, or a text that is empty or too long, is kept as "skipped" with the reason.
     *
     * @param string $event one of the Message::EVENT_* constants
     * @param string $dedupeKey the same key is never queued twice
     * @param int|null $storeId
     * @param string $rawNumber the number as it was typed
     * @param PhoneNumberResult $number
     * @param string $text
     * @param Order|null $order
     * @param bool $publish false to send it yourself right away (the test text)
     * @return int|null the new row's ID, or null when the key was already queued
     */
    public function add(
        string $event,
        string $dedupeKey,
        ?int $storeId,
        string $rawNumber,
        PhoneNumberResult $number,
        string $text,
        ?Order $order = null,
        bool $publish = true
    ): ?int {
        $status = Message::STATUS_PENDING;
        $details = null;
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if (!$number->isValid()) {
            $status = Message::STATUS_SKIPPED;
            $details = $number->getReason();
        } elseif ($text === '') {
            $status = Message::STATUS_SKIPPED;
            $details = (string) __('The text is empty: check its template.');
        } elseif ($length > self::MAX_LENGTH) {
            $status = Message::STATUS_SKIPPED;
            $details = (string) __('The text has %1 characters; at most %2 fit.', $length, self::MAX_LENGTH);
        }

        $now = $this->dateTime->gmtDate();
        $row = [
            'store_id' => $storeId,
            'event' => $event,
            'order_id' => $order !== null && $order->getId() !== null ? (int) $order->getId() : null,
            'order_increment_id' => $order !== null ? $order->getIncrementId() : null,
            'dedupe_key' => substr($dedupeKey, 0, 128),
            'recipient' => $number->isValid() ? $number->getNumber() : $this->shorten($rawNumber, 32),
            'message' => $text,
            'status' => $status,
            'attempts' => 0,
            'details' => $details,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $connection = $this->resource->getConnection();
        try {
            $connection->insert($this->resource->getMainTable(), $row);
        } catch (DuplicateException $exception) {
            return null;
        }
        $id = (int) $connection->lastInsertId($this->resource->getMainTable());

        if ($status === Message::STATUS_PENDING && $publish) {
            $this->publish($id);
        }

        return $id;
    }

    /**
     * Hand a queued text to the message queue consumer.
     *
     * @param int $id
     * @return void
     */
    public function publish(int $id): void
    {
        try {
            $this->publisher->publish(self::TOPIC, (string) $id);
        } catch (\Throwable $exception) {
            // The cron job sends it.
            $this->logger->warning(
                sprintf(
                    'SMSTunnel: text %d is queued, but the message queue refused it: %s',
                    $id,
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * One row of smstunnel_message.
     *
     * @param int $id
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getMainTable())->where('entity_id = ?', $id)
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Cut a string to a column's length.
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    private function shorten(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
