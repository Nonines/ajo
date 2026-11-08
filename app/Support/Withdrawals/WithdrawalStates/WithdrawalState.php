<?php

namespace App\Support\Withdrawals\WithdrawalStates;

use App\Models\WithdrawalRequest;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Defines the permitted withdrawal lifecycle transitions used by the domain services.
 *
 * Keep concrete states in this directory so persisted aliases remain discoverable.
 *
 * @extends State<WithdrawalRequest>
 */
abstract class WithdrawalState extends State
{
    /**
     * Defines the initial state and permitted transitions for the persisted lifecycle.
     */
    public static function config(): StateConfig
    {
        return parent::config()->default(Requested::class)
            ->allowTransition(Requested::class, UnderReview::class)
            ->allowTransition([Requested::class, UnderReview::class], Approved::class)
            ->allowTransition(Approved::class, QueuedForPayout::class)
            ->allowTransition([Requested::class, UnderReview::class], Rejected::class)
            ->allowTransition([Requested::class, UnderReview::class, Approved::class, QueuedForPayout::class], Cancelled::class)
            ->allowTransition(QueuedForPayout::class, Paid::class)
            ->allowTransition(QueuedForPayout::class, Failed::class)
            ->allowTransition([QueuedForPayout::class, Paid::class], Reversed::class);
    }
}
