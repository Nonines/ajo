<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { Button } from '@/components/ui/button';
    import { dashboard, home, login, register } from '@/routes';

    const auth = $derived(page.props.auth);
</script>

<AppHead title="Ajo | A little intention. A bigger tomorrow." />

<div class="mx-auto flex min-h-svh max-w-7xl flex-col px-6 sm:px-10">
    <header class="flex flex-wrap items-center justify-between gap-4 border-b-2 border-foreground py-6">
        <Link href={home()} class="flex items-center gap-2" aria-label="Ajo home">
            <AppLogoIcon class="size-10" />
            <span class="font-display text-3xl font-black tracking-tight">Ajo</span>
        </Link>
        <nav aria-label="Account" class="flex items-center gap-4 text-sm font-semibold">
            {#if auth.user}
                <Button asChild>
                    {#snippet children(props)}<Link {...props} href={dashboard()}>Open your account</Link>{/snippet}
                </Button>
            {:else}
                <Link href={login()} class="underline-offset-4 hover:underline">Log in</Link>
                <Button asChild>
                    {#snippet children(props)}<Link {...props} href={register()}>Get started</Link>{/snippet}
                </Button>
            {/if}
        </nav>
    </header>

    <main class="grid flex-1 items-center gap-12 py-16 md:grid-cols-[1.3fr_1fr] md:py-24">
        <section class="flex flex-col items-start gap-8">
            <p class="rounded-sm border-2 border-foreground bg-secondary px-3 py-1 font-mono text-xs font-bold tracking-widest text-secondary-foreground uppercase">Small steps. Your pace.</p>
            <h1 class="font-display text-5xl leading-[1.02] font-black tracking-tighter sm:text-7xl lg:text-8xl">A little intention.<br />A bigger tomorrow.</h1>
            <p class="max-w-lg text-lg leading-relaxed text-muted-foreground">Make room for what matters. Ajo is a place to build a savings habit, one intentional step at a time.</p>
            <Button size="lg" asChild>
                {#snippet children(props)}<Link {...props} href={auth.user ? dashboard() : register()}>{auth.user ? 'Go to your account' : 'Create your account'} <span aria-hidden="true">↗</span></Link>{/snippet}
            </Button>
        </section>

        <div class="relative mx-auto w-full max-w-md" aria-hidden="true">
            <div class="absolute -top-5 right-3 rotate-6 rounded-sm border-2 border-foreground bg-secondary px-5 py-2 font-mono text-sm font-bold text-secondary-foreground">FOR FUTURE YOU</div>
            <div class="rounded-md border-2 border-foreground bg-primary p-8 text-primary-foreground shadow-[8px_8px_0_var(--foreground)] sm:p-10">
                <AppLogoIcon class="mb-10 size-24" />
                <p class="font-display text-5xl leading-tight font-black tracking-tighter">Start small.<br />Think ahead.<br />Make it yours.</p>
                <div class="mt-10 flex items-center justify-between border-t-2 border-current pt-4 font-mono text-xs font-bold uppercase"><span>Ajo / NGN</span><span>Your next chapter ↗</span></div>
            </div>
        </div>
    </main>

    <footer class="flex flex-wrap justify-between gap-3 border-t-2 border-foreground py-6 text-xs text-muted-foreground">
        <span>Ajo. A little today, for tomorrow.</span>
        <span>A fictional savings platform. Payments are test-mode only.</span>
    </footer>
</div>
