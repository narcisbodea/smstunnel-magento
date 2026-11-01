<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Test\Unit\Model;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\Api\ApiException;
use SmsTunnel\SmsNotifications\Model\Api\Client;
use SmsTunnel\SmsNotifications\Model\Api\SendResult;
use SmsTunnel\SmsNotifications\Model\Config;
use SmsTunnel\SmsNotifications\Model\Message;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;
use SmsTunnel\SmsNotifications\Model\Sender;

class SenderTest extends TestCase
{
    private const NOW = 1793534400; // 2026-11-01 12:00:00 UTC

    /**
     * @var array<int, array<string, mixed>>
     */
    private $updates = [];

    /**
     * @var int
     */
    private $claimResult = 1;

    /**
     * @var array<int, array<int, mixed>>
     */
    private $clientCalls = [];

    /**
     * @var SendResult|ApiException
     */
    private $clientAnswer;

    /**
     * @var array<string, mixed>
     */
    private $row = [
        'entity_id' => '5',
        'store_id' => '1',
        'event' => Message::EVENT_ORDER_PLACED,
        'recipient' => '+14155550123',
        'message' => 'Thanks for your order',
        'attempts' => '1',
    ];

    protected function setUp(): void
    {
        $this->updates = [];
        $this->clientCalls = [];
        $this->claimResult = 1;
        $this->clientAnswer = new SendResult('m-1', false);
    }

    public function testClaimsSendsAndRecords(): void
    {
        self::assertSame(Sender::RESULT_SENT, $this->sender()->send(5));

        self::assertSame(
            [['http://127.0.0.1:3101', 'sk_test', '+14155550123', 'Thanks for your order', null]],
            $this->clientCalls
        );
        self::assertSame(Message::STATUS_SENDING, $this->updates[0]['status']);
        self::assertSame(Message::STATUS_SENT, $this->updates[1]['status']);
        self::assertSame('m-1', $this->updates[1]['smstunnel_message_id']);
        self::assertNull($this->updates[1]['details']);
    }

    public function testNotesAnOfflinePhone(): void
    {
        $this->clientAnswer = new SendResult('m-2', true);

        self::assertSame(Sender::RESULT_SENT, $this->sender()->send(5));
        self::assertStringContainsString('offline', (string) $this->updates[1]['details']);
    }

    public function testDoesNothingWhenAnotherWorkerHasTheText(): void
    {
        $this->claimResult = 0;

        self::assertSame(Sender::RESULT_NOT_DUE, $this->sender()->send(5));
        self::assertCount(1, $this->updates);
        self::assertSame([], $this->clientCalls);
    }

    public function testRetriesLaterWhenSmsTunnelCannotBeReached(): void
    {
        $this->clientAnswer = new ApiException('Could not reach SMSTunnel: refused', 0);

        self::assertSame(Sender::RESULT_RETRY, $this->sender()->send(5));
        self::assertSame(Message::STATUS_PENDING, $this->updates[1]['status']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW + 60), $this->updates[1]['next_attempt_at']);
        self::assertSame('Could not reach SMSTunnel: refused', $this->updates[1]['details']);
    }

    public function testRateLimitWaitsAsLongAsSmsTunnelAsks(): void
    {
        $this->clientAnswer = new ApiException('Too many requests', 429, null, 600);

        self::assertSame(Sender::RESULT_RATE_LIMITED, $this->sender()->send(5));
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW + 600), $this->updates[1]['next_attempt_at']);
    }

    public function testFailsAtOnceOnABadKey(): void
    {
        $this->clientAnswer = new ApiException('Invalid or expired API key', 401);

        self::assertSame(Sender::RESULT_FAILED, $this->sender()->send(5));
        self::assertSame(Message::STATUS_FAILED, $this->updates[1]['status']);
        self::assertSame('SMSTunnel answered 401: Invalid or expired API key', $this->updates[1]['details']);
    }

    public function testGivesUpAfterTheLastAttempt(): void
    {
        $this->row['attempts'] = (string) Sender::MAX_ATTEMPTS;
        $this->clientAnswer = new ApiException('Bad gateway', 502);

        self::assertSame(Sender::RESULT_FAILED, $this->sender()->send(5));
        self::assertSame(Message::STATUS_FAILED, $this->updates[1]['status']);
    }

    public function testSkipsWhenTextsWereTurnedOff(): void
    {
        self::assertSame(Sender::RESULT_SKIPPED, $this->sender(false)->send(5));
        self::assertSame(Message::STATUS_SKIPPED, $this->updates[1]['status']);
        self::assertSame([], $this->clientCalls);
    }

    public function testTheTestTextGoesOutWhileTextsAreOff(): void
    {
        $this->row['event'] = Message::EVENT_TEST;

        self::assertSame(Sender::RESULT_SENT, $this->sender(false)->send(5));
        self::assertCount(1, $this->clientCalls);
    }

    public function testRetryDelays(): void
    {
        $sender = $this->sender();

        self::assertSame(60, $sender->retryDelay(1));
        self::assertSame(300, $sender->retryDelay(2));
        self::assertSame(900, $sender->retryDelay(3));
        self::assertSame(3600, $sender->retryDelay(4));
        self::assertSame(3600, $sender->retryDelay(9));
        self::assertSame(120, $sender->retryDelay(1, 120));
        self::assertSame(300, $sender->retryDelay(2, 10));
    }

    /**
     * A Sender over stubs that record what it writes and sends.
     *
     * @param bool $enabled
     * @return Sender
     */
    private function sender(bool $enabled = true): Sender
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getApiKey')->willReturn('sk_test');
        $config->method('getServerUrl')->willReturn('http://127.0.0.1:3101');
        $config->method('getDeviceId')->willReturn(null);

        $client = $this->createStub(Client::class);
        $client->method('send')->willReturnCallback(function (...$arguments) {
            $this->clientCalls[] = $arguments;
            if ($this->clientAnswer instanceof ApiException) {
                throw $this->clientAnswer;
            }

            return $this->clientAnswer;
        });

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(function () {
            return $this->row;
        });
        $connection->method('update')->willReturnCallback(function ($table, array $bind) {
            $this->updates[] = $bind;

            return count($this->updates) === 1 ? $this->claimResult : 1;
        });
        $resource = $this->createStub(MessageResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('smstunnel_message');

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(gmdate('Y-m-d H:i:s', self::NOW));
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        return new Sender($resource, $config, $client, $dateTime, $this->createStub(LoggerInterface::class));
    }
}
