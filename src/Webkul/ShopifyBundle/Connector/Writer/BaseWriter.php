<?php

namespace Webkul\ShopifyBundle\Connector\Writer;

use Akeneo\Component\Batch\Item\ItemWriterInterface;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\Batch\Item\DataInvalidItem;
use Webkul\ShopifyBundle\Services\ShopifyConnector;

/**
 * Add resources to shopify
 *
 * @author    Webkul
 * @copyright 2010-2017 Webkul pvt. ltd.
 * @license   https://store.webkul.com/license.html
 */
class BaseWriter implements StepExecutionAwareInterface
{
    protected $connectorService;

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
        if(!empty($this->connectorService) && $this->connectorService instanceOf ShopifyConnector) {
            $this->connectorService->setStepExecution($stepExecution);
        }
    }

    public function __construct(ShopifyConnector $connectorService)
    {
        $this->connectorService = $connectorService;
    } 
}
