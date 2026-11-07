<?php

namespace App\Support\WithdrawalStates;

use App\Models\WithdrawalRequest;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/** @extends State<WithdrawalRequest> */
abstract class WithdrawalState extends State
{
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
