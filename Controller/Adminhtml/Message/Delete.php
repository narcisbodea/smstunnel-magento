<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Controller\Adminhtml\Message;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message\CollectionFactory;

/**
 * Deletes the selected texts from the log (and stops the ones still waiting).
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SmsTunnel_SmsNotifications::messages_manage';

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var MessageResource
     */
    private $resource;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param MessageResource $resource
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        MessageResource $resource
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->resource = $resource;
    }

    /**
     * Delete the selected texts.
     *
     * @return Redirect
     */
    public function execute()
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = array_map('intval', $collection->getAllIds());
            $deleted = $ids === [] ? 0 : $this->resource->getConnection()->delete(
                $this->resource->getMainTable(),
                ['entity_id IN (?)' => $ids]
            );
            $this->messageManager->addSuccessMessage(__('%1 text(s) were deleted.', $deleted));
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('*/*/index');
    }
}
