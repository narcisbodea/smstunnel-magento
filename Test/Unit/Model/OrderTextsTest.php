<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Test\Unit\Model;

use Magento\Framework\Math\Random;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use SmsTunnel\SmsNotifications\Model\CallingCodes;
use SmsTunnel\SmsNotifications\Model\Config;
use SmsTunnel\SmsNotifications\Model\Message;
use SmsTunnel\SmsNotifications\Model\OrderTexts;
use SmsTunnel\SmsNotifications\Model\Outbox;
use SmsTunnel\SmsNotifications\Model\PhoneNumber;
use SmsTunnel\SmsNotifications\Model\TemplateRenderer;

class OrderTextsTest extends TestCase
{
    /**
     * What was handed to the outbox.
     *
     * @var array<int, array<string, mixed>>
     */
    private $queued = [];

    protected function setUp(): void
    {
        $this->queued = [];
    }

    public function testNewOrderTextsTheCustomerAndEachOwnerNumber(): void
    {
        $this->orderTexts()->orderPlaced($this->order());

        self::assertSame([
            [Message::EVENT_ORDER_PLACED, 'order_placed:42', '+14155550123',
                'Hi Ana, thanks for order #000000042 at Luma Shop. Total: $25.00.'],
            [Message::EVENT_OWNER_NEW_ORDER, 'owner_new_order:42:14155550100', '+14155550100',
                'New order #000000042: $25.00 from Ana Pop.'],
            [Message::EVENT_OWNER_NEW_ORDER, 'owner_new_order:42:0722123456', '+40722123456',
                'New order #000000042: $25.00 from Ana Pop.'],
        ], $this->summary());
    }

    public function testNothingWhenTextsAreOff(): void
    {
        $this->orderTexts(['enabled' => false])->orderPlaced($this->order());

        self::assertSame([], $this->queued);
    }

    public function testOnlyTheTextsThatAreOn(): void
    {
        $texts = $this->orderTexts(['events' => [Message::EVENT_OWNER_NEW_ORDER => false]]);
        $texts->orderPlaced($this->order());

        self::assertSame([Message::EVENT_ORDER_PLACED], array_column($this->queued, 'event'));
    }

    public function testWaitsForAPaymentFinishedOnAnotherSite(): void
    {
        $texts = $this->orderTexts();
        $texts->orderPlaced($this->order(['state' => Order::STATE_PENDING_PAYMENT, 'status' => 'pending_payment']));
        self::assertSame([], $this->queued);

        $texts->orderSaved($this->order([
            'state' => Order::STATE_PROCESSING,
            'status' => 'processing',
            'orig' => ['state' => Order::STATE_PENDING_PAYMENT, 'status' => 'pending_payment'],
        ]));
        self::assertSame(
            [Message::EVENT_ORDER_PLACED, Message::EVENT_OWNER_NEW_ORDER, Message::EVENT_OWNER_NEW_ORDER],
            array_column($this->queued, 'event')
        );
    }

    public function testOrderSavedIgnoresNewOrders(): void
    {
        $this->orderTexts()->orderSaved($this->order(['orig' => null]));

        self::assertSame([], $this->queued);
    }

    public function testCanceledOrderGetsOnlyTheCanceledText(): void
    {
        $this->orderTexts()->orderSaved($this->order([
            'state' => Order::STATE_CANCELED,
            'status' => 'canceled',
            'orig' => ['state' => Order::STATE_PROCESSING, 'status' => 'processing'],
        ]));

        self::assertSame([
            [Message::EVENT_ORDER_CANCELED, 'order_canceled:42', '+14155550123',
                'Luma Shop: order #000000042 has been canceled.'],
        ], $this->summary());
    }

    public function testNoCanceledTextWhenThePaymentWasNeverFinished(): void
    {
        $this->orderTexts()->orderSaved($this->order([
            'state' => Order::STATE_CANCELED,
            'status' => 'canceled',
            'orig' => ['state' => Order::STATE_PENDING_PAYMENT, 'status' => 'pending_payment'],
        ]));

        self::assertSame([], $this->queued);
    }

    public function testStatusChangeTextGoesOutOncePerRequest(): void
    {
        $texts = $this->orderTexts();
        $order = $this->order([
            'state' => Order::STATE_HOLDED,
            'status' => 'holded',
            'orig' => ['state' => Order::STATE_PROCESSING, 'status' => 'processing'],
        ]);
        $texts->orderSaved($order);
        $texts->orderSaved($order);

        self::assertSame([
            [Message::EVENT_STATUS_CHANGED, 'status_changed:42:holded:hash', '+14155550123',
                'Luma Shop: order #000000042 is now On Hold.'],
        ], $this->summary());
    }

