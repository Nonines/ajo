<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import SavingsArtwork from '@/components/SavingsArtwork.svelte';
    import { home } from '@/routes';

    let { title = '', description = '', children }: { title?: string; description?: string; children?: Snippet } = $props();
</script>

<div class="customer-auth grid min-h-svh lg:grid-cols-2">
    <a href="#auth-content" class="skip-link">Skip to form</a>
    <aside class="relative hidden flex-col justify-between border-r border-foreground bg-secondary p-12 text-secondary-foreground lg:flex xl:p-16">
        <Link href={home()} class="flex w-fit items-center gap-2" aria-label="Ajo home"><AppLogoIcon class="size-11" /><span class="font-display text-4xl font-black tracking-tighter">ajo.</span></Link>
        <div class="my-10 max-w-lg"><p class="eyebrow mb-6">For your next chapter</p><h2 class="font-display text-6xl leading-[1.02] font-black tracking-[-.06em] xl:text-7xl">Good things<br />start little.</h2><SavingsArtwork class="mx-auto max-w-[370px]" /></div>
        <p class="font-mono text-xs font-semibold tracking-wider uppercase">A little today. A bigger tomorrow.</p>
    </aside>
    <div class="flex min-w-0 flex-col px-6 py-8 sm:px-12">
        <div class="flex items-center justify-between gap-4">
            <Link href={home()} class="flex items-center gap-2 lg:hidden" aria-label="Ajo home"><AppLogoIcon class="size-9" /><span class="font-display text-3xl font-black tracking-tighter">ajo.</span></Link>
            <Link href={home()} class="ml-auto py-2 text-xs font-semibold text-muted-foreground hover:text-foreground">← Back to Ajo</Link>
        </div>
        <main id="auth-content" tabindex="-1" class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-12 outline-none sm:py-16">
            <div class="mb-9"><p class="eyebrow mb-4 text-muted-foreground">Your space to start</p><h1 class="font-display text-4xl leading-[1.08] font-black tracking-[-.05em]">{title}</h1><p class="mt-4 text-sm leading-relaxed text-muted-foreground">{description}</p></div>
            {@render children?.()}
        </main>
        <p class="mx-auto max-w-sm text-center text-xs leading-relaxed text-muted-foreground">Ajo is a fictional savings platform.<br />Payments and payouts are test-mode only.</p>
    </div>
</div>
