<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Models\LedgerAccount;
use App\Models\PaymentException;
use App\Models\SavedDestination;
use App\Models\WithdrawalRequest;
use App\Support\Ledger;
use App\Support\Withdrawals;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exposes owned withdrawal reservations and masks destination details in customer responses.
 */
class WithdrawalController extends Controller
{
    /**
     * Lists reservations and real destinations with wallet value, recovery debt, and estimated fee tiers.
     */
    public function index(Request $request, Ledger $ledger, PaymentGateway $gateway): Response
    {
        $user = $request->user('web');
        abort_unless($user->is_active, 403);
        try {
            $banks = $gateway->banks();
        } catch (\Throwable) {
            $banks = [];
        }

        return Inertia::render('Withdrawals/Index', [
            'banks' => $banks,
            'destinations' => SavedDestination::where('user_id', $user->id)->where('origin', 'real')->orderBy('id')->get()
                ->map(fn (SavedDestination $saved): array => ['id' => $saved->id, 'label' => $saved->label,
                    'accountName' => $saved->details['account_name'], 'bankName' => $saved->details['bank_name'],
                    'accountNumber' => '******'.substr($saved->details['account_number'], -4)]),
            'requestKey' => (string) Str::uuid(),
            'balance' => (string) $ledger->balance(LedgerAccount::where('code', 'customer:'.$user->id.':wallet')->sole())->negate()->kobo,
            'debt' => (string) $ledger->balance(LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole())->kobo,
            'feeTiers' => array_map(static fn (array $tier): array => ['through' => (string) $tier[0], 'fee' => (string) $tier[1]], config('withdrawals.fee_tiers')),
            'withdrawals' => WithdrawalRequest::where('user_id', $user->id)->latest('id')->paginate(20)
                ->through(fn (WithdrawalRequest $withdrawal): array => $this->summary($withdrawal)),
        ]);
    }

    /**
     * Reserves funds against an owned destination and maps domain failures to form errors.
     */
    public function store(StoreWithdrawalRequest $request, Withdrawals $withdrawals): RedirectResponse
    {
        $destination = SavedDestination::where('user_id', $request->user('web')->id)->findOrFail($request->integer('destination_id'));
        try {
            $withdrawal = $withdrawals->request($request->user('web'), $destination, $request->money(), $request->string('request_key')->toString());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return to_route('withdrawals.show', $withdrawal);
    }

    /**
     * Shows an owned withdrawal and whether unresolved payout evidence needs investigation.
     */
    public function show(Request $request, WithdrawalRequest $withdrawal): Response
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($withdrawal->user_id === $request->user('web')->id, 404);

        return Inertia::render('Withdrawals/Show', ['withdrawal' => $this->summary($withdrawal),
            'investigation' => PaymentException::where('resource_type', 'withdrawal')->where('resource_id', (string) $withdrawal->id)->whereNull('resolved_at')->exists()]);
    }

    /**
     * Requests cancellation of an owned withdrawal subject to the service's approval cutoff.
     */
    public function destroy(Request $request, WithdrawalRequest $withdrawal, Withdrawals $withdrawals): RedirectResponse
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($withdrawal->user_id === $request->user('web')->id, 404);
        try {
            $withdrawals->cancel($request->user('web'), $withdrawal);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['withdrawal' => $exception->getMessage()]);
        }

        return to_route('withdrawals.show', $withdrawal);
    }

    /**
     * Masks bank details and exposes string amounts alongside the current review and cancellation status.
     *
     * @return array<string, mixed>
     */
    private function summary(WithdrawalRequest $withdrawal): array
    {
        return ['id' => $withdrawal->id, 'reference' => $withdrawal->reference, 'state' => (string) $withdrawal->state,
            'simulated' => $withdrawal->origin === 'simulated',
            'principal' => (string) $withdrawal->principal, 'reservedFee' => (string) $withdrawal->reserved_fee,
            'actualFee' => $withdrawal->accounted_fee === null ? null : (string) $withdrawal->accounted_fee,
            'accountName' => $withdrawal->destination['account_name'], 'bankName' => $withdrawal->destination['bank_name'],
            'accountNumber' => '******'.substr($withdrawal->destination['account_number'], -4),
            'requiresChecker' => app(Withdrawals::class)->requiresChecker($withdrawal),
            'expiresAt' => $withdrawal->expires_at->toIso8601String(), 'createdAt' => $withdrawal->created_at->toIso8601String(),
            'canCancel' => $withdrawal->approved_at === null && in_array((string) $withdrawal->state, ['requested', 'under_review'], true)];
    }
}
