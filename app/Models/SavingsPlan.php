<?php

namespace App\Models;

use App\Support\PlanStates\PlanState;
use Carbon\CarbonImmutable;
use Database\Factories\SavingsPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property int $user_id
 * @property string $request_key
 * @property PlanState $state
 * @property array{name: string, disclosure: string, minimum: string, maximum: string, withholding_bp: int, terms: list<array{days: int, annual_rate_bp: int}>, interest: string, day_count: string, lock: string, fee: string, principal: string, days: int, annual_rate_bp: int} $contract
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $funding_deadline
 * @property CarbonImmutable|null $funded_at
 * @property CarbonImmutable|null $accrual_starts_at
 * @property CarbonImmutable|null $matures_at
 * @property int|null $funding_journal_id
 * @property string|null $closure_reason
 */
class SavingsPlan extends Model
{
    /** @use HasFactory<SavingsPlanFactory> */
    use HasFactory;

    use HasStates;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contract' => 'array', 'state' => PlanState::class, 'created_at' => 'immutable_datetime',
            'funding_deadline' => 'immutable_datetime', 'funded_at' => 'immutable_datetime',
            'accrual_starts_at' => 'immutable_datetime', 'matures_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime', 'next_process_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
