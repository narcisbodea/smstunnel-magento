<?php
/**
 * SMSTunnel for Magento: order texts from your own phone, from your own number.
 *
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'SmsTunnel_SmsNotifications', __DIR__);
