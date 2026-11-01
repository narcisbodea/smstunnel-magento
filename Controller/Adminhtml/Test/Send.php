<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use SmsTunnel\SmsNotifications\Model\TestText;

/**
 * POST smstunnel/test/send: the "Send a Test Text" button.
 */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SmsTunnel_SmsNotifications::config';

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var TestText
     */
    private $testText;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param TestText $testText
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        TestText $testText,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->testText = $testText;
        $this->storeManager = $storeManager;
    }

    /**
     * Send the test text with the settings of the scope the configuration page shows.
     *
     * @return Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $answer = $this->testText->send(
                $this->resolveStoreId(),
                (string) $this->getRequest()->getParam('number', '')
            );
        } catch (\Throwable $exception) {
            $answer = [
                'success' => false,
                'message' => (string) __('The test text could not be sent: %1', $exception->getMessage()),
            ];
        }

        return $result->setData($answer);
    }

    /**
     * The store view of the scope: the one picked, the website's default one, or the default store view.
     *
     * @return int
     */
    private function resolveStoreId(): int
    {
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        if ($storeId > 0) {
            return (int) $this->storeManager->getStore($storeId)->getId();
        }
        $websiteId = (int) $this->getRequest()->getParam('website', 0);
        if ($websiteId > 0) {
            $groupId = (int) $this->storeManager->getWebsite($websiteId)->getDefaultGroupId();
            if ($groupId > 0) {
                return (int) $this->storeManager->getGroup((string) $groupId)->getDefaultStoreId();
            }
        }
        $store = $this->storeManager->getDefaultStoreView();

        return $store !== null ? (int) $store->getId() : 0;
    }
}
