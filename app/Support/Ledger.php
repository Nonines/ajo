<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Brick\Math\BigInteger;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts balanced, replay-safe journals and maintains rebuildable account balance projections.
 */
class Ledger
{
    public function __construct(private FinancialTransaction $transactions, private Clock $clock) {}

    /**
     * Posts signed kobo entries; reusing a key requires the same financial inputs.
     *
     * @param  list<array{account_id: int, amount: Money}>  $entries
     */
    public function post(string $key, array $entries, string $effectiveDate, string $description, ?int $customerId = null, ?int $reversesId = null): Journal
    {
        return $this->write($key, $entries, $effectiveDate, $description, $customerId, $reversesId);
    }

    /**
     * Recovers a verified reversal from available wallet value, booking any shortfall as debt.
     */
    public function recoverDeposit(PaymentReversal $reversal): Journal
    {
        $effect = PaymentReversal::findOrFail($reversal->id);
        $deposit = $effect->deposit;

        return $this->transactions->run([$deposit->user_id], function () use ($effect, $deposit): Journal {
            $effect->refresh();
            if ($effect->journal_id !== null) {
                return Journal::findOrFail($effect->journal_id);
            }
            $previous = PaymentReversal::where('deposit_id', $deposit->id)->whereNotNull('journal_id');
            if ($deposit->recognition_journal_id === null || $effect->amount <= 0 || $effect->principal < 0 || $effect->fee < 0
                || $effect->principal + $effect->fee !== $effect->amount
                || $effect->principal > $deposit->requested_net - (clone $previous)->sum('principal')
                || $effect->fee > $deposit->fee_amount - (clone $previous)->sum('fee')) {
                throw new DomainException('Recovery must be bounded by verified original customer value and attributable fees.');
            }
            $wallet = LedgerAccount::where('code', 'customer:'.$deposit->user_id.':wallet')->sole();
            $receivable = LedgerAccount::where('code', 'customer:'.$deposit->user_id.':reversal_receivable')->sole();
            $available = $this->balance($wallet)->negate()->kobo;
            $collected = min($available, $effect->amount);
            $shortfall = $effect->amount - $collected;
            $entries = [['account_id' => LedgerAccount::where('code', 'house:cash_at_gateway')->sole()->id, 'amount' => new Money(-$effect->amount)]];
            if ($collected > 0) {
                $entries[] = ['account_id' => $wallet->id, 'amount' => new Money($collected)];
            }
            if ($shortfall > 0) {
                $entries[] = ['account_id' => $receivable->id, 'amount' => new Money($shortfall)];
            }
            $journal = $this->write('deposit-reversal:'.$effect->id, $entries, $this->clock->now()->setTimezone(config('app.timezone'))->toDateString(), 'Verified deposit reversal', $deposit->user_id, systemRecovery: true);
            $effect->forceFill(['wallet_collected' => $collected, 'receivable_created' => $shortfall, 'journal_id' => $journal->id])->save();
            app(RecoveryControls::class)->activate($deposit->user_id);

            return $journal;
        });
    }

