<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ArrowUpRight from '@lucide/svelte/icons/arrow-up-right';
    import Wallet from '@lucide/svelte/icons/wallet';
    import Sprout from '@lucide/svelte/icons/sprout';
    import ScanLine from '@lucide/svelte/icons/scan-line';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import SavingsArtwork from '@/components/SavingsArtwork.svelte';
    import { dashboard, home, login, register } from '@/routes';

    const auth = $derived(page.props.auth);
    const start = $derived(auth.user ? dashboard() : register());
    const features = [
        { number: '01', icon: Wallet, title: 'A home for your money.', description: 'Add money to your naira wallet. See what is available and follow every transaction in one place.' },
        { number: '02', icon: Sprout, title: 'Give your plans time.', description: 'Explore fixed-term savings. Review the rate, duration, and withdrawal terms before you commit.' },
        { number: '03', icon: ScanLine, title: 'The details, in the open.', description: 'Follow deposits, savings, and withdrawal requests with clear statuses and a record of what happened.' },
    ];
    const questions = [
        { title: 'What can I do with Ajo?', answer: 'Create an account, explore a naira wallet, add test funds, and review available fixed-term savings products. You can also request a withdrawal to a saved bank account.' },
        { title: 'Does Ajo handle real money?', answer: 'No. Ajo is a fictional savings platform. Payments and payouts operate in test mode. Do not use real funds.' },
        { title: 'How do fixed-term savings work?', answer: 'Choose an available product, an amount, and a term. Review the full contract before accepting and funding it from your wallet. Rates, fees, withholding, and early-exit conditions depend on the product and are shown before you commit.' },
        { title: 'Can I withdraw whenever I want?', answer: 'Wallet withdrawals require staff review before payout and may include processing fees. Fixed-term savings have their own lock and early-exit conditions. Check the terms of your plan before funding it.' },
    ];
</script>

<AppHead title="A little today. A bigger tomorrow.">
    <meta name="description" content="A little intention goes a long way. Explore Ajo, a fictional naira wallet and fixed-term savings platform. Test payments only." />
</AppHead>

