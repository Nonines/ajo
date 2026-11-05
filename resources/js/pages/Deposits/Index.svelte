<script module lang="ts">
    import { index } from '@/routes/deposits';
    export const layout = { breadcrumbs: [{ title: 'Deposits', href: index() }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { formatMoney } from '@/lib/money';
    import { show, store } from '@/routes/deposits';

    type Deposit = { id: number; reference: string; state: string; requestedNet: string };
    let { requestKey, checkoutAvailable, remainingDebt, deposits }: {
        requestKey: string;
        checkoutAvailable: boolean;
        remainingDebt: string;
        deposits: { data: Deposit[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();
</script>

<AppHead title="Add money" />
<div class="flex flex-1 flex-col gap-8 p-6 sm:p-10">
    <section class="rounded-md border-2 border-foreground bg-primary p-6 text-primary-foreground shadow-[4px_4px_0_var(--foreground)]">
        <p class="font-mono text-xs font-bold tracking-widest uppercase">Your wallet / add money</p>
        <h1 class="mt-4 font-display text-4xl font-black">Make room for your next goal.</h1>
        <p class="mt-4">Choose the net amount to add. Paystack adds its processing fee at checkout. Payments are test-mode only.</p>
    </section>
    {#if remainingDebt !== '0'}
        <section class="rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-lg font-bold">Recovery balance: {formatMoney(remainingDebt)}</h2>
            <p class="mt-2">Incoming funds first repay this balance. Your receipt shows how much repays debt and how much reaches your available wallet. Recovery can also apply after a verified payment reversal, including incurred fees.</p>
        </section>
    {/if}
    <section class="rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="mb-4 text-2xl font-bold">Start a deposit</h2>
        {#if checkoutAvailable}
            <Form {...store.form()} class="grid max-w-lg gap-4">
                {#snippet children({ errors, processing })}
                    <input type="hidden" name="request_key" value={requestKey} />
                    <Label for="amount">Net amount in naira</Label>
                    <Input id="amount" name="amount" type="text" inputmode="decimal" placeholder="5000.00" required aria-describedby="deposit-limits" />
                    <InputError message={errors.amount} />
                    <InputError message={errors.request_key} />
                    <p id="deposit-limits" class="text-sm text-muted-foreground">NGN 1,000 to NGN 100,000,000, subject to provider limits. Actual incurred processing fees remain payable if a payment is reversed. Verified reversal shortfalls are recovered from incoming funds before wallet credit.</p>
                    <Button type="submit" disabled={processing}>Continue to payment</Button>
                {/snippet}
            </Form>
        {:else}
            <p>Test checkout is not configured yet. You can still view your deposit history.</p>
        {/if}
    </section>
    <section class="rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="text-2xl font-bold">Deposit history</h2>
        {#if deposits.data.length === 0}<p class="mt-4 text-muted-foreground">Your deposits will appear here.</p>{/if}
        <ul class="mt-4 divide-y divide-border">
            {#each deposits.data as deposit (deposit.id)}
                <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                    <Link href={show(deposit.reference)} class="font-semibold underline">{formatMoney(deposit.requestedNet)}</Link>
                    <span class="text-sm">{deposit.state.replaceAll('_', ' ')}</span>
                </li>
            {/each}
        </ul>
        <nav class="mt-4 flex gap-4" aria-label="Deposit pages">
            {#if deposits.prev_page_url}<Link href={index({ query: { page: new URL(deposits.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
            {#if deposits.next_page_url}<Link href={index({ query: { page: new URL(deposits.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
        </nav>
    </section>
</div>
