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
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Ui\Component\MassAction\Filter;
use SmsTunnel\SmsNotifications\Model\Message;
use SmsTunnel\SmsNotifications\Model\Outbox;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message as MessageResource;
use SmsTunnel\SmsNotifications\Model\ResourceModel\Message\CollectionFactory;

/**
 * "Send again" for the selected texts that failed or were sent.
 */
class Retry extends Action implements HttpPostActionInterface
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
     * @var Outbox
     */
    private $outbox;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param MessageResource $resource
     * @param Outbox $outbox
     * @param DateTime $dateTime
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        MessageResource $resource,
        Outbox $outbox,
        DateTime $dateTime
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->resource = $resource;
        $this->outbox = $outbox;
        $this->dateTime = $dateTime;
    }

    /**
     * Queue the selected texts again.
     *
     * @return Redirect
     */
    public function execute()
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $collection->addFieldToFilter('status', ['in' => [Message::STATUS_FAILED, Message::STATUS_SENT]]);
            $ids = array_map('intval', $collection->getAllIds());
            if ($ids !== []) {
                $this->resource->getConnection()->update(
                    $this->resource->getMainTable(),
                    [
                        'status' => Message::STATUS_PENDING,
                        'attempts' => 0,
                        'next_attempt_at' => null,
                        'smstunnel_message_id' => null,
                        'sent_at' => null,
                        'details' => null,
                        'updated_at' => $this->dateTime->gmtDate(),
                    ],
                    ['entity_id IN (?)' => $ids]
                );
                foreach ($ids as $id) {
                    $this->outbox->publish($id);
                }
            }
            $this->messageManager->addSuccessMessage(
                __(
                    '%1 text(s) will be sent again. Skipped texts and texts still waiting are left as they are.',
                    count($ids)
                )
            );
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('*/*/index');
    }
}
