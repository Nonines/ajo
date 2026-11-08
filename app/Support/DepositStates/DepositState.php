<?php

namespace App\Support\DepositStates;

use App\Models\Deposit;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Defines the permitted deposit lifecycle transitions used by the domain services.
 *
 * @extends State<Deposit>
 */
abstract class DepositState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()->default(Initiated::class)
            ->allowTransition(Initiated::class, PendingConfirmation::class)
            ->allowTransition([Initiated::class, PendingConfirmation::class], Failed::class)
            ->allowTransition([Initiated::class, PendingConfirmation::class, Failed::class], Confirmed::class)
            ->allowTransition([Initiated::class, PendingConfirmation::class, Failed::class, Confirmed::class], Reversed::class);
    }
}
