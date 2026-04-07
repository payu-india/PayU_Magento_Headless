<?php
declare(strict_types=1);

namespace PayUIndia\Payu\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Payu extends Base
{
    protected $loggerType = Logger::INFO;
    protected $fileName = '/var/log/payu.log';
}