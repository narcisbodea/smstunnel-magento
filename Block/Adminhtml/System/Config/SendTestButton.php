<?php
/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
declare(strict_types=1);

namespace SmsTunnel\SmsNotifications\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The "Send a Test Text" button in Stores > Configuration > SMSTunnel > Order Texts.
 */
class SendTestButton extends Field
{
    /**
     * @var string
     */
    protected $_template = 'SmsTunnel_SmsNotifications::system/config/send-test.phtml';

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Context $context
     * @param Json $json
     * @param mixed[] $data
     */
    public function __construct(Context $context, Json $json, array $data = [])
    {
        $this->json = $json;
        parent::__construct($context, $data);
    }

    /**
     * A button has no scope or "use default" checkbox.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsetData('scope');
        $element->unsetData('can_use_website_value');
        $element->unsetData('can_use_default_value');

        return parent::render($element);
    }

    /**
     * The JS widget's settings, for data-mage-init.
     *
     * @return string
     */
    public function getWidgetInit(): string
    {
        return (string) $this->json->serialize([
            'SmsTunnel_SmsNotifications/js/send-test' => [
                'url' => $this->getUrl('smstunnel/test/send'),
                'website' => (int) $this->getRequest()->getParam('website', 0),
                'store' => (int) $this->getRequest()->getParam('store', 0),
                'numberField' => '#smstunnel_general_test_number',
            ],
        ]);
    }

    /**
     * The button.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
