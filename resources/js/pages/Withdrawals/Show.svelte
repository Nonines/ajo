<script module lang="ts">
    import { index } from '@/routes/withdrawals';
    export const layout = { breadcrumbs: [{ title: 'Withdrawals', href: index() }] };
</script>

<script lang="ts">
    import { Form, Link, usePoll } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { formatMoney } from '@/lib/money';
    import { destroy } from '@/routes/withdrawals';
    import type { Withdrawal } from '@/types/withdrawals';

    let { withdrawal, investigation }: { withdrawal: Withdrawal; investigation: boolean } = $props();
    const polling = usePoll(5000, { only: ['withdrawal', 'investigation'] }, { autoStart: false });
    $effect(() => {
        if (['requested', 'under_review', 'approved', 'queued_for_payout'].includes(withdrawal.state)) polling.start();
        else polling.stop();
    });
</script>

<AppHead title="Withdrawal status" />
<div class="flex flex-1 flex-col gap-8 p-6 sm:p-10">
    {#if withdrawal.simulated}<p class="rounded-md border-2 border-foreground bg-card p-6">This is an explicitly simulated payout. No real provider transfer is sent.</p>{/if}
    <section class="rounded-md border-2 border-foreground bg-primary p-6 text-primary-foreground shadow-[4px_4px_0_var(--foreground)]">
        <p class="font-mono text-xs font-bold tracking-widest uppercase">Withdrawal / {withdrawal.state.replaceAll('_', ' ')}</p>
        <h1 class="mt-4 font-display text-4xl font-black">{formatMoney(withdrawal.principal)}</h1>
        <p class="mt-4 break-all text-sm">Reference: {withdrawal.reference}</p>
    </section>
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6" aria-live="polite">
        <h2 class="text-2xl font-bold">Your payout details</h2>
        <dl class="grid grid-cols-2 gap-3">
            <dt>Destination</dt><dd>{withdrawal.accountName} / {withdrawal.bankName} / {withdrawal.accountNumber}</dd>
            <dt>Reserved fee</dt><dd>{formatMoney(withdrawal.reservedFee)}</dd>
            <dt>Actual incurred fee</dt><dd>{withdrawal.actualFee === null ? 'Awaiting verification' : formatMoney(withdrawal.actualFee)}</dd>
            <dt>Staff review</dt><dd>{withdrawal.requiresChecker ? 'Maker and a different checker' : 'One authorized reviewer'}</dd>
            <dt>Review deadline</dt><dd>{new Date(withdrawal.expiresAt).toLocaleString()}</dd>
        </dl>
        <p>Uncertain payouts retain their reservation. Confirmed returns repay recovery debt before any remainder becomes available in your wallet.</p>
        {#if investigation}<p role="status">Staff are investigating provider evidence or a fee adjustment. A verified paid payout is never sent again to resolve a fee difference.</p>{/if}
        {#if withdrawal.canCancel}
            <Form {...destroy.form(withdrawal.id)}>
                {#snippet children({ errors, processing })}
                    <Button type="submit" variant="outline" disabled={processing}>Cancel request</Button>
                    <InputError message={errors.withdrawal} />
                {/snippet}
            </Form>
        {/if}
        <Link href={index()} class="underline">Back to withdrawals</Link>
    </section>
</div>
