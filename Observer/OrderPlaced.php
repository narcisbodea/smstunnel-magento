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
 * Events sales_model_service_quote_submit_success and checkout_submit_all_after (also multishipping).
 */
class OrderPlaced implements ObserverInterface
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
     * Queue the new-order texts. Never throws, so checkout never fails because of a text.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $event = $observer->getEvent();
            $orders = $event->getData('orders');
            $orders = is_array($orders) ? $orders : [];
            $orders[] = $event->getData('order');
            foreach ($orders as $order) {
                if ($order instanceof Order) {
                    $this->orderTexts->orderPlaced($order);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: could not queue the new-order texts: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
