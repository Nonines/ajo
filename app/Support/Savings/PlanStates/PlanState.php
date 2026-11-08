<?php

namespace App\Support\Savings\PlanStates;

use App\Models\SavingsPlan;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Defines the permitted savings plan lifecycle transitions used by the domain services.
 *
 * Keep concrete states in this directory so persisted aliases remain discoverable.
 *
 * @extends State<SavingsPlan>
 */
abstract class PlanState extends State
{
    /**
     * Defines the initial state and permitted transitions for the persisted lifecycle.
     */
    public static function config(): StateConfig
    {
        return parent::config()->default(Draft::class)
            ->allowTransition(Draft::class, PendingFunding::class)
            ->allowTransition([Draft::class, PendingFunding::class], Cancelled::class)
            ->allowTransition(PendingFunding::class, Active::class)
            ->allowTransition(Active::class, Suspended::class)
            ->allowTransition(Suspended::class, Active::class)
            ->allowTransition(Active::class, Matured::class)
            ->allowTransition(Active::class, Closed::class)
            ->allowTransition(Matured::class, Closed::class);
    }
}
