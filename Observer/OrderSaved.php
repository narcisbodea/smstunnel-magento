<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\OrderTexts;

/**
 * Event sales_order_save_commit_after: canceled orders, status changes, payments finished on another site.
 */
class OrderSaved implements ObserverInterface
{
    /**
     * @var OrderTexts
     */
    private $orderTexts;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OrderTexts $orderTexts
     * @param LoggerInterface $logger
     */
    public function __construct(OrderTexts $orderTexts, LoggerInterface $logger)
    {
        $this->orderTexts = $orderTexts;
        $this->logger = $logger;
    }

    /**
     * Queue the texts the change calls for. Never throws.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if ($order instanceof Order) {
                $this->orderTexts->orderSaved($order);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: could not queue the order texts: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