    public function testNoStatusTextForStatusesNotPicked(): void
    {
        $this->orderTexts()->orderSaved($this->order([
            'state' => Order::STATE_COMPLETE,
            'status' => 'complete',
            'orig' => ['state' => Order::STATE_PROCESSING, 'status' => 'processing'],
        ]));

        self::assertSame([], $this->queued);
    }

    public function testFallsBackToTheBillingPhone(): void
    {
        $this->orderTexts(['events' => [Message::EVENT_OWNER_NEW_ORDER => false]])
            ->orderPlaced($this->order(['phone' => '', 'billing_phone' => '0722 123 456', 'billing_country' => 'RO']));

        self::assertSame('+40722123456', $this->queued[0]['number']);
    }

    public function testAnUnusableNumberIsHandedOverWithTheReason(): void
    {
        $this->orderTexts(['events' => [Message::EVENT_OWNER_NEW_ORDER => false]])
            ->orderPlaced($this->order(['phone' => '+1 900 555 0123']));

        self::assertSame('', $this->queued[0]['number']);
        self::assertStringContainsString('premium-rate', $this->queued[0]['reason']);
    }

    public function testShipmentTextCarriesTheTrackingNumber(): void
    {
        $this->orderTexts()->shipmentCreated($this->shipment([['1Z999AA10123456784', 'United Parcel Service']]));

        self::assertSame([
            [Message::EVENT_SHIPMENT_CREATED, 'shipment_created:9', '+14155550123',
                "Order #000000042 is on its way.\nTracking: 1Z999AA10123456784 (United Parcel Service)"],
        ], $this->summary());
    }

    public function testShipmentWithoutTrackingLeavesOutTheTrackingLine(): void
    {
        $this->orderTexts()->shipmentCreated($this->shipment([]));

        self::assertSame("Order #000000042 is on its way.", $this->queued[0]['text']);
    }

    public function testOnlyNewInvoicesAndShipments(): void
    {
        $texts = $this->orderTexts();
        $texts->invoiceCreated($this->invoice(7));
        $texts->shipmentCreated($this->shipment([], 9));
        self::assertSame([], $this->queued);

        $texts->invoiceCreated($this->invoice(null));
        self::assertSame([
            [Message::EVENT_INVOICE_CREATED, 'invoice_created:7', '+14155550123',
                'Order #000000042 is invoiced: $25.00.'],
        ], $this->summary());
    }

