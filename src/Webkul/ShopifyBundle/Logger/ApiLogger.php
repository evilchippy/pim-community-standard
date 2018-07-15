<?php 

namespace Webkul\ShopifyBundle\Logger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Bridge\Monolog\Logger;

class ApiLogger extends Logger
{
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface     $logger
     * @param TranslatorInterface $translator
     */
    public function __construct($logger)
    {
        parent::__construct($logger);
    }

}