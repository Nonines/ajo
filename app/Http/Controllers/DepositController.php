<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepositRequest;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\PaymentReversal;
use App\Support\Ledger\Ledger;
use App\Support\Payments\Deposits;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves customer checkout and receipts with explicit ownership checks and string monetary values.
 */
class DepositController extends Controller
{
    /**
     * Lists the active customer's deposits alongside checkout availability and outstanding recovery debt.
     */
    public function index(Request $request, Ledger $ledger): Response
    {
        $user = $request->user('web');
        abort_unless($user->is_active, 403);
        $receivable = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->first();

        return Inertia::render('Deposits/Index', [
            'requestKey' => (string) Str::uuid(), 'checkoutAvailable' => str_starts_with((string) config('payments.secret_key'), 'sk_test_'),
            'remainingDebt' => $receivable === null ? '0' : (string) $ledger->balance($receivable)->kobo,
            'deposits' => Deposit::where('user_id', $user->id)->orderByDesc('id')->paginate(20)->through(fn (Deposit $deposit): array => $this->summary($deposit)),
        ]);
    }

    /**
     * Initializes a validated checkout and maps domain failures to the amount field.
     */
    public function store(StoreDepositRequest $request, Deposits $deposits): RedirectResponse
    {
        abort_unless(str_starts_with((string) config('payments.secret_key'), 'sk_test_'), 503, 'Paystack test checkout is not configured.');

        try {
            $deposit = $deposits->initialize($request->user('web'), $request->money(), $request->validated('request_key'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return to_route('deposits.show', $deposit->reference);
    }

    /**
     * Shows an owned deposit with checkout access and only financially posted reversal allocations.
     */
    public function show(Request $request, Deposit $deposit, Ledger $ledger): Response
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($deposit->user_id === $request->user('web')->id, 404);
        $receivable = LedgerAccount::where('code', 'customer:'.$deposit->user_id.':reversal_receivable')->sole();

        return Inertia::render('Deposits/Show', ['deposit' => $this->summary($deposit) + ['accessCode' => $deposit->origin === 'simulated' ? null : $deposit->access_code],
            'remainingDebt' => (string) $ledger->balance($receivable)->kobo,
            'reversals' => PaymentReversal::where('deposit_id', $deposit->id)->whereNotNull('journal_id')->orderBy('id')->get()->map(fn (PaymentReversal $effect): array => [
                'id' => $effect->id, 'principal' => (string) $effect->principal, 'fee' => (string) $effect->fee,
                'walletCollected' => (string) $effect->wallet_collected, 'receivableCreated' => (string) $effect->receivable_created,
            ]),
        ]);
    }

    /**
     * Serializes deposit evidence with kobo amounts as strings for lossless browser transport.
     *
     * @return array<string, int|string|null>
     */
    private function summary(Deposit $deposit): array
    {
        return ['id' => $deposit->id, 'reference' => $deposit->reference, 'state' => (string) $deposit->state,
            'origin' => $deposit->origin,
            'requestedNet' => (string) $deposit->requested_net, 'gross' => (string) $deposit->gross_amount,
            'fee' => (string) $deposit->fee_amount, 'walletCredit' => (string) $deposit->wallet_credit,
            'debtRepayment' => (string) $deposit->debt_repayment, 'recognizedAt' => $deposit->recognized_at?->toIso8601String()];
    }
}
