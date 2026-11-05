<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\PaymentNotice;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Notifications\PaymentStatusNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class PaymentNotices
{
    public function __construct(private Clock $clock, private Ledger $ledger) {}

    public function record(Deposit $deposit, string $type, string $operationKey, ?PaymentReversal $reversal = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Financial notice intent must share the financial transaction.');
        }
        $notice = PaymentNotice::where('operation_key', $operationKey)->first();
        if ($notice !== null) {
            return;
        }
        $receivable = LedgerAccount::where('code', 'customer:'.$deposit->user_id.':reversal_receivable')->sole();
        $data = ['type' => $type, 'reference' => $deposit->reference, 'net' => (string) $deposit->requested_net,
            'gross' => (string) $deposit->gross_amount, 'fee' => (string) $deposit->fee_amount, 'wallet_credit' => (string) $deposit->wallet_credit,
            'debt_repayment' => (string) $deposit->debt_repayment, 'remaining_debt' => (string) $this->ledger->balance($receivable)->kobo];
        if ($reversal !== null) {
            $data += ['reversed_principal' => (string) $reversal->principal, 'reversed_fee' => (string) $reversal->fee,
                'wallet_collected' => (string) $reversal->wallet_collected, 'receivable_created' => (string) $reversal->receivable_created];
        }
        $notice = new PaymentNotice;
        $notice->forceFill(['id' => (string) Str::uuid(), 'user_id' => $deposit->user_id, 'deposit_id' => $deposit->id,
            'operation_key' => $operationKey, 'recipient' => $deposit->payer_email, 'data' => $data,
            'created_at' => $this->clock->now(), 'next_attempt_at' => $this->clock->now()])->save();
        DB::afterCommit(function () use ($notice): void {
            try {
                DeliverPaymentNotice::dispatch($notice->id);
            } catch (Throwable) {
                // The durable notice intent remains eligible for scheduler recovery.
            }
        });
    }

    public function deliver(string $id): void
    {
        $lock = Cache::lock('payment-notice:'.$id, config('payments.claim_seconds'));
        if (! $lock->get()) {
            return;
        }
        try {
            $notice = PaymentNotice::findOrFail($id);
            DB::transaction(function () use ($notice): void {
                DB::table('notifications')->insertOrIgnore(['id' => $notice->id, 'type' => PaymentStatusNotice::class,
                    'notifiable_type' => User::class, 'notifiable_id' => $notice->user_id,
                    'data' => json_encode($notice->data, JSON_THROW_ON_ERROR), 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
            });
            if ($notice->mail_sent_at !== null) {
                return;
            }
            Notification::route('mail', $notice->recipient)->notify(new PaymentStatusNotice($notice->data));
            $notice->forceFill(['mail_sent_at' => $this->clock->now()])->save();
        } catch (Throwable $exception) {
            PaymentNotice::whereKey($id)->update(['next_attempt_at' => $this->clock->now()->addMinutes(config('payments.notification_retry_minutes'))]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
