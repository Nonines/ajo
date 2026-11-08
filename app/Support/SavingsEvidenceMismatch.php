<?php

namespace App\Support;

use DomainException;

class SavingsEvidenceMismatch extends DomainException
{
    public function __construct(public int $customerId, public int $planId, public string $operation, string $message)
    {
        parent::__construct($message);
    }
}