    /**
     * Reconciles the verified payout fee against its reservation and previously accounted fee.
     */
    public function settleWithdrawalFee(WithdrawalRequest $withdrawal): void
    {
        $this->transactions->run([$withdrawal->user_id], function () use ($withdrawal): void {
            $current = $withdrawal->refresh();
            $fee = $current->verified_fee;
            if ($current->principal_journal_id === null || $fee === null || $fee < 0) {
                throw new DomainException('Payout fee recovery requires verified outcome and fee evidence.');
            }
            if ($current->accounted_fee === $fee) {
                return;
            }
            $reserved = $current->accounted_fee === null ? $current->reserved_fee : 0;
            $delta = $fee - ($current->accounted_fee ?? 0);
            $due = $delta - $reserved;
            $entries = [];
            foreach (['clearing:payouts_in_transit' => $reserved, 'house:cash_at_gateway' => -$delta,
                'house:gateway_fees' => $delta, 'house:fee_recovery_income' => -$delta] as $code => $amount) {
                if ($amount !== 0) {
                    $entries[] = ['account_id' => LedgerAccount::where('code', $code)->sole()->id, 'amount' => new Money($amount)];
                }
            }
            if ($due > 0) {
                $wallet = LedgerAccount::where('code', 'customer:'.$current->user_id.':wallet')->sole();
                $collected = min($due, $this->balance($wallet)->negate()->kobo);
                if ($collected > 0) {
                    $entries[] = ['account_id' => $wallet->id, 'amount' => new Money($collected)];
                }
                if ($due > $collected) {
                    $entries[] = ['account_id' => LedgerAccount::where('code', 'customer:'.$current->user_id.':reversal_receivable')->sole()->id,
                        'amount' => new Money($due - $collected)];
                }
            } elseif ($due < 0) {
                $entries = array_merge($entries, app(RecoveryControls::class)->credits($current->user_id, -$due));
            }
            $revision = $current->fee_revision + 1;
            $this->write('withdrawal:'.$current->id.':fee:'.$revision, $entries,
                $this->clock->now()->setTimezone(config('app.timezone'))->toDateString(), 'Verified payout processing fee',
                $current->user_id, systemRecovery: true);
            $current->forceFill(['accounted_fee' => $fee, 'fee_revision' => $revision])->save();
            if ($fee > $current->reserved_fee) {
                PaymentException::record('payout_fee_excess', 'Verified payout fee exceeded its immutable quote; excess was collected or booked as recovery debt.',
                    $this->clock->now(), resourceType: 'withdrawal', resourceId: (string) $current->id);
            }
            app(RecoveryControls::class)->activate($current->user_id);
        });
    }

