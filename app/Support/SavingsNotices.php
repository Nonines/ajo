<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Jobs\DeliverSavingsNotice;
use App\Models\SavingsNotice;
use App\Models\SavingsPlan;
use App\Models\User;
use App\Notifications\SavingsMaturityNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps savings notice intent in the financial transaction and recovers delivery independently.
 */
class SavingsNotices
{
    public function __construct(private Clock $clock) {}

    /**
     * Snapshots settlement amounts as strings and selects the notice type from the closure reason.
     *
     * @param  array<string, mixed>  $statement
     */
    public function record(SavingsPlan $plan, array $statement): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Savings notice intent must share the financial transaction.');
        }
        $data = [];
        foreach (['principal', 'gross_interest', 'net_interest', 'withholding', 'debt_repayment', 'remaining_debt', 'wallet_credit', 'rounding_micro_kobo',
            'capitalized_interest', 'forfeiture', 'principal_penalty', 'waived_forfeiture', 'waived_penalty', 'incurred_fees'] as $key) {
            $data[$key] = (string) $statement[$key];
        }
        $type = $plan->closure_reason === 'matured_withdrawal' ? 'savings_maturity' : 'savings_'.$plan->closure_reason;
        $this->event($plan, $type, $data, 'plan:'.$plan->id.':settled');
    }

    /**
     * Persists one notice per event key and requests delivery after the surrounding transaction commits.
     *
     * @param  array<string, string>  $data
     */
    public function event(SavingsPlan $plan, string $type, array $data, string $eventKey): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Savings notice intent must share the financial transaction.');
        }
        if (SavingsNotice::where('event_key', $eventKey)->exists()) {
            return;
        }
        $notice = new SavingsNotice;
        $notice->forceFill(['id' => (string) Str::uuid(), 'savings_plan_id' => $plan->id, 'user_id' => $plan->user_id,
            'event_key' => $eventKey, 'recipient' => $plan->user->email, 'data' => ['type' => $type, 'plan_id' => (string) $plan->id, ...$data],
            'created_at' => $this->clock->now(), 'next_attempt_at' => $this->clock->now()])->save();
        DB::afterCommit(function () use ($notice): void {
            try {
                DeliverSavingsNotice::dispatch($notice->id);
            } catch (Throwable) {
                // Discovery retries the committed intent after a dispatch outage.
            }
        });
    }

    /**
     * Deduplicates the database notice and retries unsent mail; a crash after sending can repeat email.
     */
    public function deliver(string $id): void
    {
        $lock = Cache::lock('savings-notice:'.$id, config('payments.claim_seconds'));
        if (! $lock->get()) {
            return;
        }
        try {
            $notice = SavingsNotice::findOrFail($id);
            DB::table('notifications')->insertOrIgnore(['id' => $notice->id, 'type' => SavingsMaturityNotice::class,
                'notifiable_type' => User::class, 'notifiable_id' => $notice->user_id,
                'data' => json_encode($notice->data, JSON_THROW_ON_ERROR), 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
            if ($notice->mail_sent_at === null) {
                Notification::route('mail', $notice->recipient)->notify(new SavingsMaturityNotice($notice->data));
                $notice->forceFill(['mail_sent_at' => $this->clock->now()])->save();
            }
        } catch (Throwable $exception) {
            SavingsNotice::whereKey($id)->update(['next_attempt_at' => $this->clock->now()->addMinutes(config('savings.retry_minutes'))]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
