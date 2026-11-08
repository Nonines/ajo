<script module lang="ts">
    import { dashboard } from '@/routes';
    import { index } from '@/routes/wallet';

    export const layout = {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Wallet', href: index() },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { formatMoney } from '@/lib/money';
    import { index as deposits } from '@/routes/deposits';
    import { index as withdrawals } from '@/routes/withdrawals';

    type Transaction = { id: number; amount: string; description: string; effective_date: string; posted_at: string };
    let { balance, history }: {
        balance: string;
        history: { data: Transaction[]; prev_page_url: string | null; next_page_url: string | null };
    } = $props();

</script>

<AppHead title="Your wallet" />

<div class="page-stack">
    <section class="money-summary">
        <p class="eyebrow">Your wallet / NGN</p>
        <h1 class="mt-4 font-display text-[clamp(2rem,7vw,4.5rem)] leading-tight font-black tracking-[-.05em] break-words">{formatMoney(balance)}</h1>
        <p class="mt-4">Available balance</p>
        <nav class="mt-7 flex flex-wrap gap-3" aria-label="Wallet actions"><Link href={deposits()} class="inline-flex min-h-12 items-center gap-6 rounded-sm border-2 border-[#18181b] bg-[#18181b] px-5 py-3 text-sm font-bold text-[#fff9ed] hover:bg-[#303033]">Add money <span aria-hidden="true">↙︎</span></Link><Link href={withdrawals()} class="inline-flex min-h-12 items-center gap-6 rounded-sm border-2 border-[#18181b] px-5 py-3 text-sm font-bold hover:bg-[#18181b]/10">Withdraw <span aria-hidden="true">↗︎</span></Link></nav>
    </section>
    <section class="flex flex-col gap-4 panel">
        <h2 class="section-title">Transaction history</h2>
        {#if history.data.length === 0}
            <div class="empty-state"><p class="mb-2 font-display text-xl font-bold text-foreground">Your first move starts here.</p><p>No transactions yet. Add money to your wallet to get started.</p><Link href={deposits()} class="mt-4 inline-block font-semibold text-foreground underline underline-offset-4">Add money ↗︎</Link></div>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b"><th class="p-3">Description</th><th class="p-3">Effective date</th><th class="p-3">Posted at (UTC)</th><th class="p-3 text-right">Amount</th></tr></thead>
                    <tbody>
                        {#each history.data as transaction (transaction.id)}
                            <tr class="border-b"><td class="p-3">{transaction.description}</td><td class="p-3">{transaction.effective_date}</td><td class="p-3">{transaction.posted_at}</td><td class="p-3 text-right font-mono whitespace-nowrap">{formatMoney(transaction.amount)}</td></tr>
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
</div>
