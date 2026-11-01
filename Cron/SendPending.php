<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Cron;

use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\Sender;

/**
 * Every minute: sends texts the queue consumer did not send yet, and retries the ones that failed for a reason
 * that can pass. SMSTunnel takes 30 texts a minute, so one run sends at most 25.
 */
class SendPending
{
    private const BATCH = 25;

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
     * Run the job.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->sender->releaseStuck();
            foreach ($this->sender->getDueIds(self::BATCH) as $id) {
                if ($this->sender->send($id) === Sender::RESULT_RATE_LIMITED) {
                    break;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: the send job stopped: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
