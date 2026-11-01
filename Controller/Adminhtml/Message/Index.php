<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Controller\Adminhtml\Message;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Sales > SMSTunnel Texts.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'SmsTunnel_SmsNotifications::messages';

    /**
     * @var PageFactory
     */
    private $pageFactory;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    /**
     * The grid of texts.
     *
     * @return Page
     */
    public function execute()
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('SmsTunnel_SmsNotifications::messages');
        $page->getConfig()->getTitle()->prepend((string) __('SMSTunnel Texts'));

        return $page;
    }
}
