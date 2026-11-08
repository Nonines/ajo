<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replays due financial work at chronological scheduler instants, with durable restart progress.
 */
class DemoTimeMachine
{
    public function __construct(private Clock $clock, private PaymentMonitoring $payments, private SavingsProcessing $savings) {}

    public function authorize(Staff $staff): void
    {
        $this->requireDemo();
        $current = app(Approvals::class)->authorize($staff, 'simulator.use');
        if (! $current->hasRole('admin')) {
            throw new AuthorizationException('Only administrators may advance demo time.');
        }
    }

    public function requireDemo(): void
    {
        if (! app()->environment('demo')) {
            throw new AuthorizationException('Demo features require APP_ENV=demo.');
        }
    }

    /** @return array{current_at: string, target_at: ?string, failure: ?string, history_step: int, seeded_at: ?string} */
    public function state(): array
    {
        $this->requireDemo();
        $this->clock->now();
        $row = DB::table('demo_state')->where('id', 1)->sole();

        return ['current_at' => $row->current_at, 'target_at' => $row->target_at, 'failure' => $row->failure,
            'history_step' => (int) $row->history_step, 'seeded_at' => $row->seeded_at];
    }

    /**
     * An expected instant rejects double submissions after a completed advance.
     */
    public function start(Staff $staff, int $days, string $expected, bool $history = false): void
    {
        $this->authorize($staff);
        if ($days < 1 || $days > config('demo.maximum_advance_days')) {
            throw new DomainException('Advance between 1 and '.config('demo.maximum_advance_days').' whole days.');
        }
        $this->exclusive(function () use ($staff, $days, $expected, $history): void {
            $this->assertCompatible();
            $now = $this->clock->now();
            $state = $this->state();
            if (! $history && $state['history_step'] > 0 && $state['seeded_at'] === null) {
                throw new DomainException('Finish or resume history seeding before manually advancing demo time.');
            }
            if ($state['target_at'] !== null || ! $now->equalTo(CarbonImmutable::parse($expected, 'UTC'))) {
                throw new DomainException('Demo time has changed or an advance is already pending. Refresh or resume it.');
            }
            $target = $now->setTimezone(config('app.timezone'))->addDays($days)->utc();
            DB::transaction(function () use ($staff, $now, $target): void {
                DB::table('demo_state')->where('id', 1)->update(['target_at' => $target, 'requested_by' => $staff->id, 'failure' => null, 'processed_at' => null]);
                app(AuditTrail::class)->record('demo.advance_requested', properties: ['from' => $now->toIso8601String(), 'to' => $target->toIso8601String()], actor: $staff);
            });
        });
    }

    public function resume(Staff $staff): void
    {
        $this->authorize($staff);
        $this->exclusive(function (): void {
            DB::table('demo_state')->where('id', 1)->update(['failure' => null]);
        });
    }

    /**
     * Runs a bounded chunk; polling and the demo scheduler continue the saved target after a disconnect.
     */
    public function process(): void
    {
        $this->requireDemo();
        $this->exclusive(function (): void {
            $state = $this->state();
            if ($state['failure'] !== null) {
                return;
            }
            try {
                $this->assertCompatible();
                $deadline = microtime(true) + config('demo.chunk_seconds');
                do {
                    $now = $this->clock->now();
                    $this->settleDueWork();
                    // IMMEDIATE also excludes ordinary intent writers during the final scan and clock write.
                    $finished = DB::transaction(function () use ($now): bool {
                        $this->assertCompatible();
                        $this->assertHealthy();
                        if ($this->hasDueWork()) {
                            return false;
                        }
                        DB::table('demo_state')->where('id', 1)->update(['processed_at' => $now]);
                        $state = $this->state();
                        if ($state['target_at'] === null) {
                            return true;
                        }
                        $target = CarbonImmutable::parse($state['target_at'], 'UTC');
                        if ($now->greaterThanOrEqualTo($target)) {
                            $actor = Staff::where('id', DB::table('demo_state')->where('id', 1)->value('requested_by'))->first();
                            app(AuditTrail::class)->record('demo.advance_completed', properties: ['to' => $target->toIso8601String()], actor: $actor);
                            DB::table('demo_state')->where('id', 1)->update(['target_at' => null, 'requested_by' => null]);

                            return true;
                        }
                        $next = $this->nextInstant($now, $target);
                        // A crash resumes unfinished work at the saved instant.
                        DB::table('demo_state')->where('id', 1)->update(['current_at' => $next, 'processed_at' => null]);

                        return false;
                    });
                    if ($finished) {

                        return;
                    }
                } while (microtime(true) < $deadline);
            } catch (Throwable $exception) {
                DB::table('demo_state')->where('id', 1)->update(['failure' => $exception instanceof DomainException ? $exception->getMessage() : 'Demo processing failed. Inspect application logs, then resume.']);
                report($exception);
                throw $exception;
            }
        });
    }

