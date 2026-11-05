<script module lang="ts">
    import { dashboard } from '@/routes';
    import { index } from '@/routes/wallet';

    export const layout = {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Wallet', href: index() },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { formatMoney } from '@/lib/money';
    import { index as deposits } from '@/routes/deposits';
    import { index as notifications } from '@/routes/notifications';

    type Transaction = { id: number; amount: string; description: string; effective_date: string; posted_at: string };
    let { balance, history }: {
        balance: string;
        history: { data: Transaction[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();

</script>

<AppHead title="Your wallet" />

<div class="flex flex-1 flex-col gap-8 p-6 sm:p-10">
    <section class="rounded-md border-2 border-foreground bg-primary p-6 text-primary-foreground shadow-[4px_4px_0_var(--foreground)] sm:p-10">
        <p class="font-mono text-xs font-bold tracking-widest uppercase">Your wallet / NGN</p>
        <h1 class="mt-4 font-display text-4xl font-black tracking-tight sm:text-5xl">{formatMoney(balance)}</h1>
        <p class="mt-4">Available balance</p>
        <nav class="mt-4 flex flex-wrap gap-4" aria-label="Wallet actions"><Link href={deposits()} class="font-bold underline">Add money</Link><Link href={notifications()} class="underline">Notifications</Link></nav>
    </section>
    <section class="flex flex-col gap-4 rounded-md border-2 border-foreground bg-card p-6">
        <h2 class="font-display text-2xl font-bold">Transaction history</h2>
        {#if history.data.length === 0}
            <p class="text-muted-foreground">No transactions yet. Your wallet starts at zero.</p>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b"><th class="p-3">Description</th><th class="p-3">Effective date</th><th class="p-3">Posted at (UTC)</th><th class="p-3 text-right">Amount</th></tr></thead>
                    <tbody>
                        {#each history.data as transaction (transaction.id)}
                            <tr class="border-b"><td class="p-3">{transaction.description}</td><td class="p-3">{transaction.effective_date}</td><td class="p-3">{transaction.posted_at}</td><td class="p-3 text-right font-mono">{formatMoney(transaction.amount)}</td></tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            <nav class="flex gap-4" aria-label="Transaction pages">
                {#if history.prev_page_url}<Link href={index({ query: { page: new URL(history.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
                {#if history.next_page_url}<Link href={index({ query: { page: new URL(history.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
            </nav>
        {/if}
    </section>
    <p class="text-xs text-muted-foreground">Ajo is a fictional savings platform. Payments are test-mode only.</p>
</div>
