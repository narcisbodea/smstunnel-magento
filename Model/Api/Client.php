<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Hands texts to an SMSTunnel account (POST /api/v1/sms/send); the account's phone sends them from its own SIM.
 *
 * Built on Magento's own HTTP client, so the module has no dependency outside Magento. The calls and the error
 * handling follow the SMSTunnel PHP client (smstunnel/smstunnel-php).
 */
class Client
{
    public const VERSION = '1.0.0';

    private const SEND_PATH = '/api/v1/sms/send';
    private const TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 5;

    /**
     * Hosts that may be reached over plain http: a test server on the same machine or network.
     */
    private const LOCAL_HOST_PATTERN = '/^(localhost|127\.\d+\.\d+\.\d+|\[::1\]|host\.docker\.internal'
        . '|[a-z0-9.-]+\.(?:local|localhost|test)|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+'
        . '|172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+)$/i';

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param CurlFactory $curlFactory
     * @param Json $json
     */
    public function __construct(CurlFactory $curlFactory, Json $json)
    {
        $this->curlFactory = $curlFactory;
        $this->json = $json;
    }

    /**
     * Send one text to one number.
     *
     * @param string $serverUrl e.g. https://smstunnel.io
     * @param string $apiKey
     * @param string $to the number in international format, e.g. +14155550123
     * @param string $message up to 1600 characters
     * @param string|null $deviceId one of the account's phones, or null to let SMSTunnel pick one
     * @return SendResult
     * @throws ApiException when SMSTunnel refuses the text or cannot be reached
     */
    public function send(string $serverUrl, string $apiKey, string $to, string $message, ?string $deviceId): SendResult
    {
        $body = ['to' => $to, 'message' => $message];
        if ($deviceId !== null && $deviceId !== '') {
            $body['deviceId'] = $deviceId;
        }
        $answer = $this->post($serverUrl, self::SEND_PATH, $apiKey, $body);

        $data = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : [];
        $messageId = $answer['messageId'] ?? $data['messageId'] ?? null;
        if (!is_string($messageId) || $messageId === '') {
            throw new ApiException('SMSTunnel accepted the request but sent no message ID back.', 200);
        }

        return new SendResult($messageId, (bool) ($answer['queued'] ?? $data['queued'] ?? false));
    }

    /**
     * POST a JSON body and return the decoded answer.
     *
     * @param string $serverUrl
     * @param string $path
     * @param string $apiKey
     * @param array<string,string> $body
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function post(string $serverUrl, string $path, string $apiKey, array $body): array
    {
        $url = $this->checkServerUrl($serverUrl) . $path;

        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setOptions([CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_FOLLOWLOCATION => false]);
        $curl->setHeaders([
            'X-API-Key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'smstunnel-magento/' . self::VERSION,
            // Long texts would otherwise make curl wait for "100 Continue", which Magento's client misreads.
            'Expect' => '',
        ]);

        try {
            $curl->post($url, (string) $this->json->serialize($body));
        } catch (\Exception $exception) {
            throw new ApiException('Could not reach SMSTunnel: ' . $exception->getMessage(), 0, null, null, $exception);
        }

        $status = (int) $curl->getStatus();
        $raw = (string) $curl->getBody();
        $decoded = $this->decode($raw);

        if ($status < 200 || $status >= 300) {
            throw $this->error($status, $decoded ?? $raw, $this->retryAfter($curl->getHeaders()));
        }
        if (!is_array($decoded)) {
            throw new ApiException('SMSTunnel answered with something that is not JSON.', $status);
        }
        if (($decoded['success'] ?? null) === false) {
            throw $this->error($status, $decoded, null);
        }

        return $decoded;
    }

    /**
     * The server URL without a trailing slash; plain http only for a local test server.
     *
     * @param string $serverUrl
     * @return string
     * @throws ApiException
     */
    private function checkServerUrl(string $serverUrl): string
    {
        $serverUrl = rtrim(trim($serverUrl), '/');
        if (preg_match('~^(https?)://([^/:?#\s]+|\[[0-9a-f:]+\])(:\d+)?(/[^\s?#]*)?$~i', $serverUrl, $parts) !== 1) {
            throw new ApiException(sprintf('The SMSTunnel server URL "%s" is not valid.', $serverUrl), 0);
        }
        if (strtolower($parts[1]) === 'http' && preg_match(self::LOCAL_HOST_PATTERN, $parts[2]) !== 1) {
            throw new ApiException('The SMSTunnel server URL must start with https://.', 0);
        }

        return $serverUrl;
    }

    /**
     * The exception for an error answer.
     *
     * @param int $status
     * @param mixed $body the decoded JSON body, or the raw text when it was not JSON
     * @param int|null $retryAfter
     * @return ApiException
     */
    private function error(int $status, $body, ?int $retryAfter): ApiException
    {
        $errorCode = is_array($body) && isset($body['code']) && is_string($body['code']) ? $body['code'] : null;

        return new ApiException($this->messageOf($body, $status), $status, $errorCode, $retryAfter);
    }

    /**
     * The words of an error body, whatever its shape.
     *
     * @param mixed $body
     * @param int $status
     * @return string
     */
    private function messageOf($body, int $status): string
    {
        if (is_string($body) && trim($body) !== '') {
            // A proxy's error page, say: keep the first 300 characters of its text.
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
            if ($text !== '') {
                return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
            }
        }
        if (is_array($body)) {
            $message = $body['message'] ?? null;
            if (is_array($message)) {
                $message = implode('; ', array_filter($message, 'is_string'));
            }
            if (is_string($message) && $message !== '') {
                return $message;
            }
            if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                return $body['error'];
            }
        }

        return sprintf('SMSTunnel returned error: %d', $status);
    }

    /**
     * The JSON body as an array, or null when it is not JSON.
     *
     * @param string $raw
     * @return array<string,mixed>|null
     */
    private function decode(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Seconds from a Retry-After header, when SMSTunnel sends one.
     *
     * @param array<string,mixed> $headers
     * @return int|null
     */
    private function retryAfter(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after'
                && is_scalar($value)
                && is_numeric(trim((string) $value))
            ) {
                return max(0, (int) trim((string) $value));
            }
        }

        return null;
    }
}