    /**
     * OrderTexts over stub settings, with a real number check and template renderer.
     *
     * @param array<string, mixed> $settings
     * @return OrderTexts
     */
    private function orderTexts(array $settings = []): OrderTexts
    {
        $settings = array_replace_recursive([
            'enabled' => true,
            'events' => [
                Message::EVENT_ORDER_PLACED => true,
                Message::EVENT_OWNER_NEW_ORDER => true,
                Message::EVENT_INVOICE_CREATED => true,
                Message::EVENT_SHIPMENT_CREATED => true,
                Message::EVENT_ORDER_CANCELED => true,
                Message::EVENT_STATUS_CHANGED => true,
            ],
            'templates' => [
                Message::EVENT_ORDER_PLACED =>
                    'Hi {{customer_firstname}}, thanks for order #{{order_id}} at {{store_name}}.'
                    . ' Total: {{grand_total}}.',
                Message::EVENT_OWNER_NEW_ORDER =>
                    'New order #{{order_id}}: {{grand_total}} from {{customer_firstname}} {{customer_lastname}}.',
                Message::EVENT_INVOICE_CREATED => 'Order #{{order_id}} is invoiced: {{grand_total}}.',
                Message::EVENT_SHIPMENT_CREATED =>
                    "Order #{{order_id}} is on its way.\nTracking: {{tracking_number}} ({{carrier}})",
                Message::EVENT_ORDER_CANCELED => '{{store_name}}: order #{{order_id}} has been canceled.',
                Message::EVENT_STATUS_CHANGED => '{{store_name}}: order #{{order_id}} is now {{status}}.',
            ],
        ], $settings);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($settings['enabled']);
        $config->method('isEventEnabled')->willReturnCallback(static function (string $event) use ($settings): bool {
            return (bool) ($settings['events'][$event] ?? false);
        });
        $config->method('getTemplate')->willReturnCallback(static function (string $event) use ($settings): string {
            return (string) ($settings['templates'][$event] ?? '');
        });
        $config->method('getOwnerNumbers')->willReturn(['+1 415 555 0100', '0722 123 456']);
        $config->method('getStatusChangeStatuses')->willReturn(['holded', 'canceled']);
        $config->method('getDefaultCallingCode')->willReturn(null);
        $config->method('getStoreCountry')->willReturn('RO');

        $outbox = $this->createStub(Outbox::class);
        $outbox->method('add')->willReturnCallback(function (...$arguments): int {
            $this->queued[] = [
                'event' => $arguments[0],
                'key' => $arguments[1],
                'number' => $arguments[4]->getNumber(),
                'reason' => $arguments[4]->getReason(),
                'text' => $arguments[5],
            ];

            return count($this->queued);
        });

        $status = $this->createStub(Status::class);
        $status->method('load')->willReturnSelf();
        $status->method('getData')->willReturn('holded');
        $status->method('getStoreLabel')->willReturn('On Hold');
        $statusFactory = $this->createStub(StatusFactory::class);
        $statusFactory->method('create')->willReturn($status);
        $random = $this->createStub(Random::class);
        $random->method('getUniqueHash')->willReturn('hash');

        return new OrderTexts(
            $config,
            $outbox,
            new PhoneNumber(new CallingCodes()),
            new TemplateRenderer(),
            $statusFactory,
            $random
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return Order
     */
    private function order(array $data = []): Order
    {
        $data = array_replace([
            'state' => Order::STATE_NEW,
            'status' => 'pending',
            'orig' => null,
            'phone' => '(415) 555-0123',
            'billing_phone' => '(415) 555-0199',
            'billing_country' => 'US',
        ], $data);

        $shipping = $this->address((string) $data['phone'], 'US');
        $billing = $this->address((string) $data['billing_phone'], (string) $data['billing_country']);
        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn('Luma Shop');

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn($data['state']);
        $order->method('getStatus')->willReturn($data['status']);
        $order->method('getOrigData')->willReturnCallback(static function ($key = null) use ($data) {
            return is_array($data['orig']) ? ($data['orig'][$key] ?? null) : null;
        });
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getCustomerFirstname')->willReturn('Ana');
        $order->method('getCustomerLastname')->willReturn('Pop');
        $order->method('getShippingAddress')->willReturn($shipping);
        $order->method('getBillingAddress')->willReturn($billing);
        $order->method('getGrandTotal')->willReturn(25.0);
        $order->method('formatPriceTxt')->willReturnCallback(static function ($price): string {
            return '$' . number_format((float) $price, 2);
        });
        $order->method('getStore')->willReturn($store);

        return $order;
    }

    /**
     * @param string $phone
     * @param string $country
     * @return Address
     */
    private function address(string $phone, string $country): Address
    {
        $address = $this->createStub(Address::class);
        $address->method('getTelephone')->willReturn($phone);
        $address->method('getCountryId')->willReturn($country);
        $address->method('getFirstname')->willReturn('Ana');
        $address->method('getLastname')->willReturn('Pop');

        return $address;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $tracks number, carrier
     * @param int|null $origId
     * @return Shipment
     */
    private function shipment(array $tracks, ?int $origId = null): Shipment
    {
        $trackStubs = [];
        foreach ($tracks as [$number, $carrier]) {
            $track = $this->createStub(Track::class);
            $track->method('getTrackNumber')->willReturn($number);
            $track->method('getTitle')->willReturn($carrier);
            $trackStubs[] = $track;
        }
        $shipment = $this->createStub(Shipment::class);
        $shipment->method('getId')->willReturn(9);
        $shipment->method('getOrigData')->willReturn($origId);
        $shipment->method('getOrder')->willReturn($this->order());
        $shipment->method('getAllTracks')->willReturn($trackStubs);

        return $shipment;
    }

    /**
     * @param int|null $origId
     * @return Invoice
     */
    private function invoice(?int $origId): Invoice
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getId')->willReturn(7);
        $invoice->method('getOrigData')->willReturn($origId);
        $invoice->method('getOrder')->willReturn($this->order());

        return $invoice;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function summary(): array
    {
        return array_map(static function (array $text): array {
            return [$text['event'], $text['key'], $text['number'], $text['text']];
        }, $this->queued);
    }
}
