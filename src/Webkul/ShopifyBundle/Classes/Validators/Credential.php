<?php

namespace Webkul\ShopifyBundle\Classes\Validators;

use Symfony\Component\Validator\Constraint;

class Credential extends Constraint
{
    const INVALID_CREDENTIAL_ERROR = 'c105csb4-dre1-4f74-8988-acbcafc7fdc3';

    protected static $errorNames = array(
        self::INVALID_CREDENTIAL_ERROR => 'INVALID_CREDENTIAL_ERROR',
    );

    public $message = 'Credentials are not valid.';
}
