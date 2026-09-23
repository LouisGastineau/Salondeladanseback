<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class BusinessRuleException extends HttpException
{
    public function __construct(string $message, int $status = 409)
    {
        parent::__construct($status, $message);
    }
}
