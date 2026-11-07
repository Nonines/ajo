<script module lang="ts">
    import { index } from '@/routes/savings';
    export const layout = { breadcrumbs: [{ title: 'Savings', href: index() }] };
</script>

<script lang="ts">
    import { Form, Link, usePoll } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { formatMoney } from '@/lib/money';
    import { formatBasisPoints } from '@/lib/rates';
    import { show, update } from '@/routes/savings';
    import type { SavingsPlan } from '@/types/savings';

    let { plan, statement, investigation, accruals }: {
        plan: SavingsPlan;
        statement: Record<string, string> | null;
        investigation: boolean;
        accruals: { data: { date: string; microKobo: string; recognized: string; postedAt: string }[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();
    const polling = usePoll(5000, { only: ['plan', 'statement', 'investigation', 'accruals'] }, { autoStart: false });
    $effect(() => {
        if (['closed', 'cancelled'].includes(plan.state)) polling.stop();
        else polling.start();
    });
    const lagosDate = (date: string) => new Intl.DateTimeFormat('en-NG', { timeZone: 'Africa/Lagos', dateStyle: 'long', timeStyle: 'short' }).format(new Date(date));
</script>

<AppHead title={plan.contract.name} />
<div class="flex flex-1 flex-col gap-6 p-6 sm:p-10">
    <h1 class="font-display text-4xl font-black">{plan.contract.name}</h1>
    <p class="font-mono uppercase">{plan.state.replaceAll('_', ' ')} / plan {plan.id}</p>
    {#if investigation}<p role="status" class="rounded-md border-2 border-foreground bg-card p-6">This plan requires investigation. Settlement is held while staff review the accrual evidence.</p>{/if}
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="text-2xl font-bold">Your fixed contract</h2>
        <p>Principal: {formatMoney(plan.contract.principal)}. Term: {plan.contract.days} full days. Nominal annual rate: {formatBasisPoints(plan.contract.annual_rate_bp)}, simple interest on Actual/365.</p>
        <p>Locked until maturity. Partial funding, top-ups, and early withdrawal are unavailable. Plan fees: {formatMoney(plan.contract.fee)}. Withholding: {formatBasisPoints(plan.contract.withholding_bp)} of gross interest, rounded to kobo at settlement.</p>
        <p>Interest starts at the next Lagos midnight after funding. The initial partial day earns no interest. At maturity principal and net interest repay any recovery balance first, with the remainder paid into your wallet.</p>
        <p class="whitespace-pre-wrap">{plan.contract.disclosure}</p>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div><dt class="font-bold">Created (Lagos)</dt><dd>{lagosDate(plan.createdAt)}</dd></div>
            <div><dt class="font-bold">Funding deadline (Lagos)</dt><dd>{lagosDate(plan.fundingDeadline)}</dd></div>
            {#if plan.fundedAt}<div><dt class="font-bold">Funded (Lagos)</dt><dd>{lagosDate(plan.fundedAt)}</dd></div>{/if}
            {#if plan.accrualStartsAt}<div><dt class="font-bold">Accrual starts (Lagos)</dt><dd>{lagosDate(plan.accrualStartsAt)}</dd></div>{/if}
            {#if plan.maturesAt}<div><dt class="font-bold">Maturity (Lagos)</dt><dd>{lagosDate(plan.maturesAt)}</dd></div>{/if}
        </dl>
        {#if plan.state === 'draft' || plan.state === 'pending_funding'}
            <Form {...update.form(plan.id)} class="grid gap-3">
                {#snippet children({ errors, processing })}
                    <InputError message={errors.action} />
                    <div class="flex flex-wrap gap-4">
                        {#if plan.state === 'draft'}<Button type="submit" name="action" value="accept" disabled={processing}>Accept contract</Button>
                        {:else}<Button type="submit" name="action" value="fund" disabled={processing}>Fund {formatMoney(plan.contract.principal)} from wallet</Button>{/if}
                        <Button type="submit" name="action" value="cancel" variant="outline" disabled={processing}>Cancel unfunded plan</Button>
                    </div>
                {/snippet}
            </Form>
        {/if}
        {#if plan.closureReason}<p>Closure: {plan.closureReason.replaceAll('_', ' ')}.</p>{/if}
    </section>
    {#if statement}
        <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-2xl font-bold">Maturity statement</h2>
            <dl class="grid gap-3 sm:grid-cols-2">
                {#each [['principal', 'Principal'], ['gross_interest', 'Gross interest'], ['withholding', 'Withholding tax'], ['net_interest', 'Net interest'], ['remaining_debt', 'Remaining recovery balance'], ['debt_repayment', 'Applied to recovery'], ['wallet_credit', 'Paid to wallet']] as [key, label] (key)}
                    <div><dt class="font-bold">{label}</dt><dd>{formatMoney(statement[key])}</dd></div>
                {/each}
            </dl>
            <p>Plan fees, penalties and forfeitures: {formatMoney('0')}. Final rounding adjustment: {statement.rounding_micro_kobo} micro-kobo (1,000,000 per kobo). Posted {statement.posted_at} UTC.</p>
        </section>
    {/if}
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="text-2xl font-bold">Interest history</h2>
        <p>Daily amounts retain fractions in micro-kobo. The ledger recognizes changes in rounded cumulative interest.</p>
        {#if accruals.data.length === 0}<p>No completed accrual days yet.</p>{/if}
        {#each accruals.data as accrual (accrual.date)}
            <div class="flex flex-wrap justify-between gap-2 border-b border-border py-3"><span>{accrual.date} Lagos</span><span>{accrual.microKobo} micro-kobo earned / {formatMoney(accrual.recognized)} total recognized</span></div>
        {/each}
        <nav class="flex gap-4" aria-label="Interest pages">
            {#if accruals.prev_page_url}<Link href={show(plan.id, { query: { page: new URL(accruals.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
            {#if accruals.next_page_url}<Link href={show(plan.id, { query: { page: new URL(accruals.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
        </nav>
    </section>
</div>
