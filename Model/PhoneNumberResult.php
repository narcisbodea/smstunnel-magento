<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

/**
 * A number in international format, or the reason it cannot get a text.
 */
class PhoneNumberResult
{
    /**
     * @var string
     */
    private $number;

    /**
     * @var string
     */
    private $reason;

    /**
     * @param string $number E.164, or '' when the number cannot get texts
     * @param string $reason why it cannot, when $number is ''
     */
    public function __construct(string $number, string $reason = '')
    {
        $this->number = $number;
        $this->reason = $number === '' ? $reason : '';
    }

    /**
     * Whether the number can get texts.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->number !== '';
    }

    /**
     * The number in E.164, or '' when it is not valid.
     *
     * @return string
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * Why the number cannot get texts.
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
