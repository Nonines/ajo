<script module lang="ts">
    import { dashboard } from '@/routes';
    export const layout = { breadcrumbs: [{ title: 'Home', href: dashboard() }] };
</script>

<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import Wallet from '@lucide/svelte/icons/wallet';
    import Sprout from '@lucide/svelte/icons/sprout';
    import AppHead from '@/components/AppHead.svelte';
    import { index as wallet } from '@/routes/wallet';
    import { index as savings } from '@/routes/savings';
    import { index as deposits } from '@/routes/deposits';
    import { index as withdrawals } from '@/routes/withdrawals';
    import { edit as editProfile } from '@/routes/profile';
    import { edit as editSecurity } from '@/routes/security';
</script>

<AppHead title="Your space" />
<div class="page-stack">
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="eyebrow mb-3 text-muted-foreground">A little intention, every day</p><h1 class="font-display text-3xl font-black tracking-tight sm:text-4xl">Hello, <span class="break-words">{page.props.auth.user.name}.</span></h1></div><span class="status-label bg-accent">Your next chapter starts here ↗︎</span></div>
    <section class="relative isolate overflow-hidden rounded-md border-2 border-foreground bg-foreground px-7 py-10 text-background sm:p-12">
        <div class="relative z-10 max-w-xl"><p class="eyebrow mb-5 inline-block bg-primary px-3 py-1 text-primary-foreground">For future you</p><h2 class="font-display text-4xl leading-[1.05] font-black tracking-[-.05em] sm:text-6xl">Big plans.<br />Little beginnings.</h2><p class="mt-5 max-w-sm text-sm leading-relaxed opacity-80">Make room for what matters. Start with your wallet, then find a savings plan that fits your next chapter.</p><Link href={deposits()} class="action-link mt-7 border-primary! shadow-none!">Add money<ArrowUpRight class="size-5" /></Link></div>
        <svg viewBox="0 0 240 240" class="absolute -right-20 -bottom-20 -z-0 size-80 text-primary/20 sm:right-0 sm:-bottom-28 sm:size-[440px]" aria-hidden="true" fill="none"><circle cx="120" cy="120" r="118" stroke="currentColor" /><circle cx="120" cy="120" r="85" stroke="currentColor" /><circle cx="120" cy="120" r="50" stroke="currentColor" /><path d="M120 0v240M0 120h240" stroke="currentColor" /></svg>
    </section>
    <div class="grid gap-5 md:grid-cols-2">
        <Link href={wallet()} class="group flex flex-col items-start rounded-md border border-foreground bg-primary p-7 text-primary-foreground sm:p-8"><div class="mb-8 flex w-full items-center justify-between"><Wallet class="size-7" strokeWidth={1.5} /><ArrowUpRight class="size-6 transition-transform motion-safe:group-hover:translate-x-1 motion-safe:group-hover:-translate-y-1" /></div><p class="eyebrow mb-3">The everyday starting point</p><h2 class="section-title">Your wallet</h2><p class="mt-3 max-w-sm text-sm leading-relaxed">Check your available balance and follow every transaction.</p><span class="mt-7 text-sm font-bold underline underline-offset-4">Open wallet</span></Link>
        <Link href={savings()} class="group flex flex-col items-start rounded-md border border-foreground bg-card p-7 sm:p-8"><div class="mb-8 flex w-full items-center justify-between"><Sprout class="size-7" strokeWidth={1.5} /><ArrowUpRight class="size-6 transition-transform motion-safe:group-hover:translate-x-1 motion-safe:group-hover:-translate-y-1" /></div><p class="eyebrow mb-3 text-muted-foreground">A little patience, a clear plan</p><h2 class="section-title">Your savings</h2><p class="mt-3 max-w-sm text-sm leading-relaxed text-muted-foreground">Explore fixed-term products or check in on your existing plans.</p><span class="mt-7 text-sm font-bold underline underline-offset-4">Explore savings</span></Link>
    </div>
    <section class="grid gap-6 border-y border-foreground/20 py-7 sm:grid-cols-[1fr_2fr]"><div><p class="eyebrow mb-2 text-muted-foreground">The small things matter</p><h2 class="section-title">Make it yours.</h2></div><div class="grid gap-1 sm:grid-cols-3"><Link href={withdrawals()} class="flex items-center justify-between gap-3 rounded-sm p-4 text-sm font-semibold hover:bg-muted">Withdraw money <span aria-hidden="true">↗︎</span></Link><Link href={editProfile()} class="flex items-center justify-between gap-3 rounded-sm p-4 text-sm font-semibold hover:bg-muted">Your profile <span aria-hidden="true">↗︎</span></Link><Link href={editSecurity()} class="flex items-center justify-between gap-3 rounded-sm p-4 text-sm font-semibold hover:bg-muted">Account security <span aria-hidden="true">↗︎</span></Link></div></section>
</div>
