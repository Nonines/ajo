<script module lang="ts">
    import { index } from '@/routes/savings';
    export const layout = { breadcrumbs: [{ title: 'Savings', href: index() }] };
</script>

<script lang="ts">
    import { Form, Link, useHttp, usePoll } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { formatMoney } from '@/lib/money';
    import { formatBasisPoints } from '@/lib/rates';
    import { show, update, preview } from '@/routes/savings';
    import type { SavingsPlan } from '@/types/savings';

    let { plan, statement, investigation, accruals, exitUnavailable, rolloverOpen, renewalTerms, childPlanId, waivers, waiverRequests, capitalizations, preparationStatus }: {
        plan: SavingsPlan;
        preparationStatus: 'ready' | 'preparing' | 'failed';
        exitUnavailable: string | null;
        rolloverOpen: boolean;
        renewalTerms: { days: number; annual_rate_bp: string }[];
        childPlanId: number | null;
        waivers: { id: number; interestCap: string; principalCap: string; expiresAt: string; status: string }[];
        waiverRequests: { id: number; state: string }[];
        capitalizations: { data: { date: string; amount: string; cumulative: string }[]; prev_page_url: string | null; next_page_url: string | null };
        statement: Record<string, string> | null;
        investigation: boolean;
        accruals: { data: { date: string; microKobo: string; recognized: string; postedAt: string }[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();
    const polling = usePoll(5000, { only: ['plan', 'statement', 'investigation', 'accruals', 'exitUnavailable', 'rolloverOpen', 'childPlanId', 'waivers', 'waiverRequests', 'capitalizations', 'preparationStatus'] }, { autoStart: false });
    $effect(() => {
        if (['closed', 'cancelled'].includes(plan.state)) polling.stop();
        else polling.start();
    });
    const exit = useHttp<{ waiver_id: string; action: string }, Record<string, string>>({ waiver_id: '', action: 'preview' });
    let quote = $state<Record<string, string> | null>(null);
    let renewalEnabled = $state(false);
    let renewalDays = $state<number | undefined>(30);
    let renewalMode = $state<'original_principal' | 'all_net'>('original_principal');
    // Initialize renewal controls once per plan so polling does not overwrite unsaved customer choices.
    let renewalPlanId = $state<number | null>(null);
    $effect(() => {
        if (renewalPlanId !== plan.id) {
            renewalEnabled = plan.rolloverElection?.enabled ?? false;
            renewalDays = plan.rolloverElection?.days ?? plan.contract.days;
            renewalMode = plan.rolloverElection?.amount_mode ?? 'original_principal';
            renewalPlanId = plan.id;
        }
    });
    // Clear the previous quote before requesting a fresh server-bound confirmation token.
    async function previewExit(event: SubmitEvent) {
        event.preventDefault();
        quote = null;
        try {
            quote = await exit.submit(preview(plan.id));
        } catch {
            // Validation errors are shown beside the preview controls.
        }
    }
    const localDate = (date: string) => new Intl.DateTimeFormat('en-NG', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(date));
</script>

<AppHead title={plan.contract.name} />
<div class="flex flex-1 flex-col gap-6 p-6 sm:p-10">
    <h1 class="font-display text-4xl font-black">{plan.contract.name}</h1>
    <p class="font-mono uppercase">{plan.state.replaceAll('_', ' ')} / plan {plan.id}</p>
    {#if investigation}<p role="status" class="rounded-md border-2 border-foreground bg-card p-6">This plan requires investigation. Settlement is held while staff review the accrual evidence.</p>{/if}
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="text-2xl font-bold">Your fixed contract</h2>
        <p>Principal: {formatMoney(plan.contract.principal)}. Term: {plan.contract.days} full days. Nominal annual rate: {formatBasisPoints(plan.contract.annual_rate_bp)}, {plan.contract.interest === 'simple' ? 'simple interest' : plan.contract.interest === 'daily' ? 'daily compounding' : 'compounding every 30 days'} on Actual/365.</p>
        {#if plan.contract.lock === 'terminable'}
            <p>Early exit is available after {plan.contract.minimum_holding_days} full days from accrual start. Interest forfeiture: {formatBasisPoints(plan.contract.forfeiture_bp ?? '0')}, including compounded interest. Original-principal penalty: {formatBasisPoints(plan.contract.principal_penalty_bp ?? '0')}. Suspensions and recovery restrictions block early exit.</p>
        {:else}<p>This contract is uncancellable until maturity.</p>{/if}
        <p>Partial funding and top-ups are unavailable. Plan fees: {formatMoney(plan.contract.fee)}. Withholding: {formatBasisPoints(plan.contract.withholding_bp)} of retained interest, including compounded interest, rounded to kobo at settlement.</p>
        <p>Interest starts at the next midnight in the application timezone after funding. The initial partial day earns no interest. At maturity principal and net interest repay any recovery balance first, with the remainder paid into your wallet.</p>
        <p class="whitespace-pre-wrap">{plan.contract.disclosure}</p>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div><dt class="font-bold">Created (local time)</dt><dd>{localDate(plan.createdAt)}</dd></div>
            <div><dt class="font-bold">Funding deadline (local time)</dt><dd>{localDate(plan.fundingDeadline)}</dd></div>
            {#if plan.fundedAt}<div><dt class="font-bold">Funded (local time)</dt><dd>{localDate(plan.fundedAt)}</dd></div>{/if}
            {#if plan.accrualStartsAt}<div><dt class="font-bold">Accrual starts (local time)</dt><dd>{localDate(plan.accrualStartsAt)}</dd></div>{/if}
            {#if plan.maturesAt}<div><dt class="font-bold">Maturity (local time)</dt><dd>{localDate(plan.maturesAt)}</dd></div>{/if}
        </dl>
        {#if plan.state === 'draft' || plan.state === 'pending_funding'}
            {#if preparationStatus === 'preparing'}<p role="status">Your interest calculation is being prepared. Funding will be available when it finishes. Your funding deadline stays the same.</p>{/if}
            {#if preparationStatus === 'failed'}<p role="status">This contract exceeds supported calculation limits. Cancel it and choose a different amount or term.</p>{/if}
            <Form {...update.form(plan.id)} class="grid gap-3">
                {#snippet children({ errors, processing })}
                    <InputError message={errors.action} />
                    <div class="flex flex-wrap gap-4">
                        {#if plan.state === 'draft'}<Button type="submit" name="action" value="accept" disabled={processing}>Accept contract</Button>
                        {:else}<Button type="submit" name="action" value="fund" disabled={processing || preparationStatus !== 'ready'}>Fund {formatMoney(plan.contract.principal)} from wallet</Button>{/if}
                        <Button type="submit" name="action" value="cancel" variant="outline" disabled={processing}>Cancel unfunded plan</Button>
                    </div>
                {/snippet}
            </Form>
        {/if}
        {#if plan.closureReason}<p>Closure: {plan.closureReason.replaceAll('_', ' ')}.</p>{/if}
    </section>
    {#if rolloverOpen}
        <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-2xl font-bold">Rollover at maturity</h2>
            <p>Rollover is off until you choose it. Renewal uses all product terms effective at maturity, including the rate, lock, holding period, penalties, withholding and limits. An eligible election continues into each child until you disable it.</p>
            <p>If renewal is unavailable or recovery restrictions apply, the plan settles to your wallet under recovery priority. Each child's interest starts at the next midnight in the application timezone after actual funding. Delays can leave a gap with no interest.</p>
            <Form {...update.form(plan.id)} class="grid gap-3">
                {#snippet children({ errors, processing })}
                    <input type="hidden" name="action" value="rollover" />
                    <input type="hidden" name="enabled" value={renewalEnabled ? '1' : '0'} />
                    <label class="flex items-center gap-3"><input type="checkbox" bind:checked={renewalEnabled} /> Enable rollover</label>
                    {#if renewalEnabled}
                        <Label for="renewal-days">Renewal duration in days</Label>
                        <Input id="renewal-days" name="days" type="number" min="30" step="30" bind:value={renewalDays} required />
                        <p class="text-sm">Currently offered: {renewalTerms.map((term) => term.days).join(', ')} days. Availability is checked again at maturity.</p>
                        <Label for="renewal-mode">Amount to renew</Label>
                        <select id="renewal-mode" name="amount_mode" bind:value={renewalMode} class="h-10 rounded-md border border-input bg-background px-3 text-foreground">
                            <option value="original_principal">Original funded principal only</option><option value="all_net">All net maturity proceeds</option>
                        </select>
                        <label class="flex items-start gap-3"><input name="consent" type="checkbox" value="1" required /> I consent to all product terms effective at maturity and to the possible interest gap described above.</label>
                    {/if}
                    <InputError message={errors.action ?? errors.days ?? errors.amount_mode ?? errors.consent ?? errors.enabled} />
                    <Button type="submit" disabled={processing}>Save rollover election</Button>
                {/snippet}
            </Form>
        </section>
    {/if}
    {#if plan.parentPlanId || childPlanId}
        <section class="grid gap-3 rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-2xl font-bold">Renewal history</h2>
            {#if plan.parentPlanId}<Link href={show(plan.parentPlanId)} class="underline">Parent plan {plan.parentPlanId}</Link>{/if}
            {#if childPlanId}<Link href={show(childPlanId)} class="underline">Renewed into plan {childPlanId}</Link>{/if}
        </section>
    {/if}
    {#if ['active', 'suspended'].includes(plan.state) && plan.contract.lock === 'terminable'}
        <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-2xl font-bold">Close this plan early</h2>
            {#if exitUnavailable}<p role="status">{exitUnavailable}</p>
            {:else}
                <p>The preview includes interest through the last completed date in the application timezone. The current partial day earns no interest. Review the deductions before confirming full closure.</p>
                <form onsubmit={previewExit} class="grid gap-3">
                    <Label for="exit-waiver">Approved waiver</Label>
                    <select id="exit-waiver" bind:value={exit.waiver_id} onchange={() => quote = null} class="h-10 rounded-md border border-input bg-background px-3 text-foreground">
                        <option value="">Use contractual deductions</option>
                        {#each waivers.filter((waiver) => waiver.status === 'available') as waiver (waiver.id)}<option value={String(waiver.id)}>Waiver {waiver.id}: forfeiture cap {formatMoney(waiver.interestCap)}, principal cap {formatMoney(waiver.principalCap)}</option>{/each}
                    </select>
                    <InputError message={exit.errors.action ?? exit.errors.waiver_id} />
                    <Button type="submit" disabled={exit.processing}>Preview early closure</Button>
                </form>
                {#if quote}
                    <p>Interest accrued through {quote.accrual_through} (application date).</p>
                    <dl class="grid gap-3 sm:grid-cols-2">
                        {#each [['principal', 'Original principal'], ['gross_interest', 'Total gross interest'], ['capitalized_interest', 'Previously compounded interest'], ['forfeiture', 'Interest forfeited'], ['principal_penalty', 'Principal penalty'], ['waived_forfeiture', 'Forfeiture waived'], ['waived_penalty', 'Principal penalty waived'], ['withholding', 'Withholding on retained interest'], ['wallet_credit', 'Paid to wallet']] as [key, label] (key)}
                            <div><dt class="font-bold">{label}</dt><dd>{formatMoney(quote[key])}</dd></div>
                        {/each}
                    </dl>
                    <Form {...update.form(plan.id)} class="grid gap-3">
                        {#snippet children({ errors, processing })}
                            <input type="hidden" name="action" value="terminate" /><input type="hidden" name="token" value={quote?.token ?? ''} /><input type="hidden" name="waiver_id" value={quote?.waiver_id ?? ''} />
                            <InputError message={errors.action ?? errors.token ?? errors.waiver_id} />
                            <Button type="submit" disabled={processing} variant="destructive">Confirm full early closure</Button>
                        {/snippet}
                    </Form>
                {/if}
            {/if}
        </section>
    {/if}
    {#if waivers.length > 0 || waiverRequests.length > 0}
        <section class="grid gap-3 rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-2xl font-bold">Penalty waivers</h2>
            {#each waiverRequests as request (request.id)}<p>Request {request.id}: {request.state}.</p>{/each}
            {#each waivers as waiver (waiver.id)}<p>Waiver {waiver.id}: {waiver.status}. Forfeiture cap {formatMoney(waiver.interestCap)}; principal-penalty cap {formatMoney(waiver.principalCap)}. Expires {waiver.expiresAt} UTC.</p>{/each}
        </section>
    {/if}
    {#if statement}
        <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-2xl font-bold">Settlement statement</h2>
            <dl class="grid gap-3 sm:grid-cols-2">
                {#each [['principal', 'Original principal'], ['remaining_principal', 'Principal after penalty'], ['gross_interest', 'Gross interest'], ['capitalized_interest', 'Previously compounded interest'], ['forfeiture', 'Interest forfeited'], ['principal_penalty', 'Principal penalty'], ['waived_forfeiture', 'Forfeiture waived'], ['waived_penalty', 'Principal penalty waived'], ['incurred_fees', 'Plan processing fees'], ['withholding', 'Withholding tax'], ['net_interest', 'Net interest'], ['remaining_debt', 'Remaining recovery balance'], ['debt_repayment', 'Applied to recovery'], ['wallet_credit', 'Paid to wallet']] as [key, label] (key)}
                    <div><dt class="font-bold">{label}</dt><dd>{formatMoney(statement[key])}</dd></div>
                {/each}
            </dl>
            <p>Final rounding adjustment: {statement.rounding_micro_kobo} micro-kobo (1,000,000 per kobo). Posted {statement.posted_at} UTC.</p>
        </section>
    {/if}
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="text-2xl font-bold">Compounding history</h2>
        {#if capitalizations.data.length === 0}<p>No interest capitalization events.</p>{/if}
        {#each capitalizations.data as capitalization (capitalization.date)}
            <p>{capitalization.date} (application date): {formatMoney(capitalization.amount)} added to interest-bearing principal, {formatMoney(capitalization.cumulative)} total compounded.</p>
        {/each}
        <nav class="flex gap-4" aria-label="Compounding pages">
            {#if capitalizations.prev_page_url}<Link href={show(plan.id, { query: { capitalizations_page: new URL(capitalizations.prev_page_url).searchParams.get('capitalizations_page') } })}>Previous</Link>{/if}
            {#if capitalizations.next_page_url}<Link href={show(plan.id, { query: { capitalizations_page: new URL(capitalizations.next_page_url).searchParams.get('capitalizations_page') } })}>Next</Link>{/if}
        </nav>
    </section>
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="text-2xl font-bold">Interest history</h2>
        <p>Daily amounts retain fractions in micro-kobo. The ledger recognizes changes in rounded cumulative interest.</p>
        {#if accruals.data.length === 0}<p>No completed accrual days yet.</p>{/if}
        {#each accruals.data as accrual (accrual.date)}
            <div class="flex flex-wrap justify-between gap-2 border-b border-border py-3"><span>{accrual.date} (application date)</span><span>{accrual.microKobo} micro-kobo earned / {formatMoney(accrual.recognized)} total recognized</span></div>
        {/each}
        <nav class="flex gap-4" aria-label="Interest pages">
            {#if accruals.prev_page_url}<Link href={show(plan.id, { query: { page: new URL(accruals.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
            {#if accruals.next_page_url}<Link href={show(plan.id, { query: { page: new URL(accruals.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
        </nav>
    </section>
</div>
