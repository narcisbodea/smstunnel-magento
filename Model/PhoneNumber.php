<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

/**
 * Turns the number a customer typed into international format (E.164, e.g. +14155550123) and refuses numbers
 * texts should not go to: malformed, short codes, premium-rate and satellite numbers.
 *
 * The checks follow smstunnel_sanitize_recipient() of the SMSTunnel WordPress plugin and the SMSTunnel server.
 */
class PhoneNumber
{
    /**
     * Satellite networks the SMSTunnel server refuses.
     */
    private const BLOCKED_PREFIXES = ['+8816', '+8817', '+88216', '+88217', '+870', '+871', '+872', '+873', '+874',
        '+881', '+882'];

    /**
     * Premium-rate prefixes, kept in sync with the WordPress plugin.
     */
    private const PREMIUM_PATTERNS = [
        '/^\+40(90|80)\d+$/',
        '/^\+44(90|91|98)\d+$/',
        '/^\+1900\d+$/',
        '/^\+49(900|137|138)\d+$/',
        '/^\+3389\d+$/',
        '/^\+39(89|166|899)\d+$/',
        '/^\+34(80[3-7]|90[3-7])\d+$/',
    ];

    /**
     * @var CallingCodes
     */
    private $callingCodes;

    /**
     * @param CallingCodes $callingCodes
     */
    public function __construct(CallingCodes $callingCodes)
    {
        $this->callingCodes = $callingCodes;
    }

    /**
     * Normalize and check one number.
     *
     * A number written without "+" or "00" is completed with, in this order: the calling code of the address's
     * country, the default country code from the settings, the calling code of the store's default country.
     *
     * @param string $raw what the customer typed
     * @param string|null $countryId country of the address the number comes from (ISO 3166-1 alpha-2)
     * @param string|null $defaultCallingCode digits, from the settings
     * @param string|null $storeCountryId the store's default country
     * @return PhoneNumberResult
     */
    public function normalize(
        string $raw,
        ?string $countryId = null,
        ?string $defaultCallingCode = null,
        ?string $storeCountryId = null
    ): PhoneNumberResult {
        $raw = trim($raw);
        if ($raw === '') {
            return new PhoneNumberResult('', (string) __('There is no phone number.'));
        }
        // "+40 (0)722 ..." is written in many countries: the "(0)" is not dialed from abroad.
        $cleaned = (string) preg_replace('/\(\s*0\s*\)/', '', $raw);
        $hasPlus = preg_match('/^\(?\s*\+/', $cleaned) === 1;
        if (preg_match('/[^\d\s+().\/\-]/', $cleaned) === 1
            || substr_count($cleaned, '+') > ($hasPlus ? 1 : 0)
        ) {
            return new PhoneNumberResult('', (string) __('"%1" is not a phone number.', $raw));
        }
        $digits = (string) preg_replace('/\D+/', '', $cleaned);
        if ($digits === '') {
            return new PhoneNumberResult('', (string) __('"%1" is not a phone number.', $raw));
        }

        if ($hasPlus) {
            $number = '+' . $digits;
        } elseif (strpos($digits, '00') === 0) {
            $number = '+' . substr($digits, 2);
        } else {
            $country = $this->pickCountry($countryId, $defaultCallingCode, $storeCountryId);
            if ($country === null) {
                return new PhoneNumberResult('', (string) __(
                    '"%1" has no country code, and there is no country to complete it with.',
                    $raw
                ));
            }
            $number = $this->completeNational($digits, $country);
            if ($number === null) {
                return new PhoneNumberResult('', (string) __(
                    '"%1" does not look like a phone number of country code +%2.',
                    $raw,
                    $country[0]
                ));
            }
        }

        if (preg_match('/^\+[1-9]\d{6,14}$/', $number) !== 1) {
            return new PhoneNumberResult('', (string) __('"%1" is not a valid phone number.', $raw));
        }
        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (strpos($number, $prefix) === 0) {
                return new PhoneNumberResult('', (string) __('%1 is a satellite phone number.', $number));
            }
        }
        if ($this->isPremium($number)) {
            return new PhoneNumberResult('', (string) __('%1 is a short code or a premium-rate number.', $number));
        }

        return new PhoneNumberResult($number);
    }

    /**
     * Short codes and premium-rate numbers.
     *
     * @param string $number E.164
     * @return bool
     */
    public function isPremium(string $number): bool
    {
        if (strlen($number) < 10) {
            return true;
        }
        foreach (self::PREMIUM_PATTERNS as $pattern) {
            if (preg_match($pattern, $number) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Country data to complete a local number with.
     *
     * @param string|null $countryId
     * @param string|null $defaultCallingCode
     * @param string|null $storeCountryId
     * @return array{0:string,1:string[],2:int[],3:int[]}|null
     */
    private function pickCountry(?string $countryId, ?string $defaultCallingCode, ?string $storeCountryId): ?array
    {
        if ($countryId !== null && $countryId !== '') {
            $country = $this->callingCodes->get($countryId);
            if ($country !== null) {
                return $country;
            }
        }
        if ($defaultCallingCode !== null && $defaultCallingCode !== '') {
            // Without data for the code, treat it like the SMSTunnel server does: a leading 0 is the trunk prefix.
            return $this->callingCodes->getByCallingCode($defaultCallingCode) ?? [$defaultCallingCode, ['0'], [], []];
        }
        if ($storeCountryId !== null && $storeCountryId !== '') {
            return $this->callingCodes->get($storeCountryId);
        }

        return null;
    }

    /**
     * Complete a number written without "+" or "00".
     *
     * Tried in order: without the trunk prefix (0722 123 456), as written (415 555 0123), and as an international
     * number that only lacks the "+" (40722123456). The first one with a valid length wins, mobile lengths first.
     *
     * @param string $digits
     * @param array{0:string,1:string[],2:int[],3:int[]} $country
     * @return string|null E.164, or null when no reading has a valid length
     */
    private function completeNational(string $digits, array $country): ?string
    {
        [$code, $trunks, $mobileLengths, $allLengths] = $country;

        $candidates = [];
        $hasTrunk = false;
        foreach ($trunks as $trunk) {
            if ($trunk !== '' && strpos($digits, $trunk) === 0) {
                $hasTrunk = true;
                $candidates[] = $code . substr($digits, strlen($trunk));
            }
        }
        if (!$hasTrunk) {
            $candidates[] = $code . $digits;
        }
        if (strpos($digits, $code) === 0) {
            $candidates[] = $digits;
        }

        if ($mobileLengths === [] && $allLengths === []) {
            return '+' . $candidates[0];
        }
        foreach ([$mobileLengths, $allLengths] as $lengths) {
            foreach ($candidates as $candidate) {
                if (in_array(strlen($candidate) - strlen($code), $lengths, true)) {
                    return '+' . $candidate;
                }
            }
        }

        return null;
    }
}
