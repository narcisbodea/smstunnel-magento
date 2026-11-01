<?php
/**
 * Dev only: runs the smstunnel_send_pending cron job once (what Magento's cron does every minute).
 * Run inside the Magento root: php /module/dev/e2e/send-pending.php
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use SmsTunnel\SmsNotifications\Cron\SendPending;

require getcwd() . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();
$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('crontab');
$objectManager->get(SendPending::class)->execute();
echo 'done', PHP_EOL;
