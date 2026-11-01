<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model\Queue;

use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\Sender;

/**
 * Consumer "smstunnel.message.send": sends the text whose ID was published.
 *
 * Run by Magento's cron (consumers_runner) or by hand: bin/magento queue:consumers:start smstunnel.message.send
 */
class Consumer
{
    /**
     * @var Sender
     */
    private $sender;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Sender $sender
     * @param LoggerInterface $logger
     */
    public function __construct(Sender $sender, LoggerInterface $logger)
    {
        $this->sender = $sender;
        $this->logger = $logger;
    }

    /**
     * Send one queued text. Never throws: the row keeps the outcome, and the cron job retries.
     *
     * @param string $messageId ID of a row in smstunnel_message
     * @return void
     */
    public function process(string $messageId): void
    {
        try {
            $this->sender->send((int) $messageId);
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: the queue consumer could not send text ' . $messageId, [
                'exception' => $exception,
            ]);
        }
    }
}
