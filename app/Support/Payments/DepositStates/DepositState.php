<?php

namespace App\Support\Payments\DepositStates;

use App\Models\Deposit;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Defines the permitted deposit lifecycle transitions used by the domain services.
 *
 * Keep concrete states in this directory so persisted aliases remain discoverable.
 *
 * @extends State<Deposit>
 */
abstract class DepositState extends State
{
    /**
     * Defines the initial state and permitted transitions for the persisted lifecycle.
     */
    public static function config(): StateConfig
    {
        return parent::config()->default(Initiated::class)
            ->allowTransition(Initiated::class, PendingConfirmation::class)
            ->allowTransition([Initiated::class, PendingConfirmation::class], Failed::class)
            ->allowTransition([Initiated::class, PendingConfirmation::class, Failed::class], Confirmed::class)
            ->allowTransition([Initiated::class, PendingConfirmation::class, Failed::class, Confirmed::class], Reversed::class);
    }
}
