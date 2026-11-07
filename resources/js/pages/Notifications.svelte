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
<div class="flex flex-1 flex-col gap-6 p-6 sm:p-10">
    <h1 class="font-display text-4xl font-black">Your updates.</h1>
    {#if notifications.data.length === 0}<p>No notifications yet.</p>{/if}
    {#each notifications.data as notice (notice.id)}
        <section class="grid gap-3 rounded-md border-2 border-foreground bg-card p-6">
            {#if notice.data.type.startsWith('withdrawal_')}
                <h2 class="text-xl font-bold">Withdrawal {notice.data.type.slice(11).replaceAll('_', ' ')}</h2>
                <p>Principal: {formatMoney(notice.data.principal)}. Reserved fee: {formatMoney(notice.data.reserved_fee)}. Actual fee: {notice.data.actual_fee === 'unverified' ? 'awaiting verification' : formatMoney(notice.data.actual_fee)}. Remaining recovery balance: {formatMoney(notice.data.remaining_debt)}.</p>
                <Link href={withdrawalShow(Number(notice.data.withdrawal_id))} class="underline">View withdrawal</Link>
            {:else if notice.data.type === 'savings_maturity'}
                <h2 class="text-xl font-bold">Your savings matured</h2>
                <p>Principal: {formatMoney(notice.data.principal)}. Gross interest: {formatMoney(notice.data.gross_interest)}. Withholding: {formatMoney(notice.data.withholding)}. Net interest: {formatMoney(notice.data.net_interest)}. Applied to recovery: {formatMoney(notice.data.debt_repayment)}. Paid to wallet: {formatMoney(notice.data.wallet_credit)}. Remaining recovery balance: {formatMoney(notice.data.remaining_debt)}.</p>
                <Link href={savingsShow(Number(notice.data.plan_id))} class="underline">View maturity statement</Link>
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