    /**
     * Refuses to reinterpret real provider records under a simulated date.
     */
    public function assertCompatible(): void
    {
        $this->requireDemo();
        foreach (['deposits', 'withdrawal_requests', 'webhook_events'] as $table) {
            if (DB::table($table)->where('origin', '!=', 'simulated')->exists()) {
                throw new DomainException('Demo time cannot process real provider records. Use a database containing simulated payments only.');
            }
        }
        if (DB::table('payment_exceptions')->where('provider_open', true)->exists()) {
            throw new DomainException('Resolve provider investigations before advancing demo time.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function exclusive(Closure $operation): mixed
    {
        $this->requireDemo();
        $store = Cache::store(config('ledger.lock_store'))->getStore();
        if (! $store instanceof LockProvider) {
            throw new DomainException('Demo processing requires a cache store with atomic locks.');
        }
        $lock = $store->lock('demo:time-machine', config('demo.lock_seconds'));
        if (! $lock->get()) {
            throw new DomainException('Demo processing is already running.');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function settleDueWork(): void
    {
        for ($pass = 0; $pass < config('demo.maximum_passes'); $pass++) {
            // Preserve the financial scheduler declaration order; inline jobs finish at this instant.
            $this->payments->recover();
            if (Artisan::call('withdrawals:recover') !== 0) {
                throw new DomainException('Withdrawal recovery failed.');
            }
            $this->savings->discover();
            $this->assertHealthy();
            if (! $this->hasDueWork()) {
                return;
            }
        }
        throw new DomainException('Due work did not complete within the demo processing limit. Inspect exceptions before resuming.');
    }

    private function assertHealthy(): void
    {
        $failure = DB::table('demo_state')->where('id', 1)->value('failure');
        if ($failure !== null) {
            throw new DomainException($failure);
        }
        if (DB::table('savings_exceptions')->exists() || DB::table('savings_calculations')->whereNotNull('failure')->exists()) {
            throw new DomainException('Savings processing requires investigation before demo time can advance.');
        }
        if (DB::table('webhook_events')->whereNull('processed_at')->where('attempts', '>', 0)->whereNull('claimed_at')->exists()) {
            throw new DomainException('Payment inbox processing requires investigation before demo time can advance.');
        }
        foreach (['payment_notices', 'savings_notices'] as $table) {
            if (DB::table($table)->whereNull('mail_sent_at')->where('created_at', '<=', $this->clock->now())->exists()) {
                throw new DomainException('Demo notification delivery is incomplete. Retry at the current date.');
            }
        }
    }

    /** @return list<array{Builder, string}> */
    private function intents(): array
    {
        $now = $this->clock->now();

        return [
            [DB::table('webhook_events')->whereNull('processed_at')->where(fn (Builder $query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $now->subSeconds(config('payments.claim_seconds')))), 'next_attempt_at'],
            [DB::table('deposits')->whereNotNull('next_verify_at'), 'next_verify_at'],
            [DB::table('payment_notices')->whereNull('mail_sent_at'), 'next_attempt_at'],
            [DB::table('approval_requests')->where('state', 'pending'), 'expires_at'],
            [DB::table('withdrawal_requests')->whereIn('state', ['requested', 'under_review']), 'expires_at'],
            [DB::table('withdrawal_requests')->where('state', 'queued_for_payout')->whereNull('submitted_at'), 'created_at'],
            [DB::table('withdrawal_requests')->whereNotNull('submitted_at')->whereNotNull('next_verify_at'), 'next_verify_at'],
            [DB::table('savings_calculations')->whereNull('completed_at')->whereNull('failure'), 'next_process_at'],
            [DB::table('savings_plans')->whereIn('state', ['draft', 'pending_funding']), 'funding_deadline'],
            [DB::table('savings_plans')->whereIn('state', ['active', 'suspended', 'matured']), 'next_process_at'],
            [DB::table('plan_waivers')->whereNull('used_at')->whereNull('expired_at'), 'expires_at'],
            [DB::table('savings_notices')->whereNull('mail_sent_at'), 'next_attempt_at'],
        ];
    }

    private function hasDueWork(): bool
    {
        foreach ($this->intents() as [$query, $column]) {
            if ($query->where($column, '<=', $this->clock->now())->exists()) {
                return true;
            }
        }

        return false;
    }

    private function nextInstant(CarbonImmutable $now, CarbonImmutable $target): CarbonImmutable
    {
        // Empty minute ticks have no financial effect; visit the next due minute and every daily boundary.
        $daily = $now->setTimezone(config('app.timezone'))->startOfDay()->addMinutes(5);
        $next = ($daily->greaterThan($now) ? $daily : $daily->addDay())->utc()->min($target);
        foreach ($this->intents() as [$query, $column]) {
            $due = $query->min($column);
            if ($due !== null) {
                $instant = CarbonImmutable::parse($due, 'UTC')->ceilMinute();
                $next = $next->min($instant->max($now->addMinute()));
            }
        }
        $claim = DB::table('webhook_events')->whereNull('processed_at')->whereNotNull('claimed_at')->min('claimed_at');
        if ($claim !== null) {
            $next = $next->min(CarbonImmutable::parse($claim, 'UTC')->addSeconds(config('payments.claim_seconds'))->ceilMinute()->max($now->addMinute()));
        }

        return $next;
    }
}
