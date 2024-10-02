<?php

namespace Tabby\Feed\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Tabby\Feed\Model\Service;

class ConfigObserver implements ObserverInterface
{
    /**
     * @var Service
     */
    protected $_service;

    /**
     * ConfigObserver constructor.
     *
     * @param Service $service
     */
    public function __construct(
        Service $service
    ) {
        $this->_service = $service;
    }

    /**
     * Main method, try to get Tabby Feed service
     *
     * @param EventObserver $observer
     */
    public function execute(EventObserver $observer)
    {
        try {
            $this->_service->onServiceRequested();
        } catch (LocalizedException $e) {
            // ignore exceptions
            $e->getCode();
        }
    }
}
