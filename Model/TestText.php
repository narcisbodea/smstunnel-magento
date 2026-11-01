<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Model;

use Magento\Framework\Math\Random;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Send a test text" in Stores > Configuration > SMSTunnel: sends right away with the saved settings.
 */
class TestText
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var PhoneNumber
     */
    private $phoneNumber;

    /**
     * @var Outbox
     */
    private $outbox;

    /**
     * @var Sender
     */
    private $sender;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Random
     */
    private $random;

    /**
     * @param Config $config
     * @param PhoneNumber $phoneNumber
     * @param Outbox $outbox
     * @param Sender $sender
     * @param StoreManagerInterface $storeManager
     * @param Random $random
     */
    public function __construct(
        Config $config,
        PhoneNumber $phoneNumber,
        Outbox $outbox,
        Sender $sender,
        StoreManagerInterface $storeManager,
        Random $random
    ) {
        $this->config = $config;
        $this->phoneNumber = $phoneNumber;
        $this->outbox = $outbox;
        $this->sender = $sender;
        $this->storeManager = $storeManager;
        $this->random = $random;
    }

    /**
     * Send the test text and say how it went.
     *
     * @param int $storeId the store view whose settings to use
     * @param string $number the number typed in the form, or '' for the saved test number
     * @return array{success: bool, message: string}
     */
    public function send(int $storeId, string $number): array
    {
        if ($this->config->getApiKey($storeId) === '') {
            return $this->answer(false, (string) __(
                'Save an API key first: in your SMSTunnel dashboard, open Apps, then Add an app.'
            ));
        }
        $number = trim($number) !== '' ? trim($number) : $this->config->getTestNumber($storeId);
        if ($number === '') {
            return $this->answer(false, (string) __('Type the number that should get the test text.'));
        }
        $result = $this->phoneNumber->normalize(
            $number,
            null,
            $this->config->getDefaultCallingCode($storeId),
            $this->config->getStoreCountry($storeId)
        );
        if (!$result->isValid()) {
            return $this->answer(false, $result->getReason());
        }

        $store = $this->storeManager->getStore($storeId);
        $text = (string) __(
            'Test text from SMSTunnel for Magento: order texts from %1 will come from this phone.',
            $store instanceof Store ? $store->getFrontendName() : $store->getName()
        );
        $id = $this->outbox->add(
            Message::EVENT_TEST,
            'test:' . $this->random->getUniqueHash(),
            $storeId,
            $number,
            $result,
            $text,
            null,
            false
        );
        if ($id === null) {
            return $this->answer(false, (string) __('The test text could not be queued. Please try again.'));
        }

        $outcome = $this->sender->send($id);
        $row = $this->outbox->find($id) ?? [];
        $details = trim((string) ($row['details'] ?? ''));
        if ($outcome === Sender::RESULT_SENT) {
            $message = (string) __('Sent to %1: your phone sends it now.', $result->getNumber());

            return $this->answer(true, $details !== '' ? $message . ' ' . $details : $message);
        }
        if ($outcome === Sender::RESULT_RETRY || $outcome === Sender::RESULT_RATE_LIMITED) {
            return $this->answer(false, (string) __(
                'Not sent yet: %1 It will be tried again in the background.',
                $details
            ));
        }

        return $this->answer(false, (string) __('The test text was not sent: %1', $details));
    }

    /**
     * The answer for the admin page.
     *
     * @param bool $success
     * @param string $message
     * @return array{success: bool, message: string}
     */
    private function answer(bool $success, string $message): array
    {
        return ['success' => $success, 'message' => $message];
    }
}
