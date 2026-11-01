<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsTunnel\SmsNotifications\Model\CallingCodes;
use SmsTunnel\SmsNotifications\Model\PhoneNumber;

class PhoneNumberTest extends TestCase
{
    /**
     * @var PhoneNumber
     */
    private $phoneNumber;

    protected function setUp(): void
    {
        $this->phoneNumber = new PhoneNumber(new CallingCodes());
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: string}>
     */
    public static function validNumbers(): array
    {
        return [
            'E.164' => ['+14155550123', null, null, null, '+14155550123'],
            'E.164 with spaces and dashes' => ['+1 (415) 555-0123', 'RO', null, null, '+14155550123'],
            'US national' => ['(415) 555-0123', 'US', null, null, '+14155550123'],
            'US with the 1 trunk prefix' => ['1-415-555-0123', 'US', null, null, '+14155550123'],
            'Romania with trunk 0' => ['0722 123 456', 'RO', null, null, '+40722123456'],
            'Romania international without plus' => ['40722123456', 'RO', null, null, '+40722123456'],
            '00 prefix' => ['0040 722 123 456', null, null, null, '+40722123456'],
            'the (0) written after the country code' => ['+40 (0)722 123 456', null, null, null, '+40722123456'],
            'UK mobile' => ['07911 123456', 'GB', null, null, '+447911123456'],
            'Hungary 06 trunk prefix' => ['06 30 123 4567', 'HU', null, null, '+36301234567'],
            'Russia 8 trunk prefix' => ['8 (916) 123-45-67', 'RU', null, null, '+79161234567'],
            'Lithuania old 8 trunk prefix' => ['8 612 34567', 'LT', null, null, '+37061234567'],
            'Italy keeps its digits' => ['392 123 4567', 'IT', null, null, '+393921234567'],
            'Italy international without plus' => ['393921234567', 'IT', null, null, '+393921234567'],
            'Germany international without plus' => ['4915112345678', 'DE', null, null, '+4915112345678'],
            'Germany landline' => ['030 1234567', 'DE', null, null, '+49301234567'],
            'country lowercase' => ['0722123456', 'ro', null, null, '+40722123456'],
            'default calling code from the settings' => ['0722123456', null, '40', null, '+40722123456'],
            'store country when nothing else' => ['0722123456', null, null, 'RO', '+40722123456'],
            'the address country wins over the settings' => ['0151 1234567', 'DE', '40', 'RO', '+491511234567'],
            'unknown code from the settings' => ['0123456789', null, '999', null, '+999123456789'],
        ];
    }

    /**
     * @dataProvider validNumbers
     */
    #[DataProvider('validNumbers')]
    public function testNormalizesNumbers(
        string $raw,
        ?string $country,
        ?string $defaultCode,
        ?string $storeCountry,
        string $expected
    ): void {
        $result = $this->phoneNumber->normalize($raw, $country, $defaultCode, $storeCountry);

        self::assertTrue($result->isValid(), $result->getReason());
        self::assertSame($expected, $result->getNumber());
        self::assertSame('', $result->getReason());
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'empty' => ['', 'US', null, 'no phone number'],
            'letters' => ['call me', 'US', null, 'not a phone number'],
            'two plus signs' => ['+40 722 +123', null, null, 'not a phone number'],
            'too short for the country' => ['12', 'RO', null, 'does not look like a phone number'],
            'no country to complete it with' => ['0722123456', null, null, 'no country code'],
            'short code' => ['+12345', null, null, 'not a valid phone number'],
            'short E.164 number' => ['+4012345', null, null, 'short code or a premium-rate number'],
            'US premium 1-900' => ['+1 900 555 0123', null, null, 'premium-rate'],
            'Romanian premium 090' => ['0900 123 456', 'RO', null, 'premium-rate'],
            'UK premium 09' => ['+44 909 123 4567', null, null, 'premium-rate'],
            'German premium 0900' => ['+49 900 1234567', null, null, 'premium-rate'],
            'satellite' => ['+8816 1234 5678', null, null, 'satellite'],
            'too long' => ['+1234567890123456', null, null, 'not a valid phone number'],
        ];
    }

    /**
     * @dataProvider invalidNumbers
     */
    #[DataProvider('invalidNumbers')]
    public function testRefusesNumbers(string $raw, ?string $country, ?string $defaultCode, string $reason): void
    {
        $result = $this->phoneNumber->normalize($raw, $country, $defaultCode, null);

        self::assertFalse($result->isValid());
        self::assertSame('', $result->getNumber());
        self::assertStringContainsString($reason, $result->getReason());
    }

    public function testCleansCallingCodes(): void
    {
        $codes = new CallingCodes();

        self::assertSame('44', $codes->clean('+44'));
        self::assertSame('44', $codes->clean('0044'));
        self::assertSame('1', $codes->clean(' 1 '));
        self::assertSame('1268', $codes->clean('+1 268'));
        self::assertNull($codes->clean('RO'));
        self::assertNull($codes->clean(''));
        self::assertNull($codes->clean('+01'));
        self::assertNull($codes->clean('+12345'));
    }

    public function testKnowsCountries(): void
    {
        $codes = new CallingCodes();

        self::assertSame('40', $codes->get('RO')[0] ?? null);
        self::assertSame('1', $codes->get('us')[0] ?? null);
        self::assertSame('GB', array_search($codes->getByCallingCode('44'), [
            'GB' => $codes->get('GB'),
        ], true));
        self::assertNull($codes->get('ZZ'));
        self::assertNull($codes->getByCallingCode('999'));
    }
}
