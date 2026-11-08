<?php

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Jobs\DispatchWithdrawal;
use App\Jobs\VerifyWithdrawal;
use App\Models\ApprovalRequest;
use App\Models\PaymentException;
use App\Models\SavingsPlan;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Auth\Approvals;
use App\Support\Ledger\FinancialTransaction;
use App\Support\Withdrawals\Withdrawals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Expires review work and queues dispatch or verification according to saved submission evidence.
 */
#[Signature('withdrawals:recover')]
#[Description('Expire withdrawal reviews and recover approved payouts and pending verification')]
class RecoverWithdrawals extends Command
{
    /**
     * Expires reviews before dispatching new payouts or verifying submitted ones.
     */
    public function handle(Withdrawals $withdrawals, Approvals $approvals, Clock $clock, FinancialTransaction $transactions): int
    {
        foreach (ApprovalRequest::where('state', 'pending')->where('expires_at', '<=', $clock->now())->orderBy('id')->limit(50)->get() as $approval) {
            $customers = in_array($approval->subject_type, [WithdrawalRequest::class, SavingsPlan::class], true)
                ? [$approval->subject_type::findOrFail($approval->subject_id)->user_id]
                : ($approval->subject_type === User::class ? [$approval->subject_id] : []);
            $transactions->run($customers, fn () => $approvals->expire($approval));
        }

        // Release expired review reservations before dispatching approved payouts.
        foreach (WithdrawalRequest::whereIn('state', ['requested', 'under_review'])->where('expires_at', '<=', $clock->now())->orderBy('id')->limit(50)->get() as $withdrawal) {
            $transactions->run([$withdrawal->user_id], fn () => $withdrawals->expire($withdrawal->refresh()));
        }

        foreach (WithdrawalRequest::where('state', 'queued_for_payout')->whereNull('submitted_at')->orderBy('id')->limit(50)->get() as $withdrawal) {
            try {
                DispatchWithdrawal::dispatch($withdrawal->id);
            } catch (Throwable) {
                // The durable queued state will be retried by the next scheduler pass.
            }
        }

        // Submitted payouts need verification rather than a fresh transfer request.
        foreach (WithdrawalRequest::whereNotNull('submitted_at')->where('next_verify_at', '<=', $clock->now())->orderBy('next_verify_at')->limit(50)->get() as $withdrawal) {
            if ((string) $withdrawal->state === 'queued_for_payout' && $withdrawal->submitted_at->addHours(24)->lessThanOrEqualTo($clock->now())) {
                PaymentException::record('payout_unresolved', 'Payout remains externally uncertain after 24 hours; retain reserved funds.', $clock->now(),
                    resourceType: 'withdrawal', resourceId: (string) $withdrawal->id);
            }

            $withdrawal->forceFill(['next_verify_at' => $clock->now()->addMinutes(5)])->save();

            try {
                VerifyWithdrawal::dispatch($withdrawal->id);
            } catch (Throwable) {
                // A later pass retries verification without dispatching another transfer.
            }
        }

        return self::SUCCESS;
    }
}
