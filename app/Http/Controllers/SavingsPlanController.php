<?php

namespace App\Http\Controllers;

use App\Contracts\Clock;
use App\Http\Requests\StoreSavingsPlanRequest;
use App\Http\Requests\UpdateSavingsPlanRequest;
use App\Models\ApprovalRequest;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\SavingsProductVersion;
use App\Support\Ledger\Ledger;
use App\Support\Savings\SavingsAccrual;
use App\Support\Savings\SavingsPlans;
use App\Support\Savings\SavingsSettlements;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exposes owned savings contracts, dated evidence, and customer lifecycle actions.
 */
class SavingsPlanController extends Controller
{
    /**
     * Lists currently offered product versions and the active customer's snapshotted plans.
     */
    public function index(Request $request, Ledger $ledger): Response
    {
        $user = $request->user('web');
        abort_unless($user->is_active, 403);
        $versions = SavingsProductVersion::whereHas('product', fn ($query) => $query->whereColumn('savings_products.current_version', 'savings_product_versions.version'))
            ->orderBy('id')->get()->filter(fn (SavingsProductVersion $version): bool => $version->contract['available'] ?? true)
            ->values()->map(fn (SavingsProductVersion $version): array => ['id' => $version->id, 'version' => $version->version, 'contract' => $this->transportContract($version->contract)]);
        $debt = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();

        return Inertia::render('Savings/Index', ['products' => $versions, 'requestKey' => (string) Str::uuid(),
            'remainingDebt' => (string) $ledger->balance($debt)->kobo,
            'plans' => SavingsPlan::where('user_id', $user->id)->latest('id')->paginate(20)->through(fn (SavingsPlan $plan): array => $this->summary($plan))]);
    }

    /**
     * Drafts a savings contract from validated terms and maps domain failures to form errors.
     */
    public function store(StoreSavingsPlanRequest $request, SavingsPlans $plans): RedirectResponse
    {
        try {
            $plan = $plans->draft($request->user('web'), SavingsProductVersion::findOrFail($request->integer('version_id')),
                $request->money(), $request->integer('days'), $request->string('request_key')->toString());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return to_route('savings.show', $plan);
    }

    /**
     * Shows an owned plan with accruals, settlement evidence, waiver status, and renewal options.
     */
    public function show(Request $request, SavingsPlan $plan, SavingsSettlements $settlements, Clock $clock, SavingsAccrual $accrual): Response
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($plan->user_id === $request->user('web')->id, 404);
        $settlement = DB::table('plan_settlements')->where('savings_plan_id', $plan->id)->first();
        $statement = null;

        // Keep settlement amounts as strings when transporting immutable financial evidence.
        if ($settlement !== null) {
            $statement = [];

            foreach (['principal', 'gross_interest', 'net_interest', 'withholding', 'debt_repayment', 'remaining_debt', 'wallet_credit', 'rounding_micro_kobo', 'journal_id', 'posted_at',
                'capitalized_interest', 'forfeiture', 'forfeited_capitalized', 'forfeited_payable', 'principal_penalty', 'waived_forfeiture', 'waived_penalty', 'incurred_fees', 'accrual_through'] as $field) {
                $statement[$field] = (string) $settlement->$field;
            }

            $statement['remaining_principal'] = (string) ($settlement->remaining_principal ?? $settlement->principal);
        }

        // Expose early-exit eligibility as guidance; the service rechecks it on confirmation.
        $exitUnavailable = null;

        try {
            $settlements->earlyEligible($plan);
        } catch (DomainException $exception) {
            $exitUnavailable = $exception->getMessage();
        }

        $source = SavingsProductVersion::findOrFail($plan->savings_product_version_id);
        $current = SavingsProductVersion::where('savings_product_id', $source->savings_product_id)->orderByDesc('version')->first();

        return Inertia::render('Savings/Show', ['plan' => $this->summary($plan), 'statement' => $statement,
            'preparationStatus' => $accrual->preparationStatus($plan->contract),
            'exitUnavailable' => $exitUnavailable,
            'rolloverOpen' => in_array((string) $plan->state, ['active', 'suspended'], true) && $clock->now()->lessThan($plan->matures_at),
            'renewalTerms' => $current === null ? [] : $this->transportContract($current->contract)['terms'],
            'childPlanId' => SavingsPlan::where('parent_plan_id', $plan->id)->value('id'),
            'waivers' => DB::table('plan_waivers')->where('savings_plan_id', $plan->id)->orderByDesc('id')->get()->map(fn ($waiver): array => [
                'id' => $waiver->id, 'interestCap' => (string) $waiver->interest_cap, 'principalCap' => (string) $waiver->principal_cap,
                'expiresAt' => $waiver->expires_at, 'status' => $waiver->used_at !== null ? 'used' : ($clock->now()->greaterThanOrEqualTo($waiver->expires_at) ? 'expired' : 'available')]),
            'waiverRequests' => ApprovalRequest::where('subject_type', SavingsPlan::class)->where('subject_id', $plan->id)->where('action', 'plan.waive')
                ->latest('id')->limit(20)->get()->map(fn (ApprovalRequest $approval): array => ['id' => $approval->id, 'state' => $approval->state]),
            'capitalizations' => DB::table('plan_capitalizations')->where('savings_plan_id', $plan->id)->orderByDesc('boundary_date')->paginate(30, ['*'], 'capitalizations_page')
                ->through(fn ($row): array => ['date' => $row->boundary_date, 'amount' => (string) $row->amount, 'cumulative' => (string) $row->cumulative_capitalized]),
            'investigation' => DB::table('savings_exceptions')->where('savings_plan_id', $plan->id)->exists(),
            'accruals' => DB::table('plan_accruals')->where('savings_plan_id', $plan->id)->orderByDesc('accrual_date')->paginate(30)
                ->through(fn ($row): array => ['date' => $row->accrual_date, 'microKobo' => $row->micro_kobo, 'recognized' => (string) $row->recognized_kobo, 'postedAt' => $row->posted_at])]);
    }

