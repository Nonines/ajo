<?php

namespace App\Support\Payments;

use RuntimeException;

/**
 * Signals unavailable or inconclusive provider evidence with a retry delay in seconds.
 */
class GatewayFailure extends RuntimeException
{
    public function __construct(string $message, public int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
