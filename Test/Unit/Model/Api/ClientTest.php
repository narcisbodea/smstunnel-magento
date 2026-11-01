<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Test\Unit\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use SmsTunnel\SmsNotifications\Model\Api\ApiException;
use SmsTunnel\SmsNotifications\Model\Api\Client;

class ClientTest extends TestCase
{
    /**
     * What the client handed to curl.
     *
     * @var array<string, mixed>
     */
    private $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];
    }

    public function testSendsOneTextWithTheKeyInTheHeader(): void
    {
        $client = $this->client(200, '{"success":true,"messageId":"m-1","data":{"messageId":"m-1","branded":false}}');

        $result = $client->send('https://smstunnel.io/', 'sk_test_123', '+14155550123', 'Hi Ana', 'phone-1');

        self::assertSame('m-1', $result->getMessageId());
        self::assertFalse($result->isQueued());
        self::assertSame('https://smstunnel.io/api/v1/sms/send', $this->sent['url']);
        self::assertSame(
            ['to' => '+14155550123', 'message' => 'Hi Ana', 'deviceId' => 'phone-1'],
            json_decode($this->sent['body'], true)
        );
        self::assertSame('sk_test_123', $this->sent['headers']['X-API-Key']);
        self::assertSame('application/json', $this->sent['headers']['Content-Type']);
        self::assertSame('', $this->sent['headers']['Expect']);
        self::assertFalse($this->sent['options'][CURLOPT_FOLLOWLOCATION]);
        self::assertSame(15, $this->sent['timeout']);
    }

    public function testLeavesOutAnEmptyPhoneIdAndReadsQueued(): void
    {
        $client = $this->client(201, '{"success":true,"data":{"messageId":"m-2","queued":true}}');

        $result = $client->send('https://smstunnel.io', 'sk', '+14155550123', 'Hi', null);

        self::assertSame('m-2', $result->getMessageId());
        self::assertTrue($result->isQueued());
        self::assertSame(['to' => '+14155550123', 'message' => 'Hi'], json_decode($this->sent['body'], true));
    }

    public function testBadKey(): void
    {
        $exception = $this->sendExpectingError(
            $this->client(401, '{"message":"Invalid or expired API key","error":"Unauthorized","statusCode":401}')
        );

        self::assertSame(401, $exception->getStatusCode());
        self::assertSame('Invalid or expired API key', $exception->getMessage());
        self::assertFalse($exception->isRetryable());
        self::assertSame('SMSTunnel answered 401: Invalid or expired API key', $exception->describe());
    }

    public function testPermissionCode(): void
    {
        $exception = $this->sendExpectingError(
            $this->client(403, '{"message":["Needs sms:send"],"code":"PERMISSION_REQUIRED","permission":"sms:send"}')
        );

        self::assertSame(403, $exception->getStatusCode());
        self::assertSame('PERMISSION_REQUIRED', $exception->getErrorCode());
        self::assertSame('Needs sms:send', $exception->getMessage());
        self::assertFalse($exception->isRetryable());
        self::assertSame('SMSTunnel answered 403 PERMISSION_REQUIRED: Needs sms:send', $exception->describe());
    }

    public function testRateLimitIsRetriedAfterTheServersDelay(): void
    {
        $exception = $this->sendExpectingError(
            $this->client(429, '{"message":"Too many requests"}', ['Retry-After' => '42'])
        );

        self::assertTrue($exception->isRetryable());
        self::assertSame(42, $exception->getRetryAfter());
    }

    public function testServerErrorPageIsRetried(): void
    {
        $exception = $this->sendExpectingError(
            $this->client(503, '<html><body><h1>503 Service Unavailable</h1></body></html>')
        );

        self::assertTrue($exception->isRetryable());
        self::assertSame('503 Service Unavailable', $exception->getMessage());
    }

    public function testUnreachableServerIsRetried(): void
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('post')->willThrowException(new \Exception('Failed to connect to smstunnel.io port 443'));
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        $exception = $this->sendExpectingError(new Client($factory, new Json()));

        self::assertSame(0, $exception->getStatusCode());
        self::assertTrue($exception->isRetryable());
        self::assertStringContainsString('Could not reach SMSTunnel', $exception->getMessage());
    }

    public function testSuccessFalseIsAnError(): void
    {
        $exception = $this->sendExpectingError(
            $this->client(200, '{"success":false,"message":"No phone is linked to this account"}')
        );

        self::assertSame('No phone is linked to this account', $exception->getMessage());
    }

    public function testAnswerThatIsNotJson(): void
    {
        $exception = $this->sendExpectingError($this->client(200, 'OK'));

        self::assertStringContainsString('not JSON', $exception->getMessage());
    }

    public function testRefusesPlainHttpToARemoteHost(): void
    {
        $exception = $this->sendExpectingError($this->client(200, '{}'), 'http://smstunnel.io');

        self::assertStringContainsString('https://', $exception->getMessage());
        self::assertArrayNotHasKey('url', $this->sent);
    }

    public function testAllowsPlainHttpToALocalTestServer(): void
    {
        $client = $this->client(200, '{"success":true,"messageId":"m-3"}');

        $result = $client->send('http://host.docker.internal:3101', 'sk', '+14155550123', 'Hi', null);

        self::assertSame('m-3', $result->getMessageId());
        self::assertSame('http://host.docker.internal:3101/api/v1/sms/send', $this->sent['url']);
    }

    /**
     * A client whose curl records the request and gives this answer.
     *
     * @param int $status
     * @param string $body
     * @param array<string, string> $headers
     * @return Client
     */
    private function client(int $status, string $body, array $headers = []): Client
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('setHeaders')->willReturnCallback(function ($value): void {
            $this->sent['headers'] = $value;
        });
        $curl->method('setTimeout')->willReturnCallback(function ($value): void {
            $this->sent['timeout'] = $value;
        });
        $curl->method('setOptions')->willReturnCallback(function ($options): void {
            $this->sent['options'] = $options;
        });
        $curl->method('post')->willReturnCallback(function ($url, $body): void {
            $this->sent['url'] = $url;
            $this->sent['body'] = $body;
        });
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        $curl->method('getHeaders')->willReturn($headers);
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        return new Client($factory, new Json());
    }

    /**
     * @param Client $client
     * @param string $serverUrl
     * @return ApiException
     */
    private function sendExpectingError(Client $client, string $serverUrl = 'https://smstunnel.io'): ApiException
    {
        try {
            $client->send($serverUrl, 'sk', '+14155550123', 'Hi', null);
        } catch (ApiException $exception) {
            return $exception;
        }
        self::fail('An ApiException was expected.');
    }
}
