<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsPlanRequest;
use App\Models\LedgerAccount;
use App\Models\SavingsPlan;
use App\Models\SavingsProductVersion;
use App\Support\Ledger;
use App\Support\SavingsPlans;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SavingsPlanController extends Controller
{
    public function index(Request $request, Ledger $ledger): Response
    {
        $user = $request->user('web');
        abort_unless($user->is_active, 403);
        $versions = SavingsProductVersion::whereHas('product', fn ($query) => $query->whereColumn('savings_products.current_version', 'savings_product_versions.version'))
            ->orderBy('id')->get()->map(fn (SavingsProductVersion $version): array => ['id' => $version->id, 'version' => $version->version, 'contract' => $this->transportContract($version->contract)]);
        $debt = LedgerAccount::where('code', 'customer:'.$user->id.':reversal_receivable')->sole();

        return Inertia::render('Savings/Index', ['products' => $versions, 'requestKey' => (string) Str::uuid(),
            'remainingDebt' => (string) $ledger->balance($debt)->kobo,
            'plans' => SavingsPlan::where('user_id', $user->id)->latest('id')->paginate(20)->through(fn (SavingsPlan $plan): array => $this->summary($plan))]);
    }

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

    public function show(Request $request, SavingsPlan $plan): Response
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($plan->user_id === $request->user('web')->id, 404);
        $settlement = DB::table('plan_settlements')->where('savings_plan_id', $plan->id)->first();
        $statement = null;
        if ($settlement !== null) {
            $statement = [];
            foreach (['principal', 'gross_interest', 'net_interest', 'withholding', 'debt_repayment', 'remaining_debt', 'wallet_credit', 'rounding_micro_kobo', 'journal_id', 'posted_at'] as $field) {
                $statement[$field] = (string) $settlement->$field;
            }
        }

        return Inertia::render('Savings/Show', ['plan' => $this->summary($plan), 'statement' => $statement,
            'investigation' => DB::table('savings_exceptions')->where('savings_plan_id', $plan->id)->exists(),
            'accruals' => DB::table('plan_accruals')->where('savings_plan_id', $plan->id)->orderByDesc('accrual_date')->paginate(30)
                ->through(fn ($row): array => ['date' => $row->accrual_date, 'microKobo' => $row->micro_kobo, 'recognized' => (string) $row->recognized_kobo, 'postedAt' => $row->posted_at])]);
    }

    public function update(Request $request, SavingsPlan $plan, SavingsPlans $plans): RedirectResponse
    {
        abort_unless($request->user('web')->is_active, 403);
        abort_unless($plan->user_id === $request->user('web')->id, 404);
        $data = $request->validate(['action' => ['required', 'in:accept,fund,cancel']]);
        try {
            match ($data['action']) {
                'accept' => $plans->accept($request->user('web'), $plan),
                'fund' => $plans->fund($request->user('web'), $plan),
                'cancel' => $plans->cancel($request->user('web'), $plan),
                default => throw new DomainException('Unknown savings action.'),
            };
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        return to_route('savings.show', $plan);
    }

    /** @return array<string, mixed> */
    private function summary(SavingsPlan $plan): array
    {
        return ['id' => $plan->id, 'state' => (string) $plan->state, 'contract' => $this->transportContract($plan->contract),
            'createdAt' => $plan->created_at->toIso8601String(), 'fundingDeadline' => $plan->funding_deadline->toIso8601String(),
            'fundedAt' => $plan->funded_at?->toIso8601String(), 'accrualStartsAt' => $plan->accrual_starts_at?->toIso8601String(),
            'maturesAt' => $plan->matures_at?->toIso8601String(), 'closureReason' => $plan->closure_reason];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function transportContract(array $contract): array
    {
        $contract['withholding_bp'] = (string) $contract['withholding_bp'];
        if (isset($contract['annual_rate_bp'])) {
            $contract['annual_rate_bp'] = (string) $contract['annual_rate_bp'];
        }
        $contract['terms'] = array_map(static fn (array $term): array => ['days' => $term['days'], 'annual_rate_bp' => (string) $term['annual_rate_bp']], $contract['terms']);

        return $contract;
    }
}
