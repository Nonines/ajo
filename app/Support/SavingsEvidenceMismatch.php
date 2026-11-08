<?php

namespace App\Support;

use DomainException;

/**
 * Carries investigation details across rollback so the mismatch can be retained separately.
 */
class SavingsEvidenceMismatch extends DomainException
{
    public function __construct(public int $customerId, public int $planId, public string $operation, string $message)
    {
        parent::__construct($message);
    }
}
