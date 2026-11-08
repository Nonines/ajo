<script module lang="ts">
    import { index } from '@/routes/withdrawals';
    export const layout = { breadcrumbs: [{ title: 'Withdrawals', href: index() }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { formatMoney } from '@/lib/money';
    import { store as saveDestination } from '@/routes/destinations';
    import { show, store } from '@/routes/withdrawals';
    import type { Withdrawal } from '@/types/withdrawals';

    let { destinations, banks, requestKey, balance, debt, feeTiers, withdrawals }: {
        banks: { code: string; name: string }[];
        destinations: { id: number; label: string; accountName: string; accountNumber: string; bankName: string }[];
        requestKey: string;
        balance: string;
        debt: string;
        feeTiers: { through: string; fee: string }[];
        withdrawals: { data: Withdrawal[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();
    let amount = $state('');
    // Parse decimal naira into BigInt kobo for the estimate; the server validates and snapshots the actual fee.
    const kobo = $derived.by(() => {
        if (!/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/.test(amount)) return null;
        const [whole, fraction = ''] = amount.split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    });
    const fee = $derived(kobo === null ? null : feeTiers.find((tier) => kobo <= BigInt(tier.through))?.fee ?? null);
    const total = $derived(kobo === null || fee === null ? null : (kobo + BigInt(fee)).toString());
</script>

<AppHead title="Withdrawals" />
<div class="page-stack">
    <section class="page-intro">
        <p class="eyebrow">Your wallet / withdrawals</p>
        <h1 class="mt-4 font-display text-4xl font-black tracking-[-.045em]">Ready when you are.</h1>
        <p class="mt-4">Available: <strong>{formatMoney(balance)}</strong>. Staff review each request before payout.</p>
    </section>
    {#if debt !== '0'}
        <p class="panel">Recovery balance: {formatMoney(debt)}. Withdrawals are restricted until it is repaid or written off through staff approval. Incoming funds repay debt first.</p>
    {/if}
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="grid gap-4 panel">
            <h2 class="section-title">Request a withdrawal</h2>
            <Form {...store.form()} class="grid gap-4">
                {#snippet children({ errors, processing })}
                    <input type="hidden" name="request_key" value={requestKey} />
                    <Label for="destination">Saved destination</Label>
                    <select id="destination" name="destination_id" class="h-10 rounded-md border border-input bg-background px-3" required>
                        <option value="">Choose a bank account</option>
                        {#each destinations as destination (destination.id)}
                            <option value={destination.id}>{destination.label}: {destination.accountName} / {destination.bankName} / {destination.accountNumber}</option>
                        {/each}
                    </select>
                    <InputError message={errors.destination_id} />
                    <Label for="amount">Amount in naira</Label>
                    <Input id="amount" name="amount" bind:value={amount} inputmode="decimal" placeholder="1000.00" required />
                    <InputError message={errors.amount ?? errors.request_key} />
                    {#if total !== null && fee !== null}
                        <p aria-live="polite">Estimated fee: {formatMoney(fee)}. Total reserved: <strong>{formatMoney(total)}</strong>.</p>
                    {/if}
                    <p class="text-sm text-muted-foreground">The full amount and quoted fee leave your available balance while under review. You may cancel until final approval. You pay actual incurred processing fees, including verified excess fees, which may be recovered from your wallet or added to your recovery balance. Unused fees are returned.</p>
                    <Button type="submit" disabled={processing || debt !== '0' || destinations.length === 0}>Reserve and request review</Button>
                {/snippet}
            </Form>
        </section>
        <section class="grid gap-4 panel">
            <h2 class="section-title">Save a bank account</h2>
            <p>We resolve the account name before saving. You can use an account belonging to someone else.</p>
            <Form {...saveDestination.form()} class="grid gap-4" resetOnSuccess>
                {#snippet children({ errors, processing })}
                    <Label for="label">Label</Label><Input id="label" name="label" required maxlength={100} placeholder="Family account" />
                    <InputError message={errors.label} />
                    <Label for="bank-code">Bank</Label>
                    <select id="bank-code" name="bank_code" class="h-10 rounded-md border border-input bg-background px-3" required>
                        <option value="">Choose a bank</option>
                        {#each banks as bank (bank.code)}<option value={bank.code}>{bank.name}</option>{/each}
                    </select>
                    {#if banks.length === 0}<p role="status">The bank list is unavailable. Refresh this page to try again.</p>{/if}
                    <InputError message={errors.bank_code} />
                    <Label for="account-number">Account number</Label><Input id="account-number" name="account_number" inputmode="numeric" required minlength={10} maxlength={10} />
                    <InputError message={errors.account_number} />
                    <Button type="submit" variant="outline" disabled={processing || banks.length === 0}>Resolve and save</Button>
                {/snippet}
            </Form>
        </section>
    </div>
    <section class="grid gap-4">
        <h2 class="font-display text-2xl font-bold tracking-tight">Your requests.</h2>
        {#if withdrawals.data.length === 0}<p class="empty-state">No withdrawal requests yet. When you make one, you can follow its progress here.</p>{/if}
        {#each withdrawals.data as withdrawal (withdrawal.id)}
            <Link href={show(withdrawal.id)} class="flex flex-wrap justify-between gap-3 record-link">
                <span>{formatMoney(withdrawal.principal)} / {withdrawal.accountNumber}</span><span class="status-label">{withdrawal.state.replaceAll('_', ' ')}</span>
            </Link>
        {/each}
        <nav class="flex gap-4" aria-label="Withdrawal pages">
            {#if withdrawals.prev_page_url}<Link href={index({ query: { page: new URL(withdrawals.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
            {#if withdrawals.next_page_url}<Link href={index({ query: { page: new URL(withdrawals.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
        </nav>
    </section>
</div>
