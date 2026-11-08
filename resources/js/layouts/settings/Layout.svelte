<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { edit as editAppearance } from '@/routes/appearance';
    import { edit as editProfile } from '@/routes/profile';
    import { edit as editSecurity } from '@/routes/security';

    let { children }: { children?: Snippet } = $props();
    const navigation = [{ title: 'Profile', href: editProfile() }, { title: 'Security', href: editSecurity() }, { title: 'Appearance', href: editAppearance() }];
    const url = currentUrlState();
</script>

<div class="page-stack">
    <div class="page-intro"><p class="eyebrow mb-4 text-muted-foreground">Your account, your way</p><h1 class="font-display text-4xl font-black tracking-[-.045em] sm:text-5xl">The personal details.</h1><p class="mt-4">A few small things that make this space yours. Manage your profile, security, and appearance.</p></div>
    <nav aria-label="Settings" class="flex w-fit max-w-full flex-wrap gap-1 rounded-sm border border-foreground/20 bg-muted/50 p-1">
        {#each navigation as item (item.title)}<Link href={item.href} aria-current={url.isCurrentUrl(item.href, url.currentUrl) ? 'page' : undefined} class="min-h-11 rounded-sm px-5 py-3 text-sm font-semibold {url.isCurrentUrl(item.href, url.currentUrl) ? 'bg-foreground text-background' : 'hover:bg-accent'}">{item.title}</Link>{/each}
    </nav>
    <div class="panel w-full max-w-3xl"><div class="space-y-10">{@render children?.()}</div></div>
</div>
