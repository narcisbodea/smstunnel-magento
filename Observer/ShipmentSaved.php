<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\OrderTexts;

/**
 * Event sales_order_shipment_save_commit_after.
 */
class ShipmentSaved implements ObserverInterface
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
     * Queue the "shipment created" text for a new shipment. Never throws.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $shipment = $observer->getEvent()->getData('shipment');
            if ($shipment instanceof Shipment) {
                $this->orderTexts->shipmentCreated($shipment);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: could not queue the shipment text: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
