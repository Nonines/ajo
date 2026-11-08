<script module lang="ts">
    import { index } from '@/routes/notifications';
    export const layout = { breadcrumbs: [{ title: 'Notifications', href: index() }] };
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { formatMoney } from '@/lib/money';
    import { show } from '@/routes/deposits';
    import { update } from '@/routes/notifications';
    import { show as savingsShow } from '@/routes/savings';
    import { show as withdrawalShow } from '@/routes/withdrawals';

    type Notice = { id: string; readAt: string | null; createdAt: string; data: Record<string, string> };
    let { notifications }: { notifications: { data: Notice[]; prev_page_url: string | null; next_page_url: string | null } } = $props();
</script>

<AppHead title="Notifications" />
<div class="page-stack">
    <div class="page-intro"><p class="eyebrow mb-4 text-muted-foreground">Stay in the loop</p><h1>Your updates.</h1><p class="mt-4">A record of what’s happening with your money. Deposits, savings, and withdrawals, all in one place.</p></div>
    {#if notifications.data.length === 0}<div class="empty-state"><p class="mb-2 font-display text-xl font-bold text-foreground">All quiet for now.</p><p>Your payment and savings updates will appear here.</p></div>{/if}
    {#each notifications.data as notice (notice.id)}
        <section class="panel grid gap-4 {notice.readAt ? '' : 'border-l-4 border-l-foreground'}"><div class="flex flex-wrap items-center justify-between gap-3"><span class="status-label">{notice.readAt ? 'Read' : 'New update'}</span><time datetime={notice.createdAt} class="text-xs text-muted-foreground">{new Date(notice.createdAt).toLocaleString()}</time></div>
            {#if notice.data.type.startsWith('withdrawal_')}
                <h2 class="text-xl font-bold">Withdrawal {notice.data.type.slice(11).replaceAll('_', ' ')}</h2>
                <p>Principal: {formatMoney(notice.data.principal)}. Reserved fee: {formatMoney(notice.data.reserved_fee)}. Actual fee: {notice.data.actual_fee === 'unverified' ? 'awaiting verification' : formatMoney(notice.data.actual_fee)}. Remaining recovery balance: {formatMoney(notice.data.remaining_debt)}.</p>
                <Link href={withdrawalShow(Number(notice.data.withdrawal_id))} class="underline">View withdrawal</Link>
            {:else if notice.data.type.startsWith('savings_waiver_')}
                <h2 class="text-xl font-bold">Savings waiver {notice.data.type.slice(15)}</h2>
                {#if notice.data.interest_cap}<p>Interest-forfeiture cap: {formatMoney(notice.data.interest_cap)}. Principal-penalty cap: {formatMoney(notice.data.principal_cap)}. Expires {notice.data.expires_at}.</p>{/if}
                <Link href={savingsShow(Number(notice.data.plan_id))} class="underline">View savings plan</Link>
            {:else if notice.data.type.startsWith('savings_')}
                <h2 class="text-xl font-bold">Savings {notice.data.type.slice(8).replaceAll('_', ' ')}</h2>
                <p>Principal: {formatMoney(notice.data.principal)}. Gross interest: {formatMoney(notice.data.gross_interest)}. Withholding: {formatMoney(notice.data.withholding)}. Net interest: {formatMoney(notice.data.net_interest)}. Applied to recovery: {formatMoney(notice.data.debt_repayment)}. Paid to wallet: {formatMoney(notice.data.wallet_credit)}. Remaining recovery balance: {formatMoney(notice.data.remaining_debt)}.</p>
                {#if notice.data.forfeiture}<p>Interest forfeited: {formatMoney(notice.data.forfeiture)}. Principal penalty: {formatMoney(notice.data.principal_penalty)}. Waived forfeiture: {formatMoney(notice.data.waived_forfeiture)}. Waived penalty: {formatMoney(notice.data.waived_penalty)}.</p>{/if}
                <Link href={savingsShow(Number(notice.data.plan_id))} class="underline">View settlement and renewal history</Link>
            {:else}
            <h2 class="text-xl font-bold">Payment {notice.data.type}</h2>
            <p>Net value: {formatMoney(notice.data.net)}. Applied to debt: {formatMoney(notice.data.debt_repayment)}. Remaining debt: {formatMoney(notice.data.remaining_debt)}.</p>
            <Link href={show(notice.data.reference)} class="break-all underline">View {notice.data.reference}</Link>
            {/if}
            {#if !notice.readAt}<Button variant="outline" onclick={() => router.patch(update(notice.id))}>Mark as read</Button>{:else}<p class="text-sm text-muted-foreground">Read</p>{/if}
        </section>
    {/each}
    <nav class="flex gap-4" aria-label="Notification pages">
        {#if notifications.prev_page_url}<Link href={index({ query: { page: new URL(notifications.prev_page_url).searchParams.get('page') } })}>Previous</Link>{/if}
        {#if notifications.next_page_url}<Link href={index({ query: { page: new URL(notifications.next_page_url).searchParams.get('page') } })}>Next</Link>{/if}
    </nav>
</div>
