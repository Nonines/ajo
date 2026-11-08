<script module lang="ts">
    import { index } from '@/routes/savings';
    export const layout = { breadcrumbs: [{ title: 'Savings', href: index() }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { formatMoney } from '@/lib/money';
    import { formatBasisPoints } from '@/lib/rates';
    import { show, store } from '@/routes/savings';
    import type { SavingsProductContract, SavingsPlan } from '@/types/savings';

    let { products, requestKey, remainingDebt, plans }: {
        products: { id: number; version: number; contract: SavingsProductContract }[];
        requestKey: string;
        remainingDebt: string;
        plans: { data: SavingsPlan[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();
</script>

<AppHead title="Fixed-term savings" />
<div class="page-stack">
    <section class="page-intro">
        <p class="eyebrow">Your savings / fixed term</p>
        <h1 class="mt-4 font-display text-4xl font-black tracking-[-.045em]">Give your plans time.</h1>
        <p class="mt-4">Set aside a lump sum from your wallet. Review the fixed rate, interest mode and withdrawal terms before funding.</p>
    </section>
    {#if remainingDebt !== '0'}
        <p class="panel">Recovery balance: {formatMoney(remainingDebt)}. Repay this balance before funding a new plan. Existing contracts continue earning under their original terms; net maturity proceeds repay debt first.</p>
    {/if}
    <section class="grid gap-6 md:grid-cols-2" aria-label="Available savings products">
        {#if products.length === 0}<p>No products are currently available. Your existing savings remain below.</p>{/if}
        {#each products as product (product.id)}
            <article class="grid gap-4 panel">
                <h2 class="section-title border-b border-foreground/20 pb-5">{product.contract.name}</h2>
                <p class="whitespace-pre-wrap">{product.contract.disclosure}</p>
                <p>Principal: {formatMoney(product.contract.minimum)} to {formatMoney(product.contract.maximum)}. {product.contract.interest === 'simple' ? 'Simple interest' : product.contract.interest === 'daily' ? 'Daily compounding' : 'Compounding every 30 days'} on Actual/365. Withholding: {formatBasisPoints(product.contract.withholding_bp)} of retained interest. Plan fees: {formatMoney(product.contract.fee)}.</p>
                {#if product.contract.lock === 'terminable'}
                    <p>Early exit after {product.contract.minimum_holding_days} full days from accrual start. Interest forfeiture: {formatBasisPoints(product.contract.forfeiture_bp ?? '0')}, including compounded interest. Original-principal penalty: {formatBasisPoints(product.contract.principal_penalty_bp ?? '0')}.</p>
                {:else}<p>Uncancellable until maturity.</p>{/if}
                <Form {...store.form()} class="grid gap-4">
                    {#snippet children({ errors, processing })}
                        <input type="hidden" name="request_key" value={requestKey} />
                        <input type="hidden" name="version_id" value={product.id} />
                        <Label for={'amount-' + product.id}>Principal in naira</Label>
                        <Input id={'amount-' + product.id} name="amount" inputmode="decimal" placeholder="10000.00" required />
                        <InputError message={errors.amount} />
                        <Label for={'days-' + product.id}>Term and nominal annual rate</Label>
                        <select id={'days-' + product.id} name="days" class="h-10 rounded-md border border-input bg-background px-3 text-foreground" required>
                            {#each product.contract.terms as term (term.days)}
                                <option value={term.days}>{term.days} days / {formatBasisPoints(term.annual_rate_bp)} per year</option>
                            {/each}
                        </select>
                        <InputError message={errors.days ?? errors.version_id ?? errors.request_key} />
                        <Button type="submit" disabled={processing}>Review contract</Button>
                    {/snippet}
                </Form>
            </article>
        {/each}
    </section>
    <section class="grid gap-4">
        <h2 class="font-display text-2xl font-bold tracking-tight">Your plans.</h2>
        {#if plans.data.length === 0}<p class="empty-state">A little room for future you. Your savings plans will appear here once you create one.</p>{/if}
        {#each plans.data as plan (plan.id)}
            <Link href={show(plan.id)} class="flex flex-wrap justify-between gap-3 record-link">
                <span>{plan.contract.name} / {plan.contract.days} days</span>
                <span class="flex flex-wrap items-center gap-4"><strong class="font-mono">{formatMoney(plan.contract.principal)}</strong><span class="status-label">{plan.state.replaceAll('_', ' ')}</span></span>
            </Link>
        {/each}
        <nav class="flex gap-4" aria-label="Savings pages">
            {#if plans.prev_page_url}<Link href={index({ query: { page: new URL(plans.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
            {#if plans.next_page_url}<Link href={index({ query: { page: new URL(plans.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
        </nav>
    </section>
</div>
