<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import House from '@lucide/svelte/icons/house';
    import Wallet from '@lucide/svelte/icons/wallet';
    import Sprout from '@lucide/svelte/icons/sprout';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import Bell from '@lucide/svelte/icons/bell';
    import ChevronDown from '@lucide/svelte/icons/chevron-down';
    import type { Snippet } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { Toaster } from '@/components/ui/sonner';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { dashboard, logout } from '@/routes';
    import { index as wallet } from '@/routes/wallet';
    import { index as savings } from '@/routes/savings';
    import { index as withdrawals } from '@/routes/withdrawals';
    import { index as notifications } from '@/routes/notifications';
    import { edit as profile } from '@/routes/profile';
    import type { BreadcrumbItem } from '@/types';

    let { breadcrumbs = [], children }: { breadcrumbs?: BreadcrumbItem[]; children?: Snippet } = $props();
    const url = currentUrlState();
    const navigation = [
        { title: 'Home', route: dashboard(), icon: House },
        { title: 'Wallet', route: wallet(), icon: Wallet },
        { title: 'Savings', route: savings(), icon: Sprout },
        { title: 'Withdrawals', route: withdrawals(), icon: ArrowUpRight },
    ];
    const user = $derived(page.props.auth.user);
    const isActive = (path: string) => url.currentUrl === path || url.currentUrl.startsWith(`${path}/`);
    let accountMenu = $state<HTMLDetailsElement>();
    function closeAccountMenu() {
        accountMenu?.removeAttribute('open');
    }
</script>

<svelte:window
    onkeydown={(event) => {
        if (event.key === 'Escape' && accountMenu?.open) {
            closeAccountMenu();
            accountMenu.querySelector('summary')?.focus();
        }
    }}
    onclick={(event) => {
        if (accountMenu?.open && event.target instanceof Node && !accountMenu.contains(event.target)) closeAccountMenu();
    }}
/>

<div class="customer-app min-h-svh">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <header class="border-b border-foreground/20 bg-background">
        <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-5 sm:px-8 lg:px-12">
            <Link href={dashboard()} class="flex items-center gap-2" aria-label="Ajo home">
                <AppLogoIcon class="size-10" /><span class="font-display text-3xl font-black tracking-tighter">ajo<span class="text-secondary">.</span></span>
            </Link>
            <nav aria-label="Main navigation" class="hidden h-full items-center gap-1 md:flex">
                {#each navigation as item (item.title)}
                    <Link href={item.route} aria-current={isActive(item.route.url) ? 'page' : undefined} class="app-nav-link {isActive(item.route.url) ? 'bg-foreground text-background' : 'hover:bg-muted'}">{item.title}</Link>
                {/each}
            </nav>
            <div class="flex items-center gap-3 sm:gap-5">
                <Link href={notifications()} aria-label="Notifications" aria-current={isActive(notifications.url()) ? 'page' : undefined} class="flex size-11 items-center justify-center rounded-full border border-foreground/20 hover:bg-accent"><Bell class="size-5" /></Link>
                <details bind:this={accountMenu} class="relative">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-full border border-foreground/20 p-1 pr-3 [&::-webkit-details-marker]:hidden" aria-label="Account menu">
                        <span class="flex size-8 items-center justify-center rounded-full bg-secondary font-bold text-secondary-foreground">{user.name.slice(0, 1).toUpperCase()}</span><ChevronDown class="size-4" />
                    </summary>
                    <div class="absolute right-0 z-50 mt-3 w-64 max-w-[calc(100vw-2.5rem)] border border-foreground bg-popover p-3 shadow-[4px_4px_0_var(--foreground)]">
                        <p class="truncate px-3 pt-2 font-bold">{user.name}</p>
                        <p class="truncate px-3 pb-3 text-xs text-muted-foreground">{user.email}</p>
                        <Link href={profile()} onclick={closeAccountMenu} class="block border-t border-foreground/20 px-3 py-3 text-sm font-semibold hover:bg-accent">Account settings ↗︎</Link>
                        <Link href={logout()} as="button" onclick={() => { closeAccountMenu(); router.flushAll(); }} data-test="logout-button" class="w-full px-3 py-3 text-left text-sm hover:bg-muted">Log out</Link>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <main id="main-content" tabindex="-1" class="mx-auto min-h-[calc(100svh-12rem)] max-w-7xl px-5 py-8 outline-none sm:px-8 sm:py-10 lg:px-12">
        {#if breadcrumbs.length > 0}
            <nav aria-label="Breadcrumb" class="mb-7 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span class="font-mono tracking-wider uppercase">Your space</span>
                {#each breadcrumbs as breadcrumb, i (i)}
                    <span aria-hidden="true">/</span>
                    <Link href={breadcrumb.href} class="hover:text-foreground">{breadcrumb.title === 'Dashboard' ? 'Home' : breadcrumb.title}</Link>
                {/each}
            </nav>
        {/if}
        {@render children?.()}
    </main>

    <footer class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-5 pt-8 pb-28 text-xs text-muted-foreground sm:px-8 md:pb-8 lg:px-12">
        <span class="font-mono tracking-wider uppercase">A little today. A bigger tomorrow.</span>
        <span>Fictional platform · Test payments only</span>
    </footer>
    <nav aria-label="Mobile navigation" class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-foreground bg-background pb-[env(safe-area-inset-bottom)] md:hidden">
        {#each navigation as item (item.title)}
            <Link href={item.route} aria-current={isActive(item.route.url) ? 'page' : undefined} class="flex min-h-18 min-w-0 flex-col items-center justify-center gap-1 text-[11px] font-semibold {isActive(item.route.url) ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}"><item.icon class="size-5" />{item.title}</Link>
        {/each}
    </nav>
    <Toaster position="top-right" />
</div>
