<script module lang="ts">
    import { index } from '@/routes/deposits';
    export const layout = { breadcrumbs: [{ title: 'Deposits', href: index() }] };
</script>

<script lang="ts">
    import { Link, router, usePoll } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { formatMoney } from '@/lib/money';
    import { confirm } from '@/routes/deposits';
    import { index as wallet } from '@/routes/wallet';

    type Deposit = { reference: string; state: string; requestedNet: string; gross: string; fee: string; walletCredit: string; debtRepayment: string; recognizedAt: string | null; accessCode: string | null };
    type Reversal = { id: number; principal: string; fee: string; walletCollected: string; receivableCreated: string };
    let { deposit, remainingDebt, reversals }: { deposit: Deposit; remainingDebt: string; reversals: Reversal[] } = $props();
    let checkoutError = $state('');
    let opening = $state(false);
    const polling = usePoll(5000, { only: ['deposit', 'remainingDebt', 'reversals'] }, { autoStart: false });
    $effect(() => {
        if (['initiated', 'pending_confirmation'].includes(deposit.state)) polling.start();
        else polling.stop();
    });

    type Popup = { resumeTransaction: (code: string) => void };
    async function openCheckout(): Promise<void> {
        opening = true;
        checkoutError = '';
        try {
            const providerWindow = window as typeof window & { PaystackPop?: new () => Popup };
            if (!providerWindow.PaystackPop) {
                await new Promise<void>((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://js.paystack.co/v2/inline.js';
                    script.onload = () => resolve();
                    script.onerror = () => { script.remove(); reject(new Error('Checkout could not load. Please try again.')); };
                    document.head.appendChild(script);
                });
            }
            if (!providerWindow.PaystackPop || !deposit.accessCode) throw new Error('Checkout is unavailable. Request verification below.');
            new providerWindow.PaystackPop().resumeTransaction(deposit.accessCode);
        } catch (error) {
            checkoutError = error instanceof Error ? error.message : 'Checkout could not open.';
        } finally {
            opening = false;
        }
    }
</script>

<AppHead title="Deposit status" />
<div class="flex flex-1 flex-col gap-8 p-6 sm:p-10">
    <section class="rounded-md border-2 border-foreground bg-primary p-6 text-primary-foreground shadow-[4px_4px_0_var(--foreground)]">
        <p class="font-mono text-xs font-bold tracking-widest uppercase">Test payment / {deposit.state.replaceAll('_', ' ')}</p>
        <h1 class="mt-4 font-display text-4xl font-black">{formatMoney(deposit.requestedNet)}</h1>
        <p class="mt-4 break-all text-sm">Reference: {deposit.reference}</p>
    </section>
    <section class="grid gap-4 rounded-md border-2 border-foreground bg-card p-6" aria-live="polite">
        {#if deposit.recognizedAt}
            <h2 class="text-2xl font-bold">Verified deposit receipt</h2>
            <dl class="grid grid-cols-2 gap-3">
                <dt>Total charged</dt><dd class="text-right font-mono">{formatMoney(deposit.gross)}</dd>
                <dt>Processing fee</dt><dd class="text-right font-mono">{formatMoney(deposit.fee)}</dd>
                <dt>Net customer value</dt><dd class="text-right font-mono">{formatMoney(deposit.requestedNet)}</dd>
                <dt>Applied to debt</dt><dd class="text-right font-mono">{formatMoney(deposit.debtRepayment)}</dd>
                <dt>Wallet credit</dt><dd class="text-right font-mono">{formatMoney(deposit.walletCredit)}</dd>
            </dl>
        {:else}
            <h2 class="text-2xl font-bold">Awaiting verified payment</h2>
            <p>Wallet credit is available after confirmation. A closed checkout or delayed response does not establish the payment's final outcome.</p>
            {#if deposit.accessCode && ['initiated', 'pending_confirmation'].includes(deposit.state)}
                <Button onclick={openCheckout} disabled={opening}>{opening ? 'Opening checkout…' : 'Pay with Paystack'}</Button>
            {/if}
            {#if checkoutError}<p role="alert" class="text-destructive">{checkoutError}</p>{/if}
        {/if}
        <Button variant="outline" onclick={() => router.post(confirm(deposit.reference))}>Request verification</Button>
        <Link href={wallet()} class="underline">View wallet</Link>
    </section>
    {#each reversals as reversal (reversal.id)}
        <section class="rounded-md border-2 border-foreground bg-card p-6">
            <h2 class="text-xl font-bold">Verified reversal</h2>
            <dl class="mt-4 grid grid-cols-2 gap-3">
                <dt>Principal recovered</dt><dd class="text-right">{formatMoney(reversal.principal)}</dd>
                <dt>Attributable fees</dt><dd class="text-right">{formatMoney(reversal.fee)}</dd>
                <dt>Collected from wallet</dt><dd class="text-right">{formatMoney(reversal.walletCollected)}</dd>
                <dt>Added to recovery balance</dt><dd class="text-right">{formatMoney(reversal.receivableCreated)}</dd>
            </dl>
        </section>
    {/each}
    {#if remainingDebt !== '0'}<p class="rounded-md border-2 border-foreground bg-card p-6">Remaining recovery balance: <strong>{formatMoney(remainingDebt)}</strong>. Incoming funds repay this before any wallet remainder becomes available.</p>{/if}
</div>
