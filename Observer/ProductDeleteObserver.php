<?php
namespace Tabby\Feed\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Tabby\Feed\Model\Service;

class ProductDeleteObserver implements ObserverInterface
{
    /**
     * @var Service
     */
    protected $_service;

    /**
     * ProductSaveObserver constructor.
     *
     * @param Service $service
     */
    public function __construct(
        Service $service
    ) {
        $this->_service = $service;
    }

    /**
     * Main method, note product updated
     *
     * @param EventObserver $observer
     */
    public function execute(EventObserver $observer)
    {
        try {
            $this->_service->onProductDeleted($observer->getProduct());
        } catch (LocalizedException $e) {
            // ignore exceptions
            $this->_service->log($e);
        }
    }
}
