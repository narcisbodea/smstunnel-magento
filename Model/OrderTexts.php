<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

use Magento\Framework\Math\Random;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\StatusFactory;

/**
 * Decides which texts an order event gets, fills their templates and queues them.
 */
class OrderTexts
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Outbox
     */
    private $outbox;

    /**
     * @var PhoneNumber
     */
    private $phoneNumber;

    /**
     * @var TemplateRenderer
     */
    private $renderer;

    /**
     * @var StatusFactory
     */
    private $statusFactory;

    /**
     * @var Random
     */
    private $random;

    /**
     * Status changes handled in this request ("order ID:status"), for an order saved twice.
     *
     * @var array<string, true>
     */
    private $handledStatusChanges = [];

    /**
     * @param Config $config
     * @param Outbox $outbox
     * @param PhoneNumber $phoneNumber
     * @param TemplateRenderer $renderer
     * @param StatusFactory $statusFactory
     * @param Random $random
     */
    public function __construct(
        Config $config,
        Outbox $outbox,
        PhoneNumber $phoneNumber,
        TemplateRenderer $renderer,
        StatusFactory $statusFactory,
        Random $random
    ) {
        $this->config = $config;
        $this->outbox = $outbox;
        $this->phoneNumber = $phoneNumber;
        $this->renderer = $renderer;
        $this->statusFactory = $statusFactory;
        $this->random = $random;
    }

    /**
     * A new order: "order placed" to the customer and "new order" to the owner.
     *
     * An order waiting for a payment on another site (state pending_payment) gets them when the payment goes
     * through, see orderSaved().
     *
     * @param Order $order
     * @return void
     */
    public function orderPlaced(Order $order): void
    {
        $storeId = (int) $order->getStoreId();
        if ($order->getId() === null || !$this->config->isEnabled($storeId)) {
            return;
        }
        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            return;
        }
        $this->queueNewOrderTexts($order, $storeId);
    }

    /**
     * An existing order was saved: canceled, a status change, or a payment finished elsewhere.
     *
     * @param Order $order
     * @return void
     */
    public function orderSaved(Order $order): void
    {
        $storeId = (int) $order->getStoreId();
        $oldState = $order->getOrigData('state');
        $oldStatus = $order->getOrigData('status');
        if ($order->getId() === null || ($oldState === null && $oldStatus === null)) {
            // A new order: see orderPlaced().
            return;
        }
        if (!$this->config->isEnabled($storeId)) {
            return;
        }
        $state = (string) $order->getState();
        $status = (string) $order->getStatus();

        if ($oldState === Order::STATE_PENDING_PAYMENT
            && !in_array($state, [Order::STATE_PENDING_PAYMENT, Order::STATE_CANCELED, Order::STATE_CLOSED], true)
        ) {
            $this->queueNewOrderTexts($order, $storeId);
        }

        $canceledTextOn = $this->config->isEventEnabled(Message::EVENT_ORDER_CANCELED, $storeId);
        if ($state === Order::STATE_CANCELED && $oldState !== Order::STATE_CANCELED) {
            // An order whose payment was never finished was never confirmed to the customer either.
            if ($canceledTextOn && $oldState !== Order::STATE_PENDING_PAYMENT) {
                $this->queueCustomerText($order, Message::EVENT_ORDER_CANCELED, 'order_canceled:' . $order->getId());
            }
        }

        if ($status !== ''
            && $status !== (string) $oldStatus
            && !($state === Order::STATE_CANCELED && $canceledTextOn)
            && $this->config->isEventEnabled(Message::EVENT_STATUS_CHANGED, $storeId)
            && in_array($status, $this->config->getStatusChangeStatuses($storeId), true)
        ) {
            $guard = $order->getId() . ':' . $status;
            if (!isset($this->handledStatusChanges[$guard])) {
                $this->handledStatusChanges[$guard] = true;
                $this->queueCustomerText(
                    $order,
                    Message::EVENT_STATUS_CHANGED,
                    'status_changed:' . $order->getId() . ':' . $status . ':' . $this->random->getUniqueHash()
                );
            }
        }
    }

    /**
     * A new invoice.
     *
     * @param Invoice $invoice
     * @return void
     */
    public function invoiceCreated(Invoice $invoice): void
    {
        if ($invoice->getId() === null || $invoice->getOrigData('entity_id') !== null) {
            return;
        }
        $order = $invoice->getOrder();
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isEnabled($storeId)
            || !$this->config->isEventEnabled(Message::EVENT_INVOICE_CREATED, $storeId)
        ) {
            return;
        }
        $this->queueCustomerText($order, Message::EVENT_INVOICE_CREATED, 'invoice_created:' . $invoice->getId());
    }

    /**
     * A new shipment, with its tracking numbers.
     *
     * @param Shipment $shipment
     * @return void
     */
    public function shipmentCreated(Shipment $shipment): void
    {
        if ($shipment->getId() === null || $shipment->getOrigData('entity_id') !== null) {
            return;
        }
        $order = $shipment->getOrder();
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isEnabled($storeId)
            || !$this->config->isEventEnabled(Message::EVENT_SHIPMENT_CREATED, $storeId)
        ) {
            return;
        }
        $numbers = [];
        $carriers = [];
        foreach ($shipment->getAllTracks() as $track) {
            $number = trim((string) $track->getTrackNumber());
            if ($number !== '') {
                $numbers[] = $number;
                $carrier = trim((string) $track->getTitle());
                if ($carrier !== '') {
                    $carriers[] = $carrier;
                }
            }
        }
        $this->queueCustomerText(
            $order,
            Message::EVENT_SHIPMENT_CREATED,
            'shipment_created:' . $shipment->getId(),
            [
                'tracking_number' => implode(', ', array_unique($numbers)),
                'carrier' => implode(', ', array_unique($carriers)),
            ]
        );
    }

    /**
     * "Order placed" to the customer and "new order" to each of the owner's numbers.
     *
     * @param Order $order
     * @param int $storeId
     * @return void
     */
    private function queueNewOrderTexts(Order $order, int $storeId): void
    {
        if ($this->config->isEventEnabled(Message::EVENT_ORDER_PLACED, $storeId)) {
            $this->queueCustomerText($order, Message::EVENT_ORDER_PLACED, 'order_placed:' . $order->getId());
        }
        if (!$this->config->isEventEnabled(Message::EVENT_OWNER_NEW_ORDER, $storeId)) {
            return;
        }
        $text = $this->renderer->render(
            $this->config->getTemplate(Message::EVENT_OWNER_NEW_ORDER, $storeId),
            $this->placeholderValues($order)
        );
        foreach ($this->config->getOwnerNumbers($storeId) as $raw) {
            $number = $this->phoneNumber->normalize(
                $raw,
                null,
                $this->config->getDefaultCallingCode($storeId),
                $this->config->getStoreCountry($storeId)
            );
            $this->outbox->add(
                Message::EVENT_OWNER_NEW_ORDER,
                'owner_new_order:' . $order->getId() . ':' . preg_replace('/\D+/', '', $raw),
                $storeId,
                $raw,
                $number,
                $text,
                $order
            );
        }
    }

    /**
     * Queue a text to the number on the order: the shipping address's, else the billing address's.
     *
     * @param Order $order
     * @param string $event
     * @param string $dedupeKey
     * @param array<string,string> $extraValues
     * @return void
     */
    private function queueCustomerText(Order $order, string $event, string $dedupeKey, array $extraValues = []): void
    {
        $storeId = (int) $order->getStoreId();
        $address = $this->addressWithPhone($order);
        $raw = $address !== null ? trim((string) $address->getTelephone()) : '';
        $number = $this->phoneNumber->normalize(
            $raw,
            $address !== null ? (string) $address->getCountryId() : null,
            $this->config->getDefaultCallingCode($storeId),
            $this->config->getStoreCountry($storeId)
        );
        $text = $this->renderer->render(
            $this->config->getTemplate($event, $storeId),
            array_merge($this->placeholderValues($order), $extraValues)
        );
        $this->outbox->add($event, $dedupeKey, $storeId, $raw, $number, $text, $order);
    }

    /**
     * The address whose telephone gets the customer's texts.
     *
     * @param Order $order
     * @return Address|null
     */
    private function addressWithPhone(Order $order): ?Address
    {
        foreach ([$order->getShippingAddress(), $order->getBillingAddress()] as $address) {
            if ($address instanceof Address && trim((string) $address->getTelephone()) !== '') {
                return $address;
            }
        }

        return null;
    }

    /**
     * Values for the placeholders every text can use.
     *
     * @param Order $order
     * @return array<string,string>
     */
    private function placeholderValues(Order $order): array
    {
        $firstName = trim((string) $order->getCustomerFirstname());
        $lastName = trim((string) $order->getCustomerLastname());
        $address = $order->getBillingAddress() ?: $order->getShippingAddress();
        if ($address instanceof Address) {
            $firstName = $firstName !== '' ? $firstName : trim((string) $address->getFirstname());
            $lastName = $lastName !== '' ? $lastName : trim((string) $address->getLastname());
        }

        return [
            'order_id' => (string) $order->getIncrementId(),
            'customer_firstname' => $firstName,
            'customer_lastname' => $lastName,
            'grand_total' => (string) $order->formatPriceTxt((float) $order->getGrandTotal()),
            'store_name' => (string) $order->getStore()->getFrontendName(),
            'status' => $this->statusLabel($order),
            'tracking_number' => '',
            'carrier' => '',
        ];
    }

    /**
     * The order status as the store view shows it to customers.
     *
     * @param Order $order
     * @return string
     */
    private function statusLabel(Order $order): string
    {
        $code = (string) $order->getStatus();
        if ($code === '') {
            return '';
        }
        $status = $this->statusFactory->create()->load($code);
        $label = $status->getData('status') !== null ? trim((string) $status->getStoreLabel($order->getStoreId())) : '';

        return $label !== '' ? $label : $code;
    }
}
