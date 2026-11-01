<?php
/**
 * Dev only: creates a second store view ("second") in the default website, for the scope test.
 * Run inside the Magento root: php /module/dev/e2e/create-store.php
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\Store;

require getcwd() . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();
$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

/** @var Store $store */
$store = $objectManager->create(Store::class);
$store->load('second', 'code');
if (!$store->getId()) {
    $store->setCode('second')
        ->setName('Second View')
        ->setWebsiteId(1)
        ->setGroupId(1)
        ->setSortOrder(10)
        ->setIsActive(1);
    $objectManager->get(StoreResource::class)->save($store);
}
echo $store->getId(), PHP_EOL;
