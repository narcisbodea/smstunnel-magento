<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\Api\ApiException;
use SmsTunnel\SmsNotifications\Model\Api\Client;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;

/**
 * Hands one queued text to SMSTunnel and records the outcome.
 *
 * A text is claimed with a single UPDATE first, so the queue consumer and the cron job never send it twice.
 * Errors that can pass (no connection, too many requests, server errors) are retried after 1, 5, 15 and 60
 * minutes; other errors (a wrong API key, a refused number) fail at once.
 */
class Sender
{
    public const RESULT_SENT = 'sent';
    public const RESULT_RETRY = 'retry';
    public const RESULT_RATE_LIMITED = 'rate_limited';
    public const RESULT_FAILED = 'failed';
    public const RESULT_SKIPPED = 'skipped';
    /** Not waiting to be sent (already sent, being sent elsewhere, or its next try is later). */
    public const RESULT_NOT_DUE = 'not_due';

    public const MAX_ATTEMPTS = 5;

    /**
     * Minutes to wait after the n-th failed attempt.
     */
    private const RETRY_MINUTES = [1 => 1, 2 => 5, 3 => 15, 4 => 60];

    /**
     * A text still "sending" after this long was left by a process that stopped; it is tried again.
     */
    private const STUCK_MINUTES = 10;

    /**
     * @var MessageResource
     */
    private $resource;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

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
     * @param Config $config
     * @param Client $client
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        MessageResource $resource,
        Config $config,
        Client $client,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->config = $config;
        $this->client = $client;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * Send one queued text, if it is due.
     *
     * @param int $id
     * @return string one of the RESULT_* constants
     */
    public function send(int $id): string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();
        $now = $this->dateTime->gmtDate();

        $claimed = $connection->update(
            $table,
            ['status' => Message::STATUS_SENDING, 'attempts' => new Expression('attempts + 1'), 'updated_at' => $now],
            [
                'entity_id = ?' => $id,
                'status = ?' => Message::STATUS_PENDING,
                'next_attempt_at IS NULL OR next_attempt_at <= ?' => $now,
            ]
        );
        if ($claimed !== 1) {
            return self::RESULT_NOT_DUE;
        }
        $row = $connection->fetchRow($connection->select()->from($table)->where('entity_id = ?', $id));
        if (!is_array($row)) {
            return self::RESULT_NOT_DUE;
        }

        $storeId = $row['store_id'] === null ? null : (int) $row['store_id'];
        if ($row['event'] !== Message::EVENT_TEST && !$this->config->isEnabled($storeId)) {
            $this->finish(
                $id,
                Message::STATUS_SKIPPED,
                (string) __('Texts were turned off for this store view before this one went out.')
            );

            return self::RESULT_SKIPPED;
        }
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            $this->finish($id, Message::STATUS_FAILED, (string) __('No API key is saved for this store view.'));

            return self::RESULT_FAILED;
        }

        try {
            $result = $this->client->send(
                $this->config->getServerUrl($storeId),
                $apiKey,
                (string) $row['recipient'],
                (string) $row['message'],
                $this->config->getDeviceId($storeId)
            );
        } catch (ApiException $exception) {
            return $this->handleError($id, (int) $row['attempts'], $exception);
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: unexpected error while sending text ' . $id, ['exception' => $exception]);

            return $this->handleError($id, (int) $row['attempts'], new ApiException($exception->getMessage(), 0));
        }

        $connection->update(
            $table,
            [
                'status' => Message::STATUS_SENT,
                'smstunnel_message_id' => $result->getMessageId(),
                'sent_at' => $now,
                'next_attempt_at' => null,
                'details' => $result->isQueued()
                    ? (string) __('Your phone was offline: it sends the text as soon as it is back online.')
                    : null,
                'updated_at' => $now,
            ],
            ['entity_id = ?' => $id]
        );

        return self::RESULT_SENT;
    }

    /**
     * IDs of texts waiting to be sent, oldest first.
     *
     * @param int $limit
     * @return int[]
     */
    public function getDueIds(int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getMainTable(), ['entity_id'])
            ->where('status = ?', Message::STATUS_PENDING)
            ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', $this->dateTime->gmtDate())
            ->order('entity_id ASC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Put back texts left "sending" by a process that stopped, so they are tried again.
     *
     * @return int how many
     */
    public function releaseStuck(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - self::STUCK_MINUTES * 60);

        return $this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            ['status' => Message::STATUS_PENDING, 'updated_at' => $this->dateTime->gmtDate()],
            ['status = ?' => Message::STATUS_SENDING, 'updated_at < ?' => $cutoff]
        );
    }

    /**
     * Seconds to wait before the next attempt.
     *
     * @param int $attempts attempts made so far
     * @param int|null $retryAfter what SMSTunnel asked for
     * @return int
     */
    public function retryDelay(int $attempts, ?int $retryAfter = null): int
    {
        $minutes = self::RETRY_MINUTES[$attempts] ?? 60;

        return max($minutes * 60, (int) $retryAfter);
    }

    /**
     * Record an error: try again later when it can pass, else mark the text failed.
     *
     * @param int $id
     * @param int $attempts attempts made so far, this one included
     * @param ApiException $exception
     * @return string
     */
    private function handleError(int $id, int $attempts, ApiException $exception): string
    {
        $details = $exception->describe();
        if ($exception->isRetryable() && $attempts < self::MAX_ATTEMPTS) {
            $next = $this->dateTime->gmtTimestamp() + $this->retryDelay($attempts, $exception->getRetryAfter());
            $this->resource->getConnection()->update(
                $this->resource->getMainTable(),
                [
                    'status' => Message::STATUS_PENDING,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', $next),
                    'details' => $details,
                    'updated_at' => $this->dateTime->gmtDate(),
                ],
                ['entity_id = ?' => $id]
            );
            $this->logger->info(sprintf('SMSTunnel: text %d will be tried again (%s)', $id, $details));

            return $exception->getStatusCode() === 429 ? self::RESULT_RATE_LIMITED : self::RESULT_RETRY;
        }

        $this->finish($id, Message::STATUS_FAILED, $details);
        $this->logger->warning(sprintf('SMSTunnel: text %d failed (%s)', $id, $details));

        return self::RESULT_FAILED;
    }

    /**
     * Mark a text failed or skipped, for good.
     *
     * @param int $id
     * @param string $status
     * @param string $details
     * @return void
     */
    private function finish(int $id, string $status, string $details): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            [
                'status' => $status,
                'next_attempt_at' => null,
                'details' => $details,
                'updated_at' => $this->dateTime->gmtDate(),
            ],
            ['entity_id = ?' => $id]
        );
    }
}
