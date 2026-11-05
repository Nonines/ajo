<?php

namespace App\Support;

use RuntimeException;

class GatewayFailure extends RuntimeException
{
    public function __construct(string $message, public int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
