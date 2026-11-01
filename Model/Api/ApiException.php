<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Api;

/**
 * SMSTunnel refused a text, or could not be reached (status 0).
 *
 * 401: the API key is wrong or was deleted. 403: the key lacks a permission (code PERMISSION_REQUIRED) or the plan
 * does not include the feature. 429: too many requests. 5xx: a problem on SMSTunnel's side.
 */
class ApiException extends \RuntimeException
{
    /**
     * @var int
     */
    private $statusCode;

    /**
     * @var string|null
     */
    private $errorCode;

    /**
     * @var int|null
     */
    private $retryAfter;

    /**
     * @param string $message SMSTunnel's own words, or why it could not be reached
     * @param int $statusCode the HTTP status, 0 when SMSTunnel could not be reached
     * @param string|null $errorCode SMSTunnel's code, such as PERMISSION_REQUIRED
     * @param int|null $retryAfter seconds, from a Retry-After header
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $statusCode,
        ?string $errorCode = null,
        ?int $retryAfter = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->retryAfter = $retryAfter;
    }

    /**
     * The HTTP status, 0 when SMSTunnel could not be reached.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * SMSTunnel's error code, when it sends one.
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Seconds to wait before the next try, when SMSTunnel says.
     *
     * @return int|null
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Whether trying again later can work: no connection, a timeout, too many requests, a server error.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->statusCode === 0
            || $this->statusCode === 408
            || $this->statusCode === 425
            || $this->statusCode === 429
            || $this->statusCode >= 500;
    }

    /**
     * One line for the log of sent texts: the status and SMSTunnel's own words.
     *
     * @return string
     */
    public function describe(): string
    {
        if ($this->statusCode === 0) {
            return $this->getMessage();
        }
        $code = $this->errorCode !== null ? ' ' . $this->errorCode : '';

        return sprintf('SMSTunnel answered %d%s: %s', $this->statusCode, $code, $this->getMessage());
    }
}
