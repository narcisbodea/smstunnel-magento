<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's settings (Stores > Configuration > SMSTunnel), read for one store view.
 */
class Config
{
    public const DEFAULT_SERVER_URL = 'https://smstunnel.io';

    private const XML_ENABLED = 'smstunnel/general/enabled';
    private const XML_API_KEY = 'smstunnel/general/api_key';
    private const XML_DEVICE_ID = 'smstunnel/general/device_id';
    private const XML_DEFAULT_COUNTRY_CODE = 'smstunnel/general/default_country_code';
    private const XML_TEST_NUMBER = 'smstunnel/general/test_number';
    private const XML_OWNER_NUMBERS = 'smstunnel/owner/numbers';
    private const XML_STATUSES = 'smstunnel/status_changed/statuses';
    private const XML_RETENTION_DAYS = 'smstunnel/log/retention_days';
    private const XML_SERVER_URL = 'smstunnel/advanced/server_url';
    private const XML_STORE_COUNTRY = 'general/country/default';

    /**
     * Config group of each text that can be turned on and off.
     */
    private const EVENT_GROUPS = [
        Message::EVENT_OWNER_NEW_ORDER => 'owner',
        Message::EVENT_ORDER_PLACED => 'order_placed',
        Message::EVENT_INVOICE_CREATED => 'invoice_created',
        Message::EVENT_SHIPMENT_CREATED => 'shipment_created',
        Message::EVENT_ORDER_CANCELED => 'order_canceled',
        Message::EVENT_STATUS_CHANGED => 'status_changed',
    ];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var CallingCodes
     */
    private $callingCodes;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param CallingCodes $callingCodes
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        CallingCodes $callingCodes
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->callingCodes = $callingCodes;
    }

    /**
     * Whether texts are turned on for the store view and an API key is saved.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)
            && $this->getApiKey($storeId) !== '';
    }

    /**
     * The API key, decrypted.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId): string
    {
        $value = trim((string) $this->getValue(self::XML_API_KEY, $storeId));
        if ($value === '') {
            return '';
        }
        // Saved through the admin or config:set, the key is encrypted ("<key version>:<cipher>:<data>").
        if (preg_match('/^\d+:\d+:/', $value) === 1) {
            $value = trim((string) $this->encryptor->decrypt($value));
        }

        return $value;
    }

    /**
     * The phone the texts go through, or null to let SMSTunnel use one of the account's phones.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getDeviceId(?int $storeId): ?string
    {
        $value = trim((string) $this->getValue(self::XML_DEVICE_ID, $storeId));

        return $value === '' ? null : $value;
    }

    /**
     * The configured default country calling code, digits only (e.g. "44"), or null.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getDefaultCallingCode(?int $storeId): ?string
    {
        return $this->callingCodes->clean((string) $this->getValue(self::XML_DEFAULT_COUNTRY_CODE, $storeId));
    }

    /**
     * The store's default country (ISO 3166-1 alpha-2), or null.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getStoreCountry(?int $storeId): ?string
    {
        $value = strtoupper(trim((string) $this->getValue(self::XML_STORE_COUNTRY, $storeId)));

        return $value === '' ? null : $value;
    }

    /**
     * The saved test number.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTestNumber(?int $storeId): string
    {
        return trim((string) $this->getValue(self::XML_TEST_NUMBER, $storeId));
    }

    /**
     * Whether the text for this event is turned on.
     *
     * @param string $event one of the Message::EVENT_* constants
     * @param int|null $storeId
     * @return bool
     */
    public function isEventEnabled(string $event, ?int $storeId): bool
    {
        $group = self::EVENT_GROUPS[$event] ?? null;
        if ($group === null) {
            return false;
        }

        return $this->scopeConfig->isSetFlag('smstunnel/' . $group . '/enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The text template for this event.
     *
     * @param string $event one of the Message::EVENT_* constants
     * @param int|null $storeId
     * @return string
     */
    public function getTemplate(string $event, ?int $storeId): string
    {
        $group = self::EVENT_GROUPS[$event] ?? null;
        if ($group === null) {
            return '';
        }

        return trim((string) $this->getValue('smstunnel/' . $group . '/template', $storeId));
    }

    /**
     * The owner's numbers for new-order texts, as typed (one per line, commas and semicolons work too).
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getOwnerNumbers(?int $storeId): array
    {
        $parts = preg_split('/[\r\n,;]+/', (string) $this->getValue(self::XML_OWNER_NUMBERS, $storeId)) ?: [];
        $numbers = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $numbers[] = $part;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * Order statuses that get the "status changed" text.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getStatusChangeStatuses(?int $storeId): array
    {
        $value = (string) $this->getValue(self::XML_STATUSES, $storeId);

        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    /**
     * Days to keep sent texts; 0 keeps them.
     *
     * @return int
     */
    public function getRetentionDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_RETENTION_DAYS));
    }

    /**
     * The SMSTunnel server. Only a test setup changes it.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getServerUrl(?int $storeId): string
    {
        $value = rtrim(trim((string) $this->getValue(self::XML_SERVER_URL, $storeId)), '/');

        return $value === '' ? self::DEFAULT_SERVER_URL : $value;
    }

    /**
     * Read one value at store view scope.
     *
     * @param string $path
     * @param int|null $storeId
     * @return mixed
     */
    private function getValue(string $path, ?int $storeId)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