    /**
     * Validates journal invariants and commits entries, projections, and audit evidence together.
     *
     * @param  list<array{account_id: int, amount: Money}>  $entries
     */
    private function write(string $key, array $entries, string $effectiveDate, string $description, ?int $customerId = null, ?int $reversesId = null, bool $systemRecovery = false): Journal
    {
        $date = explode('-', $effectiveDate);
        if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $effectiveDate) || ! checkdate((int) ($date[1] ?? 0), (int) ($date[2] ?? 0), (int) $date[0])) {
            throw new InvalidArgumentException('Invalid economic effective date.');
        }
        if ($key === '' || strlen($key) > 255 || $description === '' || mb_strlen($description) > 255 || count($entries) < 2) {
            throw new InvalidArgumentException('A journal requires a key, description, and at least two entries.');
        }
        $sum = BigInteger::zero();
        $canonical = [];
        $movements = [];
        foreach ($entries as $entry) {
            if ($entry['amount']->kobo === 0) {
                throw new InvalidArgumentException('Journal entries require nonzero Money amounts.');
            }
            $sum = $sum->plus($entry['amount']->kobo);
            $canonical[] = ['account_id' => $entry['account_id'], 'amount' => (string) $entry['amount']->kobo];
            $movements[$entry['account_id']] = ($movements[$entry['account_id']] ?? BigInteger::zero())->plus($entry['amount']->kobo);
        }
        if (! $sum->isZero()) {
            throw new DomainException('Journal entries must balance.');
        }
        usort($canonical, static fn (array $left, array $right): int => ($left['account_id'] <=> $right['account_id']) ?: strcmp($left['amount'], $right['amount']));
        $fingerprint = hash('sha256', json_encode([$canonical, 'NGN', $effectiveDate, $description, $customerId, $reversesId], JSON_THROW_ON_ERROR));

        return $this->transactions->run($customerId === null ? [] : [$customerId], function () use ($key, $canonical, $movements, $fingerprint, $effectiveDate, $description, $customerId, $reversesId, $systemRecovery): Journal {
            $existing = Journal::where('operation_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new DomainException('Operation key was already used with different inputs.');
                }

                return $existing;
            }
            $accounts = LedgerAccount::whereIn('id', array_keys($movements))->get()->keyBy('id');
            if ($accounts->count() !== count($movements)) {
                throw new DomainException('Unknown ledger account.');
            }
            $balances = [];
            foreach ($movements as $id => $amount) {
                $account = $accounts[$id];
                if ($account->currency !== 'NGN' || ($account->user_id !== null && $account->user_id !== $customerId)) {
                    throw new DomainException('Account currency or customer ownership mismatch.');
                }
                $balance = BigInteger::of($this->balance($account)->kobo)->plus($amount);
                if ($account->user_id !== null && $account->code === 'customer:'.$account->user_id.':wallet') {
                    $customer = User::findOrFail($account->user_id);
                    if ($balance->isPositive()) {
                        throw new DomainException('Insufficient wallet funds.');
                    }
                    // Validate the positive entitlement as well as the signed liability.
                    $balance->negated()->toInt();
                    if ($amount->isPositive() && ! $systemRecovery && (! $customer->is_active || ! $customer->hasVerifiedEmail())) {
                        throw new DomainException('Only active verified customers can spend.');
                    }
                }
                $balances[$id] = $balance->toInt();
            }
            if ($reversesId !== null) {
                $original = Journal::findOrFail($reversesId);
                $reversed = [];
                foreach ($original->entries as $entry) {
                    $reversed[$entry->account_id] = ($reversed[$entry->account_id] ?? BigInteger::zero())->minus($entry->amount->kobo);
                }
                ksort($reversed);
                $actual = $movements;
                ksort($actual);
                if (array_map(strval(...), $actual) !== array_map(strval(...), $reversed)) {
                    throw new DomainException('A reversal must exactly reverse its linked journal.');
                }
            }
            $journalId = DB::table('journals')->insertGetId([
                'operation_key' => $key, 'fingerprint' => $fingerprint, 'currency' => 'NGN',
                'effective_date' => $effectiveDate, 'posted_at' => $this->clock->now()->utc()->format('Y-m-d H:i:s.u'),
                'description' => $description, 'reverses_id' => $reversesId,
            ]);
            foreach ($canonical as $entry) {
                DB::table('ledger_entries')->insert(['journal_id' => $journalId, 'account_id' => $entry['account_id'], 'amount' => (new Money($entry['amount']))->kobo]);
            }
            foreach ($balances as $id => $balance) {
                DB::table('ledger_accounts')->where('id', $id)->update(['balance' => $balance]);
            }

            app(AuditTrail::class)->record('journal.posted', Journal::findOrFail($journalId), [
                'customer_id' => $customerId, 'operation_key' => $key, 'effective_date' => $effectiveDate,
                'entries' => $canonical, 'reverses_id' => $reversesId,
            ]);

            return Journal::findOrFail($journalId);
        });
    }

    /**
     * Posts the exact opposite of a journal without changing its original entries.
     */
    public function reverse(Journal $journal, string $key, string $effectiveDate, string $description, ?int $customerId = null): Journal
    {
        $entries = [];
        foreach ($journal->entries()->get() as $entry) {
            if ($entry->amount->kobo === PHP_INT_MIN) {
                // The positive counterpart needs two individually representable entries.
                $entries[] = ['account_id' => $entry->account_id, 'amount' => new Money(PHP_INT_MAX)];
                $entries[] = ['account_id' => $entry->account_id, 'amount' => new Money(1)];
            } else {
                $entries[] = ['account_id' => $entry->account_id, 'amount' => $entry->amount->negate()];
            }
        }

        return $this->post($key, $entries, $effectiveDate, $description, $customerId, $journal->id);
    }

    /**
     * Sums immutable entries, optionally through an economic effective date.
     */
    public function balance(LedgerAccount $account, ?string $effectiveThrough = null): Money
    {
        $query = $account->entries();
        if ($effectiveThrough !== null) {
            $query->whereHas('journal', fn ($query) => $query->where('effective_date', '<=', $effectiveThrough));
        }
        $sum = BigInteger::zero();
        foreach ($query->cursor() as $entry) {
            $sum = $sum->plus($entry->amount->kobo);
        }

        return new Money((string) $sum);
    }

    /**
     * Replaces cached account balances with totals derived from immutable entries.
     */
    public function rebuildProjections(): void
    {
        $this->transactions->run([], function (): void {
            foreach (LedgerAccount::cursor() as $account) {
                DB::table('ledger_accounts')->where('id', $account->id)->update(['balance' => $this->balance($account)->kobo]);
            }
        });
    }

    /**
     * Returns account IDs whose stored balances disagree with their entry totals.
     *
     * @return list<int>
     */
    public function projectionMismatches(): array
    {
        $mismatches = [];
        foreach (LedgerAccount::cursor() as $account) {
            if ($account->balance->kobo !== $this->balance($account)->kobo) {
                $mismatches[] = $account->id;
            }
        }

        return $mismatches;
    }
}