    /**
     * Returns a freshly checked early-exit quote for an owned plan and optional waiver.
     */
    public function preview(Request $request, SavingsPlan $plan, SavingsSettlements $settlements): JsonResponse
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($plan->user_id === $request->user('web')->id, 404);
        $request->validate(['waiver_id' => ['nullable', 'integer', 'min:1']]);

        try {
            return response()->json($settlements->preview($request->user('web'), $plan, $request->filled('waiver_id') ? $request->integer('waiver_id') : null));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }
    }

    /**
     * Dispatches a validated lifecycle action and presents domain failures as action errors.
     */
    public function update(UpdateSavingsPlanRequest $request, SavingsPlan $plan, SavingsPlans $plans, SavingsSettlements $settlements): RedirectResponse
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($plan->user_id === $request->user('web')->id, 404);
        $data = $request->validated();

        try {
            match ($data['action']) {
                'accept' => $plans->accept($request->user('web'), $plan),
                'fund' => $plans->fund($request->user('web'), $plan),
                'cancel' => $plans->cancel($request->user('web'), $plan),
                'terminate' => $settlements->terminate($request->user('web'), $plan, $data['token'], $request->filled('waiver_id') ? $request->integer('waiver_id') : null),
                'rollover' => $plans->electRollover($request->user('web'), $plan, $request->boolean('enabled'), $request->filled('days') ? $request->integer('days') : null,
                    $request->input('amount_mode'), $request->boolean('consent')),
                default => throw new DomainException('Unknown savings action.'),
            };
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        return to_route('savings.show', $plan);
    }

    /**
     * Serializes the plan snapshot and lifecycle timestamps for the customer interface.
     *
     * @return array<string, mixed>
     */
    private function summary(SavingsPlan $plan): array
    {
        return ['id' => $plan->id, 'state' => (string) $plan->state, 'contract' => $this->transportContract($plan->contract),
            'createdAt' => $plan->created_at->toIso8601String(), 'fundingDeadline' => $plan->funding_deadline->toIso8601String(),
            'fundedAt' => $plan->funded_at?->toIso8601String(), 'accrualStartsAt' => $plan->accrual_starts_at?->toIso8601String(),
            'maturesAt' => $plan->matures_at?->toIso8601String(), 'closureReason' => $plan->closure_reason,
            'parentPlanId' => $plan->parent_plan_id, 'rolloverElection' => $plan->rollover_election];
    }

    /**
     * Converts contract rates to strings while retaining numeric term durations for frontend controls.
     *
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function transportContract(array $contract): array
    {
        $contract['withholding_bp'] = (string) $contract['withholding_bp'];

        foreach (['forfeiture_bp', 'principal_penalty_bp'] as $key) {
            if (isset($contract[$key])) {
                $contract[$key] = (string) $contract[$key];
            }
        }

        if (isset($contract['annual_rate_bp'])) {
            $contract['annual_rate_bp'] = (string) $contract['annual_rate_bp'];
        }

        $contract['terms'] = array_map(static fn (array $term): array => ['days' => $term['days'], 'annual_rate_bp' => (string) $term['annual_rate_bp']], $contract['terms']);

        return $contract;
    }
}
