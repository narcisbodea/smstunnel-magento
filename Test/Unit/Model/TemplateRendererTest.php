<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use SmsTunnel\SmsNotifications\Model\TemplateRenderer;

class TemplateRendererTest extends TestCase
{
    /**
     * @var TemplateRenderer
     */
    private $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testFillsPlaceholders(): void
    {
        $text = $this->renderer->render(
            'Hi {{customer_firstname}}, order #{{ order_id }} ({{grand_total}}) at {{store_name}}.',
            [
                'customer_firstname' => 'Ana',
                'order_id' => '000000042',
                'grand_total' => '$25.00',
                'store_name' => 'Shop',
            ]
        );

        self::assertSame('Hi Ana, order #000000042 ($25.00) at Shop.', $text);
    }

    public function testKeepsUnknownPlaceholders(): void
    {
        self::assertSame(
            'Order {{order_number}} is ready.',
            $this->renderer->render('Order {{order_number}} is ready.', ['order_id' => '1'])
        );
    }

    public function testDropsTrackingLinesWithoutTrackingNumber(): void
    {
        $template = "Your order #{{order_id}} has shipped.\nTracking number: {{tracking_number}}\n"
            . "Carrier: {{carrier}}\nThanks!";

        self::assertSame(
            "Your order #7 has shipped.\nThanks!",
            $this->renderer->render($template, ['order_id' => '7', 'tracking_number' => '', 'carrier' => ''])
        );
        self::assertSame(
            "Your order #7 has shipped.\nTracking number: 1Z999\nCarrier: UPS\nThanks!",
            $this->renderer->render($template, ['order_id' => '7', 'tracking_number' => '1Z999', 'carrier' => 'UPS'])
        );
    }

    public function testTidiesEmptyValues(): void
    {
        self::assertSame(
            'New order from Ana: $5.00.',
            $this->renderer->render(
                'New order from {{customer_firstname}} {{customer_lastname}}: {{grand_total}}.',
                ['customer_firstname' => 'Ana', 'customer_lastname' => '', 'grand_total' => '$5.00']
            )
        );
        self::assertSame("Line one\n\nLine two", $this->renderer->render("Line one\n\n\n\nLine two  ", []));
    }

    public function testKeepsUnicode(): void
    {
        self::assertSame(
            'Comanda #9 a fost expediată, Ștefan!',
            $this->renderer->render(
                'Comanda #{{order_id}} a fost expediată, {{customer_firstname}}!',
                ['order_id' => '9', 'customer_firstname' => 'Ștefan']
            )
        );
    }
}
