<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;
use SmsTunnel\SmsNotifications\Model\OrderTexts;

/**
 * Event sales_order_invoice_save_commit_after.
 */
class InvoiceSaved implements ObserverInterface
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
     * Queue the "invoice created" text for a new invoice. Never throws.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $invoice = $observer->getEvent()->getData('invoice');
            if ($invoice instanceof Invoice) {
                $this->orderTexts->invoiceCreated($invoice);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('SMSTunnel: could not queue the invoice text: ' . $exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