<div class="marketing overflow-x-clip">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <div class="border-b border-foreground/20 bg-primary px-5 py-2 text-center font-mono text-[10px] font-semibold tracking-wider text-primary-foreground sm:text-xs">A SMALL START. A BIGGER POSSIBILITY. <span class="ml-3 opacity-75">FICTIONAL PLATFORM / TEST MODE</span></div>
    <header class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-6 sm:px-8 lg:px-12">
        <Link href={home()} class="flex items-center gap-2" aria-label="Ajo home"><AppLogoIcon class="size-11" /><span class="font-display text-4xl font-black tracking-tighter">ajo<span class="text-secondary">.</span></span></Link>
        <nav aria-label="About Ajo" class="hidden gap-8 text-sm font-semibold md:flex"><a href="#why-ajo" class="hover:underline">Why Ajo</a><a href="#how-it-works" class="hover:underline">How it works</a><a href="#questions" class="hover:underline">Questions</a></nav>
        <nav aria-label="Account" class="flex items-center gap-5 text-sm font-semibold">
            {#if !auth.user}<Link href={login()} class="underline-offset-4 hover:underline">Log in</Link>{/if}
            <Link href={start} class="action-link px-4! sm:px-6!">{auth.user ? 'Your account' : 'Get started'}<ArrowUpRight class="size-4" /></Link>
        </nav>
    </header>

    <main id="main-content" tabindex="-1" class="outline-none">
        <section class="mx-auto grid max-w-7xl items-center gap-6 px-5 pt-12 pb-20 sm:px-8 sm:pt-16 lg:grid-cols-[1.15fr_1fr] lg:gap-4 lg:px-12 lg:pt-20 lg:pb-28">
            <div class="relative z-10">
                <p class="eyebrow mb-7 flex items-center gap-2"><span class="size-2 rounded-full bg-secondary"></span> Small steps. Your pace.</p>
                <h1 class="max-w-3xl font-display text-[clamp(3.6rem,7vw,6.5rem)] leading-[.98] font-black tracking-[-.07em]">Big plans.<br />Start <span class="relative inline-block"><span class="relative z-10">little.</span><span class="absolute inset-x-0 bottom-1 h-[.25em] -rotate-2 bg-primary" aria-hidden="true"></span></span></h1>
                <p class="mt-8 max-w-md text-lg leading-relaxed text-muted-foreground">For the next chapter. The someday plan. The thing that matters to you. Make a little room for it today.</p>
                <div class="mt-9 flex flex-wrap items-center gap-6"><Link href={start} class="action-link">{auth.user ? 'Open your account' : 'Make your first move'}<ArrowUpRight class="size-5" /></Link><a href="#how-it-works" class="text-sm font-semibold underline underline-offset-4">See how it works ↓</a></div>
                <p class="mt-6 text-xs text-muted-foreground">A naira wallet. Fixed-term savings. A little more intention.</p>
            </div>
            <SavingsArtwork class="mx-auto max-w-[490px] lg:scale-105" />
        </section>

        <div class="border-y border-foreground bg-foreground text-background">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-5 py-6 font-mono text-xs font-semibold tracking-widest uppercase sm:px-8 lg:px-12"><span>Less someday.</span><span class="text-primary" aria-hidden="true">✳</span><span>More starting.</span><span class="text-secondary" aria-hidden="true">✳</span><span>Your pace. Your next chapter.</span></div>
        </div>

        <section id="why-ajo" class="mx-auto max-w-7xl scroll-mt-8 px-5 py-20 sm:px-8 lg:px-12 lg:py-28">
            <div class="flex flex-col justify-between gap-6 md:flex-row md:items-end"><div><p class="eyebrow mb-5">A little less complicated</p><h2 class="marketing-heading">Money with<br />a bit of intention.</h2></div><p class="max-w-sm leading-relaxed text-muted-foreground">A clear place to start, a plan you understand, and the details you need along the way.</p></div>
            <div class="mt-12 grid border-t border-l border-foreground md:grid-cols-3">
                {#each features as feature (feature.number)}
                    <article class="flex flex-col items-start border-r border-b border-foreground p-7 sm:p-9"><div class="mb-12 flex w-full items-center justify-between"><feature.icon class="size-7" strokeWidth={1.5} /><span class="font-mono text-xs text-muted-foreground">/{feature.number}</span></div><h3 class="font-display text-2xl font-bold tracking-tight">{feature.title}</h3><p class="mt-4 text-sm leading-relaxed text-muted-foreground">{feature.description}</p></article>
                {/each}
            </div>
        </section>

        <section id="how-it-works" class="scroll-mt-8 border-y border-foreground bg-secondary text-secondary-foreground">
            <div class="mx-auto grid max-w-7xl gap-12 px-5 py-20 sm:px-8 lg:grid-cols-2 lg:px-12 lg:py-24">
                <div><p class="eyebrow mb-5">From intention to action</p><h2 class="marketing-heading">Your next chapter<br />starts here.</h2><p class="mt-6 max-w-sm leading-relaxed">No grand gestures required.<br />Just a starting point and a plan that fits.</p><Link href={start} class="mt-8 inline-flex items-center gap-3 border-b-2 border-current pb-2 font-bold">{auth.user ? 'Back to your account' : 'Let’s get you started'}<ArrowUpRight class="size-5" /></Link></div>
                <ol class="divide-y divide-[#18181b]/30 border-y border-[#18181b]/30">
                    {#each [{ title: 'Make yourself at home.', description: 'Create your account and set up your security.' }, { title: 'Start with your wallet.', description: 'Add test funds and see your available balance.' }, { title: 'Find your next step.', description: 'Explore savings products. Read the terms. Choose when you’re ready.' }] as step, i (step.title)}
                        <li class="flex gap-6 py-7"><span class="pt-1 font-mono text-sm">0{i + 1}</span><div><h3 class="text-xl font-bold tracking-tight">{step.title}</h3><p class="mt-2 max-w-md text-sm leading-relaxed">{step.description}</p></div></li>
                    {/each}
                </ol>
            </div>
        </section>

        <section id="questions" class="mx-auto grid max-w-7xl scroll-mt-8 gap-10 px-5 py-20 sm:px-8 lg:grid-cols-[.8fr_1.2fr] lg:px-12 lg:py-28">
            <div><p class="eyebrow mb-5">Before you begin</p><h2 class="marketing-heading">Good<br />questions.</h2><p class="mt-6 max-w-xs text-sm leading-relaxed text-muted-foreground">Your money decisions deserve clear answers. Here are a few to start with.</p></div>
            <div class="border-t border-foreground/30">
                {#each questions as question (question.title)}
                    <details class="group border-b border-foreground/30 py-6"><summary class="flex cursor-pointer list-none items-center justify-between gap-6 text-lg font-semibold [&::-webkit-details-marker]:hidden">{question.title}<span class="text-2xl font-normal group-open:rotate-45" aria-hidden="true">+</span></summary><p class="mt-4 max-w-xl pr-8 text-sm leading-relaxed text-muted-foreground">{question.answer}</p></details>
                {/each}
            </div>
        </section>

        <section class="mx-5 mb-16 border-2 border-foreground bg-primary text-primary-foreground shadow-[6px_6px_0_var(--foreground)] sm:mx-8 lg:mx-auto lg:max-w-[calc(80rem-6rem)]">
            <div class="flex flex-col items-start justify-between gap-8 p-8 md:flex-row md:items-center md:p-14"><div><p class="eyebrow mb-4">A note to future you</p><h2 class="marketing-heading">Glad you started.</h2></div><Link href={start} class="inline-flex min-h-12 items-center gap-6 border-2 border-[#18181b] bg-[#18181b] px-6 py-3 text-sm font-bold text-[#fff9ed] hover:bg-[#303033]">{auth.user ? 'Open your account' : 'Create your account'}<ArrowUpRight class="size-5" /></Link></div>
        </section>
    </main>

    <footer class="mx-auto max-w-7xl px-5 pb-8 sm:px-8 lg:px-12"><div class="flex flex-wrap items-end justify-between gap-6 border-t border-foreground/30 pt-8"><div><Link href={home()} class="font-display text-4xl font-black tracking-tighter" aria-label="Ajo home">ajo.</Link><p class="mt-2 text-xs text-muted-foreground">A little today, for tomorrow.</p></div><p class="max-w-sm text-xs leading-relaxed text-muted-foreground">Ajo is a fictional savings platform. Payments and payouts are test-mode only. Do not use real funds.</p><a href="#main-content" class="text-xs font-semibold underline underline-offset-4">Back to top ↑</a></div></footer>
</div>
