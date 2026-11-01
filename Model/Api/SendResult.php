<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Api;

/**
 * SMSTunnel accepted the text for the phone.
 */
class SendResult
{
    /**
     * @var string
     */
    private $messageId;

    /**
     * @var bool
     */
    private $queued;

    /**
     * @param string $messageId
     * @param bool $queued
     */
    public function __construct(string $messageId, bool $queued)
    {
        $this->messageId = $messageId;
        $this->queued = $queued;
    }

    /**
     * SMSTunnel's ID of the text.
     *
     * @return string
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * True when the phone was offline: it sends the text as soon as it is back online.
     *
     * @return bool
     */
    public function isQueued(): bool
    {
        return $this->queued;
    }
}
